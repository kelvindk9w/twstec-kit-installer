<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Dev;

/**
 * A máquina de verdade, pelo `docker` (a linha de comando: funciona igual no
 * Linux, no macOS e no Windows) e, fora do container, pela própria rede.
 *
 * O QUE O DOCKER SABE (`docker inspect` de todos os containers, rodando ou
 * parados, e os rótulos do Compose nos volumes e nas redes): os projetos que
 * existem e as portas que cada um publica — com o NOME de quem usa. O
 * container do próprio instalador (serviço `instalar`, one-off) não conta: o
 * Compose o põe no projeto com o nome da pasta, que é o nome sugerido. Ele não
 * cria rede (rede padrão do Docker) nem volume.
 *
 * O QUE MAIS OCUPA A PORTA:
 * - fora de container (o `composer create-project` na máquina): tenta abrir a
 *   porta em 127.0.0.1 e em 0.0.0.0 — o que não abre está em uso;
 * - dentro do container do instalador (`docker compose run --rm instalar`):
 *   abrir a porta ali dentro não diz nada sobre a máquina. Quem tenta é o
 *   próprio Docker: um container descartável (a imagem do instalador,
 *   `sleep`) com as portas publicadas em 127.0.0.1 — se o Docker não
 *   consegue publicar, a porta está em uso na máquina. LIMITAÇÃO: no Docker
 *   Desktop com WSL, um programa que escuta só dentro da distribuição WSL
 *   (fora do Docker) não impede o Docker de publicar a porta e não é visto;
 *   o `docker compose up -d` também não reclama, e a porta fica com quem
 *   chegou primeiro. Programas do Windows, do macOS e do Linux nativo são
 *   vistos.
 *
 * PROJETO CRIADO QUE AINDA NÃO SUBIU: sem containers, ele não apareceria.
 * O instalador reserva o nome e o número dele (reserve(): o volume do banco
 * do projeto, criado já com o rótulo do Compose e o do número), e as portas
 * desse número contam como em uso.
 *
 * Sem o Docker (não instalado, ou sem acesso ao socket), available() é
 * false: não há nomes de projeto e, dentro do container, nenhuma porta pode
 * ser conferida.
 *
 * ESTE ARQUIVO EXISTE EM DOIS PACOTES, IGUAL (só o namespace muda): no
 * twstec/kit e no twstec/kit-installer. Um teste do monorepo confere.
 */
final class DockerHost implements Host
{
    /**
     * Rótulo dos containers de teste de porta (fora da contagem).
     */
    public const PROBE_LABEL = 'twstec.kit.probe';

    /**
     * Rótulo do volume que reserva o número de um projeto criado (reserve()).
     */
    public const SLOT_LABEL = 'twstec.kit.slot';

    /**
     * O serviço do instalador no compose.yaml do twstec-kit.
     */
    public const INSTALLER_SERVICE = 'instalar';

    private ?bool $available = null;

    /**
     * @var list<string>
     */
    private array $projects = [];

    /**
     * @var array<int, string>
     */
    private array $published = [];

    /**
     * @param  bool  $inContainer  rodando no container do instalador
     * @param  string  $probeImage  a imagem do container de teste de porta
     */
    public function __construct(
        private readonly bool $inContainer = false,
        private readonly string $probeImage = '',
        private readonly string $docker = 'docker',
    ) {}

    /**
     * A partir do ambiente: TWS_KIT_IN_DOCKER=1 (o compose do instalador põe)
     * e TWS_KIT_PROBE_IMAGE.
     *
     * @param  array<string, string|false>  $env
     */
    public static function fromEnvironment(array $env): self
    {
        return new self(
            ($env['TWS_KIT_IN_DOCKER'] ?? '') === '1',
            (string) ($env['TWS_KIT_PROBE_IMAGE'] ?? ''),
        );
    }

    public function available(): bool
    {
        $this->load();

        return (bool) $this->available;
    }

    public function projects(): array
    {
        $this->load();

        return $this->projects;
    }

    public function published(): array
    {
        $this->load();

        return $this->published;
    }

    public function busy(array $ports): array
    {
        if ($ports === []) {
            return [];
        }

        if (! $this->inContainer) {
            return array_values(array_filter($ports, fn (int $port): bool => ! $this->canListen($port)));
        }

        if (! $this->available() || $this->probeImage === '') {
            return [];
        }

        if ($this->probe($ports)) {
            return [];
        }

        if (count($ports) === 1) {
            return $ports;
        }

        // Alguma não publicou: qual (uma por vez).
        return array_values(array_filter($ports, fn (int $port): bool => ! $this->probe([$port])));
    }

    public function reserve(string $name, int $slot): void
    {
        if (! $this->available()) {
            return;
        }

        $this->run([
            'volume', 'create',
            '--label', "com.docker.compose.project={$name}",
            '--label', 'com.docker.compose.volume=postgres_data',
            '--label', self::SLOT_LABEL."={$slot}",
            "{$name}_postgres_data",
        ]);

        // A próxima leitura já vê a reserva.
        $this->available = null;
        $this->projects = [];
        $this->published = [];
    }

    /**
     * Lê do Docker, uma vez: containers (com as portas configuradas, mesmo
     * parados), volumes e redes do Compose.
     */
    private function load(): void
    {
        if ($this->available !== null) {
            return;
        }

        [$code, $ids] = $this->run(['ps', '-aq', '--no-trunc']);
        $this->available = $code === 0;

        if (! $this->available) {
            return;
        }

        $projects = [];
        $ids = array_values(array_filter(array_map('trim', explode("\n", $ids))));

        foreach (array_chunk($ids, 100) as $chunk) {
            [$code, $json] = $this->run(['inspect', ...$chunk]);
            $containers = $code === 0 ? json_decode($json, true) : null;

            foreach (is_array($containers) ? $containers : [] as $container) {
                $labels = (array) ($container['Config']['Labels'] ?? []);

                // Nem o teste de porta, nem o PRÓPRIO instalador: o `docker
                // compose run --rm instalar` roda num container do projeto com o
                // nome da pasta — que é o nome sugerido. Contá-lo recusaria o
                // nome da pasta de todo mundo.
                if (isset($labels[self::PROBE_LABEL]) || self::isInstaller($labels)) {
                    continue;
                }

                $project = $labels['com.docker.compose.project'] ?? null;
                $owner = is_string($project) && $project !== '' ? $project : ltrim((string) ($container['Name'] ?? ''), '/');

                if (is_string($project) && $project !== '') {
                    $projects[] = $project;
                }

                $bindings = [
                    ...array_values((array) ($container['HostConfig']['PortBindings'] ?? [])),
                    ...array_values((array) ($container['NetworkSettings']['Ports'] ?? [])),
                ];

                foreach ($bindings as $list) {
                    foreach ((array) $list as $binding) {
                        $port = (int) ($binding['HostPort'] ?? 0);

                        if ($port > 0) {
                            $this->published[$port] ??= $owner;
                        }
                    }
                }
            }
        }

        // Os projetos criados que ainda não subiram: o número reservado no
        // volume do banco (reserve()) vale como as quatro portas em uso.
        [$code, $reserved] = $this->run(['volume', 'ls', '--filter', 'label='.self::SLOT_LABEL, '--format', '{{.Label "com.docker.compose.project"}} {{.Label "'.self::SLOT_LABEL.'"}}']);

        foreach ($code === 0 ? array_filter(array_map('trim', explode("\n", $reserved))) : [] as $line) {
            [$project, $slot] = array_pad(explode(' ', $line, 2), 2, '');
            $slot = DevEnvironment::parseSlot($slot);

            if ($project !== '' && $slot !== null) {
                foreach (DevEnvironment::ports($slot) as $port) {
                    $this->published[$port] ??= $project;
                }
            }
        }

        foreach (['volume', 'network'] as $kind) {
            [$code, $names] = $this->run([$kind, 'ls', '--filter', 'label=com.docker.compose.project', '--format', '{{.Label "com.docker.compose.project"}}']);

            if ($code === 0) {
                $projects = [...$projects, ...array_filter(array_map('trim', explode("\n", $names)))];
            }
        }

        $projects = array_values(array_unique($projects));
        sort($projects);
        $this->projects = $projects;
        ksort($this->published);
    }

    /**
     * Um container do instalador em container (`docker compose run instalar`
     * do twstec-kit): o serviço `instalar`, de uma vez (one-off). Não é um
     * projeto nem publica porta.
     *
     * @param  array<string, mixed>  $labels
     */
    public static function isInstaller(array $labels): bool
    {
        return ($labels['com.docker.compose.service'] ?? null) === self::INSTALLER_SERVICE
            && strtolower((string) ($labels['com.docker.compose.oneoff'] ?? '')) === 'true';
    }

    /**
     * O Docker consegue publicar essas portas agora? (Um container
     * descartável, apagado logo depois.)
     *
     * @param  list<int>  $ports
     */
    private function probe(array $ports): bool
    {
        $name = 'twstec-kit-probe-'.bin2hex(random_bytes(4));
        $arguments = ['run', '-d', '--rm', '--name', $name, '--label', self::PROBE_LABEL.'=1', '--entrypoint', 'sleep'];

        foreach ($ports as $port) {
            $arguments[] = '-p';
            $arguments[] = "127.0.0.1:{$port}:{$port}";
        }

        [$code] = $this->run([...$arguments, $this->probeImage, '30']);
        $this->run(['rm', '-f', $name]);

        return $code === 0;
    }

    /**
     * A porta abre? Em 127.0.0.1 e, fora do Windows, também em 0.0.0.0 (no
     * macOS, abrir o endereço específico dá certo mesmo com a porta em uso
     * no genérico). No Windows, só 127.0.0.1: escutar em 0.0.0.0 faria o
     * firewall perguntar se o PHP pode receber conexões.
     */
    private function canListen(int $port): bool
    {
        // Porta em uso = aviso do PHP + false: só o false interessa.
        set_error_handler(static fn (): bool => true);

        try {
            foreach (PHP_OS_FAMILY === 'Windows' ? ['127.0.0.1'] : ['127.0.0.1', '0.0.0.0'] as $address) {
                $server = stream_socket_server("tcp://{$address}:{$port}", $errno, $error);

                if ($server === false) {
                    return false;
                }

                fclose($server);
            }
        } finally {
            restore_error_handler();
        }

        return true;
    }

    /**
     * @param  list<string>  $arguments
     * @return array{0: int, 1: string}
     */
    private function run(array $arguments): array
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        // Sem o `docker` instalado, o proc_open avisa (e devolve false): o
        // aviso não interessa a quem está criando o projeto.
        set_error_handler(static fn (): bool => true);

        try {
            $process = proc_open([$this->docker, ...$arguments], [
                0 => ['file', $null, 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', $null, 'w'],
            ], $pipes);
        } finally {
            restore_error_handler();
        }

        if (! is_resource($process)) {
            return [127, ''];
        }

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}

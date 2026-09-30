<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Support;

use RuntimeException;

/**
 * O projeto criado passa a ser DO PROJETO, não do starter: aplicado UMA vez,
 * na criação (junto com o Docker de desenvolvimento — o tws:install), com o
 * nome do projeto.
 *
 * - `.env.example`: o endereço, o banco e o cookie de sessão do projeto (as
 *   linhas que já existem; nenhum segredo — as senhas ficam só no `.env`);
 * - `phpunit.pgsql.xml`: o banco da suíte é `<banco>_test` — o MESMO que o
 *   serviço db-init do compose.yaml cria (`${DB_DATABASE}_test`);
 * - `docker-compose.prod.yml`: o nome padrão do banco de produção;
 * - `composer.json`: o nome do pacote (`<vendor>/<nome>`, padrão
 *   `app/<nome>`), a licença (padrão `proprietary`) e nada da identidade do
 *   starter (descrição, página, suporte, palavras-chave, versão); o `content-hash`
 *   do composer.lock acompanha (o Composer não avisa "lock desatualizado");
 * - `LICENSE` (a MIT do kit) vira `NOTICE-KIT-MIT.txt`: a MIT exige manter o
 *   aviso junto do código do kit, mas ela não é a licença do projeto;
 * - o CI base (`docker/dev/ci.yml`) vai para `.github/workflows/ci.yml`.
 *
 * Cada passo só age quando o arquivo existe, e nunca sobrescreve o que o
 * projeto já tem (NOTICE, workflow).
 */
final class CreatedProject
{
    /**
     * A identidade do starter que não é do projeto.
     *
     * @var list<string>
     */
    public const STARTER_IDENTITY = ['description', 'keywords', 'homepage', 'support', 'authors', 'funding', 'version'];

    /**
     * As chaves do composer.json que entram no content-hash do composer.lock
     * (Composer\Package\Locker::getContentHash).
     *
     * @var list<string>
     */
    private const LOCK_HASH_KEYS = ['name', 'version', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'minimum-stability', 'prefer-stable', 'repositories', 'extra'];

    public const NOTICE_FILE = 'NOTICE-KIT-MIT.txt';

    public const CI_TEMPLATE = 'docker/dev/ci.yml';

    public const CI_WORKFLOW = '.github/workflows/ci.yml';

    public function __construct(private readonly string $root) {}

    /**
     * Vendor válido para o nome do pacote no Composer?
     */
    public static function validVendor(string $vendor): bool
    {
        return preg_match('/^[a-z0-9]([_.-]?[a-z0-9]+)*$/', $vendor) === 1;
    }

    /**
     * Licença válida (identificador SPDX, "proprietary", ou uma expressão
     * simples com OR/AND)?
     */
    public static function validLicense(string $license): bool
    {
        return preg_match('/^[A-Za-z0-9.+-]+(?: (?:OR|AND) [A-Za-z0-9.+-]+)*$/', $license) === 1;
    }

    /**
     * O .env.example com os valores do projeto (só as linhas ativas que já
     * existem). Devolve as chaves trocadas.
     *
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public function environmentExample(array $values): array
    {
        $file = new EnvironmentFile($this->root.'/.env.example');

        if (! $file->exists()) {
            return [];
        }

        $changed = [];

        foreach ($values as $key => $value) {
            if ($file->get($key) !== null) {
                $file->set($key, $value);
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /**
     * O banco da suíte contra o PostgreSQL: `<banco>_test`.
     */
    public function testDatabase(string $database): bool
    {
        return $this->rewrite('phpunit.pgsql.xml', '/(<(?:env|server) name="DB_DATABASE" value=")[^"]*(")/', '${1}'.$database.'_test${2}');
    }

    /**
     * O nome padrão do banco de produção.
     */
    public function productionDatabase(string $database): bool
    {
        return $this->rewrite('docker-compose.prod.yml', '/\$\{PROD_POSTGRES_DB:-[^}]*\}/', '${PROD_POSTGRES_DB:-'.$database.'}');
    }

    /**
     * O composer.json com o nome e a licença do projeto, sem a identidade do
     * starter; e o content-hash do composer.lock atualizado.
     */
    public function composerIdentity(string $package, string $license): bool
    {
        $path = $this->root.'/composer.json';

        if (! is_file($path)) {
            return false;
        }

        $composer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach (self::STARTER_IDENTITY as $key) {
            unset($composer[$key]);
        }

        // O nome e a licença no topo, como o Composer os escreve.
        $composer = ['name' => $package, ...array_diff_key($composer, ['name' => true, 'license' => true])];
        $composer = self::insertAfter($composer, 'type', 'license', $license);

        $this->write($path, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $lockPath = $this->root.'/composer.lock';

        if (is_file($lockPath)) {
            $lock = json_decode((string) file_get_contents($lockPath), true, flags: JSON_THROW_ON_ERROR);
            $lock['content-hash'] = self::contentHash((string) file_get_contents($path));
            $this->write($lockPath, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        }

        return true;
    }

    /**
     * A licença MIT do kit vira o aviso NOTICE-KIT-MIT.txt, com o cabeçalho
     * que explica o que ele é.
     */
    public function kitLicenseNotice(string $header): bool
    {
        $license = $this->root.'/LICENSE';
        $notice = $this->root.'/'.self::NOTICE_FILE;

        if (! is_file($license) || is_file($notice)) {
            return false;
        }

        $this->write($notice, rtrim($header)."\n\n".(string) file_get_contents($license));
        unlink($license);

        return true;
    }

    /**
     * O CI base no lugar do GitHub Actions (sem sobrescrever um que exista).
     */
    public function continuousIntegration(): bool
    {
        $template = $this->root.'/'.self::CI_TEMPLATE;
        $workflow = $this->root.'/'.self::CI_WORKFLOW;

        if (! is_file($template) || is_file($workflow)) {
            return false;
        }

        if (! is_dir(dirname($workflow)) && ! mkdir(dirname($workflow), 0777, true) && ! is_dir(dirname($workflow))) {
            throw new RuntimeException('Não foi possível criar '.dirname($workflow).'.');
        }

        return rename($template, $workflow);
    }

    /**
     * O content-hash do composer.lock para este composer.json — o mesmo
     * cálculo do Composer (Composer\Package\Locker::getContentHash).
     */
    public static function contentHash(string $composerJson): string
    {
        $content = json_decode($composerJson, true, flags: JSON_THROW_ON_ERROR);
        $relevant = [];

        foreach (array_intersect(self::LOCK_HASH_KEYS, array_keys($content)) as $key) {
            $relevant[$key] = $content[$key];
        }

        if (isset($content['config']['platform'])) {
            $relevant['config']['platform'] = $content['config']['platform'];
        }

        ksort($relevant);

        return md5((string) json_encode($relevant, 0));
    }

    private function rewrite(string $file, string $pattern, string $replacement): bool
    {
        $path = $this->root.'/'.$file;

        if (! is_file($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);
        $rewritten = (string) preg_replace($pattern, $replacement, $contents, -1, $count);

        if ($count === 0) {
            return false;
        }

        $this->write($path, $rewritten);

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function insertAfter(array $data, string $after, string $key, mixed $value): array
    {
        if (! array_key_exists($after, $data)) {
            return [...$data, $key => $value];
        }

        $result = [];

        foreach ($data as $current => $item) {
            $result[$current] = $item;

            if ($current === $after) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("Não foi possível gravar {$path}.");
        }
    }
}

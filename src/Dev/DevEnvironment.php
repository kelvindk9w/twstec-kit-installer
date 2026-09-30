<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Dev;

/**
 * O Docker de desenvolvimento de um projeto criado: o NOME do projeto e o
 * NÚMERO que define as portas.
 *
 * NOME: vira o COMPOSE_PROJECT_NAME (containers, volumes e rede com o prefixo
 * do projeto), o endereço `http://<nome>.localhost:<porta>` (cada projeto no
 * seu host: cookie é por host, não por porta — dois projetos em `localhost`
 * dividiriam a sessão e o XSRF-TOKEN), o nome do banco e o do cookie de
 * sessão. Letras minúsculas, números e hífen, começando por letra.
 *
 * NÚMERO: todas as portas do projeto terminam nele — site 808N, e-mails
 * (Mailpit) 802N, Vite 803N, banco 804N (o banco só é publicado se pedido).
 * De 0 a 9; ocupados os dez, a centena seguinte, com o mesmo padrão: 10 é
 * 8180/8120/8130/8140, 11 é 8181/…, até 99 (8989/8929/8939/8949). O número
 * sugerido é o primeiro em que as QUATRO portas estão livres ao mesmo tempo.
 *
 * Classe pura (só PHP): o que consulta a máquina é o Host.
 *
 * ESTE ARQUIVO EXISTE EM DOIS PACOTES, IGUAL (só o namespace muda): no
 * twstec/kit e no twstec/kit-installer. Um teste do monorepo confere.
 */
final class DevEnvironment
{
    /**
     * Serviço => a porta do número 0.
     *
     * @var array<string, int>
     */
    public const BASE_PORTS = [
        'site' => 8080,
        'mail' => 8020,
        'vite' => 8030,
        'database' => 8040,
    ];

    /**
     * O maior número (a décima centena: 898N, 892N, 893N, 894N).
     */
    public const MAX_SLOT = 99;

    /**
     * O nome sugerido quando a pasta não diz nada (a do ZIP do twstec/kit).
     */
    public const DEFAULT_NAME = 'meu-projeto';

    public const NAME_MAX_LENGTH = 40;

    /**
     * O perfil do Compose que publica o banco na máquina.
     */
    public const DATABASE_PROFILE = 'db-port';

    /**
     * O banco da suíte contra o PostgreSQL (phpunit.pgsql.xml dos starters).
     */
    public const TEST_DATABASE = 'tws_starter_test';

    /**
     * As portas de um número.
     *
     * @return array{site: int, mail: int, vite: int, database: int}
     */
    public static function ports(int $slot): array
    {
        $offset = intdiv($slot, 10) * 100 + $slot % 10;

        return [
            'site' => self::BASE_PORTS['site'] + $offset,
            'mail' => self::BASE_PORTS['mail'] + $offset,
            'vite' => self::BASE_PORTS['vite'] + $offset,
            'database' => self::BASE_PORTS['database'] + $offset,
        ];
    }

    /**
     * O número escrito (" 3 ", "12") — ou null quando não é um número válido.
     */
    public static function parseSlot(string $text): ?int
    {
        $text = trim($text);

        if (preg_match('/^\d{1,2}$/', $text) !== 1) {
            return null;
        }

        return (int) $text;
    }

    /**
     * O texto como nome de projeto: minúsculas, sem acento, hífen no lugar
     * de espaço e pontuação ("Loja da Maria!" → "loja-da-maria").
     */
    public static function slug(string $text): string
    {
        $text = strtr(mb_strtolower(trim($text), 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y',
        ]);

        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        // Começa por letra (o nome vira host, rede e banco).
        $text = (string) preg_replace('/^[0-9-]+/', '', $text);

        return rtrim(substr($text, 0, self::NAME_MAX_LENGTH), '-');
    }

    /**
     * O que há de errado com o nome: 'format' (fora do padrão), 'length' ou
     * null (vale). O nome JÁ EM USO é outra pergunta (taken()).
     */
    public static function nameProblem(string $name): ?string
    {
        if (strlen($name) < 2 || strlen($name) > self::NAME_MAX_LENGTH) {
            return 'length';
        }

        if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $name) !== 1) {
            return 'format';
        }

        return null;
    }

    /**
     * Já existe projeto Docker com esse nome (containers, volumes ou redes —
     * mesmo parado)? Reusar o nome misturaria os dois: os containers de um
     * seriam trocados pelos do outro, e o banco novo cairia no volume velho,
     * com outra senha.
     *
     * @param  list<string>  $projects
     */
    public static function taken(string $name, array $projects): bool
    {
        return in_array($name, $projects, true);
    }

    /**
     * O nome sugerido: o da pasta (o ZIP do twstec/kit não conta — vira
     * "meu-projeto"), com -2, -3… até não colidir com projeto que já existe.
     *
     * @param  list<string>  $projects
     */
    public static function suggestName(string $folder, array $projects): string
    {
        $base = self::slug($folder);

        if (self::nameProblem($base) !== null || preg_match('/^twstec-kit(?:-(?:main|master|desenvolvimento|v?\d[\w-]*))?$/', $base) === 1) {
            $base = self::DEFAULT_NAME;
        }

        $name = $base;

        for ($i = 2; self::taken($name, $projects); $i++) {
            $name = substr($base, 0, self::NAME_MAX_LENGTH - strlen("-{$i}"))."-{$i}";
        }

        return $name;
    }

    /**
     * Quem ocupa as portas de um número: porta => quem ('' = um programa
     * fora do Docker, ou sem nome). Vazio = as quatro portas livres.
     *
     * Primeiro o que o Docker sabe (inclusive projeto parado, que volta a
     * usar a porta quando subir); o resto, tentando abrir a porta.
     *
     * @return array<int, string>
     */
    public static function occupants(Host $host, int $slot): array
    {
        $published = $host->published();
        $occupants = [];
        $unknown = [];

        foreach (self::ports($slot) as $port) {
            if (isset($published[$port])) {
                $occupants[$port] = $published[$port];
            } else {
                $unknown[] = $port;
            }
        }

        if ($unknown !== []) {
            foreach ($host->busy($unknown) as $port) {
                $occupants[$port] = '';
            }
        }

        ksort($occupants);

        return $occupants;
    }

    /**
     * O primeiro número, a partir de $from, com as QUATRO portas livres; null
     * quando não há nenhum até o 99.
     */
    public static function firstFree(Host $host, int $from = 0): ?int
    {
        for ($slot = max(0, $from); $slot <= self::MAX_SLOT; $slot++) {
            if (self::occupants($host, $slot) === []) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * Os números de uma centena que o Docker já usa (sem abrir porta
     * nenhuma — rápido): número => quem, sem repetir.
     *
     * @return array<int, list<string>>
     */
    public static function usedInHundred(Host $host, int $hundred): array
    {
        $published = $host->published();
        $used = [];

        for ($slot = $hundred * 10; $slot < $hundred * 10 + 10; $slot++) {
            foreach (self::ports($slot) as $port) {
                if (isset($published[$port])) {
                    $used[$slot][] = $published[$port];
                }
            }

            if (isset($used[$slot])) {
                $used[$slot] = array_values(array_unique($used[$slot]));
            }
        }

        return $used;
    }

    /**
     * O nome do banco (hífen não serve em nome de banco sem aspas).
     */
    public static function databaseName(string $name): string
    {
        return str_replace('-', '_', $name);
    }

    /**
     * O endereço do projeto numa porta: http://<nome>.localhost:<porta>.
     */
    public static function url(string $name, int $port): string
    {
        return "http://{$name}.localhost:{$port}";
    }

    /**
     * As variáveis do .env do projeto para o Docker de desenvolvimento.
     *
     * As senhas do banco e do Redis são do projeto (geradas), nunca uma
     * senha fixa do kit: nenhum dos dois é publicado na máquina por padrão,
     * e o banco, quando for, sai com a senha deste projeto.
     *
     * @param  callable(): string  $secret  gera uma senha nova
     * @return array<string, string>
     */
    public static function environment(string $name, int $slot, bool $exposeDatabase, int $uid, int $gid, bool $polling, callable $secret): array
    {
        $ports = self::ports($slot);
        $url = self::url($name, $ports['site']);

        return [
            'COMPOSE_PROJECT_NAME' => $name,
            'COMPOSE_PROFILES' => $exposeDatabase ? self::DATABASE_PROFILE : '',
            'DEV_SLOT' => (string) $slot,
            'DEV_SITE_PORT' => (string) $ports['site'],
            'DEV_MAIL_PORT' => (string) $ports['mail'],
            'DEV_VITE_PORT' => (string) $ports['vite'],
            'DEV_DB_PORT' => (string) $ports['database'],
            'DEV_UID' => (string) ($uid > 0 ? $uid : 1000),
            'DEV_GID' => (string) ($gid > 0 ? $gid : 1000),
            'DEV_VITE_POLLING' => $polling ? 'true' : 'false',
            'APP_URL' => $url,
            'PLATFORM_OFFICIAL_URL' => $url,
            'SESSION_COOKIE' => self::databaseName($name).'_session',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'postgres',
            'DB_PORT' => '5432',
            'DB_DATABASE' => self::databaseName($name),
            'DB_PASSWORD' => $secret(),
            'REDIS_HOST' => 'redis',
            'REDIS_PASSWORD' => $secret(),
            'MAIL_HOST' => 'mailpit',
            'MAIL_PORT' => '1025',
        ];
    }
}

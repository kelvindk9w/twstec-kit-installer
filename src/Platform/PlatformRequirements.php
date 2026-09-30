<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Platform;

/**
 * As exigências de plataforma (extensões do PHP) nas chamadas ao Composer.
 *
 * 1. O QUE A PESSOA PEDIU: `--ignore-platform-req=ext-x` e
 *    `--ignore-platform-reqs` no `composer create-project` não chegam aos
 *    comandos do Composer que o instalador roda por dentro (o `composer
 *    update` do starter, o `composer require` do tws:add). As variáveis
 *    COMPOSER_IGNORE_PLATFORM_REQ / COMPOSER_IGNORE_PLATFORM_REQS chegam
 *    (são herdadas), e as opções, quando dá para lê-las no comando que chamou
 *    (Linux e WSL: /proc), viram essas variáveis.
 *
 * 2. WINDOWS: o PHP nativo do Windows não tem `ext-pcntl` nem `ext-posix`, e o
 *    Horizon (painel de filas) as exige. Só essas duas são ignoradas, sozinhas;
 *    o aplicativo roda sem elas (a fila, com `php artisan queue:work`). Qualquer
 *    outra extensão que falte (bcmath, gd, intl…) NÃO é ignorada: a instalação
 *    para com a lista e as duas saídas (instalar a extensão, ou o caminho só
 *    com o Docker).
 *
 * Classe pura (só PHP). ESTE ARQUIVO EXISTE EM DOIS PACOTES, IGUAL (só o
 * namespace muda): no twstec/kit e no twstec/kit-installer. Um teste do
 * monorepo confere.
 */
final class PlatformRequirements
{
    /**
     * O que o Windows não tem e o kit dispensa (só o Horizon usa).
     *
     * @var list<string>
     */
    public const WINDOWS_IGNORED = ['ext-pcntl', 'ext-posix'];

    /**
     * As variáveis de ambiente para as chamadas ao Composer: o que a pessoa
     * pediu (variáveis e opções do comando que chamou) e, no Windows, as duas
     * extensões que ele não tem. Vazio = nada a ignorar.
     *
     * @param  array<string, string|false>  $env
     * @param  list<string>  $argv  o comando que chamou (vazio quando não dá para ler)
     * @return array<string, string>
     */
    public static function composerEnvironment(array $env, string $osFamily, array $argv = []): array
    {
        [$all, $requirements] = self::requested($env, $argv);

        if ($all) {
            return ['COMPOSER_IGNORE_PLATFORM_REQS' => '1'];
        }

        if (self::isWindows($osFamily)) {
            $requirements = [...$requirements, ...self::WINDOWS_IGNORED];
        }

        $requirements = array_values(array_unique($requirements));

        return $requirements === [] ? [] : ['COMPOSER_IGNORE_PLATFORM_REQ' => implode(',', $requirements)];
    }

    /**
     * O que a pessoa pediu para ignorar: tudo, ou a lista.
     *
     * @param  array<string, string|false>  $env
     * @param  list<string>  $argv
     * @return array{0: bool, 1: list<string>}
     */
    public static function requested(array $env, array $argv = []): array
    {
        $all = in_array(strtolower(trim((string) ($env['COMPOSER_IGNORE_PLATFORM_REQS'] ?? ''))), ['1', 'true', 'yes', 'on'], true);
        $requirements = self::list((string) ($env['COMPOSER_IGNORE_PLATFORM_REQ'] ?? ''));

        foreach ($argv as $i => $argument) {
            if ($argument === '--ignore-platform-reqs') {
                $all = true;
            } elseif (str_starts_with($argument, '--ignore-platform-req=')) {
                $requirements = [...$requirements, ...self::list(substr($argument, strlen('--ignore-platform-req=')))];
            } elseif ($argument === '--ignore-platform-req' && isset($argv[$i + 1])) {
                $requirements = [...$requirements, ...self::list($argv[$i + 1])];
            }
        }

        return [$all, array_values(array_unique($requirements))];
    }

    public static function isWindows(string $osFamily): bool
    {
        return strtolower($osFamily) === 'windows';
    }

    /**
     * As extensões que faltam, pela mensagem do Composer ("Install or enable
     * PHP's gd extension."), sem repetir e na ordem em que aparecem.
     *
     * @return list<string>
     */
    public static function missingExtensions(string $composerOutput): array
    {
        preg_match_all("/Install or enable PHP's ([A-Za-z0-9_]+) extension/", $composerOutput, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    /**
     * O comando do Composer que chamou este processo (o `composer
     * create-project …`), subindo pelos processos pais — só onde dá para ler
     * (/proc: Linux e WSL). Vazio quando não acha.
     *
     * @return list<string>
     */
    public static function callerArguments(int $levels = 6): array
    {
        // Pelo /proc (e não pela extensão posix, que pode faltar).
        $pid = self::parentOf('self');

        if ($pid === null) {
            return [];
        }

        for ($i = 0; $i < $levels && $pid > 1; $i++) {
            $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
            $arguments = is_string($cmdline) ? array_values(array_filter(explode("\0", $cmdline), static fn (string $a): bool => $a !== '')) : [];

            if (in_array('create-project', $arguments, true)) {
                return $arguments;
            }

            $pid = self::parentOf((string) $pid);

            if ($pid === null) {
                break;
            }
        }

        return [];
    }

    /**
     * O processo pai, pelo /proc/<pid>/status; null sem /proc.
     */
    private static function parentOf(string $pid): ?int
    {
        $status = @file_get_contents("/proc/{$pid}/status");

        if (! is_string($status) || preg_match('/^PPid:\s+(\d+)/m', $status, $match) !== 1) {
            return null;
        }

        return (int) $match[1];
    }

    /**
     * @return list<string>
     */
    private static function list(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower($raw)))));
    }
}

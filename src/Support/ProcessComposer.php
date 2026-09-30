<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Support;

use Closure;
use Illuminate\Support\Composer as LaravelComposer;
use Twstec\Kit\Installer\Contracts\Composer;
use Twstec\Kit\Installer\Platform\PlatformRequirements;

/**
 * O Composer de verdade, pelo helper do Laravel (Illuminate\Support\Composer),
 * na raiz do projeto.
 *
 * Dentro de um script do próprio Composer (o `post-create-project-cmd` do
 * `composer create-project` / `laravel new --using=`), o Composer em execução
 * se anuncia em COMPOSER_BINARY: é esse que roda os comandos, e não um
 * `composer` qualquer do PATH.
 *
 * EXTENSÕES: o que a pessoa pediu para ignorar (COMPOSER_IGNORE_PLATFORM_REQ(S))
 * vale, e no Windows `ext-pcntl` e `ext-posix` (só o Horizon as usa; o PHP do
 * Windows não as tem) são ignoradas sozinhas — nenhuma outra. Ver
 * Platform\PlatformRequirements (TWS_KIT_OS_FAMILY=Windows simula o Windows).
 */
final class ProcessComposer implements Composer
{
    public function __construct(private readonly LaravelComposer $composer) {}

    public function require(array $packages, bool $dev, Closure $output): bool
    {
        return self::withPlatform(fn (): bool => $this->composer->requirePackages($packages, $dev, $output, self::binary()));
    }

    public function remove(array $packages, bool $dev, Closure $output): bool
    {
        return self::withPlatform(fn (): bool => $this->composer->removePackages($packages, $dev, $output, self::binary()));
    }

    /**
     * O sistema: TWS_KIT_OS_FAMILY (simula outro) ou o deste PHP.
     */
    public static function osFamily(): string
    {
        $simulated = getenv('TWS_KIT_OS_FAMILY');

        return is_string($simulated) && $simulated !== '' ? $simulated : PHP_OS_FAMILY;
    }

    /**
     * Roda com as variáveis de plataforma no ambiente (o processo do Composer
     * herda de $_ENV e do getenv()), e devolve o ambiente como estava.
     *
     * @param  Closure(): bool  $call
     */
    private static function withPlatform(Closure $call): bool
    {
        $variables = PlatformRequirements::composerEnvironment(getenv(), self::osFamily());
        $previous = [];

        foreach ($variables as $name => $value) {
            $previous[$name] = [$_ENV[$name] ?? null, getenv($name)];
            $_ENV[$name] = $value;
            putenv("{$name}={$value}");
        }

        try {
            return $call();
        } finally {
            foreach ($previous as $name => [$env, $process]) {
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }

                putenv($process === false ? $name : "{$name}={$process}");
            }
        }
    }

    private static function binary(): ?string
    {
        $binary = getenv('COMPOSER_BINARY');

        return is_string($binary) && $binary !== '' ? $binary : null;
    }
}

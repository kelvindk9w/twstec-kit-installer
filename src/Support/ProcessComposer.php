<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Support;

use Closure;
use Illuminate\Support\Composer as LaravelComposer;
use Twstec\Kit\Installer\Contracts\Composer;

/**
 * O Composer de verdade, pelo helper do Laravel (Illuminate\Support\Composer),
 * na raiz do projeto.
 *
 * Dentro de um script do próprio Composer (o `post-create-project-cmd` do
 * `composer create-project` / `laravel new --using=`), o Composer em execução
 * se anuncia em COMPOSER_BINARY: é esse que roda os comandos, e não um
 * `composer` qualquer do PATH.
 */
final class ProcessComposer implements Composer
{
    public function __construct(private readonly LaravelComposer $composer) {}

    public function require(array $packages, bool $dev, Closure $output): bool
    {
        return $this->composer->requirePackages($packages, $dev, $output, self::binary());
    }

    public function remove(array $packages, bool $dev, Closure $output): bool
    {
        return $this->composer->removePackages($packages, $dev, $output, self::binary());
    }

    private static function binary(): ?string
    {
        $binary = getenv('COMPOSER_BINARY');

        return is_string($binary) && $binary !== '' ? $binary : null;
    }
}

<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Contracts;

use Closure;

/**
 * O Composer, do ponto de vista do instalador: acrescentar e tirar pacotes do
 * projeto. Interface para a suíte trocar pelo Composer de mentira (nenhum
 * teste mexe num composer.json de verdade).
 */
interface Composer
{
    /**
     * `composer require` (com `--dev` se $dev).
     *
     * @param  list<string>  $packages  `vendor/pacote` ou `vendor/pacote:restrição`
     * @param  Closure(string, string): void  $output  (tipo, linha)
     */
    public function require(array $packages, bool $dev, Closure $output): bool;

    /**
     * `composer remove` (com `--dev` se $dev).
     *
     * @param  list<string>  $packages
     * @param  Closure(string, string): void  $output  (tipo, linha)
     */
    public function remove(array $packages, bool $dev, Closure $output): bool;
}

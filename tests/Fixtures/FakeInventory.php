<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Tests\Fixtures;

use Twstec\Kit\Installer\Contracts\Inventory;

/**
 * O projeto no estado que o teste quiser — sem instalar pacote nenhum.
 */
final class FakeInventory implements Inventory
{
    /**
     * @param  list<string>  $modules
     */
    public function __construct(public array $modules, public bool $demo = false) {}

    public function optionalModules(): array
    {
        return $this->modules;
    }

    public function demoInstalled(): bool
    {
        return $this->demo;
    }
}

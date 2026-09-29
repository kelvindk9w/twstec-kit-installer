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
     * @param  list<string>  $modules  os opcionais instalados
     * @param  list<string>  $required  os obrigatórios instalados (um
     *                                  aplicativo que só tem o instalador tem
     *                                  só o foundation)
     */
    public function __construct(
        public array $modules,
        public bool $demo = false,
        public array $required = ['foundation', 'auth'],
        public bool $userModel = true,
    ) {}

    public function userModelReady(): bool
    {
        return $this->userModel;
    }

    public function installedModules(): array
    {
        return [...$this->required, ...$this->modules];
    }

    public function optionalModules(): array
    {
        return $this->modules;
    }

    public function demoInstalled(): bool
    {
        return $this->demo;
    }
}

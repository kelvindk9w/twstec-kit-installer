<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Contracts;

/**
 * O que está instalado no projeto agora. Interface para a suíte montar o
 * cenário que quiser (com admin, sem uploads…) sem instalar pacote nenhum.
 */
interface Inventory
{
    /**
     * Os módulos OPCIONAIS do kit instalados (accounts, uploads, admin).
     *
     * @return list<string>
     */
    public function optionalModules(): array;

    /**
     * A demonstração do kit (twstec/kit-demo) está instalada?
     */
    public function demoInstalled(): bool;
}

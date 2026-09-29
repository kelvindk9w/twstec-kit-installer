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
     * TODOS os módulos do kit instalados, os obrigatórios inclusive
     * (foundation, auth…) — o `tws:add` num aplicativo que só tem o
     * foundation (a dependência do instalador).
     *
     * @return list<string>
     */
    public function installedModules(): array;

    /**
     * O model de usuário do APLICATIVO (`auth.providers.users.model`) já
     * implementa o contrato da autenticação do kit
     * (Twstec\\Kit\\Auth\\Contracts\\AuthUser)? O pacote de contas liga a
     * conta pessoal aos eventos desse model já no boot: sem o contrato, o
     * aplicativo nem sobe depois do `composer require`.
     */
    public function userModelReady(): bool;

    /**
     * A demonstração do kit (twstec/kit-demo) está instalada?
     */
    public function demoInstalled(): bool;
}

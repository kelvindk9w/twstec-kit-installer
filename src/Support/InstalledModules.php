<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Support;

use Composer\InstalledVersions;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Installer\Contracts\Inventory;

/**
 * O que está instalado, perguntado ao ponto único de detecção do kit
 * (Kit::has) e ao registro do Composer (a demonstração não é módulo do
 * produto: o kit não a conhece pelo nome de classe, só pelo pacote).
 */
final class InstalledModules implements Inventory
{
    /**
     * O contrato do model de usuário da autenticação do kit — pelo nome: o
     * instalador não depende do twstec/kit-auth (ele pode nem estar
     * instalado).
     */
    public const USER_CONTRACT = 'Twstec\\Kit\\Auth\\Contracts\\AuthUser';

    public function optionalModules(): array
    {
        return Kit::installedOptional();
    }

    public function installedModules(): array
    {
        return Kit::installed();
    }

    public function userModelReady(): bool
    {
        $model = config('auth.providers.users.model');

        return is_string($model)
            && interface_exists(self::USER_CONTRACT)
            && is_subclass_of($model, self::USER_CONTRACT);
    }

    public function demoInstalled(): bool
    {
        return InstalledVersions::isInstalled(InstallPlan::DEMO_PACKAGE);
    }
}

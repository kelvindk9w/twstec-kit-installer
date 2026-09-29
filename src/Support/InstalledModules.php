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
    public function optionalModules(): array
    {
        return Kit::installedOptional();
    }

    public function demoInstalled(): bool
    {
        return InstalledVersions::isInstalled(InstallPlan::DEMO_PACKAGE);
    }
}

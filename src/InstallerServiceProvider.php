<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer;

use Illuminate\Support\ServiceProvider;
use Twstec\Kit\Foundation\Localization\PackageTranslations;
use Twstec\Kit\Installer\Console\InstallCommand;
use Twstec\Kit\Installer\Contracts\Composer;
use Twstec\Kit\Installer\Contracts\Inventory;
use Twstec\Kit\Installer\Support\InstalledModules;
use Twstec\Kit\Installer\Support\ProcessComposer;

/**
 * Provider do twstec/kit-installer (descoberta automática do Laravel): o
 * comando `tws:install` e as traduções dele (o aplicativo vence).
 *
 * POR QUE UM PACOTE À PARTE, e não o foundation: o instalador é FERRAMENTA DE
 * DESENVOLVIMENTO — roda o Composer e escreve no .env. Fica em `require-dev`
 * dos starters (os dois usam o mesmo), e por isso não chega à imagem de
 * produção (`composer install --no-dev`). O foundation é a base de segurança
 * que vai para toda aplicação, inclusive as que instalam só os pacotes, sem
 * starter nenhum — lá, um comando que tira pacotes do projeto não tem lugar.
 */
final class InstallerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(Composer::class, ProcessComposer::class);
        $this->app->bindIf(Inventory::class, InstalledModules::class);

        PackageTranslations::register($this->app, dirname(__DIR__).'/lang');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }
    }
}

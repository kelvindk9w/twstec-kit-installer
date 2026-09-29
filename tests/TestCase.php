<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Tests;

use Illuminate\Filesystem\Filesystem;
use Orchestra\Testbench\TestCase as Testbench;
use Spatie\Backup\BackupServiceProvider;
use Twstec\Kit\Foundation\FoundationServiceProvider;
use Twstec\Kit\Installer\Contracts\Composer;
use Twstec\Kit\Installer\Contracts\Inventory;
use Twstec\Kit\Installer\InstallerServiceProvider;
use Twstec\Kit\Installer\Tests\Fixtures\FakeComposer;
use Twstec\Kit\Installer\Tests\Fixtures\FakeInventory;

/**
 * Aplicação Laravel LIMPA (Testbench) com o foundation e o instalador — e um
 * PROJETO descartável numa pasta temporária (composer.json, .env.example e,
 * quando o teste quiser, .env), apontado como a pasta do .env da aplicação.
 * O Composer e o inventário são de mentira; os comandos do artisan que o
 * instalador roda em processo novo passam pelo Process::fake.
 */
abstract class TestCase extends Testbench
{
    /**
     * Providers do pacote — os mesmos que a descoberta automática instala
     * (composer.json → extra.laravel; um teste confere).
     *
     * @var list<class-string>
     */
    public const PACKAGE_PROVIDERS = [
        InstallerServiceProvider::class,
    ];

    protected string $project;

    protected FakeComposer $composer;

    protected function getPackageProviders($app): array
    {
        return [
            BackupServiceProvider::class,
            FoundationServiceProvider::class,
            ...self::PACKAGE_PROVIDERS,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $this->project = sys_get_temp_dir().'/kit-installer-'.bin2hex(random_bytes(6));
        mkdir($this->project);

        file_put_contents($this->project.'/composer.json', json_encode([
            'name' => 'twstec/starter-livewire',
            'require' => ['twstec/kit-foundation' => '^2.0@beta', 'twstec/kit-auth' => '^2.0@beta'],
        ], JSON_PRETTY_PRINT));

        file_put_contents($this->project.'/.env.example', implode("\n", [
            'APP_NAME=Kit',
            'APP_KEY=',
            '# Pepper do hash das chaves de API.',
            '# API_KEYS_HASH_PEPPER=',
            '# API_KEYS_PREVIOUS_HASH_PEPPERS=',
            '',
        ]));

        $app->useEnvironmentPath($this->project);

        $this->composer = new FakeComposer;
        $app->instance(Composer::class, $this->composer);
        $app->instance(Inventory::class, new FakeInventory(['accounts', 'uploads', 'admin']));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->project);

        parent::tearDown();
    }

    /**
     * O projeto no estado que o teste quer.
     *
     * @param  list<string>  $modules
     */
    protected function project(array $modules, bool $demo = false): void
    {
        $this->app->instance(Inventory::class, new FakeInventory($modules, $demo));
    }

    protected function envFile(): string
    {
        return (string) @file_get_contents($this->project.'/.env');
    }
}

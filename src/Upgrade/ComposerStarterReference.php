<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Upgrade;

use Composer\InstalledVersions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer as LaravelComposer;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Twstec\Kit\Installer\Contracts\StarterReference;

/**
 * O starter de referência baixado pelo Composer: `composer create-project
 * twstec/starter-<interface> <pasta> <versão> --no-install --no-scripts
 * --no-plugins` — só os arquivos do pacote publicado (o mesmo ZIP de quem cria
 * um projeto), sem instalar dependência nem rodar script nenhum.
 *
 * Fica guardado em `storage/framework/cache/tws-starters/<interface>-<versão>`
 * (fora do Git: o storage/framework/cache do projeto ignora tudo, e o
 * `cache:clear` só limpa a pasta data/): a segunda conferência da mesma versão
 * não baixa de novo.
 */
final class ComposerStarterReference implements StarterReference
{
    public function __construct(
        private readonly LaravelComposer $composer,
        private readonly string $cache,
    ) {}

    public function version(): ?string
    {
        if (! InstalledVersions::isInstalled('twstec/kit-foundation')) {
            return null;
        }

        $version = ltrim((string) InstalledVersions::getPrettyVersion('twstec/kit-foundation'), 'v');

        return $version === '' || str_contains($version, 'dev') ? null : $version;
    }

    public function directory(string $starter, string $version): string
    {
        $target = rtrim($this->cache, '/').'/'.$starter.'-'.$version;

        if (is_file($target.'/composer.json')) {
            return $target;
        }

        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($target));
        $partial = $target.'.partial';
        $files->deleteDirectory($partial);

        $binary = getenv('COMPOSER_BINARY');
        $result = Process::timeout(600)->run([
            ...$this->composer->findComposer(is_string($binary) && $binary !== '' ? $binary : null),
            'create-project', "twstec/starter-{$starter}", $partial, $version,
            '--no-install', '--no-scripts', '--no-plugins', '--no-interaction', '--no-progress',
        ]);

        if (! $result->successful() || ! is_file($partial.'/composer.json')) {
            $files->deleteDirectory($partial);

            throw new RuntimeException(__('installer.upgrade.download_failed', [
                'package' => "twstec/starter-{$starter}:{$version}",
                'output' => trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output()),
            ]));
        }

        $files->moveDirectory($partial, $target, true);

        return $target;
    }
}

<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Console\Concerns;

use Closure;
use Illuminate\Support\Facades\Process;
use Twstec\Kit\Installer\Platform\PlatformRequirements;
use Twstec\Kit\Installer\Support\EnvironmentFile;
use Twstec\Kit\Installer\Support\ProcessComposer;

/**
 * O que os dois comandos do instalador (tws:install e tws:add) fazem no
 * projeto do mesmo jeito: rodar o artisan num processo novo, achar os
 * arquivos da raiz, falar dos módulos pelo nome e preparar o .env.
 */
trait InteractsWithProject
{
    /**
     * A saída do Composer desta rodada (a mensagem de extensão que falta é
     * lida dela).
     */
    private string $composerOutput = '';

    /**
     * O que o Composer escreve: na tela e guardado.
     */
    private function composerOutput(): Closure
    {
        return function (string $type, string $line): void {
            $this->composerOutput .= $line;
            $this->output->write($line);
        };
    }

    /**
     * O Composer falhou: a mensagem — e, se faltam extensões do PHP, a lista e
     * as duas saídas (instalar a extensão, ou o caminho só com o Docker).
     */
    private function composerFailed(): void
    {
        $this->components->error(__('installer.failures.composer'));
        $missing = PlatformRequirements::missingExtensions($this->composerOutput);

        if ($missing === []) {
            return;
        }

        $this->components->error(__('installer.extensions.missing', ['extensions' => implode(', ', $missing)]));
        $this->line('  '.__('installer.extensions.install'));
        $this->line('  '.__('installer.extensions.windows', ['lines' => implode(', ', array_map(static fn (string $e): string => "extension={$e}", $missing))]));
        $this->line('  '.__('installer.extensions.linux', ['packages' => implode(' ', array_map(static fn (string $e): string => "php8.4-{$e}", $missing))]));
        $this->line('  '.__('installer.extensions.mac'));
        $this->line('  '.__('installer.extensions.docker'));
    }

    /**
     * No Windows (sem o container), o Horizon fica de fora: o aviso.
     */
    private function windowsWithoutHorizon(): bool
    {
        return PlatformRequirements::isWindows(ProcessComposer::osFamily()) && getenv('TWS_KIT_IN_DOCKER') !== '1';
    }

    /**
     * Um comando do artisan num processo NOVO (os providers carregados neste
     * processo são os de antes do Composer).
     *
     * @param  list<string>  $arguments
     */
    private function artisan(array $arguments): bool
    {
        $result = Process::path($this->laravel->basePath())
            ->forever()
            ->run([PHP_BINARY, 'artisan', ...$arguments, '--no-interaction'], function (string $type, string $line): void {
                $this->output->write($line);
            });

        return $result->successful();
    }

    /**
     * Um arquivo da raiz do projeto (a pasta do .env — a raiz do aplicativo,
     * salvo quando ele mudou de lugar com useEnvironmentPath()).
     */
    private function projectPath(string $file): string
    {
        return dirname($this->laravel->environmentFilePath()).'/'.$file;
    }

    /**
     * O .env do projeto; criado do .env.example quando falta. Null quando
     * não há nenhum dos dois (com o aviso na tela).
     */
    private function environmentFile(): ?EnvironmentFile
    {
        $env = new EnvironmentFile($this->laravel->environmentFilePath());

        if ($env->exists()) {
            return $env;
        }

        $example = $this->projectPath('.env.example');

        if (! is_file($example)) {
            $this->components->warn(__('installer.failures.env_missing'));

            return null;
        }

        copy($example, $env->path());
        $this->components->info(__('installer.steps.env_created'));

        return $env;
    }

    /**
     * @param  list<string>  $needs
     */
    private function dependencyMessage(string $module, array $needs): string
    {
        return __('installer.errors.missing_dependency', [
            'module' => __("installer.modules.{$module}"),
            'needs' => $this->labels($needs),
        ]);
    }

    /**
     * @param  list<string>  $modules
     */
    private function labels(array $modules): string
    {
        return implode(', ', array_map(static fn (string $module): string => __("installer.modules.{$module}"), $modules));
    }
}

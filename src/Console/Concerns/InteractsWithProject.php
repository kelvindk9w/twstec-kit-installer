<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Console\Concerns;

use Illuminate\Support\Facades\Process;
use Twstec\Kit\Installer\Support\EnvironmentFile;

/**
 * O que os dois comandos do instalador (tws:install e tws:add) fazem no
 * projeto do mesmo jeito: rodar o artisan num processo novo, achar os
 * arquivos da raiz, falar dos módulos pelo nome e preparar o .env.
 */
trait InteractsWithProject
{
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

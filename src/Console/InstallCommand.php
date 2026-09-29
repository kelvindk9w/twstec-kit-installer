<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Console;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Installer\Contracts\Composer;
use Twstec\Kit\Installer\Contracts\Inventory;
use Twstec\Kit\Installer\Support\EnvironmentFile;
use Twstec\Kit\Installer\Support\InstallPlan;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

/**
 * `php artisan tws:install` — a pessoa escolhe os módulos OPCIONAIS do kit
 * (contas, uploads, painel /admin) e se mantém a demonstração, e o instalador
 * aplica.
 *
 * INTERATIVO (terminal, sem opção de escolha): pergunta com Laravel Prompts —
 * os módulos marcados um a um (já vêm marcados os instalados), depois a
 * demonstração — mostra o plano e pede confirmação.
 *
 * NÃO INTERATIVO (`--no-interaction`, sem terminal, ou com qualquer opção de
 * escolha): `--with=accounts,uploads` acrescenta ou mantém, `--without=admin`
 * tira, `--no-demo` tira a demonstração; o resto fica como está. Sem opção
 * nenhuma e sem terminal (ex.: o `composer create-project` num CI), nada muda
 * nos pacotes — só as tarefas de arrumação abaixo.
 *
 * APLICA, nesta ordem: `demo:uninstall` (o banco solta o que a demo instalou —
 * gatilhos e tabelas — ANTES de o pacote sair) e a remoção da demo; o
 * `composer remove` dos módulos não escolhidos; o `composer require` dos
 * escolhidos que faltam (com a mesma restrição de versão do foundation no
 * composer.json); a limpeza dos caches; o .env (criado do .env.example se
 * faltar), a APP_KEY e o pepper dedicado das chaves de API quando faltam; e
 * as migrations. Os comandos do artisan rodam num PROCESSO NOVO: depois do
 * Composer, este processo ainda tem na memória os providers de antes.
 *
 * IDEMPOTENTE: rodar de novo com a mesma escolha não muda pacote nenhum e não
 * regera chave que já existe. RECUSA produção sem `--force`: tirar pacote e
 * mexer no .env não é coisa de servidor.
 *
 * Não apaga arquivo do aplicativo: o starter esconde sozinho as telas, rotas
 * e menus de um módulo ausente (Kit::has, o ponto único de detecção).
 */
final class InstallCommand extends Command
{
    protected $name = 'tws:install';

    /**
     * Linhas do relatório final: rótulo => resultado.
     *
     * @var array<string, string>
     */
    private array $report = [];

    public function __construct()
    {
        parent::__construct();

        $this->setDescription(__('installer.description'));
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('with', null, InputOption::VALUE_REQUIRED, __('installer.options.with')),
            new InputOption('without', null, InputOption::VALUE_REQUIRED, __('installer.options.without')),
            new InputOption('no-demo', null, InputOption::VALUE_NONE, __('installer.options.no_demo')),
            new InputOption('force', null, InputOption::VALUE_NONE, __('installer.options.force')),
            new InputOption('graceful', null, InputOption::VALUE_NONE, __('installer.options.graceful')),
        ];
    }

    public function handle(Composer $composer, Inventory $inventory): int
    {
        if ($this->laravel->isProduction() && ! $this->option('force')) {
            $this->components->error(__('installer.production_refused'));

            return self::FAILURE;
        }

        $this->components->info(__('installer.intro'));

        $plan = $this->choose($inventory->optionalModules(), $inventory->demoInstalled());

        if ($plan === null) {
            return self::FAILURE;
        }

        $this->showPlan($plan);

        if ($plan->changesPackages() && $this->asks() && ! confirm(__('installer.confirm_apply'), true)) {
            $this->components->warn(__('installer.aborted'));

            return self::FAILURE;
        }

        if (! $this->applyPackages($plan, $composer)) {
            return self::FAILURE;
        }

        $this->prepareEnvironment($plan);

        $migrated = $this->migrate();

        $this->summary($plan);

        return $migrated ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A escolha: pelas opções, pelas perguntas ou "como está".
     *
     * @param  list<string>  $installed
     */
    private function choose(array $installed, bool $demoInstalled): ?InstallPlan
    {
        if ($this->asks()) {
            return $this->askModules($installed, $demoInstalled);
        }

        $with = $this->modulesFrom('with');
        $without = $this->modulesFrom('without');

        if ($with === null || $without === null) {
            return null;
        }

        foreach (array_intersect($with, $without) as $module) {
            $this->components->error(__('installer.errors.with_and_without', ['module' => $module]));

            return null;
        }

        $target = array_values(array_diff(array_unique([...$installed, ...$with]), $without));
        $plan = new InstallPlan($installed, $target, $demoInstalled, $demoInstalled && ! $this->option('no-demo'));

        foreach ($plan->missingDependencies() as $module => $needs) {
            $this->components->error($this->dependencyMessage($module, $needs));

            return null;
        }

        if ($plan->demoMissing() !== []) {
            $this->components->error(__('installer.errors.demo_requires', ['modules' => $this->labels($plan->demoMissing())]));

            return null;
        }

        return $plan;
    }

    /**
     * As perguntas (Laravel Prompts).
     *
     * @param  list<string>  $installed
     */
    private function askModules(array $installed, bool $demoInstalled): ?InstallPlan
    {
        $options = [];

        foreach (Kit::OPTIONAL as $module) {
            $options[$module] = __("installer.modules.{$module}");
        }

        /** @var list<string> $target */
        $target = multiselect(
            label: __('installer.select_label'),
            options: $options,
            default: $installed,
            hint: __('installer.select_hint'),
            validate: function (array $values): ?string {
                foreach (Kit::missingDependencies(array_values($values)) as $module => $needs) {
                    return $this->dependencyMessage($module, $needs);
                }

                return null;
            },
        );

        $target = array_values(array_map('strval', $target));
        $keepDemo = false;

        if ($demoInstalled) {
            $missing = array_values(array_diff(InstallPlan::DEMO_REQUIRES, $target));

            if ($missing !== []) {
                if (! confirm(__('installer.demo_must_go', ['modules' => $this->labels($missing)]), true)) {
                    $this->components->warn(__('installer.aborted'));

                    return null;
                }
            } else {
                $keepDemo = confirm(__('installer.keep_demo'), true);
            }
        }

        return new InstallPlan($installed, $target, $demoInstalled, $keepDemo);
    }

    /**
     * Pergunta (terminal interativo e nenhuma opção de escolha)?
     */
    private function asks(): bool
    {
        return $this->input->isInteractive()
            && $this->option('with') === null
            && $this->option('without') === null
            && ! $this->option('no-demo');
    }

    /**
     * Módulos de `--with`/`--without`; null (com o erro na tela) quando há
     * nome inválido.
     *
     * @return list<string>|null
     */
    private function modulesFrom(string $option): ?array
    {
        $raw = (string) ($this->option($option) ?? '');
        $modules = array_values(array_unique(array_filter(array_map('trim', explode(',', strtolower($raw))))));

        foreach ($modules as $module) {
            if (in_array($module, Kit::REQUIRED, true)) {
                if ($option === 'without') {
                    $this->components->error(__('installer.errors.required_module', ['module' => $module]));

                    return null;
                }

                continue;
            }

            if (! in_array($module, Kit::OPTIONAL, true)) {
                $this->components->error(__('installer.errors.unknown_module', ['module' => $module, 'modules' => implode(', ', Kit::OPTIONAL)]));

                return null;
            }
        }

        return array_values(array_intersect($modules, Kit::OPTIONAL));
    }

    private function showPlan(InstallPlan $plan): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>'.__('installer.plan.module').'</>', '<fg=gray>'.__('installer.plan.now').' → '.__('installer.plan.after').'</>');

        foreach (Kit::REQUIRED as $module) {
            $this->components->twoColumnDetail(__("installer.modules.{$module}"), __('installer.plan.always'));
        }

        foreach (Kit::OPTIONAL as $module) {
            $this->components->twoColumnDetail(__("installer.modules.{$module}"), $this->transition(
                in_array($module, $plan->installed, true),
                in_array($module, $plan->target, true),
            ));
        }

        if ($plan->demoInstalled) {
            $this->components->twoColumnDetail(__('installer.modules.demo'), $this->transition(true, $plan->keepDemo));
        }

        $this->newLine();

        if (! $plan->changesPackages()) {
            $this->components->info(__('installer.plan.nothing_to_change'));
        }
    }

    private function transition(bool $now, bool $after): string
    {
        $state = static fn (bool $installed): string => $installed ? __('installer.plan.installed') : __('installer.plan.absent');

        if ($now === $after) {
            return $state($now);
        }

        return $state($now).' → <options=bold>'.$state($after).'</>';
    }

    /**
     * O Composer (e o demo:uninstall antes da demo sair). Falha = para tudo.
     */
    private function applyPackages(InstallPlan $plan, Composer $composer): bool
    {
        $output = function (string $type, string $line): void {
            $this->output->write($line);
        };

        if ($plan->removesDemo()) {
            $uninstalled = $this->artisan(['demo:uninstall', '--drop-tables', '--force']);

            if (! $uninstalled && ! $this->option('graceful')) {
                $this->components->error(__('installer.failures.demo_uninstall'));

                return false;
            }

            if (! $uninstalled) {
                $this->components->warn(__('installer.failures.demo_uninstall_graceful'));
            }

            $this->components->info(__('installer.steps.demo_remove'));

            if (! $composer->remove([InstallPlan::DEMO_PACKAGE], true, $output)) {
                $this->components->error(__('installer.failures.composer'));

                return false;
            }
        }

        if ($plan->toRemove() !== []) {
            $packages = array_map(Kit::package(...), $plan->toRemove());
            $this->components->info(__('installer.steps.composer_remove', ['packages' => implode(', ', $packages)]));

            if (! $composer->remove($packages, false, $output)) {
                $this->components->error(__('installer.failures.composer'));

                return false;
            }
        }

        if ($plan->toAdd() !== []) {
            $constraint = $this->kitConstraint();
            $packages = array_map(static fn (string $module): string => Kit::package($module).':'.$constraint, $plan->toAdd());
            $this->components->info(__('installer.steps.composer_require', ['packages' => implode(', ', $packages)]));

            if (! $composer->require($packages, false, $output)) {
                $this->components->error(__('installer.failures.composer'));

                return false;
            }
        }

        if ($plan->changesPackages()) {
            $this->components->task(__('installer.steps.clear_caches'), fn (): bool => $this->artisan(['optimize:clear']));
        }

        return true;
    }

    /**
     * .env, APP_KEY e o pepper dedicado das chaves de API (com contas).
     */
    private function prepareEnvironment(InstallPlan $plan): void
    {
        $env = new EnvironmentFile($this->laravel->environmentFilePath());

        if (! $env->exists()) {
            $example = $this->projectPath('.env.example');

            if (! is_file($example)) {
                $this->components->warn(__('installer.failures.env_missing'));

                return;
            }

            copy($example, $env->path());
            $this->components->info(__('installer.steps.env_created'));
        }

        $appKeyExisted = $env->filled('APP_KEY');

        if (! $appKeyExisted) {
            $env->set('APP_KEY', 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC'))));
        }

        $this->report[__('installer.steps.app_key')] = __($appKeyExisted ? 'installer.steps.app_key_kept' : 'installer.steps.app_key_generated');

        if (! in_array('accounts', $plan->target, true)) {
            return;
        }

        if ($env->filled('API_KEYS_HASH_PEPPER')) {
            $this->report[__('installer.steps.pepper')] = __('installer.steps.pepper_kept');

            return;
        }

        $env->set('API_KEYS_HASH_PEPPER', Str::random(64));

        // Até aqui o hash das chaves usava a APP_KEY (o fallback). Com a
        // APP_KEY que já existia, chaves podem ter sido emitidas: ela vai para
        // os peppers anteriores, e essas chaves continuam autenticando (o
        // hash é regravado com o pepper novo no primeiro uso).
        if ($appKeyExisted) {
            $anteriores = array_values(array_filter(array_map('trim', explode(',', (string) $env->get('API_KEYS_PREVIOUS_HASH_PEPPERS')))));
            $appKey = (string) $env->get('APP_KEY');

            if (! in_array($appKey, $anteriores, true)) {
                $env->set('API_KEYS_PREVIOUS_HASH_PEPPERS', implode(',', [...$anteriores, $appKey]));
            }

            $this->report[__('installer.steps.pepper')] = __('installer.steps.pepper_generated_previous');

            return;
        }

        $this->report[__('installer.steps.pepper')] = __('installer.steps.pepper_generated');
    }

    private function migrate(): bool
    {
        $graceful = (bool) $this->option('graceful');
        $ok = $this->artisan(['migrate', '--force']);

        if ($ok) {
            $this->report[__('installer.steps.migrate')] = 'ok';

            return true;
        }

        $this->report[__('installer.steps.migrate')] = __($graceful ? 'installer.failures.migrate_graceful' : 'installer.failures.migrate');
        $graceful
            ? $this->components->warn(__('installer.failures.migrate_graceful'))
            : $this->components->error(__('installer.failures.migrate'));

        return $graceful;
    }

    private function summary(InstallPlan $plan): void
    {
        $this->newLine();
        $this->components->info(__('installer.summary.heading'));

        foreach (Kit::REQUIRED as $module) {
            $this->components->twoColumnDetail(__("installer.modules.{$module}"), __('installer.summary.kept'));
        }

        foreach (Kit::OPTIONAL as $module) {
            $state = match (true) {
                in_array($module, $plan->toRemove(), true) => 'removed',
                in_array($module, $plan->toAdd(), true) => 'added',
                in_array($module, $plan->target, true) => 'kept',
                default => 'absent',
            };

            $this->components->twoColumnDetail(__("installer.modules.{$module}"), __("installer.summary.{$state}"));
        }

        if ($plan->demoInstalled) {
            $this->components->twoColumnDetail(__('installer.modules.demo'), __($plan->keepDemo ? 'installer.summary.kept' : 'installer.summary.removed'));
        }

        foreach ($this->report as $label => $result) {
            $this->components->twoColumnDetail($label, $result);
        }

        $next = [];

        if ($plan->changesPackages()) {
            $next[] = __('installer.summary.next_build');
        }

        if ($plan->removesDemo()) {
            $next[] = __('installer.summary.next_fresh');
        }

        if (in_array('admin', $plan->toRemove(), true)) {
            $next[] = __('installer.summary.next_horizon');
        }

        $next[] = __('installer.summary.next_serve');

        $this->newLine();
        $this->line('  <options=bold>'.__('installer.summary.next').'</>');

        foreach ($next as $step) {
            $this->line('  - '.$step);
        }

        $this->newLine();
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
     * A restrição de versão dos pacotes do kit neste projeto — a mesma do
     * foundation no composer.json (ex.: `2.x-dev` no monorepo, `^2.0` num
     * projeto criado pelo Packagist).
     */
    private function kitConstraint(): string
    {
        $composer = json_decode((string) @file_get_contents($this->projectPath('composer.json')), true);
        $constraint = is_array($composer) ? ($composer['require'][Kit::package('foundation')] ?? null) : null;

        return is_string($constraint) && $constraint !== '' ? $constraint : '^2.0';
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

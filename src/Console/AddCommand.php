<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Console;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Installer\Console\Concerns\InteractsWithProject;
use Twstec\Kit\Installer\Contracts\Composer;
use Twstec\Kit\Installer\Contracts\Inventory;
use Twstec\Kit\Installer\Support\KitConstraint;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;

/**
 * `php artisan tws:add` — acrescenta pacotes do kit a um aplicativo Laravel
 * que JÁ EXISTE (sem starter): mostra o que está instalado e o que falta, e
 * instala os escolhidos.
 *
 * Num aplicativo que só instalou o instalador (`composer require --dev
 * twstec/kit-installer`), o foundation já veio como dependência dele — e,
 * se não for requisito direto do projeto, passa a ser; `auth`, `accounts`,
 * `uploads` e `admin` estão disponíveis.
 *
 * INTERATIVO (terminal, sem argumento): pergunta com Laravel Prompts, só com
 * os disponíveis. NÃO INTERATIVO: `php artisan tws:add accounts admin`.
 *
 * AS REGRAS: a autenticação (obrigatória no kit) vem junto de qualquer
 * módulo, se faltar; um módulo cujo pré-requisito OPCIONAL não está
 * instalado nem escolhido é RECUSADO, com a explicação e o comando certo
 * (`uploads` sem `accounts`: "adicione os dois juntos"). Tudo antes de mexer
 * em qualquer coisa.
 *
 * APLICA: `composer require` dos escolhidos (e do foundation/auth como
 * requisito DIRETO do projeto, se hoje só vêm por dependência de outro
 * pacote — senão um `--no-dev` os levaria embora), com a restrição de versão
 * do kit no composer.json; `optimize:clear`; a configuração publicada de cada
 * módulo (`vendor:publish --tag=<módulo>-config`, sem sobrescrever); o .env
 * (APP_KEY, e o pepper dedicado das chaves de API com contas); `migrate`. As
 * proteções vêm ligadas pelos próprios pacotes (descoberta automática do
 * Laravel). O que só o aplicativo pode fazer (o model de usuário, o painel do
 * Filament, as colunas próprias) sai no resumo, módulo a módulo.
 *
 * RECUSA produção sem `--force`, como o tws:install.
 */
final class AddCommand extends Command
{
    use InteractsWithProject;

    /**
     * Os que podem ser acrescentados, na ordem de instalação. O foundation
     * não entra: ele é dependência do próprio instalador.
     *
     * @var list<string>
     */
    public const ADDABLE = ['auth', 'accounts', 'uploads', 'admin'];

    /**
     * Os módulos que ligam a conta pessoal aos eventos do model de usuário do
     * aplicativo JÁ NO BOOT (o de contas, e o de uploads, que vem com ele):
     * sem o model com o contrato da autenticação do kit, o aplicativo nem
     * sobe depois do `composer require` (o kit-auth falha alto, de
     * propósito). Recusados antes, com o caminho: a autenticação primeiro, o
     * model ajustado, e então eles.
     *
     * @var list<string>
     */
    public const NEEDS_KIT_USER_MODEL = ['accounts', 'uploads'];

    protected $name = 'tws:add';

    /**
     * @var array<string, string>
     */
    private array $report = [];

    public function __construct()
    {
        parent::__construct();

        $this->setDescription(__('installer.add.description'));
    }

    /**
     * @return list<InputArgument>
     */
    protected function getArguments(): array
    {
        return [
            new InputArgument('modules', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, __('installer.add.arguments.modules')),
        ];
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
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

        $installed = $inventory->installedModules();
        $available = array_values(array_filter(self::ADDABLE, static fn (string $module): bool => ! in_array($module, $installed, true)));

        $this->components->info(__('installer.add.intro'));
        $this->showModules($installed);

        if ($available === []) {
            $this->components->info(__('installer.add.nothing_available'));

            return self::SUCCESS;
        }

        $chosen = $this->choose($available, $installed, $inventory->userModelReady());

        if ($chosen === null) {
            return self::FAILURE;
        }

        if ($chosen === []) {
            $this->components->info(__('installer.add.nothing_chosen'));

            return self::SUCCESS;
        }

        // A autenticação vem junto de qualquer módulo, se faltar.
        $added = array_values(array_filter(self::ADDABLE, fn (string $module): bool => in_array($module, $chosen, true)
            || ($module === 'auth' && ! in_array('auth', $installed, true))));

        $packages = $this->packages($added);
        $this->showPlan($added, $chosen, $packages);

        if ($this->asks() && ! confirm(__('installer.confirm_apply'), true)) {
            $this->components->warn(__('installer.aborted'));

            return self::FAILURE;
        }

        $this->components->info(__('installer.steps.composer_require', ['packages' => implode(', ', $packages)]));

        if (! $composer->require($packages, false, function (string $type, string $line): void {
            $this->output->write($line);
        })) {
            $this->components->error(__('installer.failures.composer'));

            return self::FAILURE;
        }

        $this->components->task(__('installer.steps.clear_caches'), fn (): bool => $this->artisan(['optimize:clear']));

        foreach ($added as $module) {
            $this->artisan(['vendor:publish', "--tag={$module}-config"]);
        }

        $this->prepareEnvironment($added);

        $migrated = $this->migrate();

        $this->summary($added);

        return $migrated ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $installed
     */
    private function showModules(array $installed): void
    {
        $this->newLine();

        foreach (Kit::MODULES as $module => $definition) {
            $this->components->twoColumnDetail(
                __("installer.modules.{$module}"),
                in_array($module, $installed, true) ? __('installer.plan.installed') : '<fg=yellow>'.__('installer.add.available').'</>',
            );
        }

        $this->newLine();
    }

    /**
     * A escolha: pelos argumentos ou pela pergunta. Null (com o motivo na
     * tela) quando é recusada.
     *
     * @param  list<string>  $available
     * @param  list<string>  $installed
     * @return list<string>|null
     */
    private function choose(array $available, array $installed, bool $userModelReady): ?array
    {
        if ($this->asks()) {
            $options = [];

            foreach ($available as $module) {
                $options[$module] = __("installer.modules.{$module}");
            }

            /** @var list<string> $chosen */
            $chosen = multiselect(
                label: __('installer.add.select_label'),
                options: $options,
                hint: __('installer.add.select_hint'),
                validate: fn (array $values): ?string => $this->refusal(array_values(array_map('strval', $values)), $installed, $userModelReady),
            );

            return array_values(array_map('strval', $chosen));
        }

        /** @var list<string> $arguments */
        $arguments = (array) $this->argument('modules');
        $requested = array_values(array_unique(array_filter(array_map(static fn (string $m): string => strtolower(trim($m)), $arguments))));

        if ($requested === []) {
            $this->components->error(__('installer.add.errors.none_given', [
                'modules' => implode(' ', $available),
            ]));

            return null;
        }

        $chosen = [];

        foreach ($requested as $module) {
            if (! array_key_exists($module, Kit::MODULES)) {
                $this->components->error(__('installer.errors.unknown_module', ['module' => $module, 'modules' => implode(', ', self::ADDABLE)]));

                return null;
            }

            if (in_array($module, $installed, true)) {
                $this->components->warn(__('installer.add.already_installed', ['module' => __("installer.modules.{$module}")]));

                continue;
            }

            $chosen[] = $module;
        }

        $refusal = $this->refusal($chosen, $installed, $userModelReady);

        if ($refusal !== null) {
            $this->components->error($refusal);

            return null;
        }

        return $chosen;
    }

    /**
     * A recusa (com o caminho certo), ou null quando a escolha vale:
     *
     * - um módulo escolhido cujo pré-requisito opcional não está instalado
     *   nem na escolha (uploads sem contas);
     * - contas (ou uploads) sem o model de usuário do aplicativo pronto para
     *   a autenticação do kit.
     *
     * @param  list<string>  $chosen
     * @param  list<string>  $installed
     */
    private function refusal(array $chosen, array $installed, bool $userModelReady): ?string
    {
        $present = array_values(array_unique([...$installed, ...$chosen]));

        foreach (Kit::missingDependencies(array_values(array_intersect(Kit::OPTIONAL, $present))) as $module => $needs) {
            if (! in_array($module, $chosen, true)) {
                continue;
            }

            return $this->dependencyMessage($module, $needs).' '.__('installer.add.errors.add_together', [
                'command' => 'php artisan tws:add '.implode(' ', array_values(array_intersect(self::ADDABLE, [...$needs, ...$chosen]))),
            ]);
        }

        $needModel = array_values(array_intersect(self::NEEDS_KIT_USER_MODEL, $chosen));

        if ($needModel !== [] && ! $userModelReady) {
            return __('installer.add.errors.user_model', [
                'modules' => $this->labels($needModel),
                'model' => (string) config('auth.providers.users.model'),
                'first' => in_array('auth', $installed, true) ? '' : 'php artisan tws:add auth; ',
                'command' => 'php artisan tws:add '.implode(' ', $needModel),
            ]);
        }

        return null;
    }

    /**
     * Os pacotes do `composer require`: os módulos acrescentados e, para o
     * projeto depender deles DIRETAMENTE, o foundation e a autenticação que
     * hoje só vêm por dependência de outro pacote.
     *
     * @param  list<string>  $added
     * @return list<string>
     */
    private function packages(array $added): array
    {
        $direct = KitConstraint::directRequirements($this->projectPath('composer.json'));
        $constraint = KitConstraint::for($this->projectPath('composer.json'));
        $packages = [];

        foreach (array_keys(Kit::MODULES) as $module) {
            $package = Kit::package($module);

            if (in_array($module, $added, true) || (in_array($module, Kit::REQUIRED, true) && ! in_array($package, $direct, true))) {
                $packages[] = $package.':'.$constraint;
            }
        }

        return $packages;
    }

    /**
     * @param  list<string>  $added
     * @param  list<string>  $chosen
     * @param  list<string>  $packages
     */
    private function showPlan(array $added, array $chosen, array $packages): void
    {
        $this->components->twoColumnDetail('<fg=gray>'.__('installer.plan.module').'</>', '<fg=gray>'.__('installer.plan.after').'</>');

        foreach ($added as $module) {
            $this->components->twoColumnDetail(
                __("installer.modules.{$module}"),
                in_array($module, $chosen, true) ? __('installer.add.will_install') : __('installer.add.comes_along'),
            );
        }

        $this->components->twoColumnDetail('Composer', implode(' ', $packages));
        $this->newLine();
    }

    /**
     * APP_KEY se faltar; com contas acrescentadas agora, o pepper dedicado
     * das chaves de API (nenhuma chave foi emitida antes, então nada vai para
     * os peppers anteriores).
     *
     * @param  list<string>  $added
     */
    private function prepareEnvironment(array $added): void
    {
        $env = $this->environmentFile();

        if ($env === null) {
            return;
        }

        if (! $env->filled('APP_KEY')) {
            $env->set('APP_KEY', 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC'))));
            $this->report[__('installer.steps.app_key')] = __('installer.steps.app_key_generated');
        }

        if (in_array('accounts', $added, true) && ! $env->filled('API_KEYS_HASH_PEPPER')) {
            $env->set('API_KEYS_HASH_PEPPER', Str::random(64));
            $this->report[__('installer.steps.pepper')] = __('installer.steps.pepper_generated');
        }
    }

    private function migrate(): bool
    {
        $graceful = (bool) $this->option('graceful');

        if ($this->artisan(['migrate', '--force'])) {
            $this->report[__('installer.steps.migrate')] = 'ok';

            return true;
        }

        $this->report[__('installer.steps.migrate')] = __($graceful ? 'installer.failures.migrate_graceful' : 'installer.failures.migrate');
        $graceful
            ? $this->components->warn(__('installer.failures.migrate_graceful'))
            : $this->components->error(__('installer.failures.migrate'));

        return $graceful;
    }

    /**
     * @param  list<string>  $added
     */
    private function summary(array $added): void
    {
        $this->newLine();
        $this->components->info(__('installer.summary.heading'));

        foreach ($added as $module) {
            $this->components->twoColumnDetail(__("installer.modules.{$module}"), __('installer.summary.added'));
        }

        foreach ($this->report as $label => $result) {
            $this->components->twoColumnDetail($label, $result);
        }

        $this->newLine();
        $this->line('  <options=bold>'.__('installer.add.next').'</>');

        foreach ($added as $module) {
            $this->line('  - '.__("installer.add.next_steps.{$module}"));
        }

        if (in_array('admin', $added, true)) {
            $this->line('  - '.__('installer.summary.next_build'));
        }

        $this->newLine();
    }

    /**
     * Pergunta (terminal interativo e nenhum módulo nos argumentos)?
     */
    private function asks(): bool
    {
        return $this->input->isInteractive() && (array) $this->argument('modules') === [];
    }
}

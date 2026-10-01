<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Console;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;
use Twstec\Kit\Foundation\Kit;
use Twstec\Kit\Installer\Console\Concerns\InteractsWithProject;
use Twstec\Kit\Installer\Contracts\Composer;
use Twstec\Kit\Installer\Contracts\Inventory;
use Twstec\Kit\Installer\Dev\DevEnvironment;
use Twstec\Kit\Installer\Dev\Host;
use Twstec\Kit\Installer\Support\CreatedProject;
use Twstec\Kit\Installer\Support\EnvironmentFile;
use Twstec\Kit\Installer\Support\InstallPlan;
use Twstec\Kit\Installer\Support\KitConstraint;
use Twstec\Kit\Installer\Support\UploadsEncryptionKey;

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
 * PELO AMBIENTE: TWS_KIT_WITH e TWS_KIT_WITHOUT valem como `--with` e
 * `--without` quando a opção não foi dada (e também desligam as perguntas).
 * É por onde a escolha chega ao `post-create-project-cmd` do starter — o
 * Composer não repassa opções ao script: `TWS_KIT_WITHOUT=admin composer
 * create-project twstec/starter-livewire app`, e o comando único
 * (`composer create-project twstec/kit`), que passa assim o que o menu dele
 * perguntou, sem perguntar de novo.
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
 * DOCKER DE DESENVOLVIMENTO: num projeto criado (com o compose.yaml de
 * desenvolvimento na raiz — o do starter publicado), grava no .env, UMA vez
 * (enquanto não houver COMPOSE_PROJECT_NAME), o nome do projeto
 * (COMPOSE_PROJECT_NAME, banco, cookie de sessão, APP_URL
 * http://<nome>.localhost:<porta>), as portas do número escolhido (site 808N,
 * e-mails 802N, Vite 803N, banco 804N), o uid/gid de quem é dono dos arquivos
 * e as senhas do banco e do Redis, geradas. O nome e o número vêm de
 * TWS_KIT_NAME e TWS_KIT_SLOT (o comando único passa o que o menu perguntou;
 * TWS_KIT_EXPOSE_DB publica o banco) — conferidos: nome de projeto Docker que
 * já existe e número com porta ocupada são recusados antes de qualquer
 * mudança. Sem elas, o nome da pasta (sem colidir) e o primeiro número com as
 * quatro portas livres. O nome e o número ficam reservados no Docker (o
 * volume do banco do projeto, com o número no rótulo) até o primeiro
 * `docker compose up -d`: o próximo projeto criado não os repete. No monorepo
 * (sem compose.yaml na raiz do starter), nada disso acontece.
 *
 * O PROJETO CRIADO passa a ser do projeto, na mesma hora (Support\CreatedProject):
 * o .env.example, o banco da suíte PostgreSQL (`<banco>_test`, o mesmo do
 * db-init do compose.yaml), o banco padrão do docker-compose.prod.yml, o
 * composer.json (nome `<vendor>/<nome>` — TWS_KIT_VENDOR, padrão `app` —,
 * licença — TWS_KIT_LICENSE, padrão `proprietary` —, sem a identidade do
 * starter), a licença MIT do kit em NOTICE-KIT-MIT.txt e o CI base em
 * .github/workflows/ci.yml. Vendor ou licença inválidos param ANTES de
 * qualquer mudança, como o nome e o número.
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
    use InteractsWithProject;

    /**
     * Opção => a variável de ambiente que vale por ela quando a opção falta.
     *
     * @var array<string, string>
     */
    public const ENVIRONMENT = [
        'with' => 'TWS_KIT_WITH',
        'without' => 'TWS_KIT_WITHOUT',
    ];

    protected $name = 'tws:install';

    /**
     * A identidade do projeto criado (composer.json): o pacote e a licença.
     *
     * @var array{package: string, license: string}|null
     */
    private ?array $identity = null;

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

    public function handle(Composer $composer, Inventory $inventory, Host $host): int
    {
        if ($this->laravel->isProduction() && ! $this->option('force')) {
            $this->components->error(__('installer.production_refused'));

            return self::FAILURE;
        }

        $this->components->info(__('installer.intro'));

        // O Docker de desenvolvimento é conferido ANTES de mexer em qualquer
        // coisa (nome em uso e porta ocupada param aqui).
        $dev = $this->devEnvironment($host);

        if ($dev === false) {
            return self::FAILURE;
        }

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

        if (is_array($dev)) {
            $this->writeDevEnvironment($dev);
            // Até o primeiro `docker compose up -d`, nada mostra este projeto
            // a quem criar o próximo: o nome e o número ficam reservados.
            $host->reserve($dev['COMPOSE_PROJECT_NAME'], (int) $dev['DEV_SLOT']);
        }

        // Com o Docker de desenvolvimento configurado agora, o banco do .env
        // é o do projeto no Docker, que ainda não subiu: as migrations rodam
        // no primeiro `docker compose up -d` (o serviço init), sem o erro de
        // conexão no meio da criação.
        if (is_array($dev)) {
            $this->report[__('installer.steps.migrate')] = __('installer.dev.migrate_on_up');
            $migrated = true;
        } else {
            $migrated = $this->migrate();
        }

        $this->summary($plan, is_array($dev) ? $dev : null);

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
            && $this->choiceFor('with') === null
            && $this->choiceFor('without') === null
            && ! $this->option('no-demo');
    }

    /**
     * O valor de `--with`/`--without` — ou, sem a opção, o da variável de
     * ambiente dela (mesmo vazia: "nada a acrescentar"). Null quando não há
     * nenhum dos dois.
     */
    private function choiceFor(string $option): ?string
    {
        $value = $this->option($option);

        if ($value !== null) {
            return (string) $value;
        }

        $environment = getenv(self::ENVIRONMENT[$option]);

        return is_string($environment) ? $environment : null;
    }

    /**
     * Módulos de `--with`/`--without`; null (com o erro na tela) quando há
     * nome inválido.
     *
     * @return list<string>|null
     */
    private function modulesFrom(string $option): ?array
    {
        $raw = (string) ($this->choiceFor($option) ?? '');
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
        $output = $this->composerOutput();

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
                $this->composerFailed();

                return false;
            }
        }

        if ($plan->toRemove() !== []) {
            $packages = array_map(Kit::package(...), $plan->toRemove());
            $this->components->info(__('installer.steps.composer_remove', ['packages' => implode(', ', $packages)]));

            if (! $composer->remove($packages, false, $output)) {
                $this->composerFailed();

                return false;
            }
        }

        if ($plan->toAdd() !== []) {
            $constraint = KitConstraint::for($this->projectPath('composer.json'));
            $packages = array_map(static fn (string $module): string => Kit::package($module).':'.$constraint, $plan->toAdd());
            $this->components->info(__('installer.steps.composer_require', ['packages' => implode(', ', $packages)]));

            if (! $composer->require($packages, false, $output)) {
                $this->composerFailed();

                return false;
            }
        }

        if ($plan->changesPackages()) {
            $this->components->task(__('installer.steps.clear_caches'), fn (): bool => $this->artisan(['optimize:clear']));
        }

        return true;
    }

    /**
     * .env, APP_KEY, o pepper dedicado das chaves de API (com contas) e a
     * chave dos uploads confidenciais (com uploads).
     */
    private function prepareEnvironment(InstallPlan $plan): void
    {
        $env = $this->environmentFile();

        if ($env === null) {
            return;
        }

        $appKeyExisted = $env->filled('APP_KEY');

        if (! $appKeyExisted) {
            $env->set('APP_KEY', 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher', 'AES-256-CBC'))));
        }

        $this->report[__('installer.steps.app_key')] = __($appKeyExisted ? 'installer.steps.app_key_kept' : 'installer.steps.app_key_generated');

        // Com uploads: a chave PRÓPRIA dos uploads confidenciais (nunca a
        // APP_KEY). Já definida, fica — os arquivos cifrados dependem dela.
        if (in_array('uploads', $plan->target, true)) {
            $this->report[__('installer.steps.uploads_key')] = __(UploadsEncryptionKey::ensure($env) ? 'installer.steps.uploads_key_generated' : 'installer.steps.uploads_key_kept');
        }

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

    /**
     * As variáveis do Docker de desenvolvimento para o .env; null quando não
     * se aplica (sem o compose.yaml de desenvolvimento, ou já configurado);
     * false (com o erro na tela) quando a escolha não vale.
     *
     * @return array<string, string>|false|null
     */
    private function devEnvironment(Host $host): array|false|null
    {
        if (! is_file($this->projectPath('compose.yaml'))) {
            return null;
        }

        $current = new EnvironmentFile($this->laravel->environmentFilePath());

        if ($current->filled('COMPOSE_PROJECT_NAME')) {
            $this->report[__('installer.dev.step')] = __('installer.dev.kept', ['name' => (string) $current->get('COMPOSE_PROJECT_NAME')]);

            return null;
        }

        $projects = $host->projects();
        $name = trim((string) getenv('TWS_KIT_NAME'));

        if ($name === '') {
            $folder = trim((string) getenv('TWS_KIT_FOLDER'));
            $name = DevEnvironment::suggestName($folder !== '' ? $folder : basename(dirname($this->laravel->environmentFilePath())), $projects);
        } elseif (DevEnvironment::nameProblem($name) !== null) {
            $this->components->error(__('installer.dev.name_invalid', ['name' => $name, 'suggestion' => DevEnvironment::suggestName(DevEnvironment::slug($name), $projects)]));

            return false;
        } elseif (DevEnvironment::taken($name, $projects)) {
            $this->components->error(__('installer.dev.name_taken', ['name' => $name, 'suggestion' => DevEnvironment::suggestName($name, $projects)]));

            return false;
        }

        $raw = trim((string) getenv('TWS_KIT_SLOT'));

        if ($raw === '') {
            $slot = DevEnvironment::firstFree($host);

            if ($slot === null) {
                $this->components->error(__('installer.dev.no_free_slot'));

                return false;
            }
        } else {
            $slot = DevEnvironment::parseSlot($raw);

            if ($slot === null || $slot > DevEnvironment::MAX_SLOT) {
                $this->components->error(__('installer.dev.slot_invalid', ['slot' => $raw]));

                return false;
            }

            $occupants = DevEnvironment::occupants($host, $slot);

            if ($occupants !== []) {
                $described = [];

                foreach ($occupants as $port => $owner) {
                    $described[] = $port.' ('.($owner === '' ? __('installer.dev.other_program') : $owner).')';
                }

                $free = DevEnvironment::firstFree($host);
                $this->components->error(__('installer.dev.slot_busy', [
                    'slot' => (string) $slot,
                    'occupants' => implode(', ', $described),
                    'suggestion' => $free === null ? '-' : (string) $free,
                ]));

                return false;
            }
        }

        $expose = strtolower(trim((string) getenv('TWS_KIT_EXPOSE_DB')));

        if (! in_array($expose, ['', '0', '1', 'true', 'false', 'yes', 'no', 'sim', 'nao', 'não'], true)) {
            $this->components->error(__('installer.dev.expose_invalid', ['value' => $expose]));

            return false;
        }

        $vendor = strtolower(trim((string) getenv('TWS_KIT_VENDOR')));
        $vendor = $vendor === '' ? 'app' : $vendor;

        if (! CreatedProject::validVendor($vendor)) {
            $this->components->error(__('installer.dev.vendor_invalid', ['vendor' => $vendor]));

            return false;
        }

        $license = trim((string) getenv('TWS_KIT_LICENSE'));
        $license = $license === '' ? 'proprietary' : $license;

        if (! CreatedProject::validLicense($license)) {
            $this->components->error(__('installer.dev.license_invalid', ['license' => $license]));

            return false;
        }

        $this->identity = ['package' => "{$vendor}/{$name}", 'license' => $license];

        [$uid, $gid] = $this->owner();

        return DevEnvironment::environment(
            $name,
            $slot,
            in_array($expose, ['1', 'true', 'yes', 'sim'], true),
            $uid,
            $gid,
            filter_var(getenv('TWS_KIT_VITE_POLLING'), FILTER_VALIDATE_BOOLEAN),
            static fn (): string => bin2hex(random_bytes(16)),
        );
    }

    /**
     * O dono dos arquivos do projeto na máquina (os containers de
     * desenvolvimento gravam como ele): o que o instalador do Docker informou
     * (TWS_KIT_UID/TWS_KIT_GID) ou o deste processo.
     *
     * @return array{0: int, 1: int}
     */
    private function owner(): array
    {
        $uid = (int) getenv('TWS_KIT_UID');
        $gid = (int) getenv('TWS_KIT_GID');

        if ($uid <= 0 && function_exists('posix_getuid')) {
            $uid = posix_getuid();
            $gid = posix_getgid();
        }

        return [$uid, $gid];
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeDevEnvironment(array $values): void
    {
        $env = $this->environmentFile();

        if ($env === null) {
            return;
        }

        foreach ($values as $key => $value) {
            $env->set($key, $value);
        }

        $this->report[__('installer.dev.step')] = __('installer.dev.configured', [
            'name' => $values['COMPOSE_PROJECT_NAME'],
            'slot' => $values['DEV_SLOT'],
            'url' => $values['APP_URL'],
        ]);

        $this->personalize($values);
    }

    /**
     * O projeto criado com os valores DELE (ver Support\CreatedProject).
     *
     * @param  array<string, string>  $values  o Docker de desenvolvimento gravado no .env
     */
    private function personalize(array $values): void
    {
        $project = new CreatedProject(dirname($this->laravel->environmentFilePath()));
        $database = $values['DB_DATABASE'];

        $project->environmentExample(array_intersect_key($values, array_flip(['APP_URL', 'PLATFORM_OFFICIAL_URL', 'DB_DATABASE', 'SESSION_COOKIE'])));
        $project->testDatabase($database);
        $project->productionDatabase($database);

        if ($this->identity !== null) {
            $project->composerIdentity($this->identity['package'], $this->identity['license']);
            $notice = $project->kitLicenseNotice(__('installer.dev.notice_header', ['license' => $this->identity['license']]));
            $this->report[__('installer.dev.identity_step')] = __($notice ? 'installer.dev.identity_notice' : 'installer.dev.identity', [
                'package' => $this->identity['package'],
                'license' => $this->identity['license'],
                'notice' => CreatedProject::NOTICE_FILE,
            ]);
        }

        $this->report[__('installer.dev.tests_step')] = __('installer.dev.tests', ['database' => $database.'_test']);

        if ($project->continuousIntegration()) {
            $this->report[__('installer.dev.ci_step')] = __('installer.dev.ci', ['workflow' => CreatedProject::CI_WORKFLOW]);
        }
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

    /**
     * @param  array<string, string>|null  $dev  o Docker de desenvolvimento gravado agora
     */
    private function summary(InstallPlan $plan, ?array $dev = null): void
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

        // Windows: o Horizon não roda nativo (pcntl e posix ignoradas). O
        // comando único (twstec/kit) avisa no resumo dele.
        if ($this->windowsWithoutHorizon() && getenv('TWS_KIT_FROM_KIT') !== '1') {
            $next[] = __('installer.extensions.windows_horizon');
        }

        $next[] = $dev === null ? __('installer.summary.next_serve') : __('installer.dev.next', [
            'url' => $dev['APP_URL'],
            'mail' => DevEnvironment::url($dev['COMPOSE_PROJECT_NAME'], (int) $dev['DEV_MAIL_PORT']),
        ]);

        $this->newLine();
        $this->line('  <options=bold>'.__('installer.summary.next').'</>');

        foreach ($next as $step) {
            $this->line('  - '.$step);
        }

        $this->newLine();
    }
}

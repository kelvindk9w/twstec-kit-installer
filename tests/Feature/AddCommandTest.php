<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

// =============================================================================
// php artisan tws:add — acrescentar pacotes do kit a um aplicativo Laravel
// que JÁ EXISTE. O Composer e o inventário são de mentira; os comandos do
// artisan (em processo novo) passam pelo Process::fake.
// =============================================================================

beforeEach(function (): void {
    $this->runs = new ArrayObject;
    $runs = $this->runs;

    Process::fake(function (PendingProcess $process) use ($runs) {
        $runs->append(implode(' ', array_values(array_filter(array_slice($process->command, 2), fn (string $a): bool => $a !== '--no-interaction'))));

        return Process::result();
    });

    // Um aplicativo Laravel que só instalou o instalador (em require-dev): o
    // foundation veio como dependência dele; nada mais do kit.
    $this->composerJson([
        'name' => 'laravel/laravel',
        'require' => ['php' => '^8.4', 'laravel/framework' => '^13.0'],
        'require-dev' => ['twstec/kit-installer' => '^2.0@beta'],
    ]);
    $this->project([], required: ['foundation']);
    file_put_contents($this->project.'/.env', "APP_KEY=base64:QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU=\n");
});

it('mostra o que está instalado e o que falta, e acrescenta contas — com a autenticação junto e o foundation como requisito direto', function (): void {
    $this->artisan('tws:add', ['modules' => ['accounts']])
        ->expectsOutputToContain('available')
        ->expectsOutputToContain('comes along')
        ->assertSuccessful();

    expect($this->composer->calls)->toBe([
        ['require', ['twstec/kit-foundation:^2.0@beta', 'twstec/kit-auth:^2.0@beta', 'twstec/kit-accounts:^2.0@beta'], false],
    ])
        ->and($this->runs->getArrayCopy())->toBe([
            'optimize:clear',
            'vendor:publish --tag=auth-config',
            'vendor:publish --tag=accounts-config',
            'migrate --force',
        ])
        // Contas novas: o pepper dedicado; nenhuma chave foi emitida antes,
        // então a APP_KEY não vai para os peppers anteriores.
        ->and($this->envFile())->toMatch('/^API_KEYS_HASH_PEPPER=[A-Za-z0-9]{64}$/m')
        ->not->toContain('API_KEYS_PREVIOUS_HASH_PEPPERS');
});

it('RECUSA uploads sem contas, com a explicação e o comando certo — sem mexer em nada', function (): void {
    $this->artisan('tws:add', ['modules' => ['uploads']])
        ->expectsOutputToContain('needs Accounts, API keys and projects (twstec/kit-accounts). Add both together: php artisan tws:add accounts uploads')
        ->assertFailed();

    expect($this->composer->calls)->toBe([]);
    Process::assertNothingRan();
});

it('RECUSA webhooks sem contas, com a explicação e o comando certo — sem mexer em nada', function (): void {
    $this->artisan('tws:add', ['modules' => ['webhooks']])
        ->expectsOutputToContain('needs Accounts, API keys and projects (twstec/kit-accounts). Add both together: php artisan tws:add accounts webhooks')
        ->assertFailed();

    expect($this->composer->calls)->toBe([]);
    Process::assertNothingRan();
});

it('webhooks num aplicativo que já tem contas: vale, com a configuração publicada e as migrations', function (): void {
    $this->project(['accounts']);

    $this->artisan('tws:add', ['modules' => ['webhooks']])->assertSuccessful();

    // O foundation e a autenticação entram como requisito DIRETO (hoje só
    // vêm por dependência), como em todo tws:add deste aplicativo.
    expect($this->composer->calls)->toBe([['require', ['twstec/kit-foundation:^2.0@beta', 'twstec/kit-auth:^2.0@beta', 'twstec/kit-webhooks:^2.0@beta'], false]])
        ->and($this->runs->getArrayCopy())->toBe([
            'optimize:clear',
            'vendor:publish --tag=webhooks-config',
            'migrate --force',
        ]);
});

it('contas e uploads juntos valem', function (): void {
    $this->artisan('tws:add', ['modules' => ['uploads', 'accounts']])->assertSuccessful();

    expect($this->composer->calls[0][1])->toBe([
        'twstec/kit-foundation:^2.0@beta',
        'twstec/kit-auth:^2.0@beta',
        'twstec/kit-accounts:^2.0@beta',
        'twstec/kit-uploads:^2.0@beta',
    ]);
});

it('uploads num aplicativo que já tem contas: vale — e gera a chave dos uploads confidenciais (sem mexer numa que já exista)', function (): void {
    $this->project(['accounts']);
    $this->composerJson(['require' => ['twstec/kit-foundation' => '^2.0@beta', 'twstec/kit-auth' => '^2.0@beta', 'twstec/kit-accounts' => '^2.0@beta']]);

    $this->artisan('tws:add', ['modules' => ['uploads']])->expectsOutputToContain('UPLOADS_ENCRYPTION_KEY')->assertSuccessful();

    expect($this->composer->calls)->toBe([['require', ['twstec/kit-uploads:^2.0@beta'], false]])
        ->and($this->envFile())->toMatch('/^UPLOADS_ENCRYPTION_KEY=base64:[A-Za-z0-9+\/]{43}=$/m');

    // Num .env que já tem a chave, ela fica.
    file_put_contents($this->project.'/.env', "APP_KEY=base64:QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU=\nUPLOADS_ENCRYPTION_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=\n");
    $this->project(['accounts']);
    $this->artisan('tws:add', ['modules' => ['uploads']])->assertSuccessful();

    expect($this->envFile())->toContain('UPLOADS_ENCRYPTION_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=');
});

it('acrescentar o /admin não gera a chave dos confidenciais', function (): void {
    $this->project([]);
    $this->composerJson(['require' => ['twstec/kit-foundation' => '^2.0@beta', 'twstec/kit-auth' => '^2.0@beta']]);

    $this->artisan('tws:add', ['modules' => ['admin']])->assertSuccessful();

    expect($this->envFile())->not->toContain('UPLOADS_ENCRYPTION_KEY');
});

it('acrescenta o /admin — e diz o que o aplicativo faz agora (painel, model, coluna, primeiro admin, build)', function (): void {
    $this->project([]);
    $this->composerJson(['require' => ['twstec/kit-foundation' => '^2.0@beta', 'twstec/kit-auth' => '^2.0@beta']]);

    $this->artisan('tws:add', ['modules' => ['admin']])
        ->expectsOutputToContain('AdminPlugin::make(), the model with FilamentUser and the AccessesAdminPanel trait, the users.is_admin column; then php artisan user:make-admin')
        ->expectsOutputToContain('npm install && npm run build')
        ->assertSuccessful();

    expect($this->composer->calls)->toBe([['require', ['twstec/kit-admin:^2.0@beta'], false]])
        ->and($this->envFile())->not->toContain('API_KEYS_HASH_PEPPER');
});

it('módulo já instalado: avisa e não faz nada', function (): void {
    $this->project(['accounts']);

    $this->artisan('tws:add', ['modules' => ['accounts']])
        ->expectsOutputToContain('is already installed')
        ->assertSuccessful();

    expect($this->composer->calls)->toBe([]);
    Process::assertNothingRan();
});

it('tudo instalado: nada disponível', function (): void {
    $this->project(['accounts', 'uploads', 'admin', 'webhooks']);

    $this->artisan('tws:add')->expectsOutputToContain('Every kit package is already installed.')->assertSuccessful();

    expect($this->composer->calls)->toBe([]);
});

it('sem terminal e sem argumento: diz quais estão disponíveis e como pedir', function (): void {
    $this->artisan('tws:add', ['--no-interaction' => true])
        ->expectsOutputToContain('php artisan tws:add auth accounts uploads admin webhooks')
        ->assertFailed();

    expect($this->composer->calls)->toBe([]);
});

it('recusa módulo desconhecido', function (): void {
    $this->artisan('tws:add', ['modules' => ['billing']])->expectsOutputToContain('Unknown module: billing')->assertFailed();

    expect($this->composer->calls)->toBe([]);
});

it('recusa rodar em produção sem --force', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('tws:add', ['modules' => ['accounts']])->expectsOutputToContain('APP_ENV=production')->assertFailed();

    expect($this->composer->calls)->toBe([]);
    Process::assertNothingRan();
});

it('migrations que falham reprovam — com --graceful, avisam e seguem', function (): void {
    Process::fake(fn (PendingProcess $process) => in_array('migrate', $process->command, true) ? Process::result(exitCode: 1) : Process::result());

    $this->artisan('tws:add', ['modules' => ['admin']])->assertFailed();
    $this->artisan('tws:add', ['modules' => ['admin'], '--graceful' => true])->expectsOutputToContain('php artisan migrate')->assertSuccessful();
});

it('interativo: pergunta só os disponíveis, mostra o plano e pede confirmação', function (): void {
    $this->artisan('tws:add')
        ->expectsChoice('Which modules do you want to add?', ['accounts', 'uploads'], [
            'auth' => 'Authentication (twstec/kit-auth)',
            'accounts' => 'Accounts, API keys and projects (twstec/kit-accounts)',
            'uploads' => 'Secure uploads and profile photo (twstec/kit-uploads)',
            'admin' => '/admin panel with Filament (twstec/kit-admin)',
            'webhooks' => 'Signed outgoing webhooks (twstec/kit-webhooks)',
        ])
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect($this->composer->calls[0][1])->toBe([
        'twstec/kit-foundation:^2.0@beta',
        'twstec/kit-auth:^2.0@beta',
        'twstec/kit-accounts:^2.0@beta',
        'twstec/kit-uploads:^2.0@beta',
    ]);
});

it('interativo: a pergunta NÃO aceita uploads sem contas', function (): void {
    $this->project(['admin']);

    // Num terminal, a pergunta volta até a escolha valer; na suíte, o
    // Laravel encerra a pergunta recusada (PromptValidationException).
    $this->artisan('tws:add')
        ->expectsChoice('Which modules do you want to add?', ['uploads'], [
            'accounts' => 'Accounts, API keys and projects (twstec/kit-accounts)',
            'uploads' => 'Secure uploads and profile photo (twstec/kit-uploads)',
            'webhooks' => 'Signed outgoing webhooks (twstec/kit-webhooks)',
        ])
        ->expectsOutputToContain('needs Accounts, API keys and projects (twstec/kit-accounts). Add both together: php artisan tws:add accounts uploads')
        ->assertFailed();

    expect($this->composer->calls)->toBe([]);
});

// -----------------------------------------------------------------------------
// O MODEL DE USUÁRIO DO APLICATIVO: o pacote de contas liga a conta pessoal
// aos eventos dele já no boot, e o twstec/kit-auth falha alto se o model não
// implementa o contrato dele. Num aplicativo Laravel limpo (o User do
// esqueleto), acrescentar contas derrubaria o aplicativo — o tws:add recusa
// antes, com o caminho.
// -----------------------------------------------------------------------------

it('contas sem o model de usuário pronto para o kit: recusa sem mexer em nada, com o caminho', function (): void {
    $this->project([], required: ['foundation'], userModel: false);

    $this->artisan('tws:add', ['modules' => ['accounts']])
        ->expectsOutputToContain('does not implement Twstec\\Kit\\Auth\\Contracts\\AuthUser yet, and without it the application does not boot. Nothing was changed. The way: php artisan tws:add auth; adjust the model as in the twstec/kit-auth README (vendor/twstec/kit-auth/README.md) and run php artisan tws:add accounts.')
        ->assertFailed();

    expect($this->composer->calls)->toBe([]);
    Process::assertNothingRan();
});

it('com a autenticação já instalada, o caminho não manda instalá-la de novo', function (): void {
    $this->project([], userModel: false);

    $this->artisan('tws:add', ['modules' => ['accounts', 'uploads']])
        ->expectsOutputToContain('The way: adjust the model as in the twstec/kit-auth README (vendor/twstec/kit-auth/README.md) and run php artisan tws:add accounts uploads.')
        ->assertFailed();

    expect($this->composer->calls)->toBe([]);
});

it('a autenticação e o /admin não dependem do model pronto (o kit-auth confere o model só no primeiro uso)', function (): void {
    $this->project([], required: ['foundation'], userModel: false);

    $this->artisan('tws:add', ['modules' => ['auth', 'admin']])->assertSuccessful();

    expect($this->composer->calls)->toBe([
        ['require', ['twstec/kit-foundation:^2.0@beta', 'twstec/kit-auth:^2.0@beta', 'twstec/kit-admin:^2.0@beta'], false],
    ]);
});

it('uploads sem contas e sem o model: a primeira recusa é a da dependência (contas)', function (): void {
    $this->project([], required: ['foundation'], userModel: false);

    $this->artisan('tws:add', ['modules' => ['uploads']])
        ->expectsOutputToContain('Add both together: php artisan tws:add accounts uploads')
        ->assertFailed();
});

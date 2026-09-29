<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Twstec\Kit\Installer\Contracts\Composer;
use Twstec\Kit\Installer\Tests\Fixtures\FakeComposer;

// =============================================================================
// O INSTALADOR (php artisan tws:install), de ponta a ponta, com o Composer de
// mentira e o Process::fake no lugar dos comandos do artisan que ele roda em
// processo novo. O projeto (composer.json, .env.example, .env) é uma pasta
// descartável — ver tests/TestCase.php.
// =============================================================================

beforeEach(function (): void {
    fakeArtisan();
});

/**
 * Process::fake dos comandos do artisan que o instalador roda em processo
 * novo: anota cada um (sem o binário e sem o `--no-interaction` que ele sempre
 * acrescenta) e responde sucesso — ou falha, para os que começam com um dos
 * $failing.
 *
 * @param  list<string>  $failing
 */
function fakeArtisan(array $failing = []): void
{
    artisanLog()->exchangeArray([]);

    Process::fake(function (PendingProcess $process) use ($failing) {
        $command = is_array($process->command) ? $process->command : [$process->command];
        $line = implode(' ', array_values(array_filter(
            array_slice($command, 2),
            fn (string $arg): bool => $arg !== '--no-interaction',
        )));

        artisanLog()->append($line);

        foreach ($failing as $prefix) {
            if (str_starts_with($line, $prefix)) {
                return Process::result(exitCode: 1);
            }
        }

        return Process::result();
    });
}

function artisanLog(): ArrayObject
{
    static $log;

    return $log ??= new ArrayObject;
}

/**
 * Os comandos do artisan que o instalador rodou, na ordem.
 *
 * @return list<string>
 */
function artisanRuns(): array
{
    return artisanLog()->getArrayCopy();
}

it('recusa rodar em produção sem --force — e não mexe em nada', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('tws:install', ['--without' => 'admin'])
        ->expectsOutputToContain('APP_ENV=production')
        ->assertFailed();

    expect($this->composer->calls)->toBe([])
        ->and(file_exists($this->project.'/.env'))->toBeFalse();

    Process::assertNothingRan();
});

it('em produção, roda com --force', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->artisan('tws:install', ['--without' => 'admin', '--force' => true])->assertSuccessful();

    expect($this->composer->calls)->toBe([['remove', ['twstec/kit-admin'], false]]);
});

it('--without tira os módulos pedidos, limpa os caches e roda as migrations', function (): void {
    $this->artisan('tws:install', ['--without' => 'admin,uploads'])->assertSuccessful();

    expect($this->composer->calls)->toBe([['remove', ['twstec/kit-uploads', 'twstec/kit-admin'], false]])
        ->and(artisanRuns())->toBe(['optimize:clear', 'migrate --force']);
});

it('só foundation e auth: tira contas e uploads (e o admin) de uma vez', function (): void {
    $this->artisan('tws:install', ['--without' => 'accounts,uploads,admin'])->assertSuccessful();

    expect($this->composer->calls)->toBe([['remove', ['twstec/kit-accounts', 'twstec/kit-uploads', 'twstec/kit-admin'], false]]);
});

it('--with acrescenta o módulo que falta, com a mesma restrição de versão do foundation', function (): void {
    $this->project(['accounts']);

    $this->artisan('tws:install', ['--with' => 'uploads,admin'])->assertSuccessful();

    expect($this->composer->calls)->toBe([['require', ['twstec/kit-uploads:^2.0@beta', 'twstec/kit-admin:^2.0@beta'], false]]);
});

it('--with e --without juntos: acrescenta um e tira outro', function (): void {
    $this->project(['accounts', 'uploads']);

    $this->artisan('tws:install', ['--with' => 'admin', '--without' => 'uploads'])->assertSuccessful();

    expect($this->composer->calls)->toBe([
        ['remove', ['twstec/kit-uploads'], false],
        ['require', ['twstec/kit-admin:^2.0@beta'], false],
    ]);
});

it('recusa uploads sem contas, sem mexer em nada', function (): void {
    $this->artisan('tws:install', ['--without' => 'accounts'])
        ->expectsOutputToContain('twstec/kit-accounts')
        ->assertFailed();

    $this->project([]);

    $this->artisan('tws:install', ['--with' => 'uploads'])->assertFailed();

    expect($this->composer->calls)->toBe([]);
    Process::assertNothingRan();
});

it('recusa módulo desconhecido, módulo obrigatório em --without e o mesmo módulo nas duas listas', function (string $option, string $value): void {
    $this->artisan('tws:install', [$option => $value, ...($option === '--with' ? ['--without' => 'admin'] : [])])->assertFailed();

    expect($this->composer->calls)->toBe([]);
    Process::assertNothingRan();
})->with([
    'desconhecido' => ['--without', 'billing'],
    'obrigatório' => ['--without', 'auth'],
    'nas duas listas' => ['--with', 'admin'],
]);

it('com a demonstração instalada, tirar um módulo exige tirar a demo junto (--no-demo)', function (): void {
    $this->project(['accounts', 'uploads', 'admin'], demo: true);

    $this->artisan('tws:install', ['--without' => 'admin'])
        ->expectsOutputToContain('--no-demo')
        ->assertFailed();

    expect($this->composer->calls)->toBe([]);
    Process::assertNothingRan();
});

it('--no-demo: demo:uninstall ANTES de o pacote sair, depois a demo e os módulos', function (): void {
    $this->project(['accounts', 'uploads', 'admin'], demo: true);

    $this->artisan('tws:install', ['--without' => 'admin', '--no-demo' => true])->assertSuccessful();

    expect(artisanRuns())->toBe(['demo:uninstall --drop-tables --force', 'optimize:clear', 'migrate --force'])
        ->and($this->composer->calls)->toBe([
            ['remove', ['twstec/kit-demo'], true],
            ['remove', ['twstec/kit-admin'], false],
        ]);
});

it('demo:uninstall que falha para tudo antes do Composer (o banco ainda tem os gatilhos da demo)', function (): void {
    $this->project(['accounts', 'uploads', 'admin'], demo: true);
    fakeArtisan(['demo:uninstall']);

    $this->artisan('tws:install', ['--no-demo' => true])->assertFailed();

    expect($this->composer->calls)->toBe([]);
});

it('Composer que falha para tudo: sem migrations nem mexer no .env', function (): void {
    $this->app->instance(Composer::class, $composer = new FakeComposer(fails: true));

    $this->artisan('tws:install', ['--without' => 'admin'])->assertFailed();

    expect($composer->calls)->toHaveCount(1)
        ->and(file_exists($this->project.'/.env'))->toBeFalse();
    Process::assertNothingRan();
});

it('cria o .env do .env.example e gera a APP_KEY e o pepper dedicado (projeto novo)', function (): void {
    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    $env = $this->envFile();

    expect($env)->toMatch('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m')
        ->toMatch('/^API_KEYS_HASH_PEPPER=[A-Za-z0-9]{64}$/m')
        // O pepper fica junto da explicação do .env.example.
        ->toContain("# API_KEYS_HASH_PEPPER=\nAPI_KEYS_HASH_PEPPER=")
        // APP_KEY nova: nenhuma chave de API foi emitida com ela.
        ->not->toMatch('/^API_KEYS_PREVIOUS_HASH_PEPPERS=/m');
});

it('pepper novo num projeto que já tinha APP_KEY: a APP_KEY vai para os peppers anteriores', function (): void {
    file_put_contents($this->project.'/.env', "APP_KEY=base64:QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU=\n# API_KEYS_PREVIOUS_HASH_PEPPERS=\n");

    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    expect($this->envFile())
        ->toContain('APP_KEY=base64:QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU=')
        ->toContain('API_KEYS_PREVIOUS_HASH_PEPPERS=base64:QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU=')
        ->toMatch('/^API_KEYS_HASH_PEPPER=[A-Za-z0-9]{64}$/m');
});

it('sem o módulo de contas, não gera pepper (não há chaves de API)', function (): void {
    $this->artisan('tws:install', ['--without' => 'accounts,uploads'])->assertSuccessful();

    expect($this->envFile())->toContain('APP_KEY=base64:')
        ->not->toMatch('/^API_KEYS_HASH_PEPPER=/m');
});

it('é idempotente: a segunda rodada não muda pacote nem regera chave', function (): void {
    $this->artisan('tws:install', ['--without' => 'admin'])->assertSuccessful();
    $env = $this->envFile();

    // O projeto agora está sem o admin.
    $this->project(['accounts', 'uploads']);
    $this->composer->calls = [];

    $this->artisan('tws:install', ['--without' => 'admin'])
        ->expectsOutputToContain('nothing to install or remove')
        ->assertSuccessful();

    expect($this->composer->calls)->toBe([])
        ->and($this->envFile())->toBe($env);
});

it('migrations que falham reprovam a rodada — com --graceful, avisam e seguem (create-project sem banco)', function (): void {
    fakeArtisan(['migrate']);

    $this->artisan('tws:install', ['--no-interaction' => true])->assertFailed();
    $this->artisan('tws:install', ['--no-interaction' => true, '--graceful' => true])
        ->expectsOutputToContain('php artisan migrate')
        ->assertSuccessful();
});

it('interativo: pergunta os módulos (já marcados os instalados) e a demo, mostra o plano e pede confirmação', function (): void {
    $this->project(['accounts', 'uploads', 'admin'], demo: true);

    $this->artisan('tws:install')
        ->expectsChoice('Which optional modules do you want?', ['accounts', 'uploads'], [
            'accounts' => 'Accounts, API keys and projects (twstec/kit-accounts)',
            'uploads' => 'Secure uploads and profile photo (twstec/kit-uploads)',
            'admin' => '/admin panel with Filament (twstec/kit-admin)',
        ])
        ->expectsConfirmation('The demo requires every optional module (/admin panel with Filament (twstec/kit-admin)). With this choice it will be removed. Continue?', 'yes')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect($this->composer->calls)->toBe([
        ['remove', ['twstec/kit-demo'], true],
        ['remove', ['twstec/kit-admin'], false],
    ]);
});

it('interativo: recusar a confirmação não muda nada', function (): void {
    $this->artisan('tws:install')
        ->expectsChoice('Which optional modules do you want?', ['accounts', 'uploads'], [
            'accounts' => 'Accounts, API keys and projects (twstec/kit-accounts)',
            'uploads' => 'Secure uploads and profile photo (twstec/kit-uploads)',
            'admin' => '/admin panel with Filament (twstec/kit-admin)',
        ])
        ->expectsConfirmation('Apply these changes?', 'no')
        ->assertFailed();

    expect($this->composer->calls)->toBe([]);
    Process::assertNothingRan();
});

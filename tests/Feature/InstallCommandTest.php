<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
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

it('webhooks: --with acrescenta (exige contas); tirar contas com webhooks é recusado; tudo de fora tira os quatro', function (): void {
    $this->artisan('tws:install', ['--with' => 'webhooks'])->assertSuccessful();

    expect($this->composer->calls)->toBe([['require', ['twstec/kit-webhooks:^2.0@beta'], false]]);

    $this->composer->calls = [];
    $this->project(['accounts', 'webhooks']);

    $this->artisan('tws:install', ['--without' => 'accounts'])
        ->expectsOutputToContain('twstec/kit-accounts')
        ->assertFailed();

    $this->project([]);
    $this->artisan('tws:install', ['--with' => 'webhooks'])->assertFailed();

    expect($this->composer->calls)->toBe([]);

    $this->project(['accounts', 'uploads', 'admin', 'webhooks']);
    $this->artisan('tws:install', ['--without' => 'accounts,uploads,admin,webhooks'])->assertSuccessful();

    expect($this->composer->calls)->toBe([['remove', ['twstec/kit-accounts', 'twstec/kit-uploads', 'twstec/kit-admin', 'twstec/kit-webhooks'], false]]);
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

it('com UPLOADS, gera a chave PRÓPRIA dos uploads confidenciais no .env — nunca a APP_KEY, nunca na tela', function (): void {
    $this->artisan('tws:install', ['--no-interaction' => true])->expectsOutputToContain('UPLOADS_ENCRYPTION_KEY')->assertSuccessful();

    $env = $this->envFile();

    preg_match('/^UPLOADS_ENCRYPTION_KEY=(.*)$/m', $env, $chave);
    preg_match('/^APP_KEY=(.*)$/m', $env, $appKey);

    expect($chave[1] ?? '')->toMatch('/^base64:[A-Za-z0-9+\/]{43}=$/')
        ->and(strlen((string) base64_decode(substr($chave[1], 7), true)))->toBe(32)
        ->and($chave[1])->not->toBe($appKey[1] ?? null)
        ->and(substr_count($env, 'UPLOADS_ENCRYPTION_KEY='))->toBe(1);

    // Chave já definida não é trocada (os arquivos cifrados dependem dela).
    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    expect($this->envFile())->toContain('UPLOADS_ENCRYPTION_KEY='.$chave[1]);
});

it('a chave dos confidenciais não aparece na saída do instalador', function (): void {
    $this->withoutMockingConsoleOutput();

    Artisan::call('tws:install', ['--no-interaction' => true]);
    $saida = Artisan::output();

    preg_match('/^UPLOADS_ENCRYPTION_KEY=(.*)$/m', $this->envFile(), $chave);

    expect($chave[1] ?? '')->not->toBe('')
        ->and($saida)->toContain('UPLOADS_ENCRYPTION_KEY')
        ->and($saida)->not->toContain(substr($chave[1], 7, 20));
});

it('sem o módulo de uploads, não gera a chave dos confidenciais', function (): void {
    $this->artisan('tws:install', ['--without' => 'uploads,admin'])->assertSuccessful();

    expect($this->envFile())->not->toMatch('/^UPLOADS_ENCRYPTION_KEY=/m');
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
            'webhooks' => 'Signed outgoing webhooks (twstec/kit-webhooks)',
        ])
        ->expectsConfirmation('The demo requires /admin panel with Filament (twstec/kit-admin). With this choice it will be removed. Continue?', 'yes')
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
            'webhooks' => 'Signed outgoing webhooks (twstec/kit-webhooks)',
        ])
        ->expectsConfirmation('Apply these changes?', 'no')
        ->assertFailed();

    expect($this->composer->calls)->toBe([]);
    Process::assertNothingRan();
});

// -----------------------------------------------------------------------------
// A escolha PELO AMBIENTE (TWS_KIT_WITH / TWS_KIT_WITHOUT): é por onde o
// comando único (composer create-project twstec/kit) e quem cria o projeto
// sem terminal passam a escolha ao post-create-project-cmd do starter — o
// Composer não repassa opções ao script.
// -----------------------------------------------------------------------------

function kitEnvironment(?string $with, ?string $without): void
{
    putenv($with === null ? 'TWS_KIT_WITH' : "TWS_KIT_WITH={$with}");
    putenv($without === null ? 'TWS_KIT_WITHOUT' : "TWS_KIT_WITHOUT={$without}");
}

afterEach(function (): void {
    kitEnvironment(null, null);
});

it('TWS_KIT_WITHOUT vale como --without — e, com ela, não há pergunta', function (): void {
    kitEnvironment(null, 'admin');

    // Sem --no-interaction: se perguntasse, o teste reprovaria (pergunta
    // não esperada).
    $this->artisan('tws:install')->assertSuccessful();

    expect($this->composer->calls)->toBe([['remove', ['twstec/kit-admin'], false]]);
});

it('o comando único: os pacotes já estão como o menu escolheu — nada muda no Composer, só chaves e banco', function (): void {
    $this->project(['accounts', 'admin']);
    kitEnvironment('accounts,admin', 'uploads');

    $this->artisan('tws:install', ['--graceful' => true])->assertSuccessful();

    expect($this->composer->calls)->toBe([])
        ->and(artisanRuns())->toBe(['migrate --force'])
        ->and($this->envFile())->toMatch('/^APP_KEY=base64:/m')->toMatch('/^API_KEYS_HASH_PEPPER=[A-Za-z0-9]{64}$/m');
});

it('variáveis presentes e vazias também valem como escolha (nada a mudar, sem pergunta)', function (): void {
    kitEnvironment('', '');

    $this->artisan('tws:install')->assertSuccessful();

    expect($this->composer->calls)->toBe([]);
});

it('a opção vence a variável', function (): void {
    kitEnvironment(null, 'admin');

    $this->artisan('tws:install', ['--without' => 'uploads'])->assertSuccessful();

    expect($this->composer->calls)->toBe([['remove', ['twstec/kit-uploads'], false]]);
});

it('variável com escolha inválida é recusada como a opção (uploads sem contas), sem mexer em nada', function (): void {
    kitEnvironment(null, 'accounts');

    $this->artisan('tws:install')->expectsOutputToContain('twstec/kit-accounts')->assertFailed();

    expect($this->composer->calls)->toBe([]);
    Process::assertNothingRan();
});

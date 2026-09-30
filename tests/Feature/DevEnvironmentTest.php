<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

// =============================================================================
// O DOCKER DE DESENVOLVIMENTO no tws:install: num projeto criado (com o
// compose.yaml de desenvolvimento na raiz), o instalador grava no .env, uma
// vez, o nome do projeto, as portas do número, o dono dos arquivos e as
// senhas geradas do banco e do Redis — a partir de TWS_KIT_NAME/TWS_KIT_SLOT
// (o comando único passa o que o menu perguntou) ou das sugestões. Nome em
// uso e porta ocupada param ANTES de qualquer mudança. A máquina é de mentira
// (FakeHost, ver TestCase).
// =============================================================================

const DEV_VARIABLES = ['TWS_KIT_NAME', 'TWS_KIT_SLOT', 'TWS_KIT_EXPOSE_DB', 'TWS_KIT_FOLDER', 'TWS_KIT_UID', 'TWS_KIT_GID', 'TWS_KIT_VITE_POLLING'];

beforeEach(function (): void {
    Process::fake();

    // O .env.example de um starter publicado: os valores do dev do monorepo
    // (senha fixa, localhost:8180), que o projeto criado NÃO pode herdar.
    file_put_contents($this->project.'/.env.example', implode("\n", [
        'APP_KEY=',
        'APP_URL=http://localhost:8180',
        'PLATFORM_OFFICIAL_URL=http://localhost:8180',
        '# --- Docker de desenvolvimento ---',
        '# COMPOSE_PROJECT_NAME=',
        'DB_CONNECTION=pgsql',
        'DB_HOST=postgres',
        'DB_DATABASE=tws_starter',
        'DB_USERNAME=tws',
        'DB_PASSWORD=tws_dev_password',
        'REDIS_PASSWORD=null',
        '',
    ]));

    // O projeto criado: o compose.yaml de desenvolvimento na raiz.
    $this->devProject = fn () => file_put_contents($this->project.'/compose.yaml', "services: {}\n");

    // O valor ATIVO de uma variável no .env.
    $this->value = function (string $key): ?string {
        preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $this->envFile(), $match);

        return $match[1] ?? null;
    };
});

afterEach(function (): void {
    foreach (DEV_VARIABLES as $variable) {
        putenv($variable);
    }
});

it('sem o compose.yaml de desenvolvimento (o starter no monorepo): nada do Docker vai para o .env', function (): void {
    putenv('TWS_KIT_NAME=loja');

    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    expect(($this->value)('COMPOSE_PROJECT_NAME'))->toBeNull()
        ->and(($this->value)('DB_PASSWORD'))->toBe('tws_dev_password');
});

it('com o nome e o número do menu: grava o projeto, as portas, o endereço e as senhas geradas', function (): void {
    ($this->devProject)();
    putenv('TWS_KIT_NAME=loja-da-maria');
    putenv('TWS_KIT_SLOT=3');
    putenv('TWS_KIT_UID=1001');
    putenv('TWS_KIT_GID=1002');

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain('project loja-da-maria, number 3 — http://loja-da-maria.localhost:8083')
        ->expectsOutputToContain('Start the project: docker compose up -d — then open http://loja-da-maria.localhost:8083 (e-mails at http://loja-da-maria.localhost:8023).')
        ->assertSuccessful();

    expect(($this->value)('COMPOSE_PROJECT_NAME'))->toBe('loja-da-maria')
        ->and(($this->value)('APP_URL'))->toBe('http://loja-da-maria.localhost:8083')
        ->and(($this->value)('PLATFORM_OFFICIAL_URL'))->toBe('http://loja-da-maria.localhost:8083')
        ->and(($this->value)('SESSION_COOKIE'))->toBe('loja_da_maria_session')
        ->and(($this->value)('DB_DATABASE'))->toBe('loja_da_maria')
        ->and([($this->value)('DEV_SITE_PORT'), ($this->value)('DEV_MAIL_PORT'), ($this->value)('DEV_VITE_PORT'), ($this->value)('DEV_DB_PORT')])->toBe(['8083', '8023', '8033', '8043'])
        ->and(($this->value)('COMPOSE_PROFILES'))->toBe('')
        ->and([($this->value)('DEV_UID'), ($this->value)('DEV_GID')])->toBe(['1001', '1002'])
        // Nada da senha fixa do .env.example.
        ->and(($this->value)('DB_PASSWORD'))->toMatch('/^[0-9a-f]{32}$/')
        ->and(($this->value)('REDIS_PASSWORD'))->toMatch('/^[0-9a-f]{32}$/')
        ->and(($this->value)('DB_PASSWORD'))->not->toBe(($this->value)('REDIS_PASSWORD'));

    // O valor fica junto da explicação do .env.example.
    expect($this->envFile())->toContain("# COMPOSE_PROJECT_NAME=\nCOMPOSE_PROJECT_NAME=loja-da-maria\n");

    // O banco é o do Docker, que ainda não subiu: as migrations ficam para o
    // primeiro `docker compose up -d` (sem tentar conectar agora).
    Process::assertDidntRun(fn (PendingProcess $process): bool => in_array('migrate', (array) $process->command, true));

    // O nome e o número ficam reservados até o primeiro `up`.
    expect($this->host->reserved)->toBe([['loja-da-maria', 3]]);
});

it('sem variável: o nome da pasta (sem colidir) e o primeiro número com as quatro portas livres', function (): void {
    ($this->devProject)();
    putenv('TWS_KIT_FOLDER=Loja Nova');
    $this->host->withProject('loja-nova', 0);
    $this->host->busy = [8041];

    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    expect(($this->value)('COMPOSE_PROJECT_NAME'))->toBe('loja-nova-2')
        ->and(($this->value)('DEV_SLOT'))->toBe('2')
        ->and(($this->value)('APP_URL'))->toBe('http://loja-nova-2.localhost:8082');
});

it('TWS_KIT_EXPOSE_DB=1 publica o banco (o perfil db-port)', function (): void {
    ($this->devProject)();
    putenv('TWS_KIT_EXPOSE_DB=1');

    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    expect(($this->value)('COMPOSE_PROFILES'))->toBe('db-port');
});

it('nome de um projeto Docker que já existe: RECUSADO antes de mexer em qualquer coisa', function (): void {
    ($this->devProject)();
    $this->host->withProject('loja', 5);
    putenv('TWS_KIT_NAME=loja');

    $this->artisan('tws:install', ['--without' => 'admin'])
        // Uma linha só: a recusa e o nome sugerido.
        ->expectsOutputToContain('There is already a Docker project called loja on this machine (containers or volumes, even stopped). Use another name in TWS_KIT_NAME — suggestion: loja-2.')
        ->assertFailed();

    expect($this->composer->calls)->toBe([])
        ->and(file_exists($this->project.'/.env'))->toBeFalse()
        ->and($this->host->reserved)->toBe([]);
});

it('número com porta ocupada: RECUSADO antes de mexer em qualquer coisa, com quem ocupa e o primeiro livre', function (): void {
    ($this->devProject)();
    $this->host->withProject('loja-da-maria', 0);
    putenv('TWS_KIT_SLOT=0');

    $this->artisan('tws:install', ['--without' => 'admin'])
        ->expectsOutputToContain('Number 0 (TWS_KIT_SLOT) is in use — ports 8020 (loja-da-maria), 8030 (loja-da-maria), 8080 (loja-da-maria). The first number with all four ports free is 1.')
        ->assertFailed();

    expect($this->composer->calls)->toBe([])
        ->and(file_exists($this->project.'/.env'))->toBeFalse();
});

it('nome ou número fora do padrão: recusados', function (string $variable, string $value, string $message): void {
    ($this->devProject)();
    putenv("{$variable}={$value}");

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(file_exists($this->project.'/.env'))->toBeFalse();
})->with([
    ['TWS_KIT_NAME', 'Loja Nova', 'Invalid project name in TWS_KIT_NAME: Loja Nova'],
    ['TWS_KIT_SLOT', '100', 'Invalid project number in TWS_KIT_SLOT: 100'],
    ['TWS_KIT_EXPOSE_DB', 'talvez', 'Invalid value in TWS_KIT_EXPOSE_DB: talvez'],
]);

it('uma vez só: com o COMPOSE_PROJECT_NAME no .env, rodar de novo não troca nome, portas nem senhas', function (): void {
    ($this->devProject)();
    putenv('TWS_KIT_NAME=loja');

    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();
    $before = $this->envFile();

    // Mesmo com outro nome pedido e a máquina mudada.
    putenv('TWS_KIT_NAME=outra');
    $this->host->withProject('loja', 0);

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain('already configured (COMPOSE_PROJECT_NAME=loja)')
        ->assertSuccessful();

    expect($this->envFile())->toBe($before);
});

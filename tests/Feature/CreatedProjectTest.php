<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Twstec\Kit\Installer\Support\CreatedProject;

// =============================================================================
// O PROJETO CRIADO é DO PROJETO: na criação (o tws:install com o
// compose.yaml de desenvolvimento na raiz), junto com o Docker de
// desenvolvimento, o instalador troca o que o starter trazia com os valores
// dele — o .env.example, o banco da suíte PostgreSQL, o banco padrão de
// produção, o composer.json (nome, licença, nada da identidade do starter) e
// o content-hash do lock —, guarda a licença MIT do kit como aviso e põe o CI
// base no lugar do GitHub Actions. A máquina é de mentira (FakeHost).
// =============================================================================

const PROJECT_VARIABLES = ['TWS_KIT_NAME', 'TWS_KIT_SLOT', 'TWS_KIT_VENDOR', 'TWS_KIT_LICENSE'];

beforeEach(function (): void {
    Process::fake();

    // Os arquivos de um starter publicado, com os valores DO STARTER.
    $starter = [
        'name' => 'twstec/starter-react',
        // A versão do starter (a de um pacote empacotado com ela).
        'version' => '2.0.0-beta.5',
        'type' => 'project',
        'description' => 'TWS Laravel Starter Kit — starter React',
        'keywords' => ['laravel', 'react'],
        'license' => 'MIT',
        'homepage' => 'https://github.com/kelvindk9w/tws-laravel-starter-kit',
        'support' => ['issues' => 'https://github.com/kelvindk9w/tws-laravel-starter-kit/issues'],
        'require' => ['php' => '^8.4', 'twstec/kit-foundation' => '^2.0@beta'],
        'minimum-stability' => 'stable',
        'prefer-stable' => true,
    ];
    file_put_contents($this->project.'/composer.json', json_encode($starter, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    file_put_contents($this->project.'/composer.lock', json_encode(['_readme' => ['...'], 'content-hash' => 'do-starter', 'packages' => []], JSON_PRETTY_PRINT)."\n");

    file_put_contents($this->project.'/.env.example', implode("\n", [
        'APP_KEY=',
        'APP_URL=http://127.0.0.1:8181',
        'PLATFORM_OFFICIAL_URL=http://127.0.0.1:8181',
        '# COMPOSE_PROJECT_NAME=',
        'DB_DATABASE=tws_starter_react',
        'DB_PASSWORD=tws_dev_password',
        'SESSION_COOKIE=tws_starter_react_session',
        'REDIS_PASSWORD=null',
        'RATE_LIMIT_SENSITIVE=30',
        '',
    ]));

    file_put_contents($this->project.'/phpunit.pgsql.xml', implode("\n", [
        '<phpunit><php>',
        '    <env name="DB_DATABASE" value="tws_starter_react_test" force="true"/>',
        '    <server name="DB_DATABASE" value="tws_starter_react_test" force="true"/>',
        '</php></phpunit>',
        '',
    ]));

    file_put_contents($this->project.'/docker-compose.prod.yml', implode("\n", [
        'x-app-env: &app-env',
        '    DB_DATABASE: ${PROD_POSTGRES_DB:-tws_starter_react}',
        'services:',
        '    postgres:',
        '        environment:',
        '            POSTGRES_DB: ${PROD_POSTGRES_DB:-tws_starter_react}',
        '',
    ]));

    file_put_contents($this->project.'/LICENSE', "MIT License\n\nCopyright (c) 2026 TWS\n");
    mkdir($this->project.'/docker/dev', 0777, true);
    file_put_contents($this->project.'/docker/dev/ci.yml', "name: CI\n");
    file_put_contents($this->project.'/compose.yaml', "services: {}\n");

    $this->json = fn (string $file): array => json_decode((string) file_get_contents($this->project.'/'.$file), true);
    $this->read = fn (string $file): string => (string) file_get_contents($this->project.'/'.$file);
});

afterEach(function (): void {
    foreach (PROJECT_VARIABLES as $variable) {
        putenv($variable);
    }
});

it('troca os valores do starter pelos do projeto — e nenhum segredo vai para o .env.example', function (): void {
    putenv('TWS_KIT_NAME=loja-da-maria');
    putenv('TWS_KIT_SLOT=3');

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain('loja_da_maria_test')
        ->assertSuccessful();

    $example = ($this->read)('.env.example');

    expect($example)->toContain("APP_URL=http://loja-da-maria.localhost:8083\n")
        ->toContain("PLATFORM_OFFICIAL_URL=http://loja-da-maria.localhost:8083\n")
        ->toContain("DB_DATABASE=loja_da_maria\n")
        ->toContain("SESSION_COOKIE=loja_da_maria_session\n")
        // As senhas geradas ficam SÓ no .env.
        ->toContain("DB_PASSWORD=tws_dev_password\n")
        ->toContain("REDIS_PASSWORD=null\n")
        ->not->toContain('tws_starter');

    // O limite das rotas sensíveis de desenvolvimento (a suíte E2E).
    expect($this->envFile())->toContain("RATE_LIMIT_SENSITIVE=30\n");

    // O banco da suíte: <banco>_test, nos dois lugares do phpunit.pgsql.xml
    // (o db-init do compose.yaml cria o mesmo: ${DB_DATABASE}_test).
    expect(substr_count(($this->read)('phpunit.pgsql.xml'), 'value="loja_da_maria_test"'))->toBe(2)
        ->and(($this->read)('phpunit.pgsql.xml'))->not->toContain('tws_starter');

    // O banco padrão de produção.
    expect(substr_count(($this->read)('docker-compose.prod.yml'), '${PROD_POSTGRES_DB:-loja_da_maria}'))->toBe(2)
        ->and(($this->read)('docker-compose.prod.yml'))->not->toContain('tws_starter');
});

it('composer.json com a identidade do projeto (app/<nome>, proprietary) e o lock acompanhando', function (): void {
    putenv('TWS_KIT_NAME=loja-da-maria');

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain('app/loja-da-maria')
        ->assertSuccessful();

    $composer = ($this->json)('composer.json');

    expect($composer['name'])->toBe('app/loja-da-maria')
        ->and($composer['license'])->toBe('proprietary')
        ->and($composer)->not->toHaveKeys(CreatedProject::STARTER_IDENTITY)
        // O resto fica como estava.
        ->and($composer['type'])->toBe('project')
        ->and($composer['require'])->toBe(['php' => '^8.4', 'twstec/kit-foundation' => '^2.0@beta'])
        ->and(array_slice(array_keys($composer), 0, 3))->toBe(['name', 'type', 'license']);

    // O Composer não acusa lock desatualizado: o content-hash é o do
    // composer.json novo.
    expect(($this->json)('composer.lock')['content-hash'])->toBe(CreatedProject::contentHash(($this->read)('composer.json')))
        ->not->toBe('do-starter');
});

it('a licença MIT do kit vira NOTICE-KIT-MIT.txt, com o aviso de que ela não é a do projeto', function (): void {
    putenv('TWS_KIT_NAME=loja');

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain('NOTICE-KIT-MIT.txt')
        ->assertSuccessful();

    $notice = ($this->read)('NOTICE-KIT-MIT.txt');

    expect(file_exists($this->project.'/LICENSE'))->toBeFalse()
        // O texto da MIT inteiro, depois do cabeçalho.
        ->and($notice)->toEndWith("MIT License\n\nCopyright (c) 2026 TWS\n")
        ->and($notice)->toContain('TWS Laravel Starter Kit')
        ->and($notice)->toContain('"license": "proprietary"');
});

it('o CI base vai para .github/workflows/ci.yml — sem sobrescrever um que o projeto já tenha', function (bool $existing): void {
    putenv('TWS_KIT_NAME=loja');

    if ($existing) {
        mkdir($this->project.'/.github/workflows', 0777, true);
        file_put_contents($this->project.'/.github/workflows/ci.yml', "name: meu CI\n");
    }

    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    expect(($this->read)('.github/workflows/ci.yml'))->toBe($existing ? "name: meu CI\n" : "name: CI\n");
})->with(['sem workflow' => false, 'com workflow' => true]);

it('TWS_KIT_VENDOR e TWS_KIT_LICENSE escolhem o vendor e a licença', function (): void {
    putenv('TWS_KIT_NAME=loja');
    putenv('TWS_KIT_VENDOR=Maria-Tech');
    putenv('TWS_KIT_LICENSE=Apache-2.0');

    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    expect(($this->json)('composer.json'))->toMatchArray(['name' => 'maria-tech/loja', 'license' => 'Apache-2.0'])
        ->and(($this->read)('NOTICE-KIT-MIT.txt'))->toContain('"license": "Apache-2.0"');
});

it('vendor ou licença inválidos: RECUSADOS antes de mexer em qualquer coisa', function (string $variable, string $value, string $message): void {
    putenv('TWS_KIT_NAME=loja');
    putenv("{$variable}={$value}");
    $composer = ($this->read)('composer.json');

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(file_exists($this->project.'/.env'))->toBeFalse()
        ->and(($this->read)('composer.json'))->toBe($composer)
        ->and(file_exists($this->project.'/LICENSE'))->toBeTrue()
        ->and($this->host->reserved)->toBe([]);
})->with([
    ['TWS_KIT_VENDOR', 'minha empresa', 'Invalid vendor in TWS_KIT_VENDOR: minha empresa'],
    ['TWS_KIT_LICENSE', 'MIT; rm -rf', 'Invalid license in TWS_KIT_LICENSE: MIT; rm -rf'],
]);

it('no monorepo (sem o compose.yaml de desenvolvimento), nada do projeto criado acontece', function (): void {
    unlink($this->project.'/compose.yaml');
    $before = ($this->read)('composer.json');

    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    expect(($this->read)('composer.json'))->toBe($before)
        ->and(file_exists($this->project.'/LICENSE'))->toBeTrue()
        ->and(file_exists($this->project.'/NOTICE-KIT-MIT.txt'))->toBeFalse()
        ->and(($this->read)('phpunit.pgsql.xml'))->toContain('tws_starter_react_test')
        ->and(file_exists($this->project.'/docker/dev/ci.yml'))->toBeTrue();
});

it('o content-hash é o mesmo que o Composer calcula', function (): void {
    // composer.json e content-hash gravados pelo próprio Composer 2
    // (`composer update` numa pasta com só este arquivo): cobre as chaves que
    // entram no hash, o config.platform, a barra e o texto acentuado.
    $composer = <<<'JSON'
        {
            "name": "app/loja-da-maria",
            "type": "project",
            "license": "proprietary",
            "require": {
                "php": "^8.4"
            },
            "minimum-stability": "stable",
            "prefer-stable": true,
            "config": {
                "platform": {
                    "php": "8.4.24"
                },
                "sort-packages": true
            },
            "extra": {
                "laravel": {
                    "dont-discover": []
                },
                "nota": "ação/ç"
            }
        }
        JSON;

    expect(CreatedProject::contentHash($composer))->toBe('ffbb76049379af7e781cf9de8350066a');
});

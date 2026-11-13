<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Twstec\Kit\Installer\Support\CreatedProject;
use Twstec\Kit\Installer\Upgrade\Manifest;

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
        'require' => ['php' => '^8.4', 'twstec/kit-foundation' => '^2.0'],
        'minimum-stability' => 'stable',
        'prefer-stable' => true,
    ];
    file_put_contents($this->project.'/composer.json', json_encode($starter, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    file_put_contents($this->project.'/composer.lock', json_encode(['_readme' => ['...'], 'content-hash' => 'do-starter', 'packages' => []], JSON_PRETTY_PRINT)."\n");

    file_put_contents($this->project.'/.env.example', implode("\n", [
        'APP_NAME="TWS Starter Kit"',
        'APP_KEY=',
        'APP_URL=http://127.0.0.1:8181',
        'PLATFORM_NAME="TWS Starter Kit"',
        'PLATFORM_REPO_URL=https://github.com/kelvindk9w/tws-laravel-starter-kit',
        'PLATFORM_COMPANY_NAME=TWS',
        'PLATFORM_COMPANY_URL=https://example.com',
        'PLATFORM_VERSION=2.3.0',
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
    // O README do starter publicado: descreve o monorepo e o espelho.
    file_put_contents($this->project.'/README.md', implode("\n", [
        '# Starter React',
        '> Parte do TWS Laravel Starter Kit. O código fica no monorepo (pasta `starters/react`);',
        '> este repositório é o espelho só-leitura publicado a cada versão.',
        'scripts/preparar-dev',
        'docker compose exec react-app php artisan migrate',
        'Aplicação: http://127.0.0.1:8181 · Mailpit: http://localhost:18025',
        '',
    ]));
    mkdir($this->project.'/docker/dev', 0777, true);
    file_put_contents($this->project.'/docker/dev/ci.yml', "name: CI\n");
    file_put_contents($this->project.'/docker/dev/dependabot.yml', "version: 2\n");
    file_put_contents($this->project.'/compose.yaml', "services: {}\n");
    mkdir($this->project.'/.devcontainer');
    file_put_contents($this->project.'/.devcontainer/devcontainer.json', "// Dev Container\n{\n    \"name\": \"TWS Laravel Starter Kit (React)\",\n    \"service\": \"app\"\n}\n");

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

it('o projeto nasce com o nome dele no .env, no .env.example e no Dev Container — nada da identidade do kit', function (): void {
    putenv('TWS_KIT_NAME=acme');

    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    foreach (['.env.example' => ($this->read)('.env.example'), '.env' => $this->envFile()] as $file => $contents) {
        expect($contents)->toContain("APP_NAME=acme\n", "PLATFORM_NAME=acme\n", "PLATFORM_VERSION=0.1.0\n")
            ->toContain("PLATFORM_REPO_URL=\n", "PLATFORM_COMPANY_NAME=\n", "PLATFORM_COMPANY_URL=\n")
            ->not->toContain('Starter Kit')
            ->not->toContain('TWS')
            ->not->toContain('tws-laravel-starter-kit')
            ->not->toContain('2.0.0-beta');
    }

    expect(($this->read)('.devcontainer/devcontainer.json'))->toContain('"name": "acme"')
        ->not->toContain('Starter Kit')
        ->toContain('"service": "app"');
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
        ->and($composer['require'])->toBe(['php' => '^8.4', 'twstec/kit-foundation' => '^2.0'])
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

it('o README do projeto substitui o do starter: nome, endereços, comandos do projeto e a documentação da versão do kit — nada do monorepo', function (): void {
    putenv('TWS_KIT_NAME=loja-da-maria');
    putenv('TWS_KIT_SLOT=3');

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain('README.md')
        ->assertSuccessful();

    $readme = ($this->read)('README.md');
    $docs = 'https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/v2.0.0-beta.20/docs';

    expect($readme)->toStartWith("# loja-da-maria\n")
        ->toContain('<http://loja-da-maria.localhost:8083>', '<http://loja-da-maria.localhost:8023>')
        ->toContain('docker compose up -d', 'scripts/verificar', 'scripts/e2e')
        ->toContain('docker compose exec app php artisan user:make-admin', '<http://loja-da-maria.localhost:8083/admin>')
        ->toContain('php artisan kit:overrides', 'php artisan tws:upgrade --check')
        ->toContain('Starter Kit](https://github.com/kelvindk9w/tws-laravel-starter-kit)', '2.0.0-beta.20,', 'React (Inertia + TypeScript)')
        ->toContain("]({$docs}/instalacao.md)", "]({$docs}/personalizacao.md)", "]({$docs})")
        ->toContain('o PostgreSQL, o Redis, o', 'COMPOSE_PROFILES=db-port', 'resources/js/')
        // Nada do README do starter nem do monorepo, e nenhuma marca do modelo sobrando.
        ->not->toContain('monorepo')
        ->not->toContain('espelho')
        ->not->toContain('starters/')
        ->not->toContain('preparar-dev')
        ->not->toContain('react-app')
        ->not->toContain('8181')
        ->not->toContain('18025')
        ->not->toContain('Modo protótipo')
        ->not->toContain('resources/views/')
        ->not->toContain('{{')
        ->not->toContain('}}')
        ->not->toContain("\n\n\n");
});

it('o README do projeto acompanha a interface, o banco, o Redis e os módulos escolhidos', function (): void {
    putenv('TWS_KIT_NAME=prototipo');
    file_put_contents($this->project.'/composer.json', (string) json_encode(['name' => 'twstec/starter-livewire', 'require' => ['twstec/kit-foundation' => '^2.0']]));
    $this->references->installed = null;

    $this->artisan('tws:install', ['--no-interaction' => true, '--database' => 'sqlite', '--no-redis' => true, '--without' => 'admin'])
        ->assertSuccessful();

    expect(($this->read)('README.md'))->toStartWith("# prototipo\n")
        ->toContain('Starter Kit](https://github.com/kelvindk9w/tws-laravel-starter-kit), com a interface em Livewire.')
        ->toContain('Modo protótipo', 'resources/views/')
        ->toContain('https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/docs/personalizacao.md')
        ->toContain("Sobem o site (nginx + PHP-FPM), o\nMailpit")
        ->not->toContain('PostgreSQL, ')
        ->not->toContain('o Redis')
        ->not->toContain('db-port')
        ->not->toContain('user:make-admin')
        ->not->toContain('resources/js/')
        ->not->toContain('{{')
        ->not->toContain("\n\n\n");
});

it('o modelo: trecho condicional em linhas próprias some com as linhas; no meio do texto, só o trecho', function (): void {
    $template = "a{{#x}} [x]{{/x}}{{#y}} [y]{{/y}} {{v}}\n{{#x}}\nlinha x\n{{/x}}\n{{#y}}\nlinha y\n{{/y}}\nfim\n";

    expect(CreatedProject::renderTemplate($template, ['v' => 'valor'], ['x' => true, 'y' => false]))
        ->toBe("a [x] valor\nlinha x\nfim\n");
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

it('o Dependabot modelo vai para .github/dependabot.yml — sem sobrescrever um que o projeto já tenha', function (bool $existing): void {
    putenv('TWS_KIT_NAME=loja');

    if ($existing) {
        mkdir($this->project.'/.github', 0777, true);
        file_put_contents($this->project.'/.github/dependabot.yml', "version: 2\nupdates: []\n");
    }

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain($existing ? 'CI' : '.github/dependabot.yml')
        ->assertSuccessful();

    expect(($this->read)('.github/dependabot.yml'))->toBe($existing ? "version: 2\nupdates: []\n" : "version: 2\n")
        ->and(file_exists($this->project.'/docker/dev/dependabot.yml'))->toBe($existing);
})->with(['sem Dependabot' => false, 'com Dependabot' => true]);

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

it('grava a base da conferência (.tws/manifest.json): o starter como chegou e o projeto como ficou — e o tws:upgrade sai 0', function (): void {
    putenv('TWS_KIT_NAME=loja');
    // O starter publicado, como chegou (a referência da mesma versão).
    $starter = sys_get_temp_dir().'/kit-starter-'.bin2hex(random_bytes(6));
    (new Filesystem)->copyDirectory($this->project, $starter);

    try {
        $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

        $manifest = Manifest::read($this->project);

        expect($manifest?->kit)->toBe('2.0.0-beta.20')
            ->and($manifest?->starter)->toBe('react')
            // Adaptado na criação: a base guarda os dois lados.
            ->and($manifest?->files['.env.example'])->toBe([
                'reference' => hash_file('sha256', $starter.'/.env.example'),
                'project' => hash_file('sha256', $this->project.'/.env.example'),
            ])
            ->and($manifest?->files['.env.example']['reference'])->not->toBe($manifest?->files['.env.example']['project'])
            // Mudado de lugar: o CI base, pelo caminho no projeto.
            ->and($manifest?->files['.github/workflows/ci.yml'])->toBe([
                'reference' => hash('sha256', "name: CI\n"),
                'project' => hash('sha256', "name: CI\n"),
            ])
            ->and($manifest?->files)->not->toHaveKey('docker/dev/ci.yml')
            // Fora da conta: o composer.json (do projeto desde a criação), o
            // lock, a licença do kit e o .env.
            ->and($manifest?->files)->not->toHaveKeys(['composer.json', 'composer.lock', 'LICENSE', '.env']);

        // Recém-criado, nada a conferir — o que o instalador adaptou não
        // aparece como diferença.
        expect(Artisan::call('tws:upgrade', ['--check' => true, '--from' => $starter]))->toBe(0);
    } finally {
        (new Filesystem)->deleteDirectory($starter);
    }
});

it('rodar o instalador de novo não troca a base gravada na criação', function (): void {
    putenv('TWS_KIT_NAME=loja');
    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();
    $first = ($this->read)(Manifest::PATH);
    file_put_contents($this->project.'/compose.yaml', "services: {meu: {}}\n");

    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    expect(($this->read)(Manifest::PATH))->toBe($first);
});

it('no monorepo (sem o compose.yaml de desenvolvimento), nada do projeto criado acontece', function (): void {
    unlink($this->project.'/compose.yaml');
    $before = ($this->read)('composer.json');

    $this->artisan('tws:install', ['--no-interaction' => true])->assertSuccessful();

    expect(($this->read)('composer.json'))->toBe($before)
        ->and(file_exists($this->project.'/LICENSE'))->toBeTrue()
        ->and(file_exists($this->project.'/NOTICE-KIT-MIT.txt'))->toBeFalse()
        ->and(($this->read)('phpunit.pgsql.xml'))->toContain('tws_starter_react_test')
        ->and(file_exists($this->project.'/docker/dev/ci.yml'))->toBeTrue()
        ->and(file_exists($this->project.'/docker/dev/dependabot.yml'))->toBeTrue()
        ->and(file_exists($this->project.'/.github/dependabot.yml'))->toBeFalse()
        ->and(($this->read)('README.md'))->toStartWith('# Starter React')
        ->and(file_exists($this->project.'/'.Manifest::PATH))->toBeFalse();
});

it('o content-hash é o mesmo que o Composer calcula', function (): void {
    // composer.json e content-hash gravados pelo próprio Composer 2
    // (`composer update` numa pasta com só este arquivo): cobre as chaves que
    // entram no hash, o config.platform, a barra e o texto acentuado. O
    // valor da plataforma é o da gravação: trocá-lo exige gravar de novo.
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

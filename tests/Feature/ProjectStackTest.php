<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Yaml\Yaml;
use Twstec\Kit\Installer\Support\ComposeFile;
use Twstec\Kit\Installer\Support\ProjectStack;
use Twstec\Kit\Installer\Support\StackFiles;

// =============================================================================
// BANCO E REDIS DO PROJETO CRIADO (issue #73): PostgreSQL ou SQLite, Redis
// sim ou não — escolhidos na criação (TWS_KIT_DATABASE/TWS_KIT_REDIS,
// --database/--no-redis ou as perguntas) e aplicados aos arquivos do projeto
// (Support\StackFiles).
//
// As transformações rodam sobre os arquivos DE VERDADE dos dois starters do
// monorepo (compose de desenvolvimento e de produção, .env.example,
// .env.prod.example, o provider e a configuração do Horizon): texto do starter
// que mude e deixe uma transformação sem efeito reprova aqui.
// =============================================================================

const STACK_VARIABLES = ['TWS_KIT_NAME', 'TWS_KIT_SLOT', 'TWS_KIT_DATABASE', 'TWS_KIT_REDIS'];

/**
 * Uma cópia dos arquivos do starter que a escolha mexe, no formato do
 * projeto criado (o compose de desenvolvimento na raiz, o .env do
 * .env.example).
 */
function starterProject(string $starter): string
{
    $source = dirname(__DIR__, 4).'/starters/'.$starter;

    expect(is_dir($source))->toBeTrue("o starter {$starter} do monorepo: {$source}");
    $target = sys_get_temp_dir().'/kit-stack-'.$starter.'-'.bin2hex(random_bytes(6));

    $files = [
        'docker/dev/compose.yaml' => 'compose.yaml',
        'docker-compose.prod.yml' => 'docker-compose.prod.yml',
        '.env.example' => '.env.example',
        '.env.prod.example' => '.env.prod.example',
        'bootstrap/providers.php' => 'bootstrap/providers.php',
        'composer.json' => 'composer.json',
    ];

    foreach ([...$files, ...array_combine(StackFiles::HORIZON_FILES, StackFiles::HORIZON_FILES)] as $from => $to) {
        if (! is_file($source.'/'.$from)) {
            continue;
        }

        @mkdir(dirname($target.'/'.$to), 0755, true);
        copy($source.'/'.$from, $target.'/'.$to);
    }

    copy($target.'/.env.example', $target.'/.env');

    return $target;
}

/**
 * As variáveis ATIVAS de um arquivo .env (nome => valor).
 *
 * @return array<string, string>
 */
function activeVariables(string $file): array
{
    preg_match_all('/^([A-Z][A-Z0-9_]*)=(.*)$/m', (string) file_get_contents($file), $matches, PREG_SET_ORDER);

    return array_column($matches, 2, 1);
}

/**
 * As variáveis de exemplo comentadas (`# CHAVE=`).
 *
 * @return list<string>
 */
function exampleVariables(string $file): array
{
    preg_match_all('/^#\s*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents($file), $matches);

    return $matches[1];
}

/**
 * Cada arquivo do projeto (caminho relativo => conteúdo).
 *
 * @return array<string, string>
 */
function stackProjectFiles(string $root): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $files[substr((string) $file, strlen($root) + 1)] = (string) file_get_contents((string) $file);
    }

    ksort($files);

    return $files;
}

/**
 * @return array<string, mixed>
 */
function composeYaml(string $file): array
{
    $parsed = Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);

    expect($parsed)->toBeArray();

    return (array) $parsed;
}

afterEach(function (): void {
    foreach (STACK_VARIABLES as $variable) {
        putenv($variable);
    }

    foreach (glob(sys_get_temp_dir().'/kit-stack-*') ?: [] as $folder) {
        (new Filesystem)->deleteDirectory($folder);
    }
});

dataset('starters', ['livewire', 'react']);

it('o padrão (PostgreSQL com Redis) não muda nenhum arquivo', function (string $starter): void {
    $root = starterProject($starter);
    $before = stackProjectFiles($root);

    (new StackFiles($root))->apply(new ProjectStack);

    expect(stackProjectFiles($root))->toBe($before)
        ->and(is_file($root.'/'.StackFiles::SQLITE_FILE))->toBeFalse();
})->with('starters');

it('SQLite: o .env aponta para o arquivo, sem nada do PostgreSQL, e o compose de desenvolvimento sem o banco', function (string $starter): void {
    $root = starterProject($starter);

    (new StackFiles($root))->apply(new ProjectStack(ProjectStack::SQLITE));

    foreach (['.env', '.env.example'] as $file) {
        $variables = activeVariables($root.'/'.$file);

        expect($variables['DB_CONNECTION'] ?? null)->toBe('sqlite', $file);

        foreach (StackFiles::POSTGRES_VARIABLES as $variable) {
            expect($variables)->not->toHaveKey($variable, "{$file}: {$variable}")
                ->and(exampleVariables($root.'/'.$file))->not->toContain($variable);
        }

        // O aviso do modo protótipo junto da variável.
        expect((string) file_get_contents($root.'/'.$file))->toContain('MODO PROTÓTIPO: sem as travas por privilégio do PostgreSQL');
    }

    expect(is_file($root.'/'.StackFiles::SQLITE_FILE))->toBeTrue();

    $compose = composeYaml($root.'/compose.yaml');

    expect(array_keys($compose['services']))->not->toContain('postgres', 'db-init', 'db-port')
        ->toContain('app', 'init', 'queue', 'redis')
        ->and(array_keys($compose['volumes']))->not->toContain('postgres_data')
        ->and(array_keys($compose['services']['init']['depends_on']))->toBe(['redis']);

    // A produção continua no PostgreSQL (o instalador avisa).
    expect(array_keys(composeYaml($root.'/docker-compose.prod.yml')['services']))->toContain('postgres');
})->with('starters');

it('sem Redis: sessão, cache e fila no banco, nenhuma variável, serviço ou arquivo do Redis e do Horizon', function (string $starter): void {
    $root = starterProject($starter);

    (new StackFiles($root))->apply(new ProjectStack(ProjectStack::POSTGRES, redis: false));

    foreach (['.env', '.env.example', '.env.prod.example'] as $file) {
        $variables = [...array_keys(activeVariables($root.'/'.$file)), ...exampleVariables($root.'/'.$file)];

        expect(preg_grep('/^(PROD_)?(REDIS|HORIZON)_/', $variables))->toBe([], $file);
    }

    foreach (['.env', '.env.example'] as $file) {
        $variables = activeVariables($root.'/'.$file);

        expect([$variables['SESSION_DRIVER'], $variables['CACHE_STORE'], $variables['QUEUE_CONNECTION']])->toBe(['database', 'database', 'database'])
            ->and((string) file_get_contents($root.'/'.$file))->not->toContain('# --- Filas / Horizon')
            ->toContain('# --- Sessão / cache / filas (no banco de dados) ---');
    }

    $dev = composeYaml($root.'/compose.yaml');

    expect(array_keys($dev['services']))->not->toContain('redis')
        ->toContain('postgres', 'queue')
        ->and(array_keys($dev['volumes']))->toBe(['postgres_data'])
        ->and(array_keys($dev['services']['init']['depends_on']))->toBe(['postgres', 'db-init']);

    $production = composeYaml($root.'/docker-compose.prod.yml');

    expect(array_keys($production['services']))->not->toContain('redis', 'horizon')
        ->toContain('app', 'queue', 'scheduler', 'postgres')
        ->and($production['services']['queue']['command'])->toBe(StackFiles::QUEUE_COMMAND)
        ->and(array_keys($production['services']['queue']['depends_on']))->toBe(['app'])
        ->and(array_keys($production['services']['app']['depends_on']))->toBe(['postgres', 'migrate'])
        ->and(array_keys($production['volumes']))->not->toContain('redis_data')
        ->and($production['x-app-env'])->not->toHaveKeys(['REDIS_HOST', 'REDIS_PASSWORD', 'REDIS_PORT'])
        ->and([$production['x-app-env']['CACHE_STORE'], $production['x-app-env']['SESSION_DRIVER'], $production['x-app-env']['QUEUE_CONNECTION']])->toBe(['database', 'database', 'database'])
        ->and((string) file_get_contents($root.'/docker-compose.prod.yml'))->not->toContain('PROD_REDIS_PASSWORD')
        ->and((string) file_get_contents($root.'/.env.prod.example'))->not->toContain('PROD_REDIS_PASSWORD')
        ->toContain("PROD_DB_APP_PASSWORD e\n# PROD_DB_MAINTENANCE_PASSWORD são OBRIGATÓRIAS")
        ->toContain("PROD_DB_MAINTENANCE_PASSWORD=rst \\\n#     docker compose");

    foreach (StackFiles::HORIZON_FILES as $file) {
        expect(file_exists($root.'/'.$file))->toBeFalse($file);
    }

    expect((string) file_get_contents($root.'/bootstrap/providers.php'))->not->toContain('Horizon')
        ->toContain('AppServiceProvider::class');
})->with('starters');

it('produção, com ou sem Redis: só o migrate tem a senha do dono; o scheduler poda com o papel de manutenção (#65)', function (string $starter, bool $redis): void {
    $root = starterProject($starter);

    (new StackFiles($root))->apply(new ProjectStack(ProjectStack::POSTGRES, redis: $redis));

    $services = composeYaml($root.'/docker-compose.prod.yml')['services'];

    expect($services['scheduler']['environment'])->toHaveKeys(['DB_USERNAME', 'DB_PASSWORD', 'DB_MAINTENANCE_USERNAME', 'DB_MAINTENANCE_PASSWORD'])
        ->not->toHaveKey('DB_MIGRATION_USERNAME')
        ->not->toHaveKey('DB_MIGRATION_PASSWORD')
        ->and($services['migrate']['environment'])->toHaveKeys(['DB_MIGRATION_USERNAME', 'DB_MIGRATION_PASSWORD', 'DB_MAINTENANCE_USERNAME', 'DB_MAINTENANCE_PASSWORD']);

    foreach ($services as $name => $service) {
        if (! in_array($name, ['migrate', 'scheduler'], true)) {
            expect((array) ($service['environment'] ?? []))->not->toHaveKey('DB_MIGRATION_PASSWORD', $name)
                ->not->toHaveKey('DB_MAINTENANCE_PASSWORD', $name);
        }
    }

    expect((string) file_get_contents($root.'/.env.prod.example'))->toContain('PROD_DB_MAINTENANCE_PASSWORD');
})->with('starters')->with(['com Redis' => [true], 'sem Redis' => [false]]);

it('SQLite e sem Redis juntos: o compose de desenvolvimento fica só com os serviços do aplicativo', function (string $starter): void {
    $root = starterProject($starter);

    (new StackFiles($root))->apply(new ProjectStack(ProjectStack::SQLITE, redis: false));

    $dev = composeYaml($root.'/compose.yaml');

    expect(array_keys($dev['services']))->not->toContain('postgres', 'db-init', 'db-port', 'redis')
        ->and($dev)->not->toHaveKey('volumes')
        ->and($dev['services']['init'])->not->toHaveKey('depends_on')
        ->and((string) file_get_contents($root.'/compose.yaml'))->not->toContain('postgres_data')->not->toContain('redis_data');
})->with('starters');

it('aplicar de novo não muda nada (idempotente)', function (string $starter): void {
    $root = starterProject($starter);
    $stack = new ProjectStack(ProjectStack::SQLITE, redis: false);

    (new StackFiles($root))->apply($stack);
    $once = stackProjectFiles($root);
    (new StackFiles($root))->apply($stack);

    expect(stackProjectFiles($root))->toBe($once);
})->with('starters');

it('o compose: tirar um serviço leva junto os comentários dele, as dependências nele e o volume', function (): void {
    $file = sys_get_temp_dir().'/kit-stack-compose-'.bin2hex(random_bytes(4)).'.yaml';
    file_put_contents($file, implode("\n", [
        'services:',
        '    app:',
        '        image: php',
        '        depends_on:',
        '            cache:',
        '                condition: service_healthy',
        '            - cache',
        '',
        '    # O cache.',
        '    cache:',
        '        image: redis',
        '',
        '    web:',
        '        depends_on:',
        '            - cache',
        '',
        'volumes:',
        '    cache_data:',
        '    app_data:',
        '',
    ]));

    $compose = new ComposeFile($file);
    $compose->removeService('cache', 'cache_data')->save();

    expect((string) file_get_contents($file))->toBe(implode("\n", [
        'services:',
        '    app:',
        '        image: php',
        '',
        '    web:',
        '',
        'volumes:',
        '    app_data:',
        '',
    ]));

    unlink($file);
});

it('na criação, TWS_KIT_DATABASE=sqlite e TWS_KIT_REDIS=0: os arquivos adaptados e o Horizon removido pelo Composer', function (): void {
    Process::fake();
    file_put_contents($this->project.'/compose.yaml', "services:\n    app:\n        image: php\n    redis:\n        image: redis\n    postgres:\n        image: postgres\n");
    file_put_contents($this->project.'/.env.example', "APP_KEY=\nDB_CONNECTION=pgsql\nDB_HOST=postgres\nDB_DATABASE=tws\nSESSION_DRIVER=redis\nCACHE_STORE=redis\nQUEUE_CONNECTION=redis\nREDIS_HOST=redis\nREDIS_PASSWORD=null\n");
    $this->composerJson(['name' => 'twstec/starter-livewire', 'require' => ['twstec/kit-foundation' => '^2.0', StackFiles::HORIZON_PACKAGE => '^5.48']]);
    putenv('TWS_KIT_NAME=loja');
    putenv('TWS_KIT_DATABASE=sqlite');
    putenv('TWS_KIT_REDIS=0');

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain('SQLite (database/database.sqlite) — prototype mode')
        ->expectsOutputToContain('SQLite is prototype mode')
        ->assertSuccessful();

    $env = activeVariables($this->project.'/.env');

    expect($env['DB_CONNECTION'])->toBe('sqlite')
        ->and($env)->not->toHaveKeys(['DB_HOST', 'DB_DATABASE', 'DB_PASSWORD', 'DB_MIGRATION_PASSWORD', 'REDIS_HOST', 'REDIS_PASSWORD', 'DEV_DB_PORT'])
        ->and([$env['SESSION_DRIVER'], $env['CACHE_STORE'], $env['QUEUE_CONNECTION']])->toBe(['database', 'database', 'database'])
        ->and(is_file($this->project.'/'.StackFiles::SQLITE_FILE))->toBeTrue()
        ->and(composeYaml($this->project.'/compose.yaml')['services'])->toHaveKeys(['app'])->not->toHaveKeys(['redis', 'postgres'])
        ->and($this->composer->calls)->toContain(['remove', [StackFiles::HORIZON_PACKAGE], false]);
});

it('sem Redis, o código do Horizon sai ANTES do composer remove (o package:discover sobe a aplicação)', function (): void {
    Process::fake();
    file_put_contents($this->project.'/compose.yaml', "services:\n    app:\n        image: php\n");
    $this->composerJson(['name' => 'twstec/starter-livewire', 'require' => ['twstec/kit-foundation' => '^2.0', StackFiles::HORIZON_PACKAGE => '^5.48']]);
    @mkdir($this->project.'/app/Providers', 0755, true);
    @mkdir($this->project.'/bootstrap', 0755, true);
    file_put_contents($this->project.'/app/Providers/HorizonServiceProvider.php', "<?php\n");
    file_put_contents($this->project.'/bootstrap/providers.php', "<?php\n\nuse App\\Providers\\AppServiceProvider;\nuse App\\Providers\\HorizonServiceProvider;\n\nreturn [\n    AppServiceProvider::class,\n    HorizonServiceProvider::class,\n];\n");
    putenv('TWS_KIT_NAME=loja');

    $seen = null;
    $this->composer->onRemove = function (array $packages) use (&$seen): void {
        if ($packages === [StackFiles::HORIZON_PACKAGE]) {
            $seen = [
                is_file($this->project.'/app/Providers/HorizonServiceProvider.php'),
                (string) file_get_contents($this->project.'/bootstrap/providers.php'),
            ];
        }
    };

    $this->artisan('tws:install', ['--no-interaction' => true, '--no-redis' => true])->assertSuccessful();

    expect($seen)->not->toBeNull()
        ->and($seen[0])->toBeFalse()
        ->and($seen[1])->not->toContain('Horizon')->toContain('AppServiceProvider::class');
});

it('na criação, pelas opções --database=sqlite e --no-redis (e sinônimos do banco)', function (): void {
    Process::fake();
    file_put_contents($this->project.'/compose.yaml', "services:\n    app:\n        image: php\n");
    putenv('TWS_KIT_NAME=loja');

    $this->artisan('tws:install', ['--no-interaction' => true, '--database' => 'SQLite', '--no-redis' => true])->assertSuccessful();

    expect(activeVariables($this->project.'/.env')['DB_CONNECTION'] ?? null)->toBe('sqlite');
});

it('sem escolha, a criação fica com o PostgreSQL e o Redis (nada do Horizon é removido)', function (): void {
    Process::fake();
    file_put_contents($this->project.'/compose.yaml', "services:\n    app:\n        image: php\n");
    $this->composerJson(['name' => 'twstec/starter-livewire', 'require' => ['twstec/kit-foundation' => '^2.0', StackFiles::HORIZON_PACKAGE => '^5.48']]);
    putenv('TWS_KIT_NAME=loja');

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Redis, with Horizon')
        ->assertSuccessful();

    expect(activeVariables($this->project.'/.env')['DB_CONNECTION'] ?? null)->toBe('pgsql')
        ->and(is_file($this->project.'/'.StackFiles::SQLITE_FILE))->toBeFalse()
        ->and($this->composer->calls)->not->toContain(['remove', [StackFiles::HORIZON_PACKAGE], false]);
});

it('valor inválido: RECUSADO antes de mexer em qualquer coisa', function (array $variables, string $message): void {
    Process::fake();
    file_put_contents($this->project.'/compose.yaml', "services: {}\n");
    putenv('TWS_KIT_NAME=loja');

    foreach ($variables as $variable => $value) {
        putenv("{$variable}={$value}");
    }

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect($this->composer->calls)->toBe([])
        ->and(is_file($this->project.'/.env'))->toBeFalse()
        ->and($this->host->reserved)->toBe([]);
})->with([
    'banco desconhecido' => [['TWS_KIT_DATABASE' => 'mysql'], 'Unknown database: mysql (accepted: pgsql, sqlite).'],
    'Redis que não é sim nem não' => [['TWS_KIT_REDIS' => 'talvez'], 'Invalid value for TWS_KIT_REDIS: talvez (use 1 or 0).'],
]);

it('fora da criação (projeto que já existe), a escolha é ignorada com aviso e nada muda', function (): void {
    Process::fake();
    file_put_contents($this->project.'/.env.example', "APP_KEY=\nDB_CONNECTION=pgsql\nREDIS_HOST=redis\n");

    $this->artisan('tws:install', ['--no-interaction' => true, '--database' => 'sqlite', '--no-redis' => true])
        ->expectsOutputToContain('The database and Redis are only chosen when the project is created')
        ->assertSuccessful();

    expect(activeVariables($this->project.'/.env'))->toMatchArray(['DB_CONNECTION' => 'pgsql', 'REDIS_HOST' => 'redis'])
        ->and($this->composer->calls)->toBe([]);
});

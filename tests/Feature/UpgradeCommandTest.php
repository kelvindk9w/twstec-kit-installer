<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Composer as LaravelComposer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Twstec\Kit\Installer\Upgrade\ComposerStarterReference;
use Twstec\Kit\Installer\Upgrade\Manifest;
use Twstec\Kit\Installer\Upgrade\StarterFiles;

// =============================================================================
// A CONFERÊNCIA DOS ARQUIVOS DE STARTER (php artisan tws:upgrade --check): o
// projeto (a pasta descartável do TestCase) contra um starter de referência
// (outra pasta descartável, entregue pelo FakeStarterReference). O comando só
// lê; o único arquivo que ele escreve é o manifesto, com --record.
// =============================================================================

beforeEach(function (): void {
    $this->reference = sys_get_temp_dir().'/kit-starter-'.bin2hex(random_bytes(6));
    mkdir($this->reference);
    $this->references->folder = $this->reference;

    // Um starter React mínimo: o composer.json e o compose de desenvolvimento.
    $this->both = function (string $path, string $content): void {
        foreach ([$this->reference, $this->project] as $root) {
            @mkdir(dirname($root.'/'.$path), 0777, true);
            file_put_contents($root.'/'.$path, $content);
        }
    };
    $this->put = function (string $root, string $path, string $content): void {
        @mkdir(dirname($root.'/'.$path), 0777, true);
        file_put_contents($root.'/'.$path, $content);
    };
    // A base: o projeto e a referência como estão AGORA (como o tws:install
    // grava na criação).
    $this->baseline = fn () => Manifest::created('2.0.0-beta.19', 'react', $this->project, Manifest::snapshot($this->reference))->write($this->project);

    ($this->both)('composer.json', "{\"name\": \"twstec/starter-react\"}\n");
    ($this->both)('compose.yaml', "services: {}\n");
    ($this->both)('resources/js/app.tsx', "// app\n");
    // Fora da conta: o .env.example do projeto tem a identidade dele, mas o
    // .env, o lock e o README não se comparam.
    ($this->put)($this->reference, 'README.md', "# Starter\n");
    ($this->put)($this->project, 'README.md', "# Meu projeto\n");
    ($this->put)($this->project, 'composer.lock', "{}\n");
    // O composer.json é do projeto desde a criação (nome, licença, módulos).
    ($this->put)($this->project, 'composer.json', "{\"name\": \"app/loja\"}\n");
    ($this->put)($this->reference, 'vendor/x/y.php', "<?php\n");
    // Assets que os pacotes publicam em public/ (livewire:publish,
    // filament:assets) são gerados no projeto, não vêm do starter.
    ($this->put)($this->project, 'public/vendor/livewire/livewire.js', "// gerado\n");
    ($this->put)($this->project, 'public/js/filament/app.js', "// gerado\n");
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->reference);
});

/**
 * Roda o tws:upgrade e devolve [código de saída, saída] (várias linhas da
 * saída conferidas de uma vez — o expectsOutputToContain casa uma expectativa
 * por escrita).
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function upgrade(array $options = []): array
{
    $code = Artisan::call('tws:upgrade', $options);

    return [$code, Artisan::output()];
}

/**
 * A linha do arquivo na lista, com o estado dele.
 */
function line(string $path, string $status): string
{
    return '/'.preg_quote($path, '/').' \\.+ '.preg_quote(__('installer.upgrade.status.'.$status), '/').'/';
}

/**
 * Os arquivos do projeto (caminho => conteúdo), para provar que a conferência
 * não mexeu em nada.
 *
 * @return array<string, string>
 */
function projectFiles(string $root): array
{
    $files = [];

    foreach ((new Filesystem)->allFiles($root, true) as $file) {
        $files[$file->getRelativePathname()] = $file->getContents();
    }

    ksort($files);

    return $files;
}

it('tudo igual à referência: nada a conferir, sai 0 e não escreve nada', function (): void {
    $before = projectFiles($this->project);

    [$code, $output] = upgrade(['--check' => true]);
    expect($code)->toBe(0)
        ->and($output)->toContain('2.0.0-beta.20')
        ->and($output)->toContain(__('installer.upgrade.up_to_date'));

    expect(projectFiles($this->project))->toBe($before)
        ->and($this->references->requests)->toBe([['react', '2.0.0-beta.20']]);
});

it('sem manifesto: o que difere sai como "difere" e o que falta como "novo no kit" — sai 1 sem mexer em nada', function (): void {
    ($this->put)($this->reference, 'compose.yaml', "services: {app: {}}\n");
    ($this->put)($this->reference, 'scripts/verificar', "#!/bin/sh\n");
    $before = projectFiles($this->project);

    [$code, $output] = upgrade([]);
    expect($code)->toBe(1)
        ->and($output)->toContain(__('installer.upgrade.no_manifest', ['file' => Manifest::PATH]))
        ->and($output)->toMatch(line('compose.yaml', 'differs'))
        ->and($output)->toMatch(line('scripts/verificar', 'new'))
        ->and($output)->toContain(__('installer.upgrade.nothing_changed'))
        ->and($output)->not->toContain('README.md')
        ->and($output)->not->toContain('composer.lock')
        ->and($output)->not->toContain('composer.json')
        ->and($output)->not->toContain('vendor/');

    expect(projectFiles($this->project))->toBe($before);
});

it('com manifesto: o kit mudou e o projeto não mexeu — "desatualizado, pode substituir"', function (): void {
    ($this->baseline)();
    ($this->put)($this->reference, 'compose.yaml', "services: {app: {}}\n");

    [$code, $output] = upgrade(['--check' => true]);
    expect($code)->toBe(1)
        ->and($output)->toContain(__('installer.upgrade.base', ['version' => '2.0.0-beta.19', 'file' => Manifest::PATH]))
        ->and($output)->toMatch(line('compose.yaml', 'outdated'))
        ->and($output)->not->toContain(__('installer.upgrade.status.customized'));
});

it('com manifesto: o kit mudou e o do projeto é outro — "personalizado, comparar à mão"', function (): void {
    ($this->baseline)();
    ($this->put)($this->project, 'compose.yaml', "services: {meu: {}}\n");
    ($this->put)($this->reference, 'compose.yaml', "services: {app: {}}\n");

    [$code, $output] = upgrade(['--check' => true]);
    expect($code)->toBe(1)
        ->and($output)->toContain(__('installer.upgrade.status.customized'))
        ->and($output)->not->toContain(__('installer.upgrade.status.outdated'));
});

it('adaptado pelo instalador na criação (base do projeto ≠ base do starter) e o kit mudou: "personalizado"', function (): void {
    ($this->put)($this->project, 'compose.yaml', "services: {}\n# loja\n");
    ($this->baseline)();
    ($this->put)($this->reference, 'compose.yaml', "services: {app: {}}\n");

    [$code, $output] = upgrade(['--check' => true]);
    expect($code)->toBe(1)
        ->and($output)->toContain(__('installer.upgrade.status.customized'));
});

it('com manifesto: personalizado, mas o kit não mudou o arquivo — fica calado, sai 0', function (): void {
    ($this->baseline)();
    ($this->put)($this->project, 'compose.yaml', "services: {meu: {}}\n");

    [$code, $output] = upgrade(['--check' => true]);
    expect($code)->toBe(0)
        ->and($output)->toContain(__('installer.upgrade.up_to_date'));
});

it('com manifesto: o kit tirou um arquivo que o projeto tem — "o kit deixou de trazer"', function (): void {
    ($this->both)('scripts/antigo', "#!/bin/sh\n");
    ($this->baseline)();
    unlink($this->reference.'/scripts/antigo');

    [$code, $output] = upgrade(['--check' => true]);
    expect($code)->toBe(1)
        ->and($output)->toMatch(line('scripts/antigo', 'removed'));
});

it('com manifesto: arquivo que o projeto já não tinha na base (módulo de fora) fica calado mesmo que o kit mude', function (): void {
    ($this->put)($this->reference, 'tests/Feature/Uploads/UploadTest.php', "<?php // 1\n");
    ($this->baseline)();
    ($this->put)($this->reference, 'tests/Feature/Uploads/UploadTest.php', "<?php // 2\n");

    [$code, $output] = upgrade(['--check' => true]);
    expect($code)->toBe(0)
        ->and($output)->not->toContain('UploadTest.php');
});

it('assets publicados em public/ (Livewire, Filament) ficam fora da conta, com ou sem base', function (): void {
    [$code, $output] = upgrade();

    expect($code)->toBe(0)
        ->and($output)->not->toContain('public/');

    // A base gravada na criação também não os conhece.
    Manifest::created('2.0.0-beta.19', 'react', $this->project, Manifest::snapshot($this->project))->write($this->project);

    expect(Manifest::read($this->project)?->files)->toHaveKey('compose.yaml')
        ->and(Manifest::read($this->project)?->files)->not->toHaveKey('public/vendor/livewire/livewire.js');
});

it('o CI base do starter (docker/dev/ci.yml) é comparado com o .github/workflows/ci.yml do projeto', function (): void {
    ($this->put)($this->reference, 'docker/dev/ci.yml', "name: CI\n");
    ($this->put)($this->project, '.github/workflows/ci.yml', "name: CI\n");

    expect(StarterFiles::in($this->reference))->toHaveKey('.github/workflows/ci.yml', 'docker/dev/ci.yml')
        ->not->toHaveKey('docker/dev/ci.yml');

    [$code, $output] = upgrade([]);
    expect($code)->toBe(0);

    ($this->put)($this->reference, 'docker/dev/ci.yml', "name: CI\non: push\n");

    [$code, $output] = upgrade([]);
    expect($code)->toBe(1)
        ->and($output)->toContain('.github/workflows/ci.yml');
});

it('o export-ignore do starter (só do monorepo) não entra na conta', function (): void {
    ($this->put)($this->reference, '.gitattributes', "* text=auto eol=lf\n/tests/e2e/smoke.spec.js export-ignore\n");
    ($this->put)($this->project, '.gitattributes', "* text=auto eol=lf\n/tests/e2e/smoke.spec.js export-ignore\n");
    ($this->put)($this->reference, 'tests/e2e/smoke.spec.js', "// só do monorepo\n");

    [$code, $output] = upgrade([]);
    expect($code)->toBe(0)
        ->and($output)->not->toContain('smoke.spec.js');
});

it('--record grava a base nova no manifesto (e só ele): a conferência seguinte fica em dia', function (): void {
    ($this->put)($this->project, 'compose.yaml', "services: {meu: {}}\n");
    $before = projectFiles($this->project);

    [$code, $output] = upgrade([]);
    expect($code)->toBe(1);

    [$code, $output] = upgrade(['--record' => true]);
    expect($code)->toBe(0)
        ->and($output)->toContain(__('installer.upgrade.recorded', ['file' => Manifest::PATH]));

    $after = projectFiles($this->project);
    $manifest = Manifest::read($this->project);

    expect(array_diff_key($after, [Manifest::PATH => true]))->toBe($before)
        ->and($manifest?->kit)->toBe('2.0.0-beta.20')
        ->and($manifest?->starter)->toBe('react')
        ->and($manifest?->files['compose.yaml'])->toBe([
            'reference' => hash('sha256', "services: {}\n"),
            'project' => hash('sha256', "services: {meu: {}}\n"),
        ]);

    [$code, $output] = upgrade([]);
    expect($code)->toBe(0);
});

it('--diff mostra a diferença de cada arquivo pelo git', function (): void {
    ($this->put)($this->reference, 'compose.yaml', "services: {app: {}}\n");
    Process::fake(fn () => Process::result(output: "-services: {app: {}}\n+services: {}\n", exitCode: 1));

    [$code, $output] = upgrade(['--diff' => true]);
    expect($code)->toBe(1)
        ->and($output)->toContain('-services: {app: {}}');

    Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
        && array_slice($process->command, 0, 3) === ['git', 'diff', '--no-index']
        && end($process->command) === $this->project.'/compose.yaml');
});

it('--from usa a pasta dada como referência, sem baixar nada', function (): void {
    [$code, $output] = upgrade(['--from' => $this->reference]);
    expect($code)->toBe(0)
        ->and($output)->toContain(__('installer.upgrade.from_folder'));

    expect($this->references->requests)->toBe([]);

    [$code, $output] = upgrade(['--from' => $this->reference.'/nada']);
    expect($code)->toBe(2)
        ->and($output)->toContain(__('installer.upgrade.from_invalid', ['folder' => $this->reference.'/nada']));
});

it('fora de uma versão publicada (monorepo, branch) e sem --from: sai 2 explicando', function (): void {
    $this->references->installed = null;

    [$code, $output] = upgrade([]);
    expect($code)->toBe(2)
        ->and($output)->toContain(__('installer.upgrade.version_unknown'));
});

it('sem conseguir a referência (Composer sem rede): sai 2 com o motivo', function (): void {
    $this->references->failure = 'sem rede';

    [$code, $output] = upgrade([]);
    expect($code)->toBe(2)
        ->and($output)->toContain('sem rede');
});

it('a interface vem do manifesto, de --starter ou do projeto — sem nenhum, sai 2', function (): void {
    unlink($this->project.'/resources/js/app.tsx');

    [$code, $output] = upgrade([]);
    expect($code)->toBe(2)
        ->and($output)->toContain(__('installer.upgrade.starter_unknown'));

    [$code, $output] = upgrade(['--starter' => 'livewire']);
    expect($code)->toBe(1);
    expect(end($this->references->requests))->toBe(['livewire', '2.0.0-beta.20']);
});

it('o starter de referência de verdade vem do Composer (create-project sem instalar nem rodar script) e fica guardado', function (): void {
    $cache = $this->reference.'/cache';
    $runs = 0;

    Process::fake(function (PendingProcess $process) use (&$runs) {
        $runs++;
        $command = (array) $process->command;
        $folder = $command[array_search('create-project', $command, true) + 2];
        mkdir($folder, 0777, true);
        file_put_contents($folder.'/composer.json', '{}');

        return Process::result();
    });

    $reference = new ComposerStarterReference($this->app->make(LaravelComposer::class), $cache);

    expect($reference->directory('react', '2.0.0-beta.20'))->toBe($cache.'/react-2.0.0-beta.20')
        ->and($reference->directory('react', '2.0.0-beta.20'))->toBe($cache.'/react-2.0.0-beta.20')
        ->and($runs)->toBe(1);

    Process::assertRan(function (PendingProcess $process): bool {
        $command = (array) $process->command;
        $at = array_search('create-project', $command, true);

        return $at !== false
            && $command[$at + 1] === 'twstec/starter-react'
            && $command[$at + 3] === '2.0.0-beta.20'
            && array_values(array_intersect(['--no-install', '--no-scripts', '--no-plugins', '--no-interaction'], $command)) === ['--no-install', '--no-scripts', '--no-plugins', '--no-interaction'];
    });
});

it('o Composer falhando: nada fica pela metade e a mensagem traz a saída dele', function (): void {
    Process::fake(fn () => Process::result(errorOutput: 'Could not find package', exitCode: 1));
    $cache = $this->reference.'/cache';

    expect(fn () => (new ComposerStarterReference($this->app->make(LaravelComposer::class), $cache))->directory('livewire', '2.0.0-beta.20'))
        ->toThrow(RuntimeException::class, 'Could not find package');

    expect(is_dir($cache.'/livewire-2.0.0-beta.20'))->toBeFalse()
        ->and(is_dir($cache.'/livewire-2.0.0-beta.20.partial'))->toBeFalse();
});

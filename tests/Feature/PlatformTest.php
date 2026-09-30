<?php

declare(strict_types=1);

use Closure as BaseClosure;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Composer as LaravelComposer;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\OutputInterface;
use Twstec\Kit\Installer\Support\ProcessComposer;

// =============================================================================
// AS EXTENSÕES DO PHP no tws:install e no tws:add: no Windows, o Composer
// ignora SÓ ext-pcntl e ext-posix (o Horizon); o que a pessoa pediu
// (COMPOSER_IGNORE_PLATFORM_REQ) vale; e uma extensão que falte faz o comando
// parar com a lista e as duas saídas (instalar, ou o caminho só com o Docker).
// TWS_KIT_OS_FAMILY=Windows simula o Windows.
// =============================================================================

const INSTALLER_MISSING_OUTPUT = "  - twstec/kit-uploads v2.0.0-beta.2 requires ext-gd * -> it is missing from your system. Install or enable PHP's gd extension.\n";

afterEach(function (): void {
    putenv('TWS_KIT_OS_FAMILY');
    putenv('COMPOSER_IGNORE_PLATFORM_REQ');
    putenv('TWS_KIT_FROM_KIT');
    unset($_ENV['COMPOSER_IGNORE_PLATFORM_REQ']);
});

/**
 * O helper do Laravel de mentira: anota o que o processo do Composer
 * receberia no ambiente (o getenv() e o $_ENV, de onde o Process herda).
 */
function recordingComposer(array &$seen): LaravelComposer
{
    return new class(new Filesystem, $seen) extends LaravelComposer
    {
        public function __construct(Filesystem $files, private array &$seen)
        {
            parent::__construct($files);
        }

        public function requirePackages(array $packages, bool $dev = false, BaseClosure|OutputInterface|null $output = null, $composerBinary = null)
        {
            $this->seen[] = [getenv('COMPOSER_IGNORE_PLATFORM_REQ'), $_ENV['COMPOSER_IGNORE_PLATFORM_REQ'] ?? null];

            return true;
        }
    };
}

it('Windows: o Composer ignora só ext-pcntl e ext-posix — e o ambiente volta ao que era', function (): void {
    putenv('TWS_KIT_OS_FAMILY=Windows');
    $seen = [];

    (new ProcessComposer(recordingComposer($seen)))->require(['twstec/kit-accounts'], false, fn () => null);

    expect($seen)->toBe([['ext-pcntl,ext-posix', 'ext-pcntl,ext-posix']])
        ->and(getenv('COMPOSER_IGNORE_PLATFORM_REQ'))->toBeFalse()
        ->and($_ENV)->not->toHaveKey('COMPOSER_IGNORE_PLATFORM_REQ');
});

it('fora do Windows, nada é ignorado sem pedido; o pedido (COMPOSER_IGNORE_PLATFORM_REQ) vale, somado ao do Windows', function (): void {
    $seen = [];
    $composer = new ProcessComposer(recordingComposer($seen));

    putenv('TWS_KIT_OS_FAMILY=Linux');
    $composer->require(['x/y'], false, fn () => null);

    putenv('COMPOSER_IGNORE_PLATFORM_REQ=ext-intl');
    $composer->require(['x/y'], false, fn () => null);

    putenv('TWS_KIT_OS_FAMILY=Windows');
    $composer->require(['x/y'], false, fn () => null);

    expect(array_column($seen, 1))->toBe([null, 'ext-intl', 'ext-intl,ext-pcntl,ext-posix'])
        // O que a pessoa tinha continua lá.
        ->and(getenv('COMPOSER_IGNORE_PLATFORM_REQ'))->toBe('ext-intl');
});

it('tws:add: extensão que falta — a lista e as duas saídas', function (): void {
    Process::fake();
    $this->composer->fails = true;
    $this->composer->failureOutput = INSTALLER_MISSING_OUTPUT;
    $this->project([], required: []);

    $this->artisan('tws:add', ['modules' => ['auth']])
        ->expectsOutputToContain('PHP extensions missing on this machine: gd.')
        ->expectsOutputToContain('sudo apt install php8.4-gd')
        ->expectsOutputToContain('docker compose run --rm instalar')
        ->assertFailed();
});

it('tws:install: extensão que falta — a lista e as duas saídas', function (): void {
    Process::fake();
    $this->composer->fails = true;
    $this->composer->failureOutput = INSTALLER_MISSING_OUTPUT;
    $this->project(['accounts', 'admin']);

    $this->artisan('tws:install', ['--with' => 'uploads'])
        ->expectsOutputToContain('PHP extensions missing on this machine: gd.')
        ->assertFailed();
});

it('tws:install no Windows avisa do Horizon — salvo quando quem chamou foi o twstec/kit (ele avisa)', function (): void {
    Process::fake(fn (PendingProcess $process) => Process::result());
    putenv('TWS_KIT_OS_FAMILY=Windows');

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Windows: Horizon (the queue dashboard) needs the pcntl and posix extensions')
        ->assertSuccessful();

    putenv('TWS_KIT_FROM_KIT=1');

    $this->artisan('tws:install', ['--no-interaction' => true])
        ->doesntExpectOutputToContain('Horizon (the queue dashboard)')
        ->assertSuccessful();
});

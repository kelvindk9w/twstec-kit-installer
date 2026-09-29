<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// =============================================================================
// ARQUITETURA DO PACOTE installer — usa só o foundation (a detecção dos
// módulos, Kit), o Laravel e o Laravel Prompts. Não nomeia o aplicativo, nem
// os módulos que ele instala e tira (conhece-os pelo NOME DO PACOTE, via Kit),
// nem a demonstração (twstec/kit-demo só como nome de pacote).
// =============================================================================

const INSTALLER_ALLOWED_ROOTS = [
    'Twstec\\Kit\\Installer\\',
    'Twstec\\Kit\\Foundation\\',
    'Illuminate\\',
    'Laravel\\Prompts\\',
    'Symfony\\Component\\Console\\',
    'Composer\\InstalledVersions',
];

it('só usa o foundation, o Laravel e o Laravel Prompts', function (): void {
    $violations = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src')->name('*.php') as $file) {
        foreach (PhpToken::tokenize($file->getContents()) as $token) {
            if (! $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            $name = ltrim($token->text, '\\');
            // O próprio namespace (`namespace Twstec\Kit\Installer;`) e nomes
            // relativos a ele (Support\InstallPlan…) são do pacote.
            $allowed = $name === 'Twstec\\Kit\\Installer'
                || (! str_starts_with($token->text, '\\') && is_dir(dirname(__DIR__, 2).'/src/'.explode('\\', $name)[0]));

            foreach (INSTALLER_ALLOWED_ROOTS as $root) {
                $allowed = $allowed || str_starts_with($name, $root);
            }

            if (! $allowed) {
                $violations[] = $file->getFilename().' usa '.$name;
            }
        }
    }

    expect(array_values(array_unique($violations)))->toBe([]);
});

it('não nomeia o aplicativo nem a demonstração pelo namespace', function (): void {
    foreach ((new Finder)->files()->in([dirname(__DIR__, 2).'/src', dirname(__DIR__, 2).'/lang'])->name('*.php') as $file) {
        expect($file->getContents())->not->toMatch('/\bApp\\\\|Twstec\\\\+Kit\\\\+Demo/');
    }
});

it('declara no composer.json só o foundation, o Laravel e o Prompts', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect(array_keys($composer['require']))->toBe(['php', 'laravel/framework', 'laravel/prompts', 'twstec/kit-foundation']);
});

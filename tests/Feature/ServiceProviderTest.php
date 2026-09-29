<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Twstec\Kit\Installer\Tests\TestCase;

it('é descoberto pelo Laravel com o provider que a suíte registra e traz o tws:install', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toBe(TestCase::PACKAGE_PROVIDERS)
        ->and($composer['name'])->toBe('twstec/kit-installer')
        ->and(Artisan::all())->toHaveKey('tws:install');
});

it('tem os textos nos três idiomas, com as mesmas chaves — e o aplicativo vence', function (): void {
    $keys = function (string $locale): array {
        $flatten = function (array $values, string $prefix = '') use (&$flatten): array {
            $out = [];

            foreach ($values as $key => $value) {
                $out = [...$out, ...(is_array($value) ? $flatten($value, "{$prefix}{$key}.") : ["{$prefix}{$key}"])];
            }

            return $out;
        };

        return $flatten(require dirname(__DIR__, 2)."/lang/{$locale}/installer.php");
    };

    expect($keys('en'))->toBe($keys('pt_BR'))->toBe($keys('es'));

    app()->setLocale('pt_BR');
    expect(__('installer.confirm_apply'))->toBe('Aplicar estas mudanças?');

    app('translator')->addLines(['installer.confirm_apply' => 'Do aplicativo'], 'pt_BR');
    expect(__('installer.confirm_apply'))->toBe('Do aplicativo');
});

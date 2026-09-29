<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Support;

/**
 * A restrição de versão dos pacotes do kit NESTE projeto, lida do
 * composer.json dele: a do foundation em `require`; senão, a de qualquer
 * pacote do kit em `require` e depois em `require-dev` (um aplicativo
 * Laravel que só instalou o instalador — `composer require --dev
 * "twstec/kit-installer:^2.0@beta"` — tem o foundation só como dependência
 * dele). Sem nenhum: `^2.0`.
 *
 * Exemplos: `2.x-dev` no monorepo; `^2.0@beta` num projeto criado durante o
 * beta (a estabilidade vai junto, porque só vale no projeto raiz).
 */
final class KitConstraint
{
    public const FALLBACK = '^2.0';

    public const FOUNDATION = 'twstec/kit-foundation';

    public static function for(string $composerJson): string
    {
        $composer = json_decode((string) @file_get_contents($composerJson), true);

        if (! is_array($composer)) {
            return self::FALLBACK;
        }

        $candidates = [
            $composer['require'][self::FOUNDATION] ?? null,
            ...self::kitConstraints($composer['require'] ?? []),
            ...self::kitConstraints($composer['require-dev'] ?? []),
        ];

        foreach ($candidates as $constraint) {
            if (is_string($constraint) && $constraint !== '') {
                return $constraint;
            }
        }

        return self::FALLBACK;
    }

    /**
     * Os pacotes do kit que o projeto requer DIRETAMENTE (em `require`).
     *
     * @return list<string>
     */
    public static function directRequirements(string $composerJson): array
    {
        $composer = json_decode((string) @file_get_contents($composerJson), true);
        $require = is_array($composer) && is_array($composer['require'] ?? null) ? $composer['require'] : [];

        return array_values(array_filter(array_keys($require), static fn (string $package): bool => str_starts_with($package, 'twstec/kit-')));
    }

    /**
     * @return list<mixed>
     */
    private static function kitConstraints(mixed $section): array
    {
        if (! is_array($section)) {
            return [];
        }

        $found = [];

        foreach ($section as $package => $constraint) {
            if (is_string($package) && str_starts_with($package, 'twstec/kit-')) {
                $found[] = $constraint;
            }
        }

        return $found;
    }
}

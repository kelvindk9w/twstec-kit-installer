<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Upgrade;

/**
 * A CONFERÊNCIA dos arquivos de starter do projeto contra os do starter de
 * referência. Só lê: não escreve nada em lugar nenhum.
 *
 * Com o manifesto (Manifest), o que o kit NÃO mudou desde a base fica calado,
 * mesmo que o projeto tenha personalizado; o que o kit mudou vira
 * "desatualizado" (o projeto não mexeu desde a base e o arquivo era o do kit:
 * pode substituir) ou "personalizado" (comparar à mão). Arquivo que o projeto
 * já não tinha na base (módulo de fora, apagado de propósito) fica calado.
 *
 * Sem manifesto, só dá para dizer "difere" ou "novo no kit".
 */
final class UpgradeCheck
{
    /**
     * @return list<array{path: string, reference: ?string, status: FileStatus}> caminho no projeto, caminho na referência
     */
    public static function compare(string $project, string $referenceRoot, ?Manifest $manifest): array
    {
        $reference = StarterFiles::in($referenceRoot);
        $paths = array_unique([...array_keys($reference), ...array_keys($manifest === null ? [] : $manifest->files)]);
        sort($paths, SORT_STRING);
        $differences = [];

        foreach ($paths as $path) {
            $referencePath = $reference[$path] ?? null;
            $current = $referencePath === null ? null : StarterFiles::hash($referenceRoot, $referencePath);
            $mine = StarterFiles::hash($project, $path);

            if ($current === $mine) {
                continue;
            }

            $status = self::status($current, $mine, $manifest?->files[$path] ?? null, $manifest !== null);

            if ($status !== null) {
                $differences[] = ['path' => $path, 'reference' => $referencePath, 'status' => $status];
            }
        }

        return $differences;
    }

    /**
     * @param  array{reference: ?string, project: ?string}|null  $base
     */
    private static function status(?string $current, ?string $mine, ?array $base, bool $hasManifest): ?FileStatus
    {
        if (! $hasManifest || $base === null) {
            if ($current === null) {
                return null;
            }

            return $mine === null ? FileStatus::New : FileStatus::Differs;
        }

        if ($current === $base['reference']) {
            return null;
        }

        if ($current === null) {
            return FileStatus::Removed;
        }

        if ($mine === null) {
            // Não existia no projeto na base (módulo de fora, apagado de
            // propósito): nada a fazer. Existia e foi apagado depois: decisão
            // do projeto — ainda assim, o kit mudou.
            if ($base['project'] === null) {
                return $base['reference'] === null ? FileStatus::New : null;
            }

            return FileStatus::Customized;
        }

        return $mine === $base['project'] && $base['project'] === $base['reference']
            ? FileStatus::Outdated
            : FileStatus::Customized;
    }
}

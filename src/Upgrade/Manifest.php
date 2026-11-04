<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Upgrade;

use JsonException;

/**
 * O MANIFESTO DO STARTER (`.tws/manifest.json`, versionado com o projeto): a
 * base da última vez em que o projeto e o starter estavam combinados — na
 * criação (o tws:install grava) ou depois de uma atualização conferida à mão
 * (`tws:upgrade --record`).
 *
 * Para cada arquivo de starter, pelo caminho no projeto: o hash do arquivo do
 * STARTER naquela versão (`reference`) e o hash do arquivo do PROJETO naquele
 * momento (`project`) — diferentes quando o instalador o adaptou na criação
 * (nome, banco, identidade). Null quando o arquivo não existia.
 *
 * Com ele, a conferência separa "o kit mudou e você não mexeu" (pode
 * substituir) de "o kit mudou e o seu é diferente" (comparar à mão), e cala o
 * que o kit não mudou.
 */
final class Manifest
{
    public const PATH = '.tws/manifest.json';

    /**
     * @param  array<string, array{reference: ?string, project: ?string}>  $files
     */
    public function __construct(
        public readonly string $kit,
        public readonly string $starter,
        public readonly array $files,
    ) {}

    public static function read(string $root): ?self
    {
        $raw = @file_get_contents(rtrim($root, '/').'/'.self::PATH);

        if ($raw === false) {
            return null;
        }

        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($data) || ! is_string($data['kit'] ?? null) || ! is_string($data['starter'] ?? null) || ! is_array($data['files'] ?? null)) {
            return null;
        }

        $files = [];

        foreach ($data['files'] as $path => $hashes) {
            if (! is_string($path) || ! is_array($hashes)) {
                continue;
            }

            $files[$path] = [
                'reference' => is_string($hashes['reference'] ?? null) ? $hashes['reference'] : null,
                'project' => is_string($hashes['project'] ?? null) ? $hashes['project'] : null,
            ];
        }

        return new self($data['kit'], $data['starter'], $files);
    }

    /**
     * A base de agora: cada arquivo de starter da referência (e os que o
     * manifesto anterior já conhecia), com o hash da referência e o do
     * projeto. Arquivo que não existe nos dois lados fica de fora.
     *
     * @param  array<string, string>  $reference  caminho no projeto => caminho na referência (StarterFiles::in)
     */
    public static function capture(string $kit, string $starter, string $project, string $referenceRoot, array $reference, ?self $previous = null): self
    {
        $files = [];
        $paths = array_unique([...array_keys($reference), ...array_keys($previous === null ? [] : $previous->files)]);
        sort($paths, SORT_STRING);

        foreach ($paths as $path) {
            $hashes = [
                'reference' => isset($reference[$path]) ? StarterFiles::hash($referenceRoot, $reference[$path]) : null,
                'project' => StarterFiles::hash($project, $path),
            ];

            if ($hashes['reference'] !== null || $hashes['project'] !== null) {
                $files[$path] = $hashes;
            }
        }

        return new self($kit, $starter, $files);
    }

    /**
     * Os hashes dos arquivos de starter de uma pasta AGORA, pelo caminho no
     * projeto — na criação, antes de o instalador adaptar o projeto, são os
     * do starter publicado (a referência da base).
     *
     * @return array<string, string>
     */
    public static function snapshot(string $root): array
    {
        $hashes = [];

        foreach (StarterFiles::in($root) as $path => $starterPath) {
            $hash = StarterFiles::hash($root, $starterPath);

            if ($hash !== null) {
                $hashes[$path] = $hash;
            }
        }

        return $hashes;
    }

    /**
     * A base da CRIAÇÃO: os hashes do starter (snapshot de antes da adaptação)
     * e os do projeto como o instalador o deixou.
     *
     * @param  array<string, string>  $starter  Manifest::snapshot de antes
     */
    public static function created(string $kit, string $starterName, string $project, array $starter): self
    {
        $files = [];

        foreach ($starter as $path => $hash) {
            $files[$path] = ['reference' => $hash, 'project' => StarterFiles::hash($project, $path)];
        }

        return new self($kit, $starterName, $files);
    }

    public function write(string $root): void
    {
        $file = rtrim($root, '/').'/'.self::PATH;

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }

        file_put_contents($file, json_encode([
            '_readme' => 'Base da conferência dos arquivos de starter (php artisan tws:upgrade --check). Gravado pelo tws:install e pelo tws:upgrade --record; versione junto com o projeto.',
            'kit' => $this->kit,
            'starter' => $this->starter,
            'files' => $this->files,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }
}

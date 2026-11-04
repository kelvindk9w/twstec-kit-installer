<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Upgrade;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Twstec\Kit\Installer\Support\CreatedProject;

/**
 * Os ARQUIVOS DE STARTER que ficam no projeto (Docker, CI, `.env*`, scripts,
 * configuração do Vite e do TypeScript, testes, rotas, providers...): tudo o
 * que o starter publicado traz, menos o que não se compara.
 *
 * Fora da conta:
 * - o que é gerado ou baixado (vendor, node_modules, storage, cache do
 *   bootstrap, build do front, os assets que os pacotes publicam em public/ —
 *   Livewire, Filament —, relatórios de teste) e os lockfiles — quem os
 *   atualiza é o Composer e o npm, não a cópia à mão;
 * - o composer.json: é do projeto desde a criação (nome, licença, os módulos
 *   escolhidos — o comando único o reescreve ANTES do instalador, então nem a
 *   base dele seria a do starter); as dependências do kit sobem pelo
 *   `composer update`, e mudança de script ou autoload vem no CHANGELOG;
 * - o que é segredo ou do projeto por natureza (.env, bancos SQLite, logs);
 * - os textos que o projeto reescreve (README, SECURITY, a licença do kit);
 * - o manifesto (.tws/) e o que o `.gitattributes` do starter marca como
 *   `export-ignore` (só existe no monorepo; o pacote publicado não traz).
 *
 * O instalador muda dois arquivos DE LUGAR na criação (CreatedProject): o CI
 * base (docker/dev/ci.yml → .github/workflows/ci.yml) e o Dependabot modelo.
 * A comparação é sempre pelo caminho do PROJETO.
 */
final class StarterFiles
{
    /**
     * Pastas que nunca entram (nem se desce nelas).
     *
     * @var list<string>
     */
    public const EXCLUDED_DIRECTORIES = [
        '.git', '.tws', 'vendor', 'node_modules', 'storage', 'bootstrap/cache', 'public/build', 'public/vendor',
        'public/js/filament', 'public/css/filament', 'public/fonts/filament',
        'test-results', 'playwright-report', 'blob-report', '.phpunit.cache', 'tests/e2e/.auth',
    ];

    /**
     * Arquivos (caminho exato, a partir da raiz) que nunca entram.
     *
     * @var list<string>
     */
    public const EXCLUDED_FILES = [
        '.env', '.env.backup', '.env.testing', 'composer.json', 'composer.lock', 'package-lock.json', 'README.md', 'SECURITY.md',
        'LICENSE', CreatedProject::NOTICE_FILE, '.phpunit.result.cache', 'public/hot',
    ];

    /**
     * Padrões de nome (em qualquer pasta) que nunca entram.
     *
     * @var list<string>
     */
    public const EXCLUDED_PATTERNS = ['*.sqlite', '*.sqlite-journal', '*.log', '.DS_Store'];

    /**
     * Caminho no starter => caminho no projeto criado.
     *
     * @var array<string, string>
     */
    public const RELOCATED = [
        CreatedProject::CI_TEMPLATE => CreatedProject::CI_WORKFLOW,
        CreatedProject::DEPENDABOT_TEMPLATE => CreatedProject::DEPENDABOT_CONFIG,
    ];

    /**
     * Os arquivos de starter numa pasta (o starter de referência ou o projeto
     * na hora da criação), pelo caminho no PROJETO => o caminho na pasta.
     *
     * @return array<string, string>
     */
    public static function in(string $root): array
    {
        $root = rtrim($root, '/');
        $ignored = self::exportIgnored($root);
        $files = [];
        $relocated = [];

        if (! is_dir($root)) {
            return [];
        }

        $directories = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS);
        $filter = new RecursiveCallbackFilterIterator($directories, static function (SplFileInfo $file) use ($root): bool {
            if (! $file->isDir()) {
                return true;
            }

            return ! in_array(substr($file->getPathname(), strlen($root) + 1), self::EXCLUDED_DIRECTORIES, true);
        });

        foreach (new RecursiveIteratorIterator($filter) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $path = substr($file->getPathname(), strlen($root) + 1);

            if (self::excluded($path, $ignored)) {
                continue;
            }

            $relocated[$path] = self::RELOCATED[$path] ?? null;
            $files[$path] = $path;
        }

        // O modelo que o instalador muda de lugar vale pelo destino (e vence
        // um arquivo que já esteja lá).
        foreach (array_filter($relocated) as $from => $to) {
            unset($files[$from]);
            $files[$to] = $from;
        }

        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * O hash do conteúdo (null se o arquivo não existe).
     */
    public static function hash(string $root, string $path): ?string
    {
        $file = rtrim($root, '/').'/'.$path;

        return is_file($file) ? hash_file('sha256', $file) ?: null : null;
    }

    /**
     * @param  list<string>  $ignored
     */
    private static function excluded(string $path, array $ignored): bool
    {
        if (in_array($path, self::EXCLUDED_FILES, true) || in_array($path, $ignored, true)) {
            return true;
        }

        foreach (self::EXCLUDED_PATTERNS as $pattern) {
            if (fnmatch($pattern, basename($path))) {
                return true;
            }
        }

        foreach ($ignored as $pattern) {
            if (str_contains($pattern, '*') && fnmatch($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Os caminhos `export-ignore` do .gitattributes da raiz (sem a barra do
     * começo).
     *
     * @return list<string>
     */
    private static function exportIgnored(string $root): array
    {
        $attributes = @file($root.'/.gitattributes', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $paths = [];

        foreach ($attributes === false ? [] : $attributes as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (count($parts) >= 2 && ! str_starts_with($parts[0], '#') && in_array('export-ignore', array_slice($parts, 1), true)) {
                $paths[] = ltrim($parts[0], '/');
            }
        }

        return $paths;
    }
}

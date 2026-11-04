<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;
use Twstec\Kit\Installer\Contracts\StarterReference;
use Twstec\Kit\Installer\Upgrade\FileStatus;
use Twstec\Kit\Installer\Upgrade\Manifest;
use Twstec\Kit\Installer\Upgrade\StarterFiles;
use Twstec\Kit\Installer\Upgrade\UpgradeCheck;

/**
 * php artisan tws:upgrade --check — CONFERE os arquivos de starter que ficam
 * no projeto (Docker, CI, `.env*`, scripts, Vite, TypeScript, testes, rotas,
 * providers: o que não mora num pacote) contra o starter publicado DA VERSÃO
 * DO KIT INSTALADA, e lista o que ficou diferente. NÃO APLICA NADA: atualizar
 * esses arquivos continua sendo à mão (os textos, e-mails, configurações e
 * telas do kit já vêm dos pacotes — `kit:overrides`).
 *
 * A referência: o starter publicado, baixado pelo Composer na versão do
 * twstec/kit-foundation instalada (ComposerStarterReference), ou uma pasta
 * com ele (`--from`, sem rede). A interface vem do manifesto, de `--starter`
 * ou do próprio projeto (resources/js/app.tsx = React).
 *
 * Com o manifesto (`.tws/manifest.json`, gravado pelo tws:install na
 * criação), cada arquivo que o kit mudou sai como "desatualizado" (pode
 * substituir pelo da referência) ou "personalizado" (comparar à mão); sem
 * ele, só "difere". `--diff` mostra a diferença (git diff --no-index).
 * Depois de conferir e atualizar à mão, `--record` grava a base nova no
 * manifesto (o único arquivo que o comando escreve, e só com essa opção).
 *
 * Saída: 0 = nada a conferir; 1 = há diferenças; 2 = sem referência.
 */
final class UpgradeCommand extends Command
{
    public const INVALID_REFERENCE = 2;

    protected $name = 'tws:upgrade';

    public function __construct()
    {
        parent::__construct();

        $this->setDescription(__('installer.upgrade.description'));
    }

    /**
     * @return list<InputOption>
     */
    protected function getOptions(): array
    {
        return [
            new InputOption('check', null, InputOption::VALUE_NONE, __('installer.upgrade.options.check')),
            new InputOption('diff', null, InputOption::VALUE_NONE, __('installer.upgrade.options.diff')),
            new InputOption('record', null, InputOption::VALUE_NONE, __('installer.upgrade.options.record')),
            new InputOption('from', null, InputOption::VALUE_REQUIRED, __('installer.upgrade.options.from')),
            new InputOption('starter', null, InputOption::VALUE_REQUIRED, __('installer.upgrade.options.starter')),
        ];
    }

    public function handle(StarterReference $references): int
    {
        $project = dirname($this->laravel->environmentFilePath());
        $manifest = Manifest::read($project);
        $starter = $this->starter($project, $manifest);

        if ($starter === null) {
            $this->components->error(__('installer.upgrade.starter_unknown'));

            return self::INVALID_REFERENCE;
        }

        $version = $references->version();

        try {
            $reference = $this->reference($references, $starter, $version);
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID_REFERENCE;
        }

        $this->components->info(__('installer.upgrade.reference', [
            'starter' => $starter,
            'version' => $this->option('from') !== null ? __('installer.upgrade.from_folder') : (string) $version,
            'folder' => $reference,
        ]));

        if ($this->option('record')) {
            Manifest::capture((string) ($version ?? 'local'), $starter, $project, $reference, StarterFiles::in($reference), $manifest)->write($project);
            $this->components->info(__('installer.upgrade.recorded', ['file' => Manifest::PATH]));

            return self::SUCCESS;
        }

        $this->line('  '.($manifest === null
            ? __('installer.upgrade.no_manifest', ['file' => Manifest::PATH])
            : __('installer.upgrade.base', ['version' => $manifest->kit, 'file' => Manifest::PATH])));

        $differences = UpgradeCheck::compare($project, $reference, $manifest);

        if ($differences === []) {
            $this->components->info(__('installer.upgrade.up_to_date'));

            return self::SUCCESS;
        }

        foreach ($differences as $difference) {
            $this->components->twoColumnDetail($difference['path'], __('installer.upgrade.status.'.$difference['status']->value));

            if ($this->option('diff')) {
                $this->diff($project, $reference, $difference);
            }
        }

        $this->newLine();

        foreach (array_unique(array_map(static fn (array $d): string => $d['status']->value, $differences)) as $status) {
            $this->line('  '.__('installer.upgrade.hint.'.$status));
        }

        $this->line('  '.__('installer.upgrade.compare', ['folder' => $reference]));
        $this->line('  '.__('installer.upgrade.after', ['command' => 'php artisan tws:upgrade --record'.($this->option('from') !== null ? ' --from='.$this->option('from') : '')]));
        $this->components->warn(__('installer.upgrade.nothing_changed'));

        return self::FAILURE;
    }

    private function starter(string $project, ?Manifest $manifest): ?string
    {
        $chosen = $this->option('starter');
        $starter = is_string($chosen) && $chosen !== '' ? $chosen : $manifest?->starter;

        if ($starter === null) {
            $starter = match (true) {
                is_file($project.'/resources/js/app.tsx') => 'react',
                is_dir($project.'/app/Livewire') => 'livewire',
                default => null,
            };
        }

        return in_array($starter, ['react', 'livewire'], true) ? $starter : null;
    }

    private function reference(StarterReference $references, string $starter, ?string $version): string
    {
        $from = $this->option('from');

        if (is_string($from) && $from !== '') {
            $folder = realpath($from);

            if ($folder === false || ! is_file($folder.'/composer.json')) {
                throw new RuntimeException(__('installer.upgrade.from_invalid', ['folder' => $from]));
            }

            return $folder;
        }

        if ($version === null) {
            throw new RuntimeException(__('installer.upgrade.version_unknown'));
        }

        return $references->directory($starter, $version);
    }

    /**
     * @param  array{path: string, reference: ?string, status: FileStatus}  $difference
     */
    private function diff(string $project, string $reference, array $difference): void
    {
        $theirs = $difference['reference'] === null ? '/dev/null' : $reference.'/'.$difference['reference'];
        $mine = is_file($project.'/'.$difference['path']) ? $project.'/'.$difference['path'] : '/dev/null';

        $result = Process::run(['git', 'diff', '--no-index', '--no-color', '--', $theirs, $mine]);

        // git diff --no-index: 1 = há diferença; outro código = não rodou.
        if (! in_array($result->exitCode(), [0, 1], true)) {
            $this->line('    '.__('installer.upgrade.diff_unavailable', ['reference' => $theirs, 'project' => $mine]));

            return;
        }

        $this->output->write($result->output());
    }
}

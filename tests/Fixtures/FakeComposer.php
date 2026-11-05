<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Tests\Fixtures;

use Closure;
use Twstec\Kit\Installer\Contracts\Composer;

/**
 * O Composer de mentira: anota o que o instalador pediu, na ordem, e responde
 * sucesso (ou a falha configurada).
 */
final class FakeComposer implements Composer
{
    /**
     * @var list<array{0: string, 1: list<string>, 2: bool}>
     */
    public array $calls = [];

    /**
     * O que o Composer escreve quando falha (ex.: a mensagem de extensão que
     * falta).
     */
    public string $failureOutput = '';

    /**
     * Chamado a cada `remove`, com os pacotes — para o teste ver o projeto
     * no momento em que o Composer roda.
     */
    public ?Closure $onRemove = null;

    public function __construct(public bool $fails = false) {}

    public function require(array $packages, bool $dev, Closure $output): bool
    {
        $this->calls[] = ['require', $packages, $dev];
        $output('out', 'composer require '.implode(' ', $packages)."\n");

        if ($this->fails && $this->failureOutput !== '') {
            $output('err', $this->failureOutput);
        }

        return ! $this->fails;
    }

    public function remove(array $packages, bool $dev, Closure $output): bool
    {
        $this->calls[] = ['remove', $packages, $dev];

        if ($this->onRemove !== null) {
            ($this->onRemove)($packages);
        }
        $output('out', 'composer remove '.implode(' ', $packages)."\n");

        return ! $this->fails;
    }
}

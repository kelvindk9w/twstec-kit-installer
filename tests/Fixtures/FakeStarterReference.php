<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Tests\Fixtures;

use RuntimeException;
use Twstec\Kit\Installer\Contracts\StarterReference;

/**
 * O starter de referência de mentira: uma pasta do teste, numa versão fixa.
 * Anota cada pedido; com $failure, recusa como o Composer sem rede.
 */
final class FakeStarterReference implements StarterReference
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    public array $requests = [];

    public ?string $failure = null;

    public function __construct(
        public string $folder = '',
        public ?string $installed = '2.0.0-beta.20',
    ) {}

    public function version(): ?string
    {
        return $this->installed;
    }

    public function directory(string $starter, string $version): string
    {
        $this->requests[] = [$starter, $version];

        if ($this->failure !== null) {
            throw new RuntimeException($this->failure);
        }

        return $this->folder;
    }
}

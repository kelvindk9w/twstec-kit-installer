<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Tests\Fixtures;

use Twstec\Kit\Installer\Dev\DevEnvironment;
use Twstec\Kit\Installer\Dev\Host;

/**
 * A máquina de mentira: os projetos Docker que "existem", as portas que o
 * Docker "já publicou" (com o dono) e as que um programa fora do Docker
 * "ocupa". Anota cada conferência de porta (a de verdade sobe um container).
 */
final class FakeHost implements Host
{
    /**
     * @var list<list<int>>
     */
    public array $probes = [];

    /**
     * As reservas pedidas: [nome, número].
     *
     * @var list<array{0: string, 1: int}>
     */
    public array $reserved = [];

    /**
     * @param  list<string>  $projects
     * @param  array<int, string>  $published  porta => projeto
     * @param  list<int>  $busy  portas ocupadas por um programa fora do Docker
     */
    public function __construct(
        public array $projects = [],
        public array $published = [],
        public array $busy = [],
        public bool $available = true,
    ) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function projects(): array
    {
        return $this->projects;
    }

    public function published(): array
    {
        return $this->published;
    }

    public function busy(array $ports): array
    {
        $this->probes[] = $ports;

        return array_values(array_filter($ports, fn (int $port): bool => in_array($port, $this->busy, true) || isset($this->published[$port])));
    }

    public function reserve(string $name, int $slot): void
    {
        $this->reserved[] = [$name, $slot];
    }

    /**
     * Um projeto Docker com as quatro portas de um número publicadas.
     */
    public function withProject(string $name, int $slot): self
    {
        $this->projects[] = $name;

        foreach (DevEnvironment::ports($slot) as $service => $port) {
            // O banco não é publicado por padrão: como um projeto de verdade.
            if ($service !== 'database') {
                $this->published[$port] = $name;
            }
        }

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Support;

use Twstec\Kit\Foundation\Kit;

/**
 * O que o instalador vai fazer: de onde o projeto está (módulos opcionais
 * instalados, demonstração) para onde a pessoa escolheu ir.
 *
 * Só dados e regras — nada aqui chama o Composer. As regras:
 *
 * - `foundation` e `auth` não entram na escolha: vêm sempre;
 * - um módulo não vai sem o que ele exige (Kit::DEPENDS_ON — `uploads`
 *   exige `accounts`);
 * - a DEMONSTRAÇÃO (twstec/kit-demo, pacote de desenvolvimento) exige todos
 *   os módulos opcionais (DEMO_REQUIRES): tirar qualquer um deles exige
 *   tirar a demo também — o instalador não a remove sem a pessoa pedir.
 */
final class InstallPlan
{
    /**
     * O pacote da demonstração do kit (require-dev do starter).
     */
    public const DEMO_PACKAGE = 'twstec/kit-demo';

    /**
     * Os módulos opcionais que a demonstração exige (o `require` do
     * composer.json dela; o starter confere que os dois não divergem).
     *
     * @var list<string>
     */
    public const DEMO_REQUIRES = ['accounts', 'uploads', 'admin'];

    /**
     * @param  list<string>  $installed  módulos opcionais instalados agora
     * @param  list<string>  $target  módulos opcionais escolhidos
     */
    public function __construct(
        public readonly array $installed,
        public readonly array $target,
        public readonly bool $demoInstalled,
        public readonly bool $keepDemo,
    ) {}

    /**
     * Módulos a tirar, na ordem de Kit::OPTIONAL.
     *
     * @return list<string>
     */
    public function toRemove(): array
    {
        return array_values(array_filter(Kit::OPTIONAL, fn (string $m): bool => in_array($m, $this->installed, true) && ! in_array($m, $this->target, true)));
    }

    /**
     * Módulos a acrescentar, na ordem de Kit::OPTIONAL.
     *
     * @return list<string>
     */
    public function toAdd(): array
    {
        return array_values(array_filter(Kit::OPTIONAL, fn (string $m): bool => in_array($m, $this->target, true) && ! in_array($m, $this->installed, true)));
    }

    /**
     * Os módulos opcionais escolhidos, na ordem de Kit::OPTIONAL.
     *
     * @return list<string>
     */
    public function targetModules(): array
    {
        return array_values(array_filter(Kit::OPTIONAL, fn (string $m): bool => in_array($m, $this->target, true)));
    }

    public function removesDemo(): bool
    {
        return $this->demoInstalled && ! $this->keepDemo;
    }

    /**
     * Alguma coisa muda no Composer?
     */
    public function changesPackages(): bool
    {
        return $this->toRemove() !== [] || $this->toAdd() !== [] || $this->removesDemo();
    }

    /**
     * Módulo => os módulos que ele exige e ficaram de fora da escolha.
     *
     * @return array<string, list<string>>
     */
    public function missingDependencies(): array
    {
        return Kit::missingDependencies($this->targetModules());
    }

    /**
     * Os módulos que a demonstração exige e ficaram de fora — só conta quando
     * a demo fica.
     *
     * @return list<string>
     */
    public function demoMissing(): array
    {
        if (! $this->demoInstalled || ! $this->keepDemo) {
            return [];
        }

        return array_values(array_diff(self::DEMO_REQUIRES, $this->target));
    }
}

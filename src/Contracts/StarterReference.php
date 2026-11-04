<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Contracts;

use RuntimeException;

/**
 * De onde vem o STARTER DE REFERÊNCIA da conferência (tws:upgrade): o starter
 * publicado (twstec/starter-<interface>) na versão do kit instalada no
 * projeto. Interface para a suíte trocar pela de mentira.
 */
interface StarterReference
{
    /**
     * A versão do kit instalada no projeto (a do twstec/kit-foundation, sem o
     * "v" da tag); null fora de uma versão publicada (monorepo, branch).
     */
    public function version(): ?string;

    /**
     * A pasta com o starter publicado nessa versão.
     *
     * @param  string  $starter  react | livewire
     *
     * @throws RuntimeException quando não dá para obter (a mensagem diz por quê)
     */
    public function directory(string $starter, string $version): string;
}

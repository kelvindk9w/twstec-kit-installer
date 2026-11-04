<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Upgrade;

/**
 * O que a conferência diz de um arquivo de starter que não está igual ao da
 * referência (a versão do kit instalada).
 */
enum FileStatus: string
{
    /** O kit mudou o arquivo, e o do projeto continua como o kit o entregou: pode substituir. */
    case Outdated = 'outdated';

    /** O kit mudou o arquivo, e o do projeto é outro (personalizado, ou adaptado na criação): comparar à mão. */
    case Customized = 'customized';

    /** O kit passou a trazer o arquivo, e o projeto não tem. */
    case New = 'new';

    /** O kit deixou de trazer o arquivo, e o projeto ainda tem. */
    case Removed = 'removed';

    /** Sem manifesto (projeto de antes da conferência): o arquivo difere da referência. */
    case Differs = 'differs';
}

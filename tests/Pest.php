<?php

declare(strict_types=1);

use Twstec\Kit\Installer\Tests\TestCase;

// Todos os testes do pacote sobem a aplicação limpa do Testbench com o
// foundation e o instalador, e um projeto descartável (ver TestCase).
pest()->extend(TestCase::class)->in('Feature', 'Architecture');

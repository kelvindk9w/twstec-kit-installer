<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Dev;

/**
 * A máquina onde os projetos rodam, do ponto de vista do Docker de
 * desenvolvimento: que projetos Docker já existem e que portas já estão em
 * uso. Interface para a suíte trocar por uma de mentira.
 *
 * ESTE ARQUIVO EXISTE EM DOIS PACOTES, IGUAL (só o namespace muda): no
 * twstec/kit (o menu, antes de qualquer pacote do kit existir no projeto) e
 * no twstec/kit-installer (o `tws:install`, que grava no .env). Um teste do
 * monorepo confere que as duas cópias não divergem.
 */
interface Host
{
    /**
     * O Docker respondeu? Sem ele, os projetos e as portas publicadas não são
     * conhecidos (e a conferência das portas vale só para o que dá para abrir
     * daqui).
     */
    public function available(): bool;

    /**
     * Os nomes dos projetos Docker que já existem na máquina (containers,
     * volumes ou redes com o rótulo do Compose — inclusive os parados).
     *
     * @return list<string>
     */
    public function projects(): array;

    /**
     * As portas da máquina que o Docker já reservou (containers rodando OU
     * parados): porta => quem (o projeto do Compose, ou o nome do container).
     *
     * @return array<int, string>
     */
    public function published(): array;

    /**
     * Das portas pedidas, as que NÃO dá para abrir agora na máquina (em uso
     * por qualquer programa).
     *
     * @param  list<int>  $ports
     * @return list<int>
     */
    public function busy(array $ports): array;

    /**
     * Reserva o nome e o número de um projeto recém-criado, que ainda não
     * subiu (sem containers, nada o mostraria a quem criar o próximo): o
     * volume do banco do projeto, criado já com o rótulo do Compose e o do
     * número. O `docker compose up -d` o usa como dele; `down -v` o apaga — e
     * a reserva acaba junto.
     */
    public function reserve(string $name, int $slot): void;
}

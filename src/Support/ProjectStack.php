<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Support;

/**
 * A infraestrutura do projeto criado, escolhida UMA vez, na criação: o banco
 * e o Redis.
 *
 * - Banco: PostgreSQL (o padrão, recomendado — as travas por privilégio do
 *   banco: papéis separados e tabelas só-acréscimo) ou SQLite ("modo
 *   protótipo": um arquivo, sem serviço de banco no Docker de
 *   desenvolvimento e sem aquelas travas). A produção pelo
 *   docker-compose.prod.yml continua no PostgreSQL.
 * - Redis: sim (o padrão — sessão, cache e fila no Redis, com o Horizon) ou
 *   não (sessão, cache e fila no próprio banco, o worker `queue:work` no
 *   lugar do Horizon, sem o serviço Redis nos composes).
 *
 * MySQL e hospedagem compartilhada ficam fora (docs/roadmap.md).
 */
final class ProjectStack
{
    public const POSTGRES = 'pgsql';

    public const SQLITE = 'sqlite';

    /**
     * @var list<string>
     */
    public const DATABASES = [self::POSTGRES, self::SQLITE];

    /**
     * Os sinônimos aceitos na opção e na variável de ambiente.
     *
     * @var array<string, string>
     */
    private const DATABASE_ALIASES = [
        'pgsql' => self::POSTGRES,
        'postgres' => self::POSTGRES,
        'postgresql' => self::POSTGRES,
        'sqlite' => self::SQLITE,
    ];

    public function __construct(
        public readonly string $database = self::POSTGRES,
        public readonly bool $redis = true,
    ) {}

    /**
     * O banco pelo nome (ou sinônimo); null quando não é um dos aceitos.
     */
    public static function database(string $value): ?string
    {
        return self::DATABASE_ALIASES[strtolower(trim($value))] ?? null;
    }

    /**
     * Sim/não da variável de ambiente (`1`, `true`, `yes`, `sim` / `0`,
     * `false`, `no`, `nao`, `não`); null quando não é nenhum.
     */
    public static function flag(string $value): ?bool
    {
        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'sim' => true,
            '0', 'false', 'no', 'nao', 'não' => false,
            default => null,
        };
    }

    public function sqlite(): bool
    {
        return $this->database === self::SQLITE;
    }

    /**
     * A escolha padrão (PostgreSQL com Redis): o projeto como o starter vem.
     */
    public function isDefault(): bool
    {
        return $this->database === self::POSTGRES && $this->redis;
    }
}

<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Support;

/**
 * Aplica a escolha de banco e de Redis (ProjectStack) aos arquivos do projeto
 * criado — UMA vez, na criação (tws:install, junto com o Docker de
 * desenvolvimento), depois das outras adaptações e antes de o manifesto
 * (.tws/manifest.json) registrar o projeto como ficou.
 *
 * SQLITE (modo protótipo):
 * - `.env` e `.env.example`: a seção do banco vira `DB_CONNECTION=sqlite`,
 *   com o aviso de que as travas por privilégio do PostgreSQL não existem;
 *   sem host, porta, nome, papéis e senhas do banco (o Laravel usa
 *   database/database.sqlite) e sem a porta do banco do Docker;
 * - `database/database.sqlite`: criado vazio (o `migrate` do init o
 *   preenche);
 * - `compose.yaml`: sem os serviços postgres, db-init e db-port nem o volume
 *   do banco;
 * - a produção (docker-compose.prod.yml, .env.prod.example) continua no
 *   PostgreSQL — o instalador avisa.
 *
 * SEM REDIS:
 * - `.env` e `.env.example`: sessão, cache e fila no banco (`database`), sem
 *   variável REDIS_* nem HORIZON_*;
 * - `compose.yaml`: sem o serviço redis (a fila de desenvolvimento já é o
 *   `queue:work`);
 * - `docker-compose.prod.yml`: sem o redis, o horizon vira o worker `queue`
 *   (`queue:work`), sessão/cache/fila no banco;
 * - `.env.prod.example`: sem PROD_REDIS_PASSWORD e sem as variáveis do
 *   Horizon;
 * - o Horizon sai: o provider, a configuração, o teste do dashboard e o
 *   registro em bootstrap/providers.php (o `composer remove laravel/horizon`
 *   é do tws:install).
 *
 * Cada passo só age quando o arquivo existe; repetir não muda nada.
 */
final class StackFiles
{
    public const SQLITE_FILE = 'database/database.sqlite';

    /**
     * O pacote do Horizon (sai do composer.json sem Redis).
     */
    public const HORIZON_PACKAGE = 'laravel/horizon';

    /**
     * Os arquivos que só existem por causa do Horizon.
     *
     * @var list<string>
     */
    public const HORIZON_FILES = [
        'app/Providers/HorizonServiceProvider.php',
        'config/horizon.php',
        'tests/Feature/Horizon/HorizonAccessTest.php',
    ];

    /**
     * As variáveis de banco do PostgreSQL (saem com SQLite).
     *
     * @var list<string>
     */
    public const POSTGRES_VARIABLES = [
        'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'DB_MIGRATION_USERNAME', 'DB_MIGRATION_PASSWORD', 'DEV_DB_PORT',
    ];

    /**
     * O worker de produção sem o Horizon (o mesmo do Docker de
     * desenvolvimento, com o reinício periódico de quem fica no ar).
     */
    public const QUEUE_COMMAND = 'php artisan queue:work --sleep=3 --tries=3 --backoff=5 --max-time=3600';

    public function __construct(private readonly string $root) {}

    public function apply(ProjectStack $stack): void
    {
        if ($stack->sqlite()) {
            $this->sqlite();
        }

        if (! $stack->redis) {
            $this->withoutRedis();
        }
    }

    private function sqlite(): void
    {
        foreach ($this->environmentFiles() as $env) {
            $env->replaceSection('Banco de dados', self::sqliteSection());
            $env->remove(...self::POSTGRES_VARIABLES);
            $env->set('DB_CONNECTION', ProjectStack::SQLITE);

            // O perfil db-port publicava o PostgreSQL: sem ele, nada a publicar.
            if (str_contains((string) $env->get('COMPOSE_PROFILES'), 'db-port')) {
                $env->set('COMPOSE_PROFILES', '');
            }
        }

        if (! is_dir($this->root.'/database')) {
            mkdir($this->root.'/database', 0755, true);
        }

        if (! is_file($this->root.'/'.self::SQLITE_FILE)) {
            touch($this->root.'/'.self::SQLITE_FILE);
        }

        $compose = new ComposeFile($this->root.'/compose.yaml');
        $compose->removeService('db-port')
            ->removeService('db-init')
            ->removeService('postgres', 'postgres_data')
            ->save();
    }

    private function withoutRedis(): void
    {
        foreach ($this->environmentFiles() as $env) {
            $env->set('SESSION_DRIVER', 'database');
            $env->set('CACHE_STORE', 'database');
            $env->set('QUEUE_CONNECTION', 'database');
            $env->replaceLine('# --- Sessão / cache / filas', self::heading('Sessão / cache / filas (no banco de dados)'));
            $env->replaceLine('# Bancos do Redis:', null);
            $env->replaceSection('Filas / Horizon', []);
            $env->removePrefixed('REDIS_', 'HORIZON_');
        }

        $compose = new ComposeFile($this->root.'/compose.yaml');
        $compose->removeService('redis', 'redis_data')->save();

        $production = new ComposeFile($this->root.'/docker-compose.prod.yml');
        $production->setEnvironment([
            'CACHE_STORE' => 'database',
            'SESSION_DRIVER' => 'database',
            'QUEUE_CONNECTION' => 'database',
        ])
            ->removeEnvironment('REDIS_')
            ->removeService('redis', 'redis_data')
            ->replaceService('horizon', 'queue', self::QUEUE_COMMAND, [
                'Fila em produção: o worker do Laravel (`queue:work`), com a fila no',
                'banco (sem Redis). O --max-time recicla o processo de hora em hora.',
            ])
            ->save();

        $this->rewrite('.env.prod.example', [
            "PROD_DB_APP_PASSWORD,\n# PROD_DB_MAINTENANCE_PASSWORD e\n# PROD_REDIS_PASSWORD são OBRIGATÓRIAS" => "PROD_DB_APP_PASSWORD e\n# PROD_DB_MAINTENANCE_PASSWORD são OBRIGATÓRIAS",
            "PROD_DB_MAINTENANCE_PASSWORD=rst \\\n#   PROD_REDIS_PASSWORD=abc \\" => 'PROD_DB_MAINTENANCE_PASSWORD=rst \\',
            "# Horizon: processos do supervisor de filas (serviço `horizon`).\n#\n" => '',
            "# Horizon: processos do supervisor de filas (serviço `horizon`).\n" => '',
            "# job concluído no Redis (minutos), job falho no Redis (minutos) e a tabela\n# failed_jobs (horas, podada diariamente pelo scheduler)." => '# a tabela failed_jobs (horas, podada diariamente pelo scheduler).',
        ]);
        (new EnvironmentFile($this->root.'/.env.prod.example'))->removePrefixed('PROD_REDIS_', 'REDIS_', 'HORIZON_');

        $this->removeHorizonFiles();
    }

    /**
     * Tira o código do Horizon do projeto: o provider (e o registro dele em
     * bootstrap/providers.php), a configuração e o teste do dashboard. Vem
     * ANTES do `composer remove laravel/horizon`: o Composer roda o
     * `package:discover`, que sobe a aplicação — com o provider ainda
     * registrado, ela cairia na classe do pacote que acabou de sair.
     */
    public function removeHorizonFiles(): void
    {
        foreach (self::HORIZON_FILES as $file) {
            if (is_file($this->root.'/'.$file)) {
                unlink($this->root.'/'.$file);
            }
        }

        $directory = $this->root.'/tests/Feature/Horizon';

        if (is_dir($directory) && (scandir($directory) ?: []) === ['.', '..']) {
            rmdir($directory);
        }

        // O registro do provider (o `use` e a linha da lista).
        $providers = $this->root.'/bootstrap/providers.php';

        if (is_file($providers)) {
            file_put_contents($providers, (string) preg_replace('/^.*\bHorizonServiceProvider\b.*\n/m', '', (string) file_get_contents($providers)));
        }
    }

    /**
     * O .env e o .env.example que existirem.
     *
     * @return list<EnvironmentFile>
     */
    private function environmentFiles(): array
    {
        $files = [];

        foreach (['.env' => true, '.env.example' => false] as $file => $secret) {
            $env = new EnvironmentFile($this->root.'/'.$file, $secret);

            if ($env->exists()) {
                $files[] = $env;
            }
        }

        return $files;
    }

    /**
     * Troca textos conhecidos (o que não estiver no arquivo fica como está).
     *
     * @param  array<string, string>  $replacements
     */
    private function rewrite(string $file, array $replacements): void
    {
        $path = $this->root.'/'.$file;

        if (! is_file($path)) {
            return;
        }

        $contents = str_replace("\r\n", "\n", (string) file_get_contents($path));
        file_put_contents($path, strtr($contents, $replacements));
    }

    /**
     * A seção do banco no .env com SQLite.
     *
     * @return list<string>
     */
    private static function sqliteSection(): array
    {
        return [
            self::heading('Banco de dados (SQLite — modo protótipo)'),
            '# O arquivo database/database.sqlite (o instalador o criou; fica fora do git).',
            '# MODO PROTÓTIPO: sem as travas por privilégio do PostgreSQL — os papéis',
            '# separados da aplicação e da migração, e as tabelas só-acréscimo garantidas',
            '# pelo próprio banco, não existem aqui (as travas da aplicação continuam).',
            '# A produção pelo docker-compose.prod.yml usa PostgreSQL. Para passar a',
            '# usá-lo aqui também, ver o guia de instalação do kit, "Banco de dados".',
            'DB_CONNECTION=sqlite',
        ];
    }

    /**
     * `# --- Título ----…` com 80 colunas, como os outros títulos do .env.
     */
    private static function heading(string $title): string
    {
        $line = '# --- '.$title.' ';

        return $line.str_repeat('-', max(3, 80 - mb_strlen($line)));
    }
}

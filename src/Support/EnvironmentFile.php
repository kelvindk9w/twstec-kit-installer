<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Support;

use RuntimeException;

/**
 * Leitura e escrita pontual de variáveis no `.env` do projeto, preservando
 * o resto do arquivo (comentários, ordem, linhas em branco).
 *
 * set() troca a linha ATIVA da variável; sem ela, escreve logo abaixo da
 * linha comentada de exemplo (`# CHAVE=`), para o valor ficar junto da
 * explicação do .env.example; sem nenhuma das duas, acrescenta no fim.
 *
 * $secret (o .env, com a APP_KEY, o pepper e as senhas): depois de cada
 * escrita, o arquivo fica só para o dono (0600 — protect()). Os containers
 * de desenvolvimento do kit rodam com o uid do dono da pasta, então continuam
 * lendo. Num servidor em que OUTRO usuário (o do servidor web) lê o .env,
 * dê a ele a leitura pelo grupo (chmod 640 e o grupo dele) — ver
 * docs/instalacao.md.
 */
final class EnvironmentFile
{
    /**
     * As permissões do arquivo de segredos: leitura e escrita só do dono.
     */
    public const SECRET_MODE = 0600;

    public function __construct(
        private readonly string $path,
        private readonly bool $secret = false,
    ) {}

    /**
     * Arquivo de segredos: só o dono lê e escreve (no Windows, sem efeito).
     * Sem ser o dono, o chmod não muda nada — e a instalação segue.
     */
    public function protect(): void
    {
        if ($this->exists()) {
            @chmod($this->path, self::SECRET_MODE);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * O valor ativo da variável (sem aspas); null quando não há linha ativa.
     */
    public function get(string $key): ?string
    {
        foreach ($this->lines() as $line) {
            if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=(.*)$/', $line, $match) === 1) {
                return self::unquote(trim($match[1]));
            }
        }

        return null;
    }

    /**
     * A variável tem valor não vazio?
     */
    public function filled(string $key): bool
    {
        $value = $this->get($key);

        return $value !== null && trim($value) !== '';
    }

    public function set(string $key, string $value): void
    {
        $lines = $this->lines();
        $line = $key.'='.self::quote($value);
        $pattern = preg_quote($key, '/');

        foreach ($lines as $i => $current) {
            if (preg_match('/^\s*'.$pattern.'\s*=/', $current) === 1) {
                $lines[$i] = $line;

                $this->write($lines);

                return;
            }
        }

        foreach ($lines as $i => $current) {
            if (preg_match('/^\s*#\s*'.$pattern.'\s*=/', $current) === 1) {
                array_splice($lines, $i + 1, 0, [$line]);

                $this->write($lines);

                return;
            }
        }

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        $lines[] = $line;
        $lines[] = '';

        $this->write($lines);
    }

    /**
     * Tira as variáveis: a linha ativa e a de exemplo comentada (`# CHAVE=`)
     * de cada uma. O resto do arquivo fica como está.
     */
    public function remove(string ...$keys): void
    {
        if (! $this->exists() || $keys === []) {
            return;
        }

        $pattern = '/^\s*#?\s*(?:'.implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), $keys)).')\s*=/';
        $lines = array_values(array_filter($this->lines(), static fn (string $line): bool => preg_match($pattern, $line) !== 1));

        $this->write($lines);
    }

    /**
     * Tira as variáveis cujos nomes começam com os prefixos dados (ex.:
     * `REDIS_`), ativas ou de exemplo comentadas.
     */
    public function removePrefixed(string ...$prefixes): void
    {
        if (! $this->exists() || $prefixes === []) {
            return;
        }

        $pattern = '/^\s*#?\s*(?:'.implode('|', array_map(static fn (string $prefix): string => preg_quote($prefix, '/'), $prefixes)).')[A-Z0-9_]*\s*=/';
        $lines = array_values(array_filter($this->lines(), static fn (string $line): bool => preg_match($pattern, $line) !== 1));

        $this->write($lines);
    }

    /**
     * Troca (ou, com null, tira) as linhas que começam exatamente com o
     * texto dado — para títulos e comentários conhecidos.
     */
    public function replaceLine(string $startsWith, ?string $replacement): void
    {
        if (! $this->exists()) {
            return;
        }

        $lines = [];

        foreach ($this->lines() as $line) {
            if (! str_starts_with($line, $startsWith)) {
                $lines[] = $line;
            } elseif ($replacement !== null) {
                $lines[] = $replacement;
            }
        }

        $this->write($lines);
    }

    /**
     * Troca a SEÇÃO inteira cujo título (`# --- <título> ---`) começa com
     * $title — do título até a linha antes do próximo título — pelas linhas
     * dadas (com o título novo, se for o caso). Sem as linhas, a seção sai.
     * Devolve se achou a seção.
     *
     * @param  list<string>  $replacement
     */
    public function replaceSection(string $title, array $replacement): bool
    {
        $lines = $this->lines();
        $start = null;

        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '# --- '.$title)) {
                $start = $i;

                break;
            }
        }

        if ($start === null) {
            return false;
        }

        $end = count($lines);

        for ($i = $start + 1; $i < count($lines); $i++) {
            if (str_starts_with($lines[$i], '# --- ')) {
                $end = $i;

                break;
            }
        }

        // A linha em branco que separa da próxima seção fica.
        $length = $end - $start;

        if ($replacement !== [] && $length > 0 && $end < count($lines) && $lines[$end - 1] === '') {
            $replacement[] = '';
        } elseif ($replacement === [] && $end === count($lines) && $start > 0 && $lines[$start - 1] === '') {
            $start--;
            $length++;
        }

        array_splice($lines, $start, $length, $replacement);
        $this->write($lines);

        return true;
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        if (! $this->exists()) {
            return [];
        }

        return explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($this->path)));
    }

    /**
     * @param  list<string>  $lines
     */
    private function write(array $lines): void
    {
        if (file_put_contents($this->path, implode("\n", $lines)) === false) {
            throw new RuntimeException("Não foi possível gravar {$this->path}.");
        }

        if ($this->secret) {
            $this->protect();
        }
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    private static function quote(string $value): string
    {
        return preg_match('/^[A-Za-z0-9_:+\/=.,-]*$/', $value) === 1 ? $value : '"'.addcslashes($value, '"\\').'"';
    }
}

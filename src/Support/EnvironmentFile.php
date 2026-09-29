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
 */
final class EnvironmentFile
{
    public function __construct(private readonly string $path) {}

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

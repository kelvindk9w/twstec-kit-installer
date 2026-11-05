<?php

declare(strict_types=1);

namespace Twstec\Kit\Installer\Support;

use RuntimeException;

/**
 * Edição pontual de um arquivo do Docker Compose do projeto (compose.yaml e
 * docker-compose.prod.yml), preservando comentários, ordem e formatação — um
 * parser de YAML reescreveria o arquivo inteiro e apagaria as explicações.
 *
 * Trabalha no formato dos arquivos do kit: indentação de 2 ou 4 espaços
 * (deduzida do arquivo), serviços sob `services:`, volumes nomeados sob
 * `volumes:` e, em cada serviço,
 * `depends_on` no formato longo (`<serviço>:` com `condition:` embaixo) ou
 * em lista (`- <serviço>`). As edições são idempotentes: tirar o que já não
 * existe não muda nada.
 */
final class ComposeFile
{
    /**
     * @var list<string>
     */
    private array $lines;

    /**
     * A unidade de indentação do arquivo (2 ou 4 espaços — os starters usam
     * as duas), deduzida da primeira chave sob `services:`.
     */
    private int $unit = 4;

    public function __construct(private readonly string $path)
    {
        $this->lines = is_file($path)
            ? explode("\n", str_replace("\r\n", "\n", (string) file_get_contents($path)))
            : [];

        foreach ($this->lines as $i => $line) {
            if ($line === 'services:') {
                for ($j = $i + 1; $j < count($this->lines); $j++) {
                    if (preg_match('/^( +)[A-Za-z0-9_.-]+:/', $this->lines[$j], $match) === 1) {
                        $this->unit = strlen($match[1]);

                        break 2;
                    }
                }
            }
        }
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Os serviços declarados, na ordem do arquivo.
     *
     * @return list<string>
     */
    public function services(): array
    {
        return $this->keysUnder('services');
    }

    /**
     * Os volumes nomeados, na ordem do arquivo.
     *
     * @return list<string>
     */
    public function volumes(): array
    {
        return $this->keysUnder('volumes');
    }

    /**
     * Tira o serviço (com os comentários logo acima dele), o volume nomeado
     * dele (se dado) e toda dependência de outro serviço nele.
     */
    public function removeService(string $service, ?string $volume = null): self
    {
        $this->removeBlock('services', $service);
        $this->removeDependency($service);

        if ($volume !== null) {
            $this->removeBlock('volumes', $volume);
        }

        return $this;
    }

    /**
     * Troca o nome de um serviço, o comando dele e os comentários logo acima
     * (null mantém os comentários).
     *
     * @param  list<string>|null  $comments  sem o `#` (a indentação entra aqui)
     */
    public function replaceService(string $from, string $to, string $command, ?array $comments = null): self
    {
        [$start, $end] = $this->block('services', $from) ?? [null, null];

        if ($start === null || $end === null) {
            return $this;
        }

        $this->lines[$start] = $this->indent(1).$to.':';

        for ($i = $start + 1; $i < $end; $i++) {
            if (preg_match('/^'.$this->indent(2).'command:/', $this->lines[$i]) === 1) {
                $this->lines[$i] = $this->indent(2).'command: '.$command;
            }
        }

        if ($comments !== null) {
            $first = $this->commentsAbove($start);
            array_splice($this->lines, $first, $start - $first, array_map(fn (string $line): string => $this->indent(1).'# '.$line, $comments));
        }

        return $this;
    }

    /**
     * Tira de TODOS os serviços a dependência do serviço dado; `depends_on`
     * que fica vazio sai junto.
     */
    public function removeDependency(string $service): self
    {
        $quoted = preg_quote($service, '/');
        $result = [];
        $count = count($this->lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $this->lines[$i];

            // Formato longo: `<serviço>:` e as linhas mais fundas.
            if (preg_match('/^'.$this->indent(3).$quoted.':\s*$/', $line) === 1 && $this->insideDependsOn($i)) {
                while ($i + 1 < $count && preg_match('/^ {'.(3 * $this->unit + 1).',}\S/', $this->lines[$i + 1]) === 1) {
                    $i++;
                }

                continue;
            }

            // Formato em lista: `- <serviço>`.
            if (preg_match('/^'.$this->indent(3).'- '.$quoted.'\s*$/', $line) === 1 && $this->insideDependsOn($i)) {
                continue;
            }

            $result[] = $line;
        }

        // `depends_on:` sem nada embaixo sai.
        $clean = [];

        foreach ($result as $i => $line) {
            if (preg_match('/^'.$this->indent(2).'depends_on:\s*$/', $line) === 1
                && preg_match('/^'.$this->indent(3).'\S/', $result[$i + 1] ?? '') !== 1) {
                continue;
            }

            $clean[] = $line;
        }

        $this->lines = $clean;

        return $this;
    }

    /**
     * Troca o valor das chaves dadas (`    CHAVE: valor`, em qualquer bloco de
     * ambiente — inclusive as âncoras `x-*`). Chave ausente: nada.
     *
     * @param  array<string, string>  $values
     */
    public function setEnvironment(array $values): self
    {
        foreach ($this->lines as $i => $line) {
            foreach ($values as $key => $value) {
                if (preg_match('/^(\s+)'.preg_quote($key, '/').':\s/', $line, $match) === 1) {
                    $this->lines[$i] = $match[1].$key.': '.$value;
                }
            }
        }

        return $this;
    }

    /**
     * Tira as linhas de ambiente cujas chaves começam com os prefixos dados
     * (ex.: `REDIS_`).
     */
    public function removeEnvironment(string ...$prefixes): self
    {
        $pattern = '/^\s+(?:'.implode('|', array_map(static fn (string $prefix): string => preg_quote($prefix, '/'), $prefixes)).')[A-Z0-9_]*:\s/';

        $this->lines = array_values(array_filter($this->lines, static fn (string $line): bool => preg_match($pattern, $line) !== 1));

        return $this;
    }

    public function contents(): string
    {
        return implode("\n", $this->lines);
    }

    public function save(): void
    {
        if (! $this->exists()) {
            return;
        }

        if (file_put_contents($this->path, $this->contents()) === false) {
            throw new RuntimeException("Não foi possível gravar {$this->path}.");
        }
    }

    /**
     * As chaves de primeiro nível (4 espaços) sob uma chave da raiz.
     *
     * @return list<string>
     */
    private function keysUnder(string $root): array
    {
        $keys = [];
        $inside = false;

        foreach ($this->lines as $line) {
            if (preg_match('/^(\S[^:]*):/', $line, $match) === 1) {
                $inside = $match[1] === $root;

                continue;
            }

            if ($inside && preg_match('/^'.$this->indent(1).'([A-Za-z0-9_.-]+):/', $line, $match) === 1) {
                $keys[] = $match[1];
            }
        }

        return $keys;
    }

    /**
     * Onde fica o bloco de uma chave de primeiro nível sob a raiz dada: a
     * linha da chave e a primeira linha DEPOIS dele (sem as linhas em branco
     * e os comentários que já são do próximo bloco).
     *
     * @return array{0: int, 1: int}|null
     */
    private function block(string $root, string $key): ?array
    {
        $inside = false;
        $start = null;
        $count = count($this->lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $this->lines[$i];

            if (preg_match('/^(\S[^:]*):/', $line, $match) === 1) {
                if ($start !== null) {
                    return [$start, $this->trimTail($start, $i)];
                }

                $inside = $match[1] === $root;

                continue;
            }

            if (! $inside) {
                continue;
            }

            if ($start === null) {
                if (preg_match('/^'.$this->indent(1).preg_quote($key, '/').':\s*$/', $line) === 1) {
                    $start = $i;
                }

                continue;
            }

            if (preg_match('/^'.$this->indent(1).'\S/', $line) === 1) {
                return [$start, $this->trimTail($start, $i)];
            }
        }

        return $start === null ? null : [$start, $this->trimTail($start, $count)];
    }

    /**
     * O fim real do bloco: antes das linhas em branco e dos comentários de
     * 4 espaços que precedem o próximo bloco.
     */
    private function trimTail(int $start, int $end): int
    {
        while ($end - 1 > $start && preg_match('/^(\s*|'.$this->indent(1).'#.*)$/', $this->lines[$end - 1]) === 1) {
            $end--;
        }

        return $end;
    }

    /**
     * A primeira linha dos comentários (4 espaços) colados acima da linha.
     */
    private function commentsAbove(int $line): int
    {
        while ($line - 1 >= 0 && preg_match('/^'.$this->indent(1).'#/', $this->lines[$line - 1]) === 1) {
            $line--;
        }

        return $line;
    }

    /**
     * Os espaços de N níveis de indentação.
     */
    private function indent(int $levels): string
    {
        return str_repeat(' ', $levels * $this->unit);
    }

    private function removeBlock(string $root, string $key): void
    {
        $block = $this->block($root, $key);

        if ($block === null) {
            return;
        }

        [$start, $end] = $block;
        $first = $this->commentsAbove($start);

        // A linha em branco que separava o bloco do anterior sai junto.
        if ($first - 1 >= 0 && trim($this->lines[$first - 1]) === '' && ($end >= count($this->lines) || trim($this->lines[$end]) === '')) {
            $first--;
        }

        array_splice($this->lines, $first, $end - $first);

        // A chave da raiz que ficou vazia (ex.: `volumes:` sem volume) sai,
        // com a linha em branco que a separava.
        if ($root !== 'services' && $this->keysUnder($root) === []) {
            foreach ($this->lines as $i => $line) {
                if ($line === $root.':') {
                    $from = $i > 0 && trim($this->lines[$i - 1]) === '' ? $i - 1 : $i;
                    array_splice($this->lines, $from, $i - $from + 1);

                    break;
                }
            }
        }
    }

    /**
     * A linha está dentro de um `depends_on:` (o mais próximo acima, com 8
     * espaços, antes de outra chave de 8 espaços)?
     */
    private function insideDependsOn(int $line): bool
    {
        for ($i = $line - 1; $i >= 0; $i--) {
            if (preg_match('/^'.$this->indent(2).'(\S[^:]*):/', $this->lines[$i], $match) === 1) {
                return $match[1] === 'depends_on';
            }

            if (preg_match('/^ {0,'.(2 * $this->unit - 1).'}\S/', $this->lines[$i]) === 1) {
                return false;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Decision\Accounting;

use InvalidArgumentException;

/** Lossless numeric token parsing; ambiguous duplicate object keys are rejected. */
final class RecordedJson
{
    private int $position = 0;

    private function __construct(private readonly string $json) {}

    public static function decode(string $json): array
    {
        if (strlen($json) > 8 * 1024 * 1024) {
            throw new InvalidArgumentException('source_json_resource_limit');
        }
        // Syntax-only validation. Its converted values are never used as evidence.
        json_decode($json, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $parser = new self($json);
        $tree = $parser->value(0);
        $parser->space();
        if ($parser->position !== strlen($json) || ! is_array($tree)) {
            throw new InvalidArgumentException('source_json_requires_container');
        }

        return $tree;
    }

    private function space(): void
    {
        $this->position += strspn($this->json, " \t\r\n", $this->position);
    }

    private function value(int $depth): mixed
    {
        if ($depth > 127) {
            throw new InvalidArgumentException('source_json_depth_limit');
        }
        $this->space();
        $c = $this->json[$this->position] ?? '';
        if ($c === '"') {
            return $this->string();
        }
        if ($c === '{' || $c === '[') {
            $object = $c === '{';
            $close = $object ? '}' : ']';
            $this->position++;
            $this->space();
            $out = [];
            $keys = [];
            if (($this->json[$this->position] ?? '') === $close) {
                $this->position++;

                return $out;
            }
            while (true) {
                if ($object) {
                    $this->space();
                    $key = $this->string();
                    if (isset($keys[$key])) {
                        throw new InvalidArgumentException('duplicate_source_json_key');
                    }
                    $keys[$key] = true;
                    $this->space();
                    if (($this->json[$this->position++] ?? '') !== ':') {
                        throw new InvalidArgumentException('invalid_source_json_object');
                    }
                    $out[$key] = $this->value($depth + 1);
                } else {
                    $out[] = $this->value($depth + 1);
                }
                $this->space();
                $next = $this->json[$this->position++] ?? '';
                if ($next === $close) {
                    return $out;
                }
                if ($next !== ',') {
                    throw new InvalidArgumentException('invalid_source_json_separator');
                }
            }
        }
        foreach (['true' => true, 'false' => false, 'null' => null] as $literal => $decoded) {
            if (substr($this->json, $this->position, strlen($literal)) === $literal) {
                $this->position += strlen($literal);

                return $decoded;
            }
        }
        if (! preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+\-]?[0-9]+)?/', $this->json, $match, 0, $this->position)) {
            throw new InvalidArgumentException('invalid_source_json_value');
        }
        $this->position += strlen($match[0]);

        return $match[0];
    }

    private function string(): string
    {
        $start = $this->position;
        if (($this->json[$this->position++] ?? '') !== '"') {
            throw new InvalidArgumentException('source_json_key_not_string');
        }
        $n = strlen($this->json);
        while ($this->position < $n) {
            if ($this->json[$this->position] === '\\') {
                $this->position += 2;

                continue;
            }
            if ($this->json[$this->position++] === '"') {
                return json_decode(substr($this->json, $start, $this->position - $start), true, 2, JSON_THROW_ON_ERROR);
            }
        }
        throw new InvalidArgumentException('unterminated_source_json_string');
    }
}

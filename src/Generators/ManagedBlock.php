<?php

declare(strict_types=1);

namespace Sirius\LaravelBuilder\Generators;

use RuntimeException;

final readonly class ManagedBlock
{
    private string $prefix;

    private string $suffix;

    public string $previous;

    private string $begin;

    private string $end;

    private string $newline;

    public function __construct(string $source, string $owner)
    {
        $this->begin = '// sirius-builder begin ' . $owner . ' ';
        $this->end = '// sirius-builder end ' . $owner;
        $this->newline = str_contains($source, "\r\n") ? "\r\n" : "\n";

        $start = $finish = null;
        $offset = $depth = $bodyStart = 0;
        $hash = '';

        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new RuntimeException('Cannot integrate invalid PHP: ' . $e->getMessage(), $e->getCode(), previous: $e);
        }

        if (($tokens[0][0] ?? null) !== T_OPEN_TAG) {
            throw new RuntimeException('Expected a PHP route file.');
        }

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $id = is_array($token) ? $token[0] : null;

            if (in_array($id, [T_CLOSE_TAG, T_NAMESPACE, T_HALT_COMPILER], true) || ($depth === 0 && in_array($id, [T_RETURN, T_EXIT, T_GOTO, T_THROW, T_ENDIF], true))) {
                throw new RuntimeException('Unsupported route file structure. Use a plain PHP registration file.');
            }

            if ($id === T_COMMENT && (str_starts_with($text, $this->begin) || $text === $this->end)) {
                if ($depth !== 0 || ($offset && $source[$offset - 1] !== "\n")) {
                    throw new RuntimeException('Misplaced managed block.');
                }

                if ($text === $this->end) {
                    if ($start === null || $finish !== null) {
                        throw new RuntimeException('Ambiguous managed block.');
                    }

                    $finish = $offset;
                } else {
                    if ($start !== null) {
                        throw new RuntimeException('Duplicate managed block.');
                    }

                    $start = $offset;
                    $hash = substr($text, strlen($this->begin));
                    $bodyStart = $offset + strlen($text) + strlen($this->newline);
                }
            }

            if ($token === '{' || in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            }

            if ($token === '}') {
                $depth--;
            }

            $offset += strlen($text);
        }

        if ($start === null) {
            $this->previous = '';
            $this->prefix = $source . (str_ends_with($source, "\n") ? '' : $this->newline) . $this->newline;
            $this->suffix = $this->newline;

            return;
        }

        if ($finish === null) {
            throw new RuntimeException('Unclosed managed block.');
        }

        $this->previous = substr($source, $bodyStart, $finish - $bodyStart);
        if (!hash_equals($hash, hash('sha256', str_replace("\r\n", "\n", $this->previous)))) {
            throw new RuntimeException('Managed block was edited. Reconcile it before generation.');
        }

        $this->prefix = substr($source, 0, $start);
        $this->suffix = substr($source, $finish + strlen($this->end));
    }

    public function replace(string $body): string
    {
        $body = rtrim(str_replace("\r\n", "\n", $body)) . "\n";

        return $this->prefix . $this->begin . hash('sha256', $body) . $this->newline . str_replace("\n", $this->newline, $body) . $this->end . $this->suffix;
    }
}

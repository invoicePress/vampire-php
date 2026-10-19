<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Xpath\Parser;

use EinvoiceValidator\Exception\UnsupportedConstructException;

/**
 * Task 2.2 — tokenizes an XPath 2.0 (whitelist subset, docs/notes/xpath-whitelist.md) expression.
 * Deliberately does NOT attempt the full formal XPath lexical-disambiguation grammar (the "occurs
 * before an operator" state machine that decides whether e.g. `*`/`and`/`div` are operators or
 * names) - instead emits one generic token per lexeme and lets the Parser decide meaning from
 * grammar position, which is what real XPath expressions in the actual rule corpus need (verified
 * against all 1956 expressions in docs/notes/xpath-inventory.md, see ParserInventoryTest).
 */
final class Lexer
{
    private readonly string $expr;
    private readonly int $length;
    private int $pos = 0;

    public function __construct(string $expr)
    {
        $this->expr = $expr;
        $this->length = strlen($expr);
    }

    /**
     * @return Token[]
     */
    public function tokenize(): array
    {
        $tokens = [];
        while (true) {
            $token = $this->next();
            $tokens[] = $token;
            if ($token->kind === TokenKind::Eof) {
                break;
            }
        }

        return $tokens;
    }

    private function next(): Token
    {
        $this->skipWhitespace();

        if ($this->pos >= $this->length) {
            return new Token(TokenKind::Eof, '', $this->pos);
        }

        $start = $this->pos;
        $c = $this->expr[$this->pos];

        if ($c === "'" || $c === '"') {
            return $this->readString($c, $start);
        }

        if (ctype_digit($c) || ($c === '.' && $this->peekDigitAt($this->pos + 1))) {
            return $this->readNumber($start);
        }

        if ($c === '$') {
            return $this->readVariable($start);
        }

        if (self::isNameStartChar($c)) {
            return $this->readName($start);
        }

        return $this->readOperator($start);
    }

    private function skipWhitespace(): void
    {
        while ($this->pos < $this->length && ctype_space($this->expr[$this->pos])) {
            $this->pos++;
        }
    }

    private function peekDigitAt(int $index): bool
    {
        return $index < $this->length && ctype_digit($this->expr[$index]);
    }

    private function readString(string $quote, int $start): Token
    {
        $value = '';
        $this->pos++; // opening quote

        while (true) {
            if ($this->pos >= $this->length) {
                throw new UnsupportedConstructException('unterminated string literal', $this->expr, $start);
            }

            $c = $this->expr[$this->pos];

            if ($c === $quote) {
                // Doubled quote ('' or "") is an escaped literal quote character, not the end -
                // the standard XPath/XQuery string-literal escaping rule.
                if ($this->pos + 1 < $this->length && $this->expr[$this->pos + 1] === $quote) {
                    $value .= $quote;
                    $this->pos += 2;
                    continue;
                }
                $this->pos++;
                break;
            }

            $value .= $c;
            $this->pos++;
        }

        return new Token(TokenKind::String, $value, $start);
    }

    private function readNumber(int $start): Token
    {
        while ($this->pos < $this->length && ctype_digit($this->expr[$this->pos])) {
            $this->pos++;
        }
        if ($this->pos < $this->length && $this->expr[$this->pos] === '.') {
            $this->pos++;
            while ($this->pos < $this->length && ctype_digit($this->expr[$this->pos])) {
                $this->pos++;
            }
        }

        return new Token(TokenKind::Number, substr($this->expr, $start, $this->pos - $start), $start);
    }

    private function readVariable(int $start): Token
    {
        $this->pos++; // '$'
        $nameStart = $this->pos;

        if ($this->pos >= $this->length || !self::isNameStartChar($this->expr[$this->pos])) {
            throw new UnsupportedConstructException('"$" not followed by a variable name', $this->expr, $start);
        }

        while ($this->pos < $this->length && self::isNameChar($this->expr[$this->pos])) {
            $this->pos++;
        }
        // Allow one ":" + more name chars for a prefixed variable ($ns:var) - rare, but valid.
        if ($this->pos < $this->length && $this->expr[$this->pos] === ':' && $this->peekNameCharAt($this->pos + 1)) {
            $this->pos++;
            while ($this->pos < $this->length && self::isNameChar($this->expr[$this->pos])) {
                $this->pos++;
            }
        }

        return new Token(TokenKind::Variable, substr($this->expr, $nameStart, $this->pos - $nameStart), $start);
    }

    private function readName(int $start): Token
    {
        while ($this->pos < $this->length && self::isNameChar($this->expr[$this->pos])) {
            $this->pos++;
        }

        // QName: exactly one ":" followed by a name (":" followed by "::" is an axis specifier,
        // not part of the name - e.g. "child::foo" must lex as Name("child"), DoubleColon, Name("foo")).
        if (
            $this->pos < $this->length
            && $this->expr[$this->pos] === ':'
            && !($this->pos + 1 < $this->length && $this->expr[$this->pos + 1] === ':')
            && $this->peekNameCharAt($this->pos + 1)
        ) {
            $this->pos++;
            while ($this->pos < $this->length && self::isNameChar($this->expr[$this->pos])) {
                $this->pos++;
            }
        } elseif (
            // Prefixed wildcard "ram:*" (any local name in the "ram" namespace) - the "*" is not a
            // name char, so the QName check above does not fire; folded into this same Name token
            // (text ends in ":*") so the Parser can recognise it without a dedicated token kind.
            $this->pos + 1 < $this->length
            && $this->expr[$this->pos] === ':'
            && $this->expr[$this->pos + 1] === '*'
        ) {
            $this->pos += 2;
        }

        return new Token(TokenKind::Name, substr($this->expr, $start, $this->pos - $start), $start);
    }

    private function peekNameCharAt(int $index): bool
    {
        return $index < $this->length && self::isNameStartChar($this->expr[$index]);
    }

    private function readOperator(int $start): Token
    {
        $two = substr($this->expr, $this->pos, 2);

        $twoCharMap = [
            '//' => TokenKind::SlashSlash,
            '..' => TokenKind::DotDot,
            '::' => TokenKind::DoubleColon,
            '!=' => TokenKind::Ne,
            '<=' => TokenKind::Le,
            '>=' => TokenKind::Ge,
            ':=' => TokenKind::ColonEquals,
        ];

        if (isset($twoCharMap[$two])) {
            $this->pos += 2;
            return new Token($twoCharMap[$two], $two, $start);
        }

        $one = $this->expr[$this->pos];

        $oneCharMap = [
            '/' => TokenKind::Slash,
            '.' => TokenKind::Dot,
            '@' => TokenKind::At,
            '(' => TokenKind::LParen,
            ')' => TokenKind::RParen,
            '[' => TokenKind::LBracket,
            ']' => TokenKind::RBracket,
            ',' => TokenKind::Comma,
            '|' => TokenKind::Pipe,
            '*' => TokenKind::Star,
            '+' => TokenKind::Plus,
            '-' => TokenKind::Minus,
            '=' => TokenKind::Eq,
            '<' => TokenKind::Lt,
            '>' => TokenKind::Gt,
        ];

        if (isset($oneCharMap[$one])) {
            $this->pos++;
            return new Token($oneCharMap[$one], $one, $start);
        }

        throw new UnsupportedConstructException("unexpected character \"$one\"", $this->expr, $start);
    }

    private static function isNameStartChar(string $c): bool
    {
        return ctype_alpha($c) || $c === '_';
    }

    private static function isNameChar(string $c): bool
    {
        return ctype_alnum($c) || $c === '_' || $c === '-' || $c === '.';
    }
}

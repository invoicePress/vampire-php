<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Xpath\Parser;

use EinvoiceValidator\Exception\UnsupportedConstructException;
use EinvoiceValidator\Xpath\Ast\BinaryOp;
use EinvoiceValidator\Xpath\Ast\CastableExpr;
use EinvoiceValidator\Xpath\Ast\CastExpr;
use EinvoiceValidator\Xpath\Ast\Expr;
use EinvoiceValidator\Xpath\Ast\ForExpr;
use EinvoiceValidator\Xpath\Ast\FunctionCall;
use EinvoiceValidator\Xpath\Ast\IfExpr;
use EinvoiceValidator\Xpath\Ast\LetExpr;
use EinvoiceValidator\Xpath\Ast\Literal;
use EinvoiceValidator\Xpath\Ast\NodeTest;
use EinvoiceValidator\Xpath\Ast\ParenExpr;
use EinvoiceValidator\Xpath\Ast\PathExpr;
use EinvoiceValidator\Xpath\Ast\QName;
use EinvoiceValidator\Xpath\Ast\QuantifiedExpr;
use EinvoiceValidator\Xpath\Ast\RangeExpr;
use EinvoiceValidator\Xpath\Ast\SequenceExpr;
use EinvoiceValidator\Xpath\Ast\Step;
use EinvoiceValidator\Xpath\Ast\UnaryOp;
use EinvoiceValidator\Xpath\Ast\VarRef;

/**
 * Task 2.2 — recursive-descent parser for the whitelist subset (docs/notes/xpath-whitelist.md) of
 * XPath 2.0. Grammar levels follow the real XPath 2.0 precedence chain, minus the two productions
 * the whitelist explicitly excludes (InstanceofExpr, TreatExpr - zero occurrences in the actual
 * rule corpus, see docs/notes/xpath-whitelist.md):
 *
 *   Expr -> ExprSingle ("," ExprSingle)*
 *   ExprSingle -> ForExpr | QuantifiedExpr | IfExpr | LetExpr | OrExpr
 *   OrExpr -> AndExpr ("or" AndExpr)*
 *   AndExpr -> ComparisonExpr ("and" ComparisonExpr)*
 *   ComparisonExpr -> RangeExpr (CompOp RangeExpr)?          -- non-associative
 *   RangeExpr -> AdditiveExpr ("to" AdditiveExpr)?
 *   AdditiveExpr -> MultiplicativeExpr (("+"|"-") MultiplicativeExpr)*
 *   MultiplicativeExpr -> UnionExpr (("*"|"div"|"mod"|"idiv") UnionExpr)*
 *   UnionExpr -> IntersectExceptExpr (("|"|"union") IntersectExceptExpr)*
 *   IntersectExceptExpr -> CastableExpr (("intersect"|"except") CastableExpr)*
 *   CastableExpr -> CastExpr ("castable" "as" SingleType)?
 *   CastExpr -> UnaryExpr ("cast" "as" SingleType)?
 *   UnaryExpr -> ("-"|"+")* PathExpr
 */
final class Parser
{
    /**
     * @var Token[]
     */
    private array $tokens;
    private int $cursor = 0;

    public function __construct(private readonly string $source)
    {
        $this->tokens = (new Lexer($source))->tokenize();
    }

    public static function parse(string $expr): Expr
    {
        $parser = new self($expr);
        $result = $parser->parseExpr();
        $parser->expectEof();

        return $result;
    }

    // --- token stream helpers ---------------------------------------------------------------

    private function peek(int $offset = 0): Token
    {
        $index = $this->cursor + $offset;
        return $this->tokens[$index] ?? $this->tokens[count($this->tokens) - 1];
    }

    private function isName(string $text, int $offset = 0): bool
    {
        $token = $this->peek($offset);
        return $token->kind === TokenKind::Name && $token->text === $text;
    }

    private function advance(): Token
    {
        $token = $this->peek();
        if ($token->kind !== TokenKind::Eof) {
            $this->cursor++;
        }

        return $token;
    }

    private function expectKind(TokenKind $kind, string $what): Token
    {
        $token = $this->peek();
        if ($token->kind !== $kind) {
            throw new UnsupportedConstructException(
                "expected $what, found \"{$token->text}\"",
                $this->source,
                $token->pos
            );
        }

        return $this->advance();
    }

    private function expectName(string $text): void
    {
        if (!$this->isName($text)) {
            $token = $this->peek();
            throw new UnsupportedConstructException(
                "expected keyword \"$text\", found \"{$token->text}\"",
                $this->source,
                $token->pos
            );
        }
        $this->advance();
    }

    private function expectEof(): void
    {
        $token = $this->peek();
        if ($token->kind !== TokenKind::Eof) {
            throw new UnsupportedConstructException(
                "unexpected trailing input \"{$token->text}\"",
                $this->source,
                $token->pos
            );
        }
    }

    // --- grammar -----------------------------------------------------------------------------

    private function parseExpr(): Expr
    {
        $pos = $this->peek()->pos;
        $items = [$this->parseExprSingle()];

        while ($this->peek()->kind === TokenKind::Comma) {
            $this->advance();
            $items[] = $this->parseExprSingle();
        }

        return count($items) === 1 ? $items[0] : new SequenceExpr($items, $pos);
    }

    private function parseExprSingle(): Expr
    {
        if ($this->isName('for')) {
            return $this->parseForExpr();
        }
        if ($this->isName('some') || $this->isName('every')) {
            return $this->parseQuantifiedExpr();
        }
        if ($this->isName('if') && $this->peek(1)->kind === TokenKind::LParen) {
            return $this->parseIfExpr();
        }
        if ($this->isName('let')) {
            return $this->parseLetExpr();
        }

        return $this->parseOrExpr();
    }

    /**
     * ⚠️ XPath 2.0 allows SEVERAL bindings in one `for`: `for $a in X, $b in Y return Z`. The spec
     * defines that as sugar for nested single-binding loops, and that is exactly how it is built here
     * (right to left), so the interpreter needs no new node type.
     *
     * Found on the real Factur-X 1.09.2 EXTENDED rule set (Task 4.1b), whose BR-AE-* rules bind
     * fourteen variables in a single `for`. The earlier single-binding parser stopped at the first
     * comma with "expected keyword return" and the whole rule set failed to import.
     */
    private function parseForExpr(): Expr
    {
        $pos = $this->advance()->pos; // 'for'

        /** @var list<array{string, Expr}> $bindings */
        $bindings = [];

        while (true) {
            $var = $this->expectKind(TokenKind::Variable, 'a variable name')->text;
            $this->expectName('in');
            $bindings[] = [$var, $this->parseExprSingle()];

            if ($this->peek()->kind !== TokenKind::Comma) {
                break;
            }

            $this->advance();
        }

        $this->expectName('return');
        $expr = $this->parseExprSingle();

        foreach (array_reverse($bindings) as [$name, $sequence]) {
            $expr = new ForExpr($name, $sequence, $expr, $pos);
        }

        return $expr;
    }

    private function parseQuantifiedExpr(): Expr
    {
        $quantifierToken = $this->advance(); // 'some' | 'every' - guaranteed by the caller's isName() check
        $quantifier = $quantifierToken->text === 'every' ? 'every' : 'some';
        $var = $this->expectKind(TokenKind::Variable, 'a variable name')->text;
        $this->expectName('in');
        $in = $this->parseExprSingle();
        $this->expectName('satisfies');
        $satisfies = $this->parseExprSingle();

        return new QuantifiedExpr($quantifier, $var, $in, $satisfies, $quantifierToken->pos);
    }

    private function parseIfExpr(): Expr
    {
        $pos = $this->advance()->pos; // 'if'
        $this->expectKind(TokenKind::LParen, '"("');
        $cond = $this->parseExpr();
        $this->expectKind(TokenKind::RParen, '")"');
        $this->expectName('then');
        $then = $this->parseExprSingle();
        $this->expectName('else');
        $else = $this->parseExprSingle();

        return new IfExpr($cond, $then, $else, $pos);
    }

    private function parseLetExpr(): Expr
    {
        $pos = $this->advance()->pos; // 'let'
        $var = $this->expectKind(TokenKind::Variable, 'a variable name')->text;
        $this->expectKind(TokenKind::ColonEquals, '":="');
        $value = $this->parseExprSingle();
        $this->expectName('return');
        $return = $this->parseExprSingle();

        return new LetExpr($var, $value, $return, $pos);
    }

    private function parseOrExpr(): Expr
    {
        $left = $this->parseAndExpr();
        while ($this->isName('or')) {
            $pos = $this->advance()->pos;
            $left = new BinaryOp('or', $left, $this->parseAndExpr(), $pos);
        }

        return $left;
    }

    private function parseAndExpr(): Expr
    {
        $left = $this->parseComparisonExpr();
        while ($this->isName('and')) {
            $pos = $this->advance()->pos;
            $left = new BinaryOp('and', $left, $this->parseComparisonExpr(), $pos);
        }

        return $left;
    }

    private const VALUE_COMPARISON_NAMES = ['eq', 'ne', 'lt', 'le', 'gt', 'ge'];
    private const NODE_COMPARISON_NAMES = ['is'];

    private function parseComparisonExpr(): Expr
    {
        $left = $this->parseRangeExpr();

        $token = $this->peek();
        $generalOp = match ($token->kind) {
            TokenKind::Eq => '=',
            TokenKind::Ne => '!=',
            TokenKind::Lt => '<',
            TokenKind::Le => '<=',
            TokenKind::Gt => '>',
            TokenKind::Ge => '>=',
            default => null,
        };
        if ($generalOp !== null) {
            $pos = $this->advance()->pos;
            return new BinaryOp($generalOp, $left, $this->parseRangeExpr(), $pos);
        }
        if ($token->kind === TokenKind::Name && in_array($token->text, self::VALUE_COMPARISON_NAMES, true)) {
            $pos = $this->advance()->pos;
            return new BinaryOp($token->text, $left, $this->parseRangeExpr(), $pos);
        }
        if ($token->kind === TokenKind::Name && in_array($token->text, self::NODE_COMPARISON_NAMES, true)) {
            $pos = $this->advance()->pos;
            return new BinaryOp('is', $left, $this->parseRangeExpr(), $pos);
        }

        return $left;
    }

    private function parseRangeExpr(): Expr
    {
        $left = $this->parseAdditiveExpr();
        if ($this->isName('to')) {
            $pos = $this->advance()->pos;
            return new RangeExpr($left, $this->parseAdditiveExpr(), $pos);
        }

        return $left;
    }

    private function parseAdditiveExpr(): Expr
    {
        $left = $this->parseMultiplicativeExpr();
        while ($this->peek()->kind === TokenKind::Plus || $this->peek()->kind === TokenKind::Minus) {
            $token = $this->advance();
            $op = $token->kind === TokenKind::Plus ? '+' : '-';
            $left = new BinaryOp($op, $left, $this->parseMultiplicativeExpr(), $token->pos);
        }

        return $left;
    }

    private const MULTIPLICATIVE_NAMES = ['div', 'mod', 'idiv'];

    private function parseMultiplicativeExpr(): Expr
    {
        $left = $this->parseUnionExpr();
        while (true) {
            if ($this->peek()->kind === TokenKind::Star) {
                $pos = $this->advance()->pos;
                $left = new BinaryOp('*', $left, $this->parseUnionExpr(), $pos);
                continue;
            }
            $token = $this->peek();
            if ($token->kind === TokenKind::Name && in_array($token->text, self::MULTIPLICATIVE_NAMES, true)) {
                $this->advance();
                $left = new BinaryOp($token->text, $left, $this->parseUnionExpr(), $token->pos);
                continue;
            }
            break;
        }

        return $left;
    }

    private function parseUnionExpr(): Expr
    {
        $left = $this->parseIntersectExceptExpr();
        while ($this->peek()->kind === TokenKind::Pipe || $this->isName('union')) {
            $pos = $this->advance()->pos;
            $left = new BinaryOp('|', $left, $this->parseIntersectExceptExpr(), $pos);
        }

        return $left;
    }

    private function parseIntersectExceptExpr(): Expr
    {
        $left = $this->parseCastableExpr();
        while ($this->isName('intersect') || $this->isName('except')) {
            $token = $this->advance();
            $left = new BinaryOp($token->text, $left, $this->parseCastableExpr(), $token->pos);
        }

        return $left;
    }

    private function parseCastableExpr(): Expr
    {
        $operand = $this->parseCastExpr();
        if ($this->isName('castable')) {
            $pos = $this->advance()->pos;
            $this->expectName('as');
            [$type, $optional] = $this->parseSingleType();
            return new CastableExpr($operand, $type, $optional, $pos);
        }

        return $operand;
    }

    private function parseCastExpr(): Expr
    {
        $operand = $this->parseUnaryExpr();
        if ($this->isName('cast')) {
            $pos = $this->advance()->pos;
            $this->expectName('as');
            [$type, $optional] = $this->parseSingleType();
            return new CastExpr($operand, $type, $optional, $pos);
        }

        return $operand;
    }

    /**
     * The SingleType "?" occurrence marker (`castable as xs:integer?`) has zero occurrences in
     * the real corpus (verified by grep, docs/notes/xpath-whitelist.md) and is deliberately NOT
     * supported here: the Lexer has no dedicated token for a bare "?", and detecting it via "*"
     * would be genuinely ambiguous with a following MultiplicativeExpr ("* 2" right after a
     * castable/cast expression) - better to not guess than to silently misparse that case.
     *
     * @return array{0: QName, 1: bool}
     */
    private function parseSingleType(): array
    {
        return [$this->parseQName(), false];
    }

    private function parseUnaryExpr(): Expr
    {
        if ($this->peek()->kind === TokenKind::Minus || $this->peek()->kind === TokenKind::Plus) {
            $token = $this->advance();
            $op = $token->kind === TokenKind::Minus ? '-' : '+';
            return new UnaryOp($op, $this->parseUnaryExpr(), $token->pos);
        }

        return $this->parsePathExpr();
    }

    // --- paths ------------------------------------------------------------------------------

    private function parsePathExpr(): Expr
    {
        $pos = $this->peek()->pos;

        if ($this->peek()->kind === TokenKind::SlashSlash) {
            $this->advance();
            $steps = $this->parseStepsSequence();
            array_unshift($steps, new Step('descendant-or-self', new NodeTest('kindtest', null, 'node')));
            return new PathExpr($steps, true, $pos);
        }

        if ($this->peek()->kind === TokenKind::Slash) {
            $this->advance();
            if (!$this->startsStep()) {
                return new PathExpr([], true, $pos); // bare "/" - root only
            }
            return new PathExpr($this->parseStepsSequence(), true, $pos);
        }

        $steps = $this->parseStepsSequence();

        // A single primary-only step with no predicates (e.g. a bare "$x" or "f()") is just that
        // expression - no need to wrap a trivial one-element PathExpr around it.
        if (count($steps) === 1 && $steps[0]->primary !== null && $steps[0]->predicates === []) {
            return $steps[0]->primary;
        }

        return new PathExpr($steps, false, $pos);
    }

    private function startsStep(): bool
    {
        $token = $this->peek();

        return match ($token->kind) {
            TokenKind::Dot, TokenKind::DotDot, TokenKind::At, TokenKind::Star,
            TokenKind::Name, TokenKind::Variable, TokenKind::String, TokenKind::Number, TokenKind::LParen => true,
            default => false,
        };
    }

    /**
     * @return Step[]
     */
    private function parseStepsSequence(): array
    {
        $steps = [$this->parseSingleStepExpr()];

        while ($this->peek()->kind === TokenKind::Slash || $this->peek()->kind === TokenKind::SlashSlash) {
            $descendant = $this->peek()->kind === TokenKind::SlashSlash;
            $this->advance();
            if ($descendant) {
                $steps[] = new Step('descendant-or-self', new NodeTest('kindtest', null, 'node'));
            }
            $steps[] = $this->parseSingleStepExpr();
        }

        return $steps;
    }

    /**
     * ⚠️ `element` and `attribute` are kind tests ONLY when a `(` follows - both call sites check
     * `peek(1)` for it. Without the parenthesis they are ordinary element names ( `cac:element`,
     * `attribute` ), and `attribute::` is an axis, read before this is ever asked.
     */
    private function isKindTestName(string $name): bool
    {
        return in_array($name, ['node', 'text', 'element', 'attribute', 'comment', 'processing-instruction'], true);
    }

    /**
     * One step: EITHER an axis step (abbreviated or explicit `axis::test`) OR any `PrimaryExpr`
     * used as a step (function call, variable, literal, parenthesized expr) - valid at any
     * position in a path, see Step's own docblock.
     */
    private function parseSingleStepExpr(): Step
    {
        $token = $this->peek();

        if ($token->kind === TokenKind::Dot) {
            $this->advance();
            return $this->withPredicates(new Step('self', new NodeTest('kindtest', null, 'node')));
        }
        if ($token->kind === TokenKind::DotDot) {
            $this->advance();
            return $this->withPredicates(new Step('parent', new NodeTest('kindtest', null, 'node')));
        }
        if ($token->kind === TokenKind::At) {
            $this->advance();
            return $this->withPredicates(new Step('attribute', $this->parseNodeTest()));
        }
        if ($token->kind === TokenKind::Name && $this->peek(1)->kind === TokenKind::DoubleColon) {
            $axis = $this->advance()->text;
            $this->advance(); // '::'
            return $this->withPredicates(new Step($axis, $this->parseNodeTest()));
        }
        if ($token->kind === TokenKind::Star) {
            $this->advance();
            return $this->withPredicates(new Step('child', new NodeTest('wildcard')));
        }
        $isFunctionCallStep = $token->kind === TokenKind::Name
            && $this->peek(1)->kind === TokenKind::LParen
            && !$this->isKindTestName($token->text);
        if ($isFunctionCallStep) {
            return $this->withPredicatesOnPrimary($this->parsePrimaryExpr());
        }
        if (
            $token->kind === TokenKind::Variable
            || $token->kind === TokenKind::String
            || $token->kind === TokenKind::Number
            || $token->kind === TokenKind::LParen
        ) {
            return $this->withPredicatesOnPrimary($this->parsePrimaryExpr());
        }
        if ($token->kind === TokenKind::Name) {
            // Abbreviated step: bare NodeTest on the default "child" axis (covers kindtest names
            // like "node()" too - parseNodeTest() itself distinguishes those from a real name).
            return $this->withPredicates(new Step('child', $this->parseNodeTest()));
        }

        throw new UnsupportedConstructException('expected a step or a primary expression', $this->source, $token->pos);
    }

    private function withPredicates(Step $step): Step
    {
        $predicates = $this->parsePredicateList();
        return $predicates === [] ? $step : new Step($step->axis, $step->test, $predicates);
    }

    private function withPredicatesOnPrimary(Expr $primary): Step
    {
        return new Step(null, null, $this->parsePredicateList(), $primary);
    }

    /**
     * @return Expr[]
     */
    private function parsePredicateList(): array
    {
        $predicates = [];
        while ($this->peek()->kind === TokenKind::LBracket) {
            $this->advance();
            $predicates[] = $this->parseExpr();
            $this->expectKind(TokenKind::RBracket, '"]"');
        }

        return $predicates;
    }

    private function parseNodeTest(): NodeTest
    {
        $token = $this->peek();

        if ($token->kind === TokenKind::Star) {
            $this->advance();
            return new NodeTest('wildcard');
        }

        if ($token->kind !== TokenKind::Name) {
            throw new UnsupportedConstructException('expected a node test', $this->source, $token->pos);
        }

        if ($this->peek(1)->kind === TokenKind::LParen && $this->isKindTestName($token->text)) {
            $this->advance();
            $this->expectKind(TokenKind::LParen, '"("');
            $this->expectKind(TokenKind::RParen, '")" (kind tests with arguments are not in the whitelist)');
            return new NodeTest('kindtest', null, $token->text);
        }

        // Prefixed wildcard "ram:*" - the Lexer folds the trailing ":*" into this Name token's
        // text (see Lexer::readName()); split it back out here into a genuine wildcard NodeTest
        // that still carries the namespace prefix to match against.
        if (str_ends_with($token->text, ':*')) {
            $this->advance();
            $prefix = substr($token->text, 0, -2);
            return new NodeTest('wildcard', new QName($prefix, '*'));
        }

        return new NodeTest('name', $this->parseQName());
    }

    // --- primary expressions ------------------------------------------------------------------

    private function parsePrimaryExpr(): Expr
    {
        $token = $this->peek();

        return match ($token->kind) {
            TokenKind::String => (function () use ($token): Literal {
                $this->advance();
                return new Literal('string', $token->text, $token->pos);
            })(),
            TokenKind::Number => $this->parseNumberLiteral($token),
            TokenKind::Variable => (function () use ($token): VarRef {
                $this->advance();
                return new VarRef($token->text, $token->pos);
            })(),
            TokenKind::LParen => $this->parseParenthesizedExpr($token->pos),
            TokenKind::Name => $this->parseFunctionCall($token),
            default => throw new UnsupportedConstructException(
                "expected a primary expression, found \"{$token->text}\"",
                $this->source,
                $token->pos
            ),
        };
    }

    private function parseNumberLiteral(Token $token): Literal
    {
        $this->advance();
        $kind = str_contains($token->text, '.') ? 'decimal' : 'integer';

        return new Literal($kind, $token->text, $token->pos);
    }

    private function parseParenthesizedExpr(int $pos): Expr
    {
        $this->advance(); // '('
        if ($this->peek()->kind === TokenKind::RParen) {
            $this->advance();
            return new SequenceExpr([], $pos);
        }

        $inner = $this->parseExpr();
        $this->expectKind(TokenKind::RParen, '")"');

        return $inner instanceof SequenceExpr ? $inner : new ParenExpr($inner, $pos);
    }

    private function parseFunctionCall(Token $nameToken): Expr
    {
        if ($this->peek(1)->kind !== TokenKind::LParen) {
            throw new UnsupportedConstructException(
                "unexpected name \"{$nameToken->text}\" (not a function call, not a valid step here)",
                $this->source,
                $nameToken->pos
            );
        }

        $qname = $this->parseQName();
        $this->expectKind(TokenKind::LParen, '"("');

        $args = [];
        if ($this->peek()->kind !== TokenKind::RParen) {
            $args[] = $this->parseExprSingle();
            while ($this->peek()->kind === TokenKind::Comma) {
                $this->advance();
                $args[] = $this->parseExprSingle();
            }
        }
        $this->expectKind(TokenKind::RParen, '")"');

        return new FunctionCall($qname, $args, $nameToken->pos);
    }

    private function parseQName(): QName
    {
        $token = $this->expectKind(TokenKind::Name, 'a name');
        if (str_contains($token->text, ':')) {
            [$prefix, $local] = explode(':', $token->text, 2);
            return new QName($prefix, $local);
        }

        return new QName(null, $token->text);
    }
}

<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath;

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
use EinvoiceValidator\Xpath\Ast\QuantifiedExpr;
use EinvoiceValidator\Xpath\Ast\RangeExpr;
use EinvoiceValidator\Xpath\Ast\SequenceExpr;
use EinvoiceValidator\Xpath\Ast\Step;
use EinvoiceValidator\Xpath\Ast\UnaryOp;
use EinvoiceValidator\Xpath\Ast\VarRef;
use EinvoiceValidator\Xpath\Runtime\AtomicValue;
use EinvoiceValidator\Xpath\Runtime\Comparisons;
use EinvoiceValidator\Xpath\Runtime\Decimal;
use EinvoiceValidator\Xpath\Runtime\FunctionLibrary;
use EinvoiceValidator\Xpath\Runtime\Sequence;

/**
 * Task 2.4 — evaluates an AST (Task 2.1/2.2) against a context node, using the Task 2.3 runtime
 * library. Path steps are walked manually (Task 2.5's cache is where repeated-evaluation cost is
 * addressed, not a premature DOMXPath fast-path here that risks being correct for the common case
 * and silently wrong for an edge case it didn't anticipate).
 *
 * Axes implemented: exactly the ones the real rule corpus uses (docs/notes/xpath-inventory.md) - child
 * (default/abbreviated), attribute (`@`), self (`.`), parent (`..`), descendant-or-self (`//`),
 * plus the explicit axes actually found in real `@test` expressions: `ancestor::`, `preceding::`,
 * `preceding-sibling::`, `self::`, `parent::`, and `ancestor-or-self::` ( CIUS-HR-UBL 1.0.4, in a
 * rule context ). Any other axis is a clear, located error, not a silent no-op (Regel A.6).
 *
 * ⚠️ `namespace::` is the one axis a shipped rule set still uses that neither engine knows
 * ( UBL-TR 1.2.1, `string(namespace::xsi)` on the `Invoice` root ). PHP's DOM exposes namespace
 * nodes only through `DOMXPath`, not as children or attributes, so it is deliberately left out and
 * fails loudly here, exactly as it does in VAMPIRE JS.
 *
 * Operators beyond the arithmetic and comparisons: `|` / `union`, `intersect`, `except` and the node
 * comparison `is`. Kind tests: `node()`, `text()`, `element()`, `attribute()`, `comment()` and
 * `processing-instruction()`. `position()` and `last()` read the focus of the innermost predicate
 * ( `$focus` ). These match the interpreter of VAMPIRE JS, including
 * the type errors the specification asks for - see `nodeIdentity()` and `nodeSetIntersectExcept()`.
 * Both engines run the same shared vectors ( `tests/vectors/xpath-node-operators.json` ); a change to
 * any of these operators belongs in both interpreters and in those vectors at once.
 */
final class Interpreter
{
    /**
     * @param array<string, string> $namespaces prefix => namespace URI, e.g. ['cac' => 'urn:oasis:...']
     */
    public function __construct(
        private readonly array $namespaces,
        private readonly FunctionLibrary $functions = new FunctionLibrary(),
        private readonly ?PathCache $pathCache = null,
    ) {
    }

    /**
     * @param array<string, Sequence> $variables
     */
    public function evaluate(Expr $expr, \DOMNode $contextNode, array $variables = []): Sequence
    {
        return match (true) {
            $expr instanceof Literal => $this->evalLiteral($expr),
            $expr instanceof VarRef => $this->evalVarRef($expr, $variables),
            $expr instanceof PathExpr => $this->evalPath($expr, $contextNode, $variables),
            $expr instanceof BinaryOp => $this->evalBinaryOp($expr, $contextNode, $variables),
            $expr instanceof UnaryOp => $this->evalUnaryOp($expr, $contextNode, $variables),
            $expr instanceof FunctionCall => $this->evalFunctionCall($expr, $contextNode, $variables),
            $expr instanceof SequenceExpr => $this->evalSequenceExpr($expr, $contextNode, $variables),
            $expr instanceof RangeExpr => $this->evalRangeExpr($expr, $contextNode, $variables),
            $expr instanceof IfExpr => $this->evalIfExpr($expr, $contextNode, $variables),
            $expr instanceof ForExpr => $this->evalForExpr($expr, $contextNode, $variables),
            $expr instanceof QuantifiedExpr => $this->evalQuantifiedExpr($expr, $contextNode, $variables),
            $expr instanceof LetExpr => $this->evaluate(
                $expr->return,
                $contextNode,
                [...$variables, $expr->var => $this->evaluate($expr->value, $contextNode, $variables)]
            ),
            $expr instanceof CastableExpr => $this->evalCastableExpr($expr, $contextNode, $variables),
            $expr instanceof CastExpr => $this->evalCastExpr($expr, $contextNode, $variables),
            $expr instanceof ParenExpr => $this->evaluate($expr->inner, $contextNode, $variables),
            default => throw new UnsupportedConstructException(
                'unknown AST node type: ' . $expr->node(),
                $expr->node(),
                $expr->pos()
            ),
        };
    }

    private function evalLiteral(Literal $expr): Sequence
    {
        return match ($expr->kind) {
            'string' => Sequence::singleton(AtomicValue::string($expr->value)),
            'integer' => Sequence::singleton(AtomicValue::integer(Decimal::fromLexical($expr->value))),
            'decimal' => Sequence::singleton(AtomicValue::decimal(Decimal::fromLexical($expr->value))),
        };
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalVarRef(VarRef $expr, array $variables): Sequence
    {
        return $variables[$expr->name] ?? throw new UnsupportedConstructException(
            'undefined variable $' . $expr->name,
            '$' . $expr->name,
            $expr->pos()
        );
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalIfExpr(IfExpr $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        $condHolds = $this->evaluate($expr->cond, $contextNode, $variables)->effectiveBooleanValue();

        return $this->evaluate($condHolds ? $expr->then : $expr->else, $contextNode, $variables);
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalSequenceExpr(SequenceExpr $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        $result = Sequence::empty();
        foreach ($expr->items as $item) {
            $result = $result->append($this->evaluate($item, $contextNode, $variables));
        }

        return $result;
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalRangeExpr(RangeExpr $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        $from = $this->singleDecimal($this->evaluate($expr->from, $contextNode, $variables), $expr->pos());
        $to = $this->singleDecimal($this->evaluate($expr->to, $contextNode, $variables), $expr->pos());

        $items = [];
        $current = $from;
        while ($current->compareTo($to) <= 0) {
            $items[] = AtomicValue::integer($current);
            $current = $current->add(Decimal::fromInt(1));
        }

        return Sequence::of($items);
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalForExpr(ForExpr $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        $in = $this->evaluate($expr->in, $contextNode, $variables);
        $result = Sequence::empty();

        foreach ($in->items() as $item) {
            $iterationVars = [...$variables, $expr->var => Sequence::singleton($item)];
            $result = $result->append($this->evaluate($expr->return, $contextNode, $iterationVars));
        }

        return $result;
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalQuantifiedExpr(QuantifiedExpr $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        $in = $this->evaluate($expr->in, $contextNode, $variables);
        $isEvery = $expr->quantifier === 'every';

        foreach ($in->items() as $item) {
            $iterationVars = [...$variables, $expr->var => Sequence::singleton($item)];
            $holds = $this->evaluate($expr->satisfies, $contextNode, $iterationVars)->effectiveBooleanValue();

            if ($isEvery && !$holds) {
                return Sequence::singleton(AtomicValue::boolean(false));
            }
            if (!$isEvery && $holds) {
                return Sequence::singleton(AtomicValue::boolean(true));
            }
        }

        return Sequence::singleton(AtomicValue::boolean($isEvery));
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalUnaryOp(UnaryOp $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        $operand = $this->evaluate($expr->operand, $contextNode, $variables);
        if ($operand->isEmpty()) {
            // Same XPath 2.0 §3.4 empty-propagation rule as evalArithmetic() - unary +/- is part
            // of the same grammar production ("Arithmetic Expressions"), see its own docblock.
            return Sequence::empty();
        }

        $value = $this->singleDecimal($operand, $expr->pos());

        return Sequence::singleton(AtomicValue::decimal($expr->op === '-' ? $value->negate() : $value));
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalCastableExpr(CastableExpr $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        $operand = $this->evaluate($expr->operand, $contextNode, $variables);

        return Sequence::singleton(AtomicValue::boolean($this->tryCast($operand, $expr->type->local) !== null));
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalCastExpr(CastExpr $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        $operand = $this->evaluate($expr->operand, $contextNode, $variables);
        $result = $this->tryCast($operand, $expr->type->local);

        if ($result === null) {
            throw new UnsupportedConstructException(
                "cast to xs:{$expr->type->local} failed (err:FORG0001)",
                'cast as',
                $expr->pos()
            );
        }

        return Sequence::singleton($result);
    }

    private function tryCast(Sequence $operand, string $targetType): ?AtomicValue
    {
        if ($operand->isEmpty()) {
            return null;
        }
        $atomized = $operand->atomize();
        if (count($atomized) !== 1) {
            return null;
        }
        $value = $atomized[0];

        try {
            return match ($targetType) {
                'string' => AtomicValue::string($value->asString()),
                'decimal' => AtomicValue::decimal($value->asDecimal()),
                'integer' => AtomicValue::integer($value->asDecimal()),
                'boolean' => AtomicValue::boolean($value->castToBoolean()),
                'date' => $this->castToDate($value),
                default => throw new UnsupportedConstructException(
                    "cast to unsupported type \"xs:$targetType\"",
                    "xs:$targetType",
                    0
                ),
            };
        } catch (\InvalidArgumentException) {
            return null; // not castable - a normal, expected outcome for "castable as", not an error.
        }
    }

    /**
     * xs:date lexical form: YYYY-MM-DD, optional timezone. Stored as a normalised string
     * AtomicValue (this project does not model a distinct date arithmetic type - the real corpus
     * only ever uses xs:date for equality/ordering comparisons, both of which plain lexical-form
     * comparison already gives correctly for well-formed ISO dates).
     */
    private function castToDate(AtomicValue $value): AtomicValue
    {
        $string = trim($value->asString());
        if (preg_match('/^-?\d{4,}-\d{2}-\d{2}(Z|[+-]\d{2}:\d{2})?$/', $string) !== 1) {
            throw new \InvalidArgumentException("not a valid xs:date lexical value: \"$string\"");
        }

        return AtomicValue::string($string);
    }

    private function singleDecimal(Sequence $sequence, int $pos): Decimal
    {
        $atomized = $sequence->atomize();
        if (count($atomized) !== 1) {
            throw new UnsupportedConstructException('expected a single numeric value', 'arithmetic', $pos);
        }

        return $atomized[0]->asDecimal();
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalFunctionCall(FunctionCall $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        $args = array_map(
            fn (Expr $arg): Sequence => $this->evaluate($arg, $contextNode, $variables),
            $expr->args
        );

        [$position, $size] = $this->focus === [] ? [1, 1] : $this->focus[array_key_last($this->focus)];

        return $this->functions->call(
            $expr->name->prefix ?? '',
            $expr->name->local,
            $args,
            $contextNode,
            $position,
            $size
        );
    }

    /**
     * The context position and size of every predicate that is being evaluated, innermost last, as
     * `[position, size]`. `fn:position()` and `fn:last()` read the top entry; outside any predicate the
     * focus is the single context item, and both answer 1 - as in VAMPIRE JS ( `currentFocus()` ).
     *
     * ⚠️ A STACK, NOT A FIELD. `cac:Line[cbc:ID = ../cac:Other[last()]/cbc:ID and position() = last()]`
     * evaluates an inner predicate in the middle of the outer one. With a single field the inner one
     * would leave its own size behind, and the outer `last()` would silently count the other lines.
     *
     * ⚠️ ONLY A PREDICATE SETS THE FOCUS HERE. In XPath 2.0 a path step `E1/E2` also gives E2 a focus
     * ( the position of each E1 item ), so `cac:Item/position()` would yield 1, 2, 3. Neither engine
     * does that - VAMPIRE JS keeps the outer focus as well - and no rule set uses `position()` or
     * `last()` outside a predicate. Two consequences follow, and both must change together if this
     * ever does: the two engines would differ, and `PathCache`, whose key holds no focus, could hand
     * out a result computed under another one. Inside a predicate the cache is safe, because the
     * predicate sets its own focus before anything in it runs.
     *
     * @var list<array{int, int}>
     */
    private array $focus = [];

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalBinaryOp(BinaryOp $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        $op = $expr->op;

        if ($op === 'and') {
            $left = $this->evaluate($expr->left, $contextNode, $variables)->effectiveBooleanValue();
            if (!$left) {
                return Sequence::singleton(AtomicValue::boolean(false));
            }
            $right = $this->evaluate($expr->right, $contextNode, $variables)->effectiveBooleanValue();
            return Sequence::singleton(AtomicValue::boolean($right));
        }
        if ($op === 'or') {
            $left = $this->evaluate($expr->left, $contextNode, $variables)->effectiveBooleanValue();
            if ($left) {
                return Sequence::singleton(AtomicValue::boolean(true));
            }
            $right = $this->evaluate($expr->right, $contextNode, $variables)->effectiveBooleanValue();
            return Sequence::singleton(AtomicValue::boolean($right));
        }

        $left = $this->evaluate($expr->left, $contextNode, $variables);
        $right = $this->evaluate($expr->right, $contextNode, $variables);

        if (in_array($op, ['=', '!=', '<', '<=', '>', '>='], true)) {
            return Sequence::singleton(AtomicValue::boolean(Comparisons::general($left, $right, $op)));
        }
        if (in_array($op, ['eq', 'ne', 'lt', 'le', 'gt', 'ge'], true)) {
            /*
             * ⚠️ AN EMPTY OPERAND MAKES A VALUE COMPARISON THE EMPTY SEQUENCE, NOT AN ERROR ( XPath 2.0
             * §3.5.1 ). Throwing here would be costly, because a throwing official rule ends the whole
             * validation: `xs:decimal(cbc:Missing) le 1` would cost the invoice its report. VAMPIRE JS
             * answers the empty sequence as well; the shared vectors `value-comparison-empty-*` hold both
             * engines to it. More than one item per side stays the type error it is.
             */
            // `isEmpty()` suffices: every node and every value atomises to exactly one item.
            if ($left->isEmpty() || $right->isEmpty()) {
                return Sequence::empty();
            }

            return Sequence::singleton(AtomicValue::boolean(Comparisons::value(
                $this->singleAtomic($left, $expr->pos()),
                $this->singleAtomic($right, $expr->pos()),
                $op
            )));
        }
        if (in_array($op, ['+', '-', '*', 'div', 'mod', 'idiv'], true)) {
            return $this->evalArithmetic($op, $left, $right, $expr->pos());
        }
        if ($op === '|' || $op === 'union') {
            return $this->nodeSetUnion($left, $right);
        }
        if ($op === 'intersect' || $op === 'except') {
            return $this->nodeSetIntersectExcept($op, $left, $right, $expr->pos());
        }
        if ($op === 'is') {
            return $this->nodeIdentity($left, $right, $expr->pos());
        }

        // ⚠️ `<<` and `>>` ( document order ) are deliberately absent: neither the lexer in devkit/
        // nor VAMPIRE JS knows them, so no compiled tree can carry them. Building them on this side
        // alone would make the two engines differ on the first rule set that used them.
        throw new UnsupportedConstructException(
            "operator \"$op\" not implemented in the Interpreter",
            $op,
            $expr->pos()
        );
    }

    private function singleAtomic(Sequence $sequence, int $pos): AtomicValue
    {
        $atomized = $sequence->atomize();
        if (count($atomized) !== 1) {
            throw new UnsupportedConstructException(
                'value comparison needs exactly one value per side',
                'eq/ne/lt/le/gt/ge',
                $pos
            );
        }

        return $atomized[0];
    }

    /**
     * ⚠️ Real bug, found via the official XRechnung test suite (BR-CO-13 on every CII invoice
     * with no `ram:AllowanceTotalAmount`, an OPTIONAL element the rule text itself guards with a
     * separate `not(ram:AllowanceTotalAmount)` fallback branch): an arithmetic operator with an
     * empty-sequence operand (e.g. `xs:decimal(ram:AllowanceTotalAmount[1])` when that element is
     * genuinely absent) must propagate an EMPTY result, per XPath 2.0 §3.4 "Arithmetic
     * Expressions" - NOT a type error. Verified via web search cross-referencing the spec (a direct
     * WebFetch of the spec page kept truncating before §3.4's own text, so corroborated across
     * multiple independent citations instead of a single unverifiable source). A bare "expected a
     * single numeric value" for an empty operand was wrong; only a MULTI-item operand still is.
     */
    private function evalArithmetic(string $op, Sequence $left, Sequence $right, int $pos): Sequence
    {
        if ($left->isEmpty() || $right->isEmpty()) {
            return Sequence::empty();
        }

        $a = $this->singleDecimal($left, $pos);
        $b = $this->singleDecimal($right, $pos);

        /*
         * ⚠️ `idiv` TRUNCATES TOWARDS ZERO, IT DOES NOT FLOOR ( XPath 2.0 F&O §6.2.5 ), and `mod` is
         * defined through it: a mod b = a - ( a idiv b ) * b, so the result takes the sign of the
         * dividend. With `floor()`, `-7 idiv 2` gives -4 instead of -3, `-7 mod 2` gives 1 instead of -1,
         * and `7 mod -2` gives -1 instead of 1. For two positive operands floor and truncation agree,
         * which is why no rule set shows the difference - PEPPOL-EN16931-UBL and XRechnung use `mod` only
         * on check digits. The shared vectors `arith-idiv-negative` and `arith-mod-negative-*` hold both
         * engines to it.
         */
        $truncate = static fn (Decimal $q): Decimal => $q->isNegative() ? $q->negate()->floor()->negate() : $q->floor();

        $result = match ($op) {
            '+' => $a->add($b),
            '-' => $a->subtract($b),
            '*' => $a->multiply($b),
            'div' => $a->divide($b),
            'idiv' => $truncate($a->divide($b)),
            'mod' => $a->subtract($truncate($a->divide($b))->multiply($b)),
            default => throw new UnsupportedConstructException("unknown arithmetic operator \"$op\"", $op, $pos),
        };

        return Sequence::singleton(AtomicValue::decimal($result));
    }

    /**
     * ⚠️ Same `spl_object_id()`-for-DOMNode-identity reasoning as `dedupeNodes()` above applies here
     * too - safe because `$left`/`$right` are both already fully-evaluated, alive Sequences by the
     * time this runs. Same "nodes only" fix applied for the same reason: `|` is only spec-defined
     * over node-sets, but if either side is somehow atomic (not rejected as a type error elsewhere
     * yet), those items must never be deduped by value - see `dedupeNodes()`'s own docblock for the
     * real bug this class of mistake caused.
     */
    private function nodeSetUnion(Sequence $left, Sequence $right): Sequence
    {
        $seen = [];
        $result = [];
        foreach ([...$left->items(), ...$right->items()] as $item) {
            if (!$item instanceof \DOMNode) {
                $result[] = $item;
                continue;
            }
            $key = spl_object_id($item);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $item;
            }
        }

        return Sequence::of($result);
    }

    /**
     * `A intersect B` keeps the nodes of A that are also in B, `A except B` the nodes of A that are
     * not. XPath 2.0 §3.3.4.
     *
     * ⚠️ BOTH OPERANDS MUST BE NODES. An atomic item is a type error ( err:XPTY0004 ), and it is raised
     * here instead of being passed through as `nodeSetUnion()` does: identity is undefined for a
     * value, so "is 3 in the other side" has no answer that could be right. VAMPIRE JS raises the same
     * type error ( `nodeSetOp()` there ).
     *
     * ⚠️ The result is a node SET: a node named twice on the left appears once. The order is the order
     * of the left operand, exactly like `nodeSetUnion()` and like VAMPIRE JS ( `nodeSetOp()` ); a path
     * already yields document order, and nothing here reorders it.
     *
     * `spl_object_id()` is safe for the same reason as in `dedupeNodes()`: both operands are fully
     * evaluated and alive while this runs.
     */
    private function nodeSetIntersectExcept(string $op, Sequence $left, Sequence $right, int $pos): Sequence
    {
        $inRight = [];
        foreach ($right->items() as $item) {
            if (!$item instanceof \DOMNode) {
                throw new UnsupportedConstructException(
                    "operator \"$op\" needs nodes on both sides, got an atomic value (err:XPTY0004)",
                    $op,
                    $pos
                );
            }
            $inRight[spl_object_id($item)] = true;
        }

        $keepContained = $op === 'intersect';
        $seen = [];
        $result = [];
        foreach ($left->items() as $item) {
            if (!$item instanceof \DOMNode) {
                throw new UnsupportedConstructException(
                    "operator \"$op\" needs nodes on both sides, got an atomic value (err:XPTY0004)",
                    $op,
                    $pos
                );
            }
            $key = spl_object_id($item);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (isset($inRight[$key]) === $keepContained) {
                $result[] = $item;
            }
        }

        return Sequence::of($result);
    }

    /**
     * The node comparison `A is B`: true when both operands are the SAME node, not merely equal in
     * value. XPath 2.0 §3.5.3.
     *
     * Measured need: ZUGFeRD 2.4 and 2.5 EXTENDED carry `some $other in
     * ../ram:IncludedSupplyChainTradeLineItem satisfies ( not($other is .) and … )` - "another line
     * than this one". Without the operator every EXTENDED invoice of those two editions aborted.
     *
     * ⚠️ THREE OUTCOMES, NOT TWO. The specification gives:
     *   - the EMPTY SEQUENCE when either operand is empty. Inside `not()` or a predicate it reads as
     *     false, which is the only way the rule sets use it; where the result is compared as a value
     *     ( `(A is B) = false()` ) the two differ.
     *   - a type error ( err:XPTY0004 ) when an operand holds more than one item or an atomic value.
     *     Raising it is what the specification asks for, and a rule that relied on "the first one"
     *     would be a broken rule worth hearing about.
     *   - otherwise true or false.
     *
     * ⚠️ VAMPIRE JS gives the same three outcomes ( `nodeIdentity()` there ), held by the shared vectors
     * `is-empty-*` and `is-*-error`; answering `false` for an empty operand or taking the first of several
     * nodes would break them.
     *
     * ⚠️ `isSameNode()`, not `===` and not `spl_object_id()`. PHP's DOM normally hands out one wrapper
     * per libxml node while that wrapper is alive, so `===` would usually work - "usually" is the trap
     * `PathCache` already fell into ( see `NodeIdentity::of()` ). `isSameNode()` compares the
     * underlying node and does not depend on wrapper lifetimes.
     */
    private function nodeIdentity(Sequence $left, Sequence $right, int $pos): Sequence
    {
        if ($left->isEmpty() || $right->isEmpty()) {
            return Sequence::empty();
        }

        $a = $this->singleNodeOperand($left, $pos);
        $b = $this->singleNodeOperand($right, $pos);

        return Sequence::singleton(AtomicValue::boolean($a->isSameNode($b)));
    }

    private function singleNodeOperand(Sequence $operand, int $pos): \DOMNode
    {
        $item = $operand->first();

        if ($operand->count() !== 1 || !$item instanceof \DOMNode) {
            throw new UnsupportedConstructException(
                'node comparison "is" needs one node per side, got ' . $operand->count()
                    . ($item instanceof \DOMNode ? ' nodes' : ' item(s) with an atomic value') . ' (err:XPTY0004)',
                'is',
                $pos
            );
        }

        return $item;
    }

    // --- path evaluation ----------------------------------------------------------------------

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalPath(PathExpr $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        // Cache only paths with no variable reference anywhere in their steps/predicates
        // (Task 2.5's own key safety property, see PathCache's docblock): the cache key is
        // (AST node identity, context node identity) alone, with no representation of $variables
        // in it - a path whose predicate reads a loop variable (e.g. inside `for $x in ... return
        // somePath[$x = ...]`) would otherwise silently return the FIRST iteration's cached
        // result on every later iteration. Exercised by
        // PathCacheTest::testVariableDependentPredicateIsNeverWronglyCachedAcrossLoopIterations().
        if ($this->pathCache !== null && !$this->containsVarRef($expr)) {
            $compute = fn (): Sequence => $this->evalPathUncached($expr, $contextNode, $variables);

            /*
             * ⚠️ AN ABSOLUTE PATH IS REMEMBERED PER DOCUMENT, NOT PER CONTEXT NODE ( the measured
             * numbers stand in PathCache::rememberForDocument() ).
             *
             * Why this is safe: `evalPathUncached()` starts an absolute path at `rootOf()`, so the
             * outer context node never enters the result - `.`, relative paths, `position()` and
             * `last()` inside its predicates refer to the predicate's own items. The one thing that
             * could still leak in is a variable, and `containsVarRef()` above already keeps every
             * such path out ( it is stricter than the JS gate: `for`, `some`/`every` and `let` count
             * as variable use even when the bound name is never read - stricter only costs a miss ).
             * An XSLT `current()` would be a second leak; `FunctionLibrary` does not know it, so a
             * rule set using it fails loudly instead of being cached wrongly.
             *
             * ⚠️ NOT for a document `filterAtomicPredicates()` made up. That document GROWS: each
             * tokenized item is appended to it before its predicate runs, so an absolute path there
             * would give a different answer on the next item, and a cached answer would be stale.
             * VAMPIRE JS has no such document - an atomic context takes the uncached way there - so
             * excluding it here is what keeps both sides equal. Such paths keep the per-context-node
             * key below, exactly as before this change.
             *
             * ⚠️ A node without a document ( `new \DOMElement()` ) has itself as root, which is not a
             * `DOMDocument`, so it also keeps the old key.
             */
            if ($expr->absolute) {
                $root = $this->rootOf($contextNode);

                if ($root instanceof \DOMDocument && !isset($this->syntheticDocuments[$root])) {
                    return $this->pathCache->rememberForDocument($expr, $root, $this->namespaceKey(), $compute);
                }
            }

            return $this->pathCache->remember($expr, $contextNode, $compute);
        }

        return $this->evalPathUncached($expr, $contextNode, $variables);
    }

    /**
     * Documents `filterAtomicPredicates()` created; see `evalPath()` for why their absolute paths
     * are never remembered per document. A `WeakMap`, so a finished one does not stay alive here.
     *
     * @var \WeakMap<\DOMDocument, true>|null
     */
    private ?\WeakMap $syntheticDocuments = null;

    private ?string $namespaceKey = null;

    /** The prefix bindings as one string, computed once - `$namespaces` is readonly. */
    private function namespaceKey(): string
    {
        if ($this->namespaceKey === null) {
            $namespaces = $this->namespaces;
            ksort($namespaces);
            $this->namespaceKey = md5(serialize($namespaces));
        }

        return $this->namespaceKey;
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function evalPathUncached(PathExpr $expr, \DOMNode $contextNode, array $variables): Sequence
    {
        if ($expr->steps === []) {
            return Sequence::singleton($this->rootOf($contextNode)); // bare "/"
        }

        $current = [$expr->absolute ? $this->rootOf($contextNode) : $contextNode];

        foreach ($expr->steps as $step) {
            $next = [];
            foreach ($current as $node) {
                foreach ($this->applyStep($step, $node, $variables)->items() as $item) {
                    $next[] = $item;
                }
            }
            $current = $this->dedupeNodes($next);
        }

        return Sequence::of($current);
    }

    private function rootOf(\DOMNode $node): \DOMNode
    {
        return $node->ownerDocument ?? $node;
    }

    /** @var array<int, bool> spl_object_id($expr) => result, memoized since the AST is immutable */
    private array $containsVarRefCache = [];

    /**
     * Recursively checks whether $expr (a whole PathExpr, including every step and predicate)
     * references any variable anywhere - the safety gate for PathCache, see evalPath()'s docblock.
     */
    private function containsVarRef(Expr $expr): bool
    {
        $id = spl_object_id($expr);
        if (isset($this->containsVarRefCache[$id])) {
            return $this->containsVarRefCache[$id];
        }

        $result = match (true) {
            $expr instanceof VarRef => true,
            $expr instanceof Literal => false,
            $expr instanceof PathExpr => $this->stepsContainVarRef($expr->steps),
            $expr instanceof BinaryOp => $this->containsVarRef($expr->left) || $this->containsVarRef($expr->right),
            $expr instanceof UnaryOp => $this->containsVarRef($expr->operand),
            $expr instanceof FunctionCall => $this->anyContainsVarRef($expr->args),
            $expr instanceof SequenceExpr => $this->anyContainsVarRef($expr->items),
            $expr instanceof RangeExpr => $this->containsVarRef($expr->from) || $this->containsVarRef($expr->to),
            $expr instanceof IfExpr => $this->containsVarRef($expr->cond)
                || $this->containsVarRef($expr->then) || $this->containsVarRef($expr->else),
            $expr instanceof ForExpr => true, // binds its own variable - never cache-safe to treat as a closed sub-tree
            $expr instanceof QuantifiedExpr => true,
            $expr instanceof LetExpr => true,
            $expr instanceof CastableExpr => $this->containsVarRef($expr->operand),
            $expr instanceof CastExpr => $this->containsVarRef($expr->operand),
            $expr instanceof ParenExpr => $this->containsVarRef($expr->inner),
            default => true, // unknown node shape - safest default is "do not cache".
        };

        $this->containsVarRefCache[$id] = $result;

        return $result;
    }

    /**
     * @param Step[] $steps
     */
    private function stepsContainVarRef(array $steps): bool
    {
        foreach ($steps as $step) {
            if ($step->primary !== null && $this->containsVarRef($step->primary)) {
                return true;
            }
            if ($this->anyContainsVarRef($step->predicates)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Expr[] $exprs
     */
    private function anyContainsVarRef(array $exprs): bool
    {
        foreach ($exprs as $e) {
            if ($this->containsVarRef($e)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ⚠️ Only DOMNode items are ever deduplicated here - a real, measured bug (found via the
     * official XRechnung test suite, BR-CO-10's own real invoice data) had this method dedupe
     * ATOMIC VALUES too, keyed by their string representation (`'#' . $item->asString()`). A path
     * step's result is a node-SET only when the step yields nodes (union/axis semantics genuinely
     * require no duplicate nodes); when the step's own primary expression yields ATOMIC values
     * instead (e.g. a function-call step like `xs:decimal(cbc:LineExtensionAmount)`, the only real
     * position such values can occur per `Step`'s own docblock, since a further axis step after an
     * atomic result would immediately fail its own DOMNode-context check), the result is a plain
     * SEQUENCE - XPath sequences keep every duplicate. Deduping by value silently collapsed three
     * `cbc:LineExtensionAmount` occurrences of "400" in a real invoice down to one, making
     * `sum(...)` under-count by 800 and fail BR-CO-10 on an officially VALID test invoice. Proven
     * with a throwaway harness before the fix: the raw sequence
     * `//(cac:InvoiceLine|cac:CreditNoteLine)/xs:decimal(cbc:LineExtensionAmount)` had 9 items
     * instead of the real 11 (exactly the two duplicate "400"s missing).
     *
     * `spl_object_id()` for the DOMNode branch is safe HERE, unlike `PathCache` (see its
     * `NodeIdentity::of()` docblock for the proven failure mode there), because every item being
     * deduped is ALIVE SIMULTANEOUSLY in the `$items` array passed in: PHP's DOM extension reuses
     * the same PHP wrapper object for a given underlying libxml node as long as some live reference
     * to it still exists, and only risks handing out a fresh (possibly id-recycled) wrapper once
     * the old one has actually been garbage collected. There is no such gap here - confirmed with a
     * throwaway harness deliberately mirroring the PathCache repro (`for $k in (1, 2) return
     * (cac:Item[@key = $k] | cac:Item[@key = $k])`, unioning a node with itself across loop
     * iterations): correctly deduped every time, unlike PathCache's cross-iteration cache lookup,
     * which DOES leave such a gap. Switch this to `NodeIdentity::of()` too if this method (or
     * `nodeSetUnion()` below, which has the SAME "nodes only" fix applied for the same reason) is
     * ever changed to persist identity across separate `evaluate()` calls.
     *
     * @param list<\DOMNode|AtomicValue> $items
     * @return list<\DOMNode|AtomicValue>
     */
    private function dedupeNodes(array $items): array
    {
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            if (!$item instanceof \DOMNode) {
                $result[] = $item;
                continue;
            }
            $key = spl_object_id($item);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<string, Sequence> $variables
     */
    private function applyStep(Step $step, \DOMNode|AtomicValue $contextItem, array $variables): Sequence
    {
        if ($step->primary !== null) {
            if (!$contextItem instanceof \DOMNode) {
                throw new UnsupportedConstructException('a primary-expression step needs a node context', 'step', 0);
            }
            $result = $this->evaluate($step->primary, $contextItem, $variables);

            return $this->filterPredicates($result->items(), $step->predicates, $variables);
        }

        if (!$contextItem instanceof \DOMNode) {
            throw new UnsupportedConstructException('an axis step needs a node context', 'step', 0);
        }

        // Validated unconditionally, BEFORE filtering candidates: an unbound namespace prefix must
        // be a clear, located error even when the axis happens to have zero candidate nodes to
        // test the NodeTest against - a first draft only checked this lazily inside the
        // array_filter callback below, so it silently returned an empty/false result instead of
        // erroring whenever the context node happened to have no children. Caught by
        // InterpreterTest::testUnboundNamespacePrefixThrows() against a childless context node.
        $this->assertNodeTestPrefixIsBound($step->test);

        $candidates = $this->axisNodes((string) $step->axis, $contextItem);
        $matching = array_values(array_filter(
            $candidates,
            fn (\DOMNode $n): bool => $this->nodeTestMatches($step->test, $n)
        ));

        return $this->filterPredicates($matching, $step->predicates, $variables);
    }

    /**
     * @return \DOMNode[]
     */
    private function axisNodes(string $axis, \DOMNode $node): array
    {
        return match ($axis) {
            'child' => iterator_to_array($node->childNodes ?? new \ArrayIterator([]), false),
            'attribute' => $this->attributeAxis($node),
            'self' => [$node],
            'parent' => $node->parentNode !== null ? [$node->parentNode] : [],
            'descendant-or-self' => [$node, ...$this->descendants($node)],
            'descendant' => $this->descendants($node),
            'ancestor' => $this->ancestors($node),
            /*
             * ⚠️ A REVERSE AXIS: the context node itself first, then its parent, up to the document
             * node - NOT document order. `filterPredicates()` numbers positions in exactly the order
             * this array has, which is what XPath 2.0 §3.2.2 asks for on a reverse axis: in
             * `ancestor-or-self::*[1]` position 1 is the node itself ( if it is an element ), and in
             * `ancestor-or-self::cac:X[last()]` it is the OUTERMOST `cac:X`. Sorting the array into
             * document order "to be safe" would silently flip both. VAMPIRE JS builds the same list in
             * the same order ( `axisNodes()` there ).
             *
             * Measured need: CIUS-HR-UBL 1.0.4, rule context of pattern M12,
             * `//*[not(*) and not(normalize-space()) and not(ancestor-or-self::sig:UBLDocumentSignatures)]`.
             * Without this axis the whole rule set aborted on 24 of the 144 corpus documents.
             */
            'ancestor-or-self' => [$node, ...$this->ancestors($node)],
            'preceding-sibling' => $this->precedingSiblings($node),
            'following-sibling' => $this->followingSiblings($node),
            'preceding' => $this->preceding($node),
            'following' => $this->following($node),
            default => throw new UnsupportedConstructException("axis \"$axis\" not implemented", "$axis::", 0),
        };
    }

    /**
     * @return \DOMNode[]
     */
    private function attributeAxis(\DOMNode $node): array
    {
        if (!$node instanceof \DOMElement) {
            return [];
        }

        return iterator_to_array($node->attributes ?? new \ArrayIterator([]), false);
    }

    /**
     * @return \DOMNode[]
     */
    private function descendants(\DOMNode $node): array
    {
        $result = [];
        foreach ($node->childNodes ?? [] as $child) {
            $result[] = $child;
            foreach ($this->descendants($child) as $d) {
                $result[] = $d;
            }
        }

        return $result;
    }

    /**
     * Nearest ancestor first (spec: ancestor axis is in reverse document order).
     *
     * @return \DOMNode[]
     */
    private function ancestors(\DOMNode $node): array
    {
        $result = [];
        $current = $node->parentNode;
        while ($current !== null) {
            $result[] = $current;
            $current = $current->parentNode;
        }

        return $result;
    }

    /**
     * @return \DOMNode[]
     */
    private function precedingSiblings(\DOMNode $node): array
    {
        $result = [];
        $sibling = $node->previousSibling;
        while ($sibling !== null) {
            $result[] = $sibling;
            $sibling = $sibling->previousSibling;
        }

        return $result;
    }

    /**
     * @return \DOMNode[]
     */
    private function followingSiblings(\DOMNode $node): array
    {
        $result = [];
        $sibling = $node->nextSibling;
        while ($sibling !== null) {
            $result[] = $sibling;
            $sibling = $sibling->nextSibling;
        }

        return $result;
    }

    /**
     * Every node before $node in document order, excluding its own ancestors (spec definition of
     * the "preceding" axis) - walks the whole document once, which is acceptable for invoice-sized
     * documents (see docs/developer/performance.md, Task 8.5, for anything beyond this project's own scope).
     *
     * @return \DOMNode[]
     */
    private function preceding(\DOMNode $node): array
    {
        $ancestorIds = array_map(spl_object_id(...), $this->ancestors($node));
        $root = $this->rootOf($node);

        $result = [];
        $stop = false;
        $this->collectPreceding($root, $node, $ancestorIds, $result, $stop);

        return $result;
    }

    /**
     * @param int[] $ancestorIds
     * @param \DOMNode[] $result
     */
    private function collectPreceding(
        \DOMNode $current,
        \DOMNode $target,
        array $ancestorIds,
        array &$result,
        bool &$stop
    ): void {
        if ($stop) {
            return;
        }
        if (spl_object_id($current) === spl_object_id($target)) {
            $stop = true;
            return;
        }
        if (!in_array(spl_object_id($current), $ancestorIds, true) && $current !== $this->rootOf($target)) {
            $result[] = $current;
        }
        foreach ($current->childNodes ?? [] as $child) {
            $this->collectPreceding($child, $target, $ancestorIds, $result, $stop);
            if ($stop) {
                return;
            }
        }
    }

    /**
     * @return \DOMNode[]
     */
    private function following(\DOMNode $node): array
    {
        $ancestorIds = array_map(spl_object_id(...), $this->ancestors($node));
        $descendantIds = array_map(spl_object_id(...), $this->descendants($node));
        $excluded = [...$ancestorIds, ...$descendantIds, spl_object_id($node)];
        $root = $this->rootOf($node);

        $result = [];
        $afterTarget = false;
        $this->collectFollowing($root, $node, $excluded, $result, $afterTarget);

        return $result;
    }

    /**
     * @param int[] $excluded
     * @param \DOMNode[] $result
     */
    private function collectFollowing(
        \DOMNode $current,
        \DOMNode $target,
        array $excluded,
        array &$result,
        bool &$afterTarget
    ): void {
        $isCandidate = !in_array(spl_object_id($current), $excluded, true) && $current !== $this->rootOf($target);
        if ($afterTarget && $isCandidate) {
            $result[] = $current;
        }
        if (spl_object_id($current) === spl_object_id($target)) {
            $afterTarget = true;
        }
        foreach ($current->childNodes ?? [] as $child) {
            $this->collectFollowing($child, $target, $excluded, $result, $afterTarget);
        }
    }

    private function assertNodeTestPrefixIsBound(?NodeTest $test): void
    {
        $prefix = $test?->name?->prefix;
        if ($prefix !== null && !isset($this->namespaces[$prefix])) {
            throw new UnsupportedConstructException("unbound namespace prefix \"$prefix\"", $prefix, 0);
        }
    }

    private function nodeTestMatches(?NodeTest $test, \DOMNode $node): bool
    {
        if ($test === null) {
            return true;
        }

        return match ($test->kind) {
            'wildcard' => $this->wildcardMatches($test, $node),
            'kindtest' => $this->kindTestMatches((string) $test->kindtestName, $node),
            'name' => $this->nameMatches($test, $node),
        };
    }

    private function wildcardMatches(NodeTest $test, \DOMNode $node): bool
    {
        if (!$node instanceof \DOMElement && !$node instanceof \DOMAttr) {
            return false;
        }
        if ($test->name === null) {
            return true; // bare "*" - any element/attribute, any namespace.
        }

        $expectedNs = $this->namespaces[(string) $test->name->prefix] ?? null;

        return $expectedNs !== null && $node->namespaceURI === $expectedNs;
    }

    /**
     * ⚠️ `element()` and `attribute()` test the node KIND only, on whatever axis they stand: on the
     * child axis `attribute()` matches nothing, and on the attribute axis `element()` matches nothing.
     * That differs from a name test or `*`, whose principal node kind follows the axis. Only the forms
     * without an argument exist - the parser refuses `element(cbc:ID)` - which is also all VAMPIRE JS
     * knows ( `matchesTest()` there ).
     *
     * ⚠️ `\DOMText` also covers CDATA sections ( `\DOMCdataSection` extends it ), which is right: the
     * XPath data model has no separate CDATA node.
     */
    private function kindTestMatches(string $kind, \DOMNode $node): bool
    {
        return match ($kind) {
            'node' => true,
            'text' => $node instanceof \DOMText,
            'element' => $node instanceof \DOMElement,
            'attribute' => $node instanceof \DOMAttr,
            'comment' => $node instanceof \DOMComment,
            'processing-instruction' => $node instanceof \DOMProcessingInstruction,
            default => false,
        };
    }

    /**
     * Assumes the prefix was already validated by `assertNodeTestPrefixIsBound()` - its one call
     * site (`applyStep()`) runs before `nodeTestMatches()`/this method are ever reached.
     */
    private function nameMatches(NodeTest $test, \DOMNode $node): bool
    {
        if (!$node instanceof \DOMElement && !$node instanceof \DOMAttr) {
            return false;
        }
        $qname = $test->name;
        if ($qname === null) {
            return false;
        }

        $expectedNs = $qname->prefix !== null ? ($this->namespaces[$qname->prefix] ?? null) : null;

        return $node->localName === $qname->local && $node->namespaceURI === $expectedNs;
    }

    /**
     * Predicates on a sequence of NODES (the overwhelming majority of real usage - every rule
     * context path and axis step) are fully supported. A predicate on a sequence of ATOMIC VALUES
     * (e.g. `tokenize(...)[7]`/`tokenize(...)[last()]`, a real but rare construct - measured 2
     * occurrences, in the real XRechnung-UBL ruleset's own `$profile` global `let` and in
     * BR-DE-18's own test) is narrowly supported for exactly those two shapes - a literal integer
     * position, or a bare `last()` call - via `filterAtomicPredicates()`, NOT by threading
     * `\DOMNode|AtomicValue` context items through the whole class (a general predicate that
     * itself needs a real context item, e.g. referencing `.`, still throws a clear, located error
     * rather than being silently mishandled, Regel A.6).
     *
     * @param list<\DOMNode|AtomicValue> $items
     * @param Expr[] $predicates
     * @param array<string, Sequence> $variables
     */
    private function filterPredicates(array $items, array $predicates, array $variables): Sequence
    {
        if ($predicates === [] || $items === []) {
            return Sequence::of($items);
        }

        if (!$items[0] instanceof \DOMNode) {
            /** @var list<AtomicValue> $atomicItems */
            $atomicItems = $items;

            return Sequence::of($this->filterAtomicPredicates($atomicItems, $predicates, $variables));
        }

        /** @var list<\DOMNode> $nodes */
        $nodes = $items;

        foreach ($predicates as $predicate) {
            $filtered = [];
            $position = 0;
            $size = count($nodes);
            foreach ($nodes as $node) {
                $position++;
                if ($this->predicateHolds($predicate, $node, $variables, $position, $size)) {
                    $filtered[] = $node;
                }
            }
            $nodes = $filtered;
        }

        return Sequence::of($nodes);
    }

    /**
     * The narrow atomic-value-predicate support `filterPredicates()`'s own docblock describes.
     * Two shapes need no real context item at all: a literal integer position (`[7]`) selects
     * directly; a bare `last()` call only needs the sequence's own size, which this method
     * already has. ( Since the focus stack exists, `fn:last()` would answer the same through
     * `predicateHolds()`; the shortcut stays because it needs no synthetic node at all. )
     *
     * Any OTHER predicate (measured real case: `tokenize(...)[starts-with(normalize-space(.),
     * '#')]`, BR-DE-18) is evaluated by realizing each atomic item as a synthetic `DOMText` node
     * holding its own string value, then reusing `predicateHolds()` unchanged - "." against a
     * text node atomizes to exactly that node's own text, which is exactly what "." means for an
     * atomic string context item. This sidesteps threading `\DOMNode|AtomicValue` through the
     * whole Interpreter (Phase 2's own deliberately deferred, broader refactor) by reusing the
     * DOM's own text-node semantics instead. Scoped to what is actually measured: every real
     * occurrence of this pattern in the corpus is over `tokenize()` results, always strings - a
     * non-string atomic value would lose its own type through this string round-trip, but none is
     * known to reach this path.
     *
     * ⚠️ Each synthetic node is APPENDED as a real child of a shared synthetic root, never left
     * unattached - a real, measured bug (found via the official XRechnung test suite, BR-DE-18 on
     * invoice 01.10a) had an earlier draft create each one as a bare, never-appended
     * `createTextNode()` call. `NodeIdentity::of()` (used by `PathCache`, and this Interpreter has
     * one whenever `filterPredicates()`'s node branch or this one calls back into `evaluate()`) can
     * only give a STABLE identity via `DOMNode::getNodePath()`, which returns null for a node with
     * no document position - falling back to `spl_object_id()`, exactly the identity source
     * `PathCache`'s own Task 2.5 bug proved unsafe across separate calls. A cacheable variable-free
     * sub-expression of the predicate (here: bare `.`) got cached keyed by one synthetic node's
     * recycled-after-GC id, then wrongly hit for a LATER, unrelated synthetic node that happened to
     * receive the same freed id - collapsing four distinct tokenized lines' own `.` values down to
     * one and silently flipping BR-DE-18's real verdict from pass to fail. Proven with a throwaway
     * harness: identical `Interpreter` construction, `pathCache: new PathCache()` vs. none, same
     * real invoice - true without the cache, false with it. Appending each node under a real root
     * gives it a genuine, distinct `getNodePath()` (e.g. `/synthetic-root/text()[3]`), so the cache
     * key is correct instead of falling back to the unsafe identity at all.
     *
     * @param list<AtomicValue> $items
     * @param Expr[] $predicates
     * @param array<string, Sequence> $variables
     * @return list<AtomicValue>
     */
    private function filterAtomicPredicates(array $items, array $predicates, array $variables): array
    {
        $syntheticDoc = new \DOMDocument();
        $syntheticRoot = $syntheticDoc->appendChild($syntheticDoc->createElement('synthetic-root'));
        assert($syntheticRoot instanceof \DOMElement);

        // ⚠️ Registered BEFORE the first predicate runs: this document grows below, so an absolute
        // path evaluated against it must never take the per-document cache ( see evalPath() ).
        $this->syntheticDocuments ??= new \WeakMap();
        $this->syntheticDocuments[$syntheticDoc] = true;

        foreach ($predicates as $predicate) {
            $size = count($items);

            if ($predicate instanceof Literal && $predicate->kind === 'integer') {
                $index = (int) $predicate->value;
                $items = ($index >= 1 && $index <= $size) ? [$items[$index - 1]] : [];
                continue;
            }
            if ($this->isBareLastCall($predicate)) {
                $items = $size > 0 ? [$items[$size - 1]] : [];
                continue;
            }

            $filtered = [];
            $position = 0;
            foreach ($items as $item) {
                $position++;
                $syntheticNode = $syntheticRoot->appendChild($syntheticDoc->createTextNode($item->asString()));
                if ($this->predicateHolds($predicate, $syntheticNode, $variables, $position, $size)) {
                    $filtered[] = $item;
                }
            }
            $items = $filtered;
        }

        return $items;
    }

    private function isBareLastCall(Expr $expr): bool
    {
        return $expr instanceof FunctionCall
            && $expr->name->prefix === null
            && $expr->name->local === 'last'
            && $expr->args === [];
    }

    /**
     * Predicate truth value rule (verified against the spec, not assumed - a first draft here
     * wrongly claimed numeric position predicates like `ram:ActualAmount[1]` do not occur in the
     * real corpus; they do, repeatedly, e.g. in the BR-CO allowance/charge total calculation
     * rules): if the predicate evaluates to a single numeric value, it is a POSITION test (true
     * iff that number equals the context node's 1-based position among the nodes being filtered
     * at this predicate step) - NOT its effective boolean value, which would make `[1]` trivially
     * true for every node instead of selecting only the first one.
     *
     * @param array<string, Sequence> $variables
     */
    private function predicateHolds(Expr $predicate, \DOMNode $node, array $variables, int $position, int $size): bool
    {
        $this->focus[] = [$position, $size];

        try {
            $result = $this->evaluate($predicate, $node, $variables);
        } finally {
            // ⚠️ In a `finally`: a predicate that throws must not leave its focus on the stack, or every
            // later predicate of the same interpreter would read a position one level too deep.
            array_pop($this->focus);
        }

        $atomized = $result->atomize();

        if (count($atomized) === 1 && $atomized[0]->isNumeric()) {
            return $atomized[0]->asDecimal()->compareTo(Decimal::fromInt($position)) === 0;
        }

        return $result->effectiveBooleanValue();
    }
}

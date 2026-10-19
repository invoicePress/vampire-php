<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Export;

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

/**
 * Phase 6 — turns an AST back into XPath 2.0 text. Needed by every exporter: a Schematron file or an
 * XSLT stylesheet carries expressions as text, while this project keeps them as trees.
 *
 * ⚠️ The output is not meant to look like the input. Whitespace, redundant brackets and the original
 * spelling of a number are lost on the way in, and reproducing them is not the goal. What must hold is
 * that parsing the output again yields the SAME tree - that is what `XPathWriterTest` checks over every
 * expression in every shipped rule set, and it is the only guarantee an exporter can rely on.
 *
 * ⚠️ Brackets are written whenever an operand is itself a binary operation, without weighing
 * precedence. `a and (b or c)` and `(a and b) or c` differ in meaning, and a writer that tries to be
 * clever about when brackets are unnecessary is one off-by-one away from silently changing a rule.
 * Superfluous brackets cost nothing.
 */
final class XPathWriter
{
    public function write(Expr $expr): string
    {
        return match (true) {
            $expr instanceof Literal => $this->literal($expr),
            $expr instanceof VarRef => '$' . $expr->name,
            $expr instanceof ParenExpr => '(' . $this->write($expr->inner) . ')',
            $expr instanceof SequenceExpr => '(' . implode(', ', array_map($this->write(...), $expr->items)) . ')',
            $expr instanceof RangeExpr => $this->write($expr->from) . ' to ' . $this->write($expr->to),
            $expr instanceof UnaryOp => $expr->op . $this->wrapIfBinary($expr->operand),
            $expr instanceof BinaryOp => $this->binary($expr),
            $expr instanceof FunctionCall => $this->functionCall($expr),
            $expr instanceof PathExpr => $this->path($expr),
            $expr instanceof IfExpr => 'if (' . $this->write($expr->cond) . ') then ' . $this->write($expr->then)
                . ' else ' . $this->write($expr->else),
            $expr instanceof ForExpr => 'for $' . $expr->var . ' in ' . $this->write($expr->in)
                . ' return ' . $this->write($expr->return),
            $expr instanceof LetExpr => 'let $' . $expr->var . ' := ' . $this->write($expr->value)
                . ' return ' . $this->write($expr->return),
            $expr instanceof QuantifiedExpr => $expr->quantifier . ' $' . $expr->var . ' in ' . $this->write($expr->in)
                . ' satisfies ' . $this->write($expr->satisfies),
            // `type` is a QName, not a string - it carries prefix and local name separately.
            $expr instanceof CastExpr => $this->wrapIfBinary($expr->operand) . ' cast as ' . $expr->type->full()
                . ($expr->optional ? '?' : ''),
            $expr instanceof CastableExpr => $this->wrapIfBinary($expr->operand) . ' castable as ' . $expr->type->full()
                . ($expr->optional ? '?' : ''),
            default => throw new UnsupportedConstructException(
                'cannot write AST node ' . $expr::class,
                $expr::class,
                $expr->pos()
            ),
        };
    }

    private function literal(Literal $literal): string
    {
        if ($literal->kind === 'string') {
            // XPath escapes a quote by doubling it.
            return "'" . str_replace("'", "''", $literal->value) . "'";
        }

        return $literal->value;
    }

    private function binary(BinaryOp $expr): string
    {
        return $this->wrapIfBinary($expr->left) . ' ' . $expr->op . ' ' . $this->wrapIfBinary($expr->right);
    }

    private function wrapIfBinary(Expr $expr): string
    {
        $text = $this->write($expr);

        return $expr instanceof BinaryOp ? "($text)" : $text;
    }

    private function functionCall(FunctionCall $call): string
    {
        return $call->name->full() . '(' . implode(', ', array_map($this->write(...), $call->args)) . ')';
    }

    private function path(PathExpr $path): string
    {
        $out = '';

        foreach ($path->steps as $index => $step) {
            $separator = $index === 0 ? ($path->absolute ? '/' : '') : '/';
            $out .= $separator . $this->step($step);
        }

        return $out === '' && $path->absolute ? '/' : $out;
    }

    private function step(Step $step): string
    {
        $text = $step->primary !== null ? $this->write($step->primary) : '';

        if ($step->test !== null) {
            $axis = $step->axis !== null && $step->axis !== 'child' ? $step->axis . '::' : '';

            // The attribute axis has its own abbreviation, and it is the one the rule sets use.
            if ($step->axis === 'attribute') {
                $axis = '@';
            }

            $text = $axis . $this->nodeTest($step->test);
        }

        foreach ($step->predicates as $predicate) {
            $text .= '[' . $this->write($predicate) . ']';
        }

        return $text;
    }

    private function nodeTest(NodeTest $test): string
    {
        // NodeTest::$kind is typed as 'name'|'wildcard'|'kindtest', so these three are exhaustive -
        // a default arm here would be unreachable code pretending to be a safety net.
        return match ($test->kind) {
            'name' => $test->name?->full() ?? '*',
            'wildcard' => $test->name === null ? '*' : $test->name->full(),
            'kindtest' => ($test->kindtestName ?? 'node') . '()',
        };
    }
}

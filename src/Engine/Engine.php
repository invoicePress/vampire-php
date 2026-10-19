<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Engine;

use EinvoiceValidator\Exception\UnsupportedConstructException;
use EinvoiceValidator\Result\RuleReport;
use EinvoiceValidator\Ruleset\LetBinding;
use EinvoiceValidator\Ruleset\MessageSegment;
use EinvoiceValidator\Ruleset\Pattern;
use EinvoiceValidator\Ruleset\Rule;
use EinvoiceValidator\Ruleset\Ruleset;
use EinvoiceValidator\Xpath\Interpreter;
use EinvoiceValidator\Xpath\NodeIdentity;
use EinvoiceValidator\Xpath\PathCache;
use EinvoiceValidator\Xpath\Runtime\FunctionLibrary;
use EinvoiceValidator\Xpath\Runtime\Sequence;

/**
 * Task 3.4 — runs a `Ruleset` (Task 3.1/3.3) against one document. Per pattern: document-global
 * `let`s are bound once (Task 3.1's own measured evidence: later globals may reference earlier
 * ones, so binding happens in the ruleset's own declaration order); rules are tried in the
 * importer's already priority-sorted order, and for each rule, EVERY node its (already
 * root-normalized) context selects is a candidate - but only the FIRST rule (by that order) to
 * claim a given node within THIS pattern actually fires for it, reproducing Schematron's "first
 * matching rule per node wins" (this task's own wording). A fresh `Interpreter`+`PathCache` is
 * built per `run()` call, matching both classes' own "one document validation" lifetime contract.
 */
final class Engine
{
    /**
     * @param string|null $codeDbDir directory the rule sets' own `fn:document()` side files live in
     *                               (Task 4.1b: the Factur-X code lists). Null keeps `document()`
     *                               unavailable, which is right for every rule set that never calls it.
     */
    /** @var list<string> */
    private array $disabledRuleIds = [];

    /** Counts every evaluation and every firing per assertion, when the positive report is on. */
    private ?RuleReport $tally = null;

    public function __construct(private readonly ?string $codeDbDir = null)
    {
    }

    /**
     * @param list<string> $disabledRuleIds Rules a profile switched off. They are skipped BEFORE
     *                                      evaluation, not filtered out of the findings: an
     *                                      unevaluated rule costs nothing and cannot leave a
     *                                      half-suppressed trace in the report.
     * @param RuleReport|null $tally Receives one call per evaluation and per firing. The rules of
     *                               `$ruleset` must be registered there before ( `RuleReport::addRuleset()` ).
     *                               ⚠️ Counting only: the findings are the same with and without it.
     * @return Finding[]
     */
    public function run(Ruleset $ruleset, \DOMDocument $document, array $disabledRuleIds = [], ?RuleReport $tally = null): array
    {
        $this->disabledRuleIds = $disabledRuleIds;
        $this->tally = $tally;

        $interpreter = new Interpreter(
            $ruleset->namespaces,
            new FunctionLibrary($this->codeDbDir),
            pathCache: new PathCache()
        );
        $globals = $this->bindLets($interpreter, $ruleset->globalLets, $document, []);

        $findings = [];
        foreach ($ruleset->patterns as $pattern) {
            array_push($findings, ...$this->runPattern($interpreter, $pattern, $document, $globals));
        }

        return $findings;
    }

    /**
     * @param LetBinding[] $lets
     * @param array<string, Sequence> $variables
     * @return array<string, Sequence>
     */
    private function bindLets(Interpreter $interpreter, array $lets, \DOMNode $contextNode, array $variables): array
    {
        foreach ($lets as $let) {
            $variables[$let->name] = $interpreter->evaluate($let->expr, $contextNode, $variables);
        }

        return $variables;
    }

    /**
     * @param array<string, Sequence> $globals
     * @return Finding[]
     */
    private function runPattern(
        Interpreter $interpreter,
        Pattern $pattern,
        \DOMDocument $document,
        array $globals
    ): array {
        $findings = [];
        /** @var array<string, true> $claimed node identity (NodeIdentity::of) => true */
        $claimed = [];

        foreach ($pattern->rules as $rule) {
            $candidates = $interpreter->evaluate($rule->context, $document, $globals);

            foreach ($candidates->items() as $node) {
                if (!$node instanceof \DOMNode) {
                    throw new UnsupportedConstructException(
                        'rule context evaluated to an atomic value, not a node',
                        'context',
                        0
                    );
                }

                // NodeIdentity, not spl_object_id: the same cross-iteration temporal gap that
                // caused PathCache's Task 2.5 bug applies here too - each rule is a SEPARATE
                // evaluate() call, and the "claimed" set is read back across all of them.
                $key = NodeIdentity::of($node);
                if (isset($claimed[$key])) {
                    continue;
                }
                $claimed[$key] = true;

                array_push($findings, ...$this->evaluateRule($interpreter, $pattern, $rule, $node, $globals));
            }
        }

        return $findings;
    }

    /**
     * @param array<string, Sequence> $globals
     * @return Finding[]
     */
    private function evaluateRule(
        Interpreter $interpreter,
        Pattern $pattern,
        Rule $rule,
        \DOMNode $node,
        array $globals
    ): array {
        $scope = $this->bindLets($interpreter, $rule->lets, $node, $globals);

        $findings = [];
        foreach ($rule->assertions as $assertion) {
            if ($assertion->id !== null && in_array($assertion->id, $this->disabledRuleIds, true)) {
                continue;
            }

            // ⚠️ `assert` and `report` fire with OPPOSITE polarity: an assert reports when its test is
            // false ("this must hold"), a report when its test is true ("this is worth reporting").
            // Getting it backwards would invert every affected rule silently.
            //
            // The Factur-X 1.09.2 rule sets use svrl:successful-report, so the polarity is not a guess
            // but something the ZUGFeRD test corpus exercises; the four rule sets carried before them
            // contained none.
            if (!in_array($assertion->kind, ['assert', 'report'], true)) {
                throw new UnsupportedConstructException(
                    "assertion kind \"{$assertion->kind}\" not implemented",
                    'kind',
                    0
                );
            }

            $testIsTrue = $interpreter->evaluate($assertion->test, $node, $scope)->effectiveBooleanValue();
            $fires = $assertion->kind === 'assert' ? !$testIsTrue : $testIsTrue;

            // ⚠️ Counted AFTER the evaluation returned. A test that throws was not evaluated, and counting
            // it first would turn an aborted rule into a `passed` line once someone catches the exception.
            $this->tally?->evaluated($assertion);

            if (!$fires) {
                continue;
            }

            $this->tally?->fired($assertion);

            $lineNo = $node->getLineNo();
            $findings[] = new Finding(
                $pattern->id,
                $pattern->name,
                $assertion->id,
                $assertion->flag,
                $this->renderMessage($interpreter, $assertion->message, $node, $scope),
                NodeIdentity::of($node),
                $lineNo > 0 ? $lineNo : null,
                $assertion->fieldRefs,
                $assertion->duplicateKey,
            );
        }

        return $findings;
    }

    /**
     * @param MessageSegment[] $segments
     * @param array<string, Sequence> $scope
     */
    private function renderMessage(Interpreter $interpreter, array $segments, \DOMNode $node, array $scope): string
    {
        $text = '';
        foreach ($segments as $segment) {
            if ($segment->isLiteral()) {
                $text .= $segment->literal;
                continue;
            }

            $expr = $segment->expr;
            if ($expr === null) {
                continue;
            }
            foreach ($interpreter->evaluate($expr, $node, $scope)->atomize() as $atomic) {
                $text .= $atomic->asString();
            }
        }

        return $text;
    }
}

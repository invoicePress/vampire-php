<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Export;

use EinvoiceValidator\Devkit\Export\XPathWriter;
use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6 — the writer is judged by ROUND-TRIP, not by how the text looks: parse(write(ast)) must give
 * back the same ast. Comparing strings would fail on harmless things (brackets, spacing) and pass on
 * harmful ones (a dropped predicate that happens to print the same).
 */
final class XPathWriterTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function expressionProvider(): array
    {
        $cases = [
            'simple path' => ['/ubl:Invoice/cbc:ID'],
            'relative path' => ['cac:Item/cbc:Name'],
            'attribute' => ['@schemeID'],
            'predicate' => ['//cac:InvoiceLine[cbc:ID = 1]'],
            'nested predicate' => ["//cac:Item[cac:ClassifiedTaxCategory[cbc:ID = 'S']]"],
            'and or' => ["(a and b) or c"],
            'not equal' => ["normalize-space(cbc:ID) != ''"],
            'function with args' => ["substring(string(), 1, 2) = 'DK'"],
            'string with quote' => ["cbc:Note = 'it''s here'"],
            'for expression' => ['for $x in //cac:InvoiceLine return $x/cbc:ID'],
            'nested for' => ['for $a in (1 to 3) return for $b in (1 to 2) return $a + $b'],
            'if expression' => ["if (cbc:ID) then 'ja' else 'nein'"],
            'let expression' => ['let $x := 2 return $x * 3'],
            'quantified' => ['some $x in //cbc:ID satisfies $x != 0'],
            'castable' => ['cbc:IssueDate castable as xs:date'],
            'cast' => ['xs:decimal(cbc:Amount) cast as xs:string'],
            'sequence' => ["('a', 'b', 'c')"],
            'range' => ['(1 to 5)'],
            'unary minus' => ['-1'],
            'wildcard' => ['//*'],
            'kind test' => ['//text()'],
            'union' => ['//cbc:ID | //cbc:Note'],
            'arithmetic' => ['(a + b) * (c - d)'],
            'idiv and mod' => ['($x idiv 10) + ($x mod 10)'],
        ];

        return $cases;
    }

    /**
     * ⚠️ `pos` is stripped before comparing. It is the offset in the SOURCE TEXT, and rewriting the
     * expression necessarily moves everything - `some $x in //cbc:ID satisfies …` becomes
     * `some $x in /descendant-or-self::node()/cbc:ID satisfies …`, so every offset after the path
     * shifts. Comparing it would make the test fail on the one thing that is allowed to differ.
     *
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private static function withoutPositions(array $tree): array
    {
        unset($tree['pos']);

        // ⚠️ A ParenExpr node is dropped as well. The writer brackets every binary operand on
        // purpose (see its own docblock), so the re-parsed tree carries brackets the original did not
        // have - semantically nothing, structurally a node.
        //
        // This does NOT blur different groupings: `(a and b) or c` parses to Or(And(a,b), c) and
        // `a and (b or c)` to And(a, Or(b,c)) even after the Paren nodes are gone. The grouping lives
        // in the nesting, not in the bracket node.
        while (($tree['node'] ?? null) === 'ParenExpr' && is_array($tree['inner'] ?? null)) {
            $tree = $tree['inner'];
            unset($tree['pos']);
        }

        foreach ($tree as $key => $value) {
            if (is_array($value)) {
                $tree[$key] = self::withoutPositions($value);
            }
        }

        return $tree;
    }

    private static function shape(\EinvoiceValidator\Xpath\Ast\Expr $expr): string
    {
        return (string) json_encode(self::withoutPositions($expr->toArray()), JSON_THROW_ON_ERROR);
    }

    #[DataProvider('expressionProvider')]
    public function testRoundTrip(string $source): void
    {
        $writer = new XPathWriter();

        $once = Parser::parse($source);
        $text = $writer->write($once);
        $twice = Parser::parse($text);

        self::assertSame(
            self::shape($once),
            self::shape($twice),
            "Neu geparst ergibt einen anderen Baum.\n  Quelle:      $source\n  Geschrieben: $text"
        );
    }

    /**
     * The real proof: every expression of every shipped rule set - contexts, tests and `let` values -
     * written out and parsed again. Roughly 4500 expressions, including the ones whose shape only
     * occurs in one distribution.
     */
    public function testEveryExpressionOfEveryShippedRulesetSurvivesTheRoundTrip(): void
    {
        $writer = new XPathWriter();
        $files = glob(dirname(__DIR__, 3) . '/rulesets/*@*.json');
        self::assertNotFalse($files);

        $checked = 0;
        $failures = [];

        foreach ($files as $file) {
            $ruleset = RulesetJsonCodec::readFile($file);

            $expressions = [];

            foreach ($ruleset->globalLets as $let) {
                $expressions[] = $let->expr;
            }

            foreach ($ruleset->patterns as $pattern) {
                foreach ($pattern->rules as $rule) {
                    $expressions[] = $rule->context;

                    foreach ($rule->lets as $let) {
                        $expressions[] = $let->expr;
                    }

                    foreach ($rule->assertions as $assertion) {
                        $expressions[] = $assertion->test;
                    }
                }
            }

            foreach ($expressions as $expr) {
                $checked++;
                $text = $writer->write($expr);

                try {
                    $again = Parser::parse($text);
                } catch (\Throwable $e) {
                    $failures[] = basename($file) . ': nicht mehr parsebar - ' . $e->getMessage() . "\n    $text";
                    continue;
                }

                if (self::shape($expr) !== self::shape($again)) {
                    $failures[] = basename($file) . ": anderer Baum\n    $text";
                }
            }
        }

        self::assertGreaterThan(4000, $checked, 'Es sollten tausende Ausdrücke geprüft werden.');
        self::assertSame([], array_slice($failures, 0, 5), count($failures) . ' Ausdrücke überleben den Round-Trip nicht.');
    }
}

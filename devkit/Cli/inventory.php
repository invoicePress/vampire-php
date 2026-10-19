<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Task 0.3 — reads every compiled Schematron-XSLT under vendor-sources/ (raw .sch is not
 * available, see docs/notes/decisions.md), extracts every XPath expression a rule actually uses
 * (assert/report test, rule context path), tallies which language constructs and functions
 * appear, and writes docs/notes/xpath-inventory.md.
 *
 * Run: php devkit/Cli/inventory.php
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

const SVRL_NS = 'http://purl.oclc.org/dsdl/svrl';
const XSL_NS = 'http://www.w3.org/1999/XSL/Transform';

/**
 * @return array<string, string>
 */
function sourceFiles(string $root): array
{
    return [
        'EN16931-CII' => "$root/en16931-schematron/1.3.16/cii/EN16931-CII-validation.xslt",
        'EN16931-UBL' => "$root/en16931-schematron/1.3.16/ubl/EN16931-UBL-validation.xslt",
        'XRechnung-CII' => "$root/xrechnung-schematron/3.0.2/XRechnung-CII-validation.xslt",
        'XRechnung-UBL' => "$root/xrechnung-schematron/3.0.2/XRechnung-UBL-validation.xslt",
    ];
}

/**
 * `DOMXPath::query()` is typed to allow `false` (an invalid expression) - never the case for our
 * own, fixed queries below, but PHPStan level 8 rightly refuses to let that go unchecked.
 *
 * @return \DOMNodeList<\DOMNameSpaceNode|\DOMNode>
 */
function queryElements(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): \DOMNodeList
{
    $result = $xpath->query($query, $context);
    if ($result === false) {
        throw new \RuntimeException("invalid XPath query in inventory.php itself: $query");
    }

    return $result;
}

/**
 * One entry per distinct XPath expression actually found, with every ruleset it occurs in.
 *
 * @return array{
 *     expressions: array<string, array{kind: string, rulesets: array<string, true>, example_rule: ?string}>,
 *     custom_functions: array<string, array{signature: string, body: string, rulesets: array<string, true>}>
 * }
 */
function extractFromFile(string $file, string $rulesetLabel): array
{
    $expressions = [];
    $customFunctions = [];

    // LIBXML_NONET here too, even though this devkit script only ever reads a known-local
    // vendor-sources file - consistent with rule A.4's "no network access" spirit everywhere
    // libxml touches a file, not only in the runtime loader (Task 1.2).
    $dom = new \DOMDocument();
    $dom->load($file, LIBXML_NONET);

    $xpath = new \DOMXPath($dom);
    $xpath->registerNamespace('svrl', SVRL_NS);
    $xpath->registerNamespace('xsl', XSL_NS);

    // Custom extension functions (xsl:function), e.g. u:gln, u:mod11 - not part of standard
    // XPath 2.0, must be reimplemented natively in Phase 2's runtime library.
    foreach (queryElements($xpath, '//xsl:function') as $fn) {
        assert($fn instanceof \DOMElement);
        $name = $fn->getAttribute('name');
        if ($name === '') {
            continue;
        }
        $params = [];
        foreach (queryElements($xpath, 'xsl:param', $fn) as $param) {
            assert($param instanceof \DOMElement);
            $params[] = '$' . $param->getAttribute('name');
        }
        $bodyParts = [];
        foreach (queryElements($xpath, './/@select', $fn) as $sel) {
            $bodyParts[] = trim($sel->nodeValue ?? '');
        }
        $key = $name . '/' . count($params);
        if (!isset($customFunctions[$key])) {
            $customFunctions[$key] = [
                'signature' => $name . '(' . implode(', ', $params) . ')',
                'body' => $bodyParts[0] ?? '',
                'rulesets' => [],
            ];
        }
        $customFunctions[$key]['rulesets'][$rulesetLabel] = true;
    }

    // Rule context paths (xsl:template/@match).
    foreach (queryElements($xpath, '//xsl:template[@match]') as $tpl) {
        assert($tpl instanceof \DOMElement);
        $match = $tpl->getAttribute('match');
        recordExpression($expressions, $match, 'context-path', $rulesetLabel, null);
    }

    // assert/report test expressions - the actual rule conditions, with the rule id as example anchor.
    $asserts = queryElements($xpath, '//svrl:failed-assert[@test] | //svrl:successful-report[@test]');
    foreach ($asserts as $node) {
        assert($node instanceof \DOMElement);
        $test = $node->getAttribute('test');
        $idAttrs = queryElements($xpath, 'xsl:attribute[@name="id"]', $node);
        $first = $idAttrs->item(0);
        $ruleId = $first !== null ? trim($first->nodeValue ?? '') : null;
        $kind = $node->localName === 'failed-assert' ? 'assert' : 'report';
        recordExpression($expressions, $test, $kind, $rulesetLabel, $ruleId);
    }

    return ['expressions' => $expressions, 'custom_functions' => $customFunctions];
}

/**
 * @param array<string, array{kind: string, rulesets: array<string, true>, example_rule: ?string}> $expressions
 */
function recordExpression(array &$expressions, string $expr, string $kind, string $rulesetLabel, ?string $ruleId): void
{
    $expr = trim($expr);
    if ($expr === '') {
        return;
    }
    if (!isset($expressions[$expr])) {
        $expressions[$expr] = ['kind' => $kind, 'rulesets' => [], 'example_rule' => $ruleId];
    }
    $expressions[$expr]['rulesets'][$rulesetLabel] = true;
    if ($expressions[$expr]['example_rule'] === null && $ruleId !== null) {
        $expressions[$expr]['example_rule'] = $ruleId;
    }
}

/**
 * Very small, deliberately conservative construct tagger: this is Phase 0's *inventory*, not
 * Phase 2's real parser - it must never claim an expression uses a construct it does not, but it
 * is allowed to be imprecise about placement (it only counts occurrences, it does not parse).
 *
 * @return string[]
 */
function tagConstructs(string $expr): array
{
    $tags = [];

    $checks = [
        'axis::' => '/\b[a-z-]+::/',
        'predicate []' => '/\[[^\]]*\]/',
        'for...in...return' => '/\bfor\s+\$\w+\s+in\b.*\breturn\b/s',
        'if...then...else' => '/\bif\s*\(.*\bthen\b.*\belse\b/s',
        'some/every...satisfies' => '/\b(some|every)\s+\$\w+\s+in\b.*\bsatisfies\b/s',
        'castable as' => '/\bcastable\s+as\b/',
        'cast as' => '/\bcast\s+as\b/',
        'instance of' => '/\binstance\s+of\b/',
        'treat as' => '/\btreat\s+as\b/',
        'let $x :=' => '/\blet\s+\$\w+\s*:=/',
        'general comparison (=,!=,<,>)' => '/(?<![!<>=])[=<>](?!=)|!=/',
        'value comparison (eq/ne/lt/le/gt/ge)' => '/\b(eq|ne|lt|le|gt|ge)\b/',
        'node comparison (is, <<, >>)' => '/\bis\b|<<|>>/',
        'union (|, union)' => '/\||\bunion\b/',
        'intersect/except' => '/\b(intersect|except)\b/',
        'sequence constructor (a, b)' => '/\(\s*[^()]*,[^()]*\)/',
        'range (to)' => '/\bto\b/',
        'quantified ?/*/+/?' => '/[?*+](?=[\s)\]|]|$)/',
        'variable reference $x' => '/\$[A-Za-z_][\w:.-]*/',
        'string/numeric literal' => "/'[^']*'|\"[^\"]*\"|\\b\\d+\\.\\d+\\b/",
        'wildcard *' => '/(?<![\w$])\*(?![\w(])/',
        'descendant-or-self //' => '#//#',
        'parent ..' => '/\.\./',
        'attribute @' => '/@[\w:*-]+/',
    ];

    foreach ($checks as $label => $pattern) {
        if (preg_match($pattern, $expr) === 1) {
            $tags[] = $label;
        }
    }

    // Function calls: name(args) - excludes keywords already handled above (if/for/some/every/let)
    // and axis steps (child::, attribute:: etc., which use the same "word(" shape only for
    // node-type tests like node()/text()/comment() which we tag as functions too, correctly).
    if (preg_match_all('/\b([A-Za-z_][\w.-]*(?::[A-Za-z_][\w.-]*)?)\s*\(/', $expr, $m) === false) {
        return array_unique($tags);
    }
    // Keywords/operators that can be immediately followed by "(" purely by coincidence of
    // whitespace (e.g. "... or (...)") and must never be mistaken for a function call.
    $keywords = [
        'if', 'for', 'some', 'every', 'return', 'then', 'else', 'satisfies', 'in',
        'and', 'or', 'to', 'div', 'idiv', 'mod', 'union', 'intersect', 'except', 'is',
        'eq', 'ne', 'lt', 'le', 'gt', 'ge', 'castable', 'cast', 'instance', 'treat', 'as', 'of', 'let',
    ];
    foreach ($m[1] as $fnName) {
        if (in_array($fnName, $keywords, true)) {
            continue;
        }
        $tags[] = 'fn:' . $fnName;
    }

    return array_unique($tags);
}

function main(): void
{
    $root = dirname(__DIR__, 2) . '/vendor-sources';
    $allExpressions = [];
    $allCustomFunctions = [];
    $fileStats = [];

    foreach (sourceFiles($root) as $label => $file) {
        if (!is_readable($file)) {
            fwrite(STDERR, "missing source file: $file\n");
            exit(1);
        }
        $result = extractFromFile($file, $label);
        $fileStats[$label] = count($result['expressions']);
        foreach ($result['expressions'] as $expr => $data) {
            if (!isset($allExpressions[$expr])) {
                $allExpressions[$expr] = $data;
            } else {
                $allExpressions[$expr]['rulesets'] = $allExpressions[$expr]['rulesets'] + $data['rulesets'];
                $allExpressions[$expr]['example_rule'] ??= $data['example_rule'];
            }
        }
        foreach ($result['custom_functions'] as $key => $data) {
            if (!isset($allCustomFunctions[$key])) {
                $allCustomFunctions[$key] = $data;
            } else {
                $allCustomFunctions[$key]['rulesets'] = $allCustomFunctions[$key]['rulesets'] + $data['rulesets'];
            }
        }
    }

    // Tally constructs across all distinct expressions (each distinct expression counted once per
    // construct it contains - frequency = number of distinct expressions using that construct, not
    // number of rule occurrences, since the goal here is "what must the interpreter support", not
    // "how popular is it").
    $constructTally = [];
    $constructExample = [];
    foreach ($allExpressions as $expr => $data) {
        foreach (tagConstructs($expr) as $tag) {
            $constructTally[$tag] = ($constructTally[$tag] ?? 0) + 1;
            $constructExample[$tag] ??= $expr;
        }
    }
    arsort($constructTally);

    writeReport($fileStats, $allExpressions, $constructTally, $constructExample, $allCustomFunctions);
    writeExpressionFixture($allExpressions);
}

/**
 * Full, untruncated list of every distinct expression found - docs/notes/xpath-inventory.md's own table
 * truncates each example to 90 characters for readability, which is useless as a parser test
 * fixture. Consumed by tests/Unit/Xpath/Parser/ParserInventoryTest.php, which requires every collected
 * expression to parse without error.
 *
 * @param array<string, array{kind: string, rulesets: array<string, true>, example_rule: ?string}> $allExpressions
 */
function writeExpressionFixture(array $allExpressions): void
{
    $path = dirname(__DIR__, 2) . '/tests/fixtures/xpath-inventory-expressions.json';
    file_put_contents($path, json_encode(array_keys($allExpressions), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fwrite(STDOUT, 'wrote ' . $path . "\n");
}

/**
 * @param array<string, int> $fileStats
 * @param array<string, array{kind: string, rulesets: array<string, true>, example_rule: ?string}> $allExpressions
 * @param array<string, int> $constructTally
 * @param array<string, string> $constructExample
 * @param array<string, array{signature: string, body: string, rulesets: array<string, true>}> $allCustomFunctions
 */
function writeReport(
    array $fileStats,
    array $allExpressions,
    array $constructTally,
    array $constructExample,
    array $allCustomFunctions
): void {
    $out = "# XPath-Inventur (Aufgabe 0.3)\n\n";
    $out .= "Erzeugt von `devkit/Cli/inventory.php` aus den kompilierten Schematron-XSLT unter "
        . "`vendor-sources/` (kein Roh-`.sch` verfügbar, siehe `docs/notes/decisions.md`). Erfasst jede "
        . "`assert`/`report`-Testbedingung (`svrl:failed-assert`/`svrl:successful-report`/`@test`) "
        . "und jeden Regel-Kontextpfad (`xsl:template/@match`).\n\n";

    $out .= "## Dateien\n\n| Regelwerk | distinkte Ausdrücke |\n|---|--:|\n";
    foreach ($fileStats as $label => $count) {
        $out .= "| {$label} | {$count} |\n";
    }
    $out .= "\n**Insgesamt " . count($allExpressions) . " distinkte Ausdrücke über alle vier Regelwerke.**\n\n";

    $out .= "## Sprachkonstrukte und Funktionen, nach Häufigkeit "
        . "(Anzahl distinkter Ausdrücke, die das Konstrukt enthalten)\n\n";
    $out .= "| Konstrukt | Ausdrücke | Beispiel |\n|---|--:|---|\n";
    foreach ($constructTally as $tag => $count) {
        $example = str_replace('|', '\\|', mb_substr($constructExample[$tag], 0, 90));
        $out .= "| `{$tag}` | {$count} | `{$example}` |\n";
    }

    $out .= "\n## Eigene Erweiterungsfunktionen (`xsl:function`, kein Standard-XPath-2.0)\n\n";
    $out .= "Diese MÜSSEN in `Xpath/Runtime/` nativ nachgebaut werden - "
        . "Standard-XPath-2.0-Funktionen decken sie nicht ab.\n\n";
    $out .= "| Funktion | Regelwerke | Body (erster `@select`) |\n|---|---|---|\n";
    ksort($allCustomFunctions);
    foreach ($allCustomFunctions as $data) {
        $rulesets = implode(', ', array_keys($data['rulesets']));
        $body = str_replace('|', '\\|', mb_substr($data['body'], 0, 120));
        $out .= "| `{$data['signature']}` | {$rulesets} | `{$body}` |\n";
    }

    $outPath = dirname(__DIR__, 2) . '/docs/notes/xpath-inventory.md';
    file_put_contents($outPath, $out);

    $message = "wrote {$outPath}: " . count($allExpressions) . ' distinct expressions, '
        . count($constructTally) . ' constructs, ' . count($allCustomFunctions) . " custom functions\n";
    fwrite(STDOUT, $message);
}

main();

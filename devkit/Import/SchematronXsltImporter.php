<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Task 3.1 — reads one compiled Schematron-XSLT (raw `.sch` unavailable, see docs/notes/decisions.md)
 * and builds a `Ruleset` (src/Ruleset/) directly - no separate "raw import" hierarchy, since the
 * importer's job IS to produce the shipped runtime model, just from XSLT instead of `.sch`.
 *
 * Every structural assumption below (the `svrl:active-pattern`/`xsl:apply-templates[@mode]`
 * pairing, strictly-descending per-mode `@priority` with no gaps/duplicates, rule-level
 * `xsl:variable[@select]` scoped per template, message text mixing literal `svrl:text` content
 * with `xsl:value-of`) was verified with throwaway harnesses against all four real
 * EN16931/XRechnung compiled rulesets before being relied on here - see docs/notes/decisions.md.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Import;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Exception\ImportException;
use EinvoiceValidator\Exception\UnsupportedConstructException;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Ruleset\Assertion;
use EinvoiceValidator\Ruleset\LetBinding;
use EinvoiceValidator\Ruleset\MessageSegment;
use EinvoiceValidator\Ruleset\Pattern;
use EinvoiceValidator\Ruleset\Rule;
use EinvoiceValidator\Ruleset\Ruleset;
use EinvoiceValidator\Xpath\Ast\BinaryOp;
use EinvoiceValidator\Xpath\Ast\Expr;
use EinvoiceValidator\Xpath\Ast\NodeTest;
use EinvoiceValidator\Xpath\Ast\ParenExpr;
use EinvoiceValidator\Xpath\Ast\PathExpr;
use EinvoiceValidator\Xpath\Ast\Step;

final class SchematronXsltImporter
{
    private const SVRL_NS = 'http://purl.oclc.org/dsdl/svrl';
    private const XSL_NS = 'http://www.w3.org/1999/XSL/Transform';

    /**
     * Field references named in a rule's message text (Aufgabe 3.3: "betroffene BT-Felder") -
     * measured to include both BT (a single field) and BG (a group) references, e.g.
     * "(BT-122)"/"BG-19"/"(BT-31), (BT-32)", see `Result\Message::$bt`'s own established
     * BT+BG-combined convention (Aufgabe 1.1) - reused here rather than inventing a second scheme.
     */
    private const FIELD_REF_PATTERN = '/\b(?:BT|BG)-\d+\b/';

    /**
     * The source path as the rule set records it: relative to the package root when the file lies inside it.
     *
     * ⚠️ NEVER THE ABSOLUTE PATH. The rule set is published, and an absolute path would carry the folder layout of
     * the machine that compiled it into every copy.
     */
    public static function packagePath(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : basename($file);
    }

    public static function import(string $file, string $id, string $version): Ruleset
    {
        $content = file_get_contents($file);
        if ($content === false) {
            throw new ImportException("cannot read ruleset source file: $file");
        }

        $dom = new \DOMDocument();
        // LIBXML_NONET even though this devkit tool only ever reads a known-local vendor-sources
        // file - consistent with rule A.4's "no network access" spirit everywhere libxml touches a
        // file (see devkit/Cli/inventory.php, Aufgabe 0.3, for the same reasoning).
        $dom->load($file, LIBXML_NONET);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('svrl', self::SVRL_NS);
        $xpath->registerNamespace('xsl', self::XSL_NS);

        $namespaces = self::importNamespaces($xpath);
        $title = self::importTitle($xpath);
        $globalLets = self::importGlobalLets($xpath);
        $patterns = self::importPatterns($xpath);

        return new Ruleset(
            $id,
            $version,
            $title,
            self::packagePath($file),
            hash('sha256', $content),
            $namespaces,
            $globalLets,
            $patterns,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function importNamespaces(\DOMXPath $xpath): array
    {
        $namespaces = [];
        foreach (self::query($xpath, '//svrl:ns-prefix-in-attribute-values') as $node) {
            assert($node instanceof \DOMElement);
            $prefix = $node->getAttribute('prefix');
            $uri = $node->getAttribute('uri');
            if ($prefix === '' || $uri === '') {
                throw new ImportException('svrl:ns-prefix-in-attribute-values with an empty prefix or uri');
            }
            $namespaces[$prefix] = $uri;
        }
        if ($namespaces === []) {
            throw new ImportException('no svrl:ns-prefix-in-attribute-values found - not a compiled Schematron XSLT?');
        }

        return $namespaces;
    }

    /**
     * The single title text right after the "SCHEMATRON PATTERNS" marker (measured: one
     * stylesheet-level `svrl:text`, e.g. "EN16931  model bound to UBL") - falls back to the id if
     * a future source ever omits it, rather than failing the whole import over a cosmetic field.
     */
    private static function importTitle(\DOMXPath $xpath): string
    {
        $nodes = self::query($xpath, '/xsl:stylesheet/svrl:text[1]');
        $first = $nodes->item(0);
        if ($first === null) {
            return '';
        }
        assert($first instanceof \DOMNode);

        return trim($first->textContent);
    }

    /**
     * @return LetBinding[]
     */
    private static function importGlobalLets(\DOMXPath $xpath): array
    {
        $query = '/xsl:stylesheet/xsl:variable[@select] | /xsl:stylesheet/xsl:param[@select]';

        $lets = [];
        foreach (self::query($xpath, $query) as $node) {
            assert($node instanceof \DOMElement);
            $lets[] = self::parseLet($node);
        }

        return $lets;
    }

    private static function parseLet(\DOMElement $node): LetBinding
    {
        $name = $node->getAttribute('name');
        if ($name === '') {
            throw new ImportException('xsl:variable/xsl:param with @select but no @name');
        }

        return new LetBinding($name, Parser::parse($node->getAttribute('select')));
    }

    /**
     * @return Pattern[]
     */
    private static function importPatterns(\DOMXPath $xpath): array
    {
        $patterns = [];
        foreach (self::query($xpath, '//svrl:active-pattern') as $activePattern) {
            assert($activePattern instanceof \DOMElement);
            $patterns[] = self::importPattern($xpath, $activePattern);
        }
        if ($patterns === []) {
            throw new ImportException('no svrl:active-pattern found - not a compiled Schematron XSLT?');
        }

        return $patterns;
    }

    private static function importPattern(\DOMXPath $xpath, \DOMElement $activePattern): Pattern
    {
        // ⚠️ Not every compiler emits id/name. The EN16931 and XRechnung XSLTs set both through child
        // `xsl:attribute` instructions; the Peppol BIS 3.0.21 distribution (Task 4.1a) writes only
        // `document`/`documents` there - its patterns are anonymous. Falling back to the template mode
        // keeps them distinguishable (M6, M7, ...) instead of failing the whole import over a naming
        // convention. The mode is what actually selects the rules, so it is the honest identity.
        $id = self::attributeValueOrNull($xpath, $activePattern, 'id');
        $name = self::attributeValueOrNull($xpath, $activePattern, 'name');

        $modeNodes = self::query($xpath, 'following-sibling::xsl:apply-templates[@mode][1]', $activePattern);
        $modeNode = $modeNodes->item(0);
        if (!$modeNode instanceof \DOMElement) {
            throw new ImportException('a pattern has no following xsl:apply-templates[@mode]');
        }
        $mode = $modeNode->getAttribute('mode');

        return new Pattern($id ?? $mode, $name ?? $mode, self::importRules($xpath, $mode));
    }

    /**
     * `<svrl:active-pattern>`'s own `id`/`name` are set via child `<xsl:attribute>` instructions
     * (an XSLT attribute value template), not plain XML attributes - see the setup block read
     * directly from a real ruleset in docs/notes/decisions.md.
     */
    private static function attributeValueOrNull(\DOMXPath $xpath, \DOMElement $activePattern, string $name): ?string
    {
        $nodes = self::query($xpath, "xsl:attribute[@name=\"$name\"]", $activePattern);
        $first = $nodes->item(0);
        if ($first === null) {
            return null;
        }
        assert($first instanceof \DOMNode);

        $value = trim($first->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * @return Rule[]
     */
    private static function importRules(\DOMXPath $xpath, string $mode): array
    {
        $rules = [];
        $lastPriority = null;
        foreach (self::query($xpath, "//xsl:template[@mode=\"$mode\"][@match]") as $template) {
            assert($template instanceof \DOMElement);

            // The compiler appends exactly two default-traversal templates at the end of every
            // mode - `match="text()"` (suppress stray text output) and `match="@*|node()"`
            // (recurse into anything no real rule matched) - never a business/structural rule,
            // never carrying an assert/report. Measured across all four real rulesets: always
            // exactly these two, always `priority="-1"`/`"-2"`, always empty, with zero
            // assert-less templates at any non-negative priority (docs/notes/decisions.md) - so a
            // negative priority is a reliable, verified signal to skip, not a guess.
            if ((int) $template->getAttribute('priority') < 0) {
                continue;
            }

            $rule = self::importRule($xpath, $template);

            // Engine (Aufgabe 3.4) relies on rules being in strictly-descending-priority order to
            // reproduce "first matching rule per node wins" - measured to hold for every real
            // pattern mode (never for the compiler's OWN infra modes, which this method never
            // sees, since it only ever runs for a mode a pattern actually references). Guarded
            // unconditionally rather than trusted blindly (Regel A.6): a future ruleset version
            // that violates it must fail the import, not silently misorder rules.
            if ($lastPriority !== null && $rule->priority >= $lastPriority) {
                throw new ImportException(
                    "mode \"$mode\": rule priorities are not strictly descending in document order "
                    . "(saw {$lastPriority} then {$rule->priority}) - the \"first matching rule wins\" "
                    . 'assumption (docs/notes/decisions.md) no longer holds for this source file'
                );
            }
            $lastPriority = $rule->priority;

            $rules[] = $rule;
        }

        // A pattern with zero real rules is not necessarily malformed: measured in both real
        // XRechnung rulesets (`id="variable-pattern"`, mode M35/M26) - the standard Schematron
        // idiom for a pattern that exists only to hold shared `<sch:let>` declarations for other
        // patterns to use, never firing a rule of its own.
        return $rules;
    }

    private static function importRule(\DOMXPath $xpath, \DOMElement $template): Rule
    {
        $match = $template->getAttribute('match');
        $priorityAttr = $template->getAttribute('priority');
        if ($match === '' || $priorityAttr === '') {
            throw new ImportException('rule template is missing @match or @priority');
        }

        $context = self::normalizeContext(Parser::parse($match));

        $lets = [];
        foreach (self::query($xpath, 'xsl:variable[@select]', $template) as $node) {
            assert($node instanceof \DOMElement);
            $lets[] = self::parseLet($node);
        }

        $assertionQuery = './/svrl:failed-assert[@test] | .//svrl:successful-report[@test]';

        $assertions = [];
        foreach (self::query($xpath, $assertionQuery, $template) as $node) {
            assert($node instanceof \DOMElement);
            $assertions[] = self::importAssertion($xpath, $node);
        }
        if ($assertions === []) {
            throw new ImportException("rule with context \"$match\" has no assert/report");
        }

        return new Rule($context, (int) $priorityAttr, $lets, $assertions);
    }

    /**
     * A relative context pattern (e.g. `cac:Foo`) means "anywhere in the document", equivalent to
     * prepending `//` - done here at the AST level (not by string-concatenating the source text)
     * so a union of mixed relative/absolute alternatives (none measured in the real corpus, but
     * not ruled out either) normalizes each side independently and correctly either way. Reuses
     * the Parser's own established shape for a leading `//` (`Step('descendant-or-self',
     * NodeTest('kindtest', null, 'node'))`, see devkit/Xpath/Parser/Parser.php) instead of
     * inventing a second representation of the same thing.
     */
    private static function normalizeContext(Expr $expr): Expr
    {
        // ⚠️ Brackets around a context are noise, not meaning. They turn up as soon as a context is a
        // union of three or more paths and the stylesheet was written by this project's own exporter
        // (Task 6.2), which brackets every binary operand on purpose. Rejecting them would make a rule
        // set that this project exported unreadable to this project.
        while ($expr instanceof ParenExpr) {
            $expr = $expr->inner;
        }

        if ($expr instanceof BinaryOp && $expr->op === '|') {
            return new BinaryOp(
                '|',
                self::normalizeContext($expr->left),
                self::normalizeContext($expr->right),
                $expr->pos()
            );
        }
        if ($expr instanceof PathExpr) {
            if ($expr->absolute) {
                return $expr;
            }

            return new PathExpr(
                [new Step('descendant-or-self', new NodeTest('kindtest', null, 'node')), ...$expr->steps],
                true,
                $expr->pos()
            );
        }

        throw new UnsupportedConstructException(
            'rule context is not a path expression (or union of path expressions)',
            'context',
            $expr->pos()
        );
    }

    private static function importAssertion(\DOMXPath $xpath, \DOMElement $node): Assertion
    {
        $test = $node->getAttribute('test');
        $id = self::childAttributeText($xpath, $node, 'id');
        // ⚠️ A missing flag is NOT an import error - it is the Schematron default. Measured on the
        // Factur-X 1.09.2 distribution (Task 4.1b): 623 of 627 assertions in the EN16931 profile and
        // 1442 of 1464 in EXTENDED carry no `flag` at all, while the EN16931/XRechnung XSLTs set one on
        // every single assertion. ISO Schematron says an assert without flag/role is an error, so the
        // default is `fatal`. Throwing here would have rejected two complete, official rule sets over a
        // convention difference between two compilers.
        $flagText = self::childAttributeText($xpath, $node, 'flag') ?? 'fatal';

        $textNodes = self::query($xpath, 'svrl:text[1]', $node);
        $textNode = $textNodes->item(0);
        if ($textNode === null) {
            throw new ImportException('svrl:failed-assert/svrl:successful-report has no svrl:text');
        }
        assert($textNode instanceof \DOMNode);

        $message = self::importMessage($textNode);
        $literalText = implode('', array_map(
            static fn (MessageSegment $m): string => $m->literal ?? '',
            $message
        ));
        preg_match_all(self::FIELD_REF_PATTERN, $literalText, $matches);
        /** @var string[] $fieldRefs */
        $fieldRefs = array_values(array_unique($matches[0]));

        return new Assertion(
            $id,
            Flag::from($flagText),
            $node->localName === 'failed-assert' ? 'assert' : 'report',
            Parser::parse($test),
            $message,
            $fieldRefs,
        );
    }

    private static function childAttributeText(\DOMXPath $xpath, \DOMElement $node, string $name): ?string
    {
        $nodes = self::query($xpath, "xsl:attribute[@name=\"$name\"]", $node);
        $first = $nodes->item(0);
        if ($first === null) {
            return null;
        }
        assert($first instanceof \DOMNode);

        return trim($first->textContent);
    }

    /**
     * A message is a mix of literal text and `xsl:value-of`: message texts with `value-of` are only
     * resolved when the rule fires - measured, not hypothetical: BR-DE-18 in the real XRechnung CIUS.
     * Adjacent literal text/xsl:text children are merged into one segment for a cleaner artifact;
     * `xsl:value-of[@select]` becomes a parsed expression segment.
     *
     * @return MessageSegment[]
     */
    private static function importMessage(\DOMNode $svrlText): array
    {
        $segments = [];
        $literalBuffer = '';

        foreach ($svrlText->childNodes as $child) {
            // DOMCdataSection extends DOMText in ext-dom, so this also covers a literal CDATA
            // section (none measured in the real corpus, but not ruled out).
            if ($child instanceof \DOMText) {
                $literalBuffer .= $child->nodeValue ?? '';
                continue;
            }
            if (self::isXslElement($child, 'text')) {
                assert($child instanceof \DOMElement);
                $literalBuffer .= $child->textContent;
                continue;
            }
            if (self::isXslElement($child, 'value-of')) {
                assert($child instanceof \DOMElement);
                if ($literalBuffer !== '') {
                    $segments[] = MessageSegment::literal($literalBuffer);
                    $literalBuffer = '';
                }
                $segments[] = MessageSegment::expression(Parser::parse($child->getAttribute('select')));
                continue;
            }

            throw new ImportException('svrl:text contains an unexpected child: ' . $child->nodeName);
        }

        if ($literalBuffer !== '') {
            $segments[] = MessageSegment::literal($literalBuffer);
        }
        if ($segments === []) {
            throw new ImportException('svrl:text has no content');
        }

        return $segments;
    }

    private static function isXslElement(\DOMNode $node, string $localName): bool
    {
        return $node instanceof \DOMElement && $node->namespaceURI === self::XSL_NS && $node->localName === $localName;
    }

    /**
     * @return \DOMNodeList<\DOMNameSpaceNode|\DOMNode>
     */
    private static function query(\DOMXPath $xpath, string $query, ?\DOMNode $context = null): \DOMNodeList
    {
        $result = $xpath->query($query, $context);
        if ($result === false) {
            throw new \RuntimeException("invalid XPath query in importer itself: $query");
        }

        return $result;
    }
}

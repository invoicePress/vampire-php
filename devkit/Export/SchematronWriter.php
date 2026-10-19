<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Export;

use EinvoiceValidator\Ruleset\Assertion;
use EinvoiceValidator\Ruleset\MessageSegment;
use EinvoiceValidator\Ruleset\Ruleset;

/**
 * Task 6.1 — writes a rule set back out as ISO Schematron (`.sch`).
 *
 * ⚠️ `queryBinding="xslt2"` is set explicitly. Without it a Schematron processor assumes XPath 1.0,
 * and every rule using `for`, `castable as`, `matches()` or a typed comparison would either fail to
 * compile or, worse, compile into something with a different meaning.
 *
 * ⚠️ The severity goes into the `flag` attribute AND stays readable in the message text. Task 6.2's
 * own note applies here too: the KoSIT configuration can re-rank rules through `customLevel`, but an
 * exported rule should carry its own severity, so that the file still says what it means when it is
 * used outside that configuration.
 */
final class SchematronWriter
{
    private const SCH_NS = 'http://purl.oclc.org/dsdl/schematron';

    public function __construct(private readonly XPathWriter $xpath = new XPathWriter())
    {
    }

    public function write(Ruleset $ruleset): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $schema = $dom->createElementNS(self::SCH_NS, 'schema');
        $schema->setAttribute('queryBinding', 'xslt2');
        $dom->appendChild($schema);

        $title = $dom->createElementNS(self::SCH_NS, 'title');
        $title->appendChild($dom->createTextNode($ruleset->title));
        $schema->appendChild($title);

        foreach ($ruleset->namespaces as $prefix => $uri) {
            $ns = $dom->createElementNS(self::SCH_NS, 'ns');
            $ns->setAttribute('prefix', $prefix);
            $ns->setAttribute('uri', $uri);
            $schema->appendChild($ns);
        }

        foreach ($ruleset->globalLets as $let) {
            $letNode = $dom->createElementNS(self::SCH_NS, 'let');
            $letNode->setAttribute('name', $let->name);
            $letNode->setAttribute('value', $this->xpath->write($let->expr));
            $schema->appendChild($letNode);
        }

        foreach ($ruleset->patterns as $pattern) {
            $patternNode = $dom->createElementNS(self::SCH_NS, 'pattern');
            $patternNode->setAttribute('id', $pattern->id);
            $schema->appendChild($patternNode);

            foreach ($pattern->rules as $rule) {
                $ruleNode = $dom->createElementNS(self::SCH_NS, 'rule');
                $ruleNode->setAttribute('context', $this->xpath->write($rule->context));
                $patternNode->appendChild($ruleNode);

                foreach ($rule->lets as $let) {
                    $letNode = $dom->createElementNS(self::SCH_NS, 'let');
                    $letNode->setAttribute('name', $let->name);
                    $letNode->setAttribute('value', $this->xpath->write($let->expr));
                    $ruleNode->appendChild($letNode);
                }

                foreach ($rule->assertions as $assertion) {
                    $ruleNode->appendChild($this->assertion($dom, $assertion));
                }
            }
        }

        return (string) $dom->saveXML();
    }

    private function assertion(\DOMDocument $dom, Assertion $assertion): \DOMElement
    {
        $node = $dom->createElementNS(self::SCH_NS, $assertion->kind);
        $node->setAttribute('test', $this->xpath->write($assertion->test));
        $node->setAttribute('flag', $assertion->flag->value);

        if ($assertion->id !== null) {
            $node->setAttribute('id', $assertion->id);
        }

        foreach ($assertion->message as $segment) {
            $node->appendChild($this->messageSegment($dom, $segment));
        }

        return $node;
    }

    /**
     * ⚠️ A message is not just text: the rule sets interleave literal text with `value-of` parts that
     * are only resolved against a document. Flattening them into one string would turn
     * "Amount must be [value]" into a sentence that always shows the same placeholder.
     */
    private function messageSegment(\DOMDocument $dom, MessageSegment $segment): \DOMNode
    {
        if ($segment->literal !== null) {
            return $dom->createTextNode($segment->literal);
        }

        $valueOf = $dom->createElementNS(self::SCH_NS, 'value-of');
        $valueOf->setAttribute('select', $segment->expr !== null ? $this->xpath->write($segment->expr) : '.');

        return $valueOf;
    }
}

<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Export;

use EinvoiceValidator\Ruleset\Assertion;
use EinvoiceValidator\Ruleset\Pattern;
use EinvoiceValidator\Ruleset\Rule;
use EinvoiceValidator\Ruleset\Ruleset;

/**
 * Writes a rule set as an XSLT 2.0 stylesheet that emits SVRL, the report format the KoSIT validator reads.
 *
 * Written directly, NOT produced by running a Schematron compiler over the exported `.sch`: the whole
 * point of this project is to have no Java in the toolchain, and every Schematron-to-XSLT compiler in
 * practical use is a Java one.
 *
 * ⚠️ Target is KoSIT validator >= 1.5.0. The stylesheet therefore stays inside plain XSLT/XPath 2.0 -
 * no 3.0 constructs, no processor extensions. A stylesheet that needs a newer processor is worthless to
 * whoever has to run it.
 *
 * ⚠️ Severities are written INTO the rule (`flag` on the SVRL element), not left to a `customLevel`
 * in a scenario configuration. An exported package must keep meaning its own severities even when it
 * is plugged into a configuration nobody controls.
 *
 * ⚠️ One template mode per pattern, and a final `xsl:template match="text()"` that eats stray text -
 * without it, the built-in XSLT rules copy every text node of the invoice into the report, and the
 * SVRL output is unusable.
 */
final class XsltWriter
{
    private const XSL_NS = 'http://www.w3.org/1999/XSL/Transform';
    private const SVRL_NS = 'http://purl.oclc.org/dsdl/svrl';

    public function __construct(private readonly XPathWriter $xpath = new XPathWriter())
    {
    }

    public function write(Ruleset $ruleset): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $stylesheet = $dom->createElementNS(self::XSL_NS, 'xsl:stylesheet');
        $stylesheet->setAttribute('version', '2.0');
        $stylesheet->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:svrl', self::SVRL_NS);
        $stylesheet->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xs', 'http://www.w3.org/2001/XMLSchema');

        foreach ($ruleset->namespaces as $prefix => $uri) {
            $stylesheet->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:$prefix", $uri);
        }

        $dom->appendChild($stylesheet);

        $output = $dom->createElementNS(self::XSL_NS, 'xsl:output');
        $output->setAttribute('method', 'xml');
        $output->setAttribute('indent', 'yes');
        $stylesheet->appendChild($output);

        $stylesheet->appendChild($this->rootTemplate($dom, $ruleset));

        foreach ($ruleset->patterns as $index => $pattern) {
            foreach ($this->patternTemplates($dom, $pattern, 'M' . ($index + 1)) as $template) {
                $stylesheet->appendChild($template);
            }
        }

        // Swallow text nodes in every mode we use, so only our own SVRL elements end up in the report.
        foreach ($ruleset->patterns as $index => $pattern) {
            $eat = $dom->createElementNS(self::XSL_NS, 'xsl:template');
            $eat->setAttribute('match', 'text()');
            $eat->setAttribute('mode', 'M' . ($index + 1));
            $eat->setAttribute('priority', '-1');
            $stylesheet->appendChild($eat);
        }

        return (string) $dom->saveXML();
    }

    /** `<xsl:attribute name="…">value</xsl:attribute>` — a fixed attribute in compiled-Schematron shape. */
    private function staticAttribute(\DOMDocument $dom, string $name, string $value): \DOMElement
    {
        $attribute = $dom->createElementNS(self::XSL_NS, 'xsl:attribute');
        $attribute->setAttribute('name', $name);
        $attribute->appendChild($dom->createTextNode($value));

        return $attribute;
    }

    private function rootTemplate(\DOMDocument $dom, Ruleset $ruleset): \DOMElement
    {
        $template = $dom->createElementNS(self::XSL_NS, 'xsl:template');
        $template->setAttribute('match', '/');

        $out = $dom->createElementNS(self::SVRL_NS, 'svrl:schematron-output');
        $out->setAttribute('title', $ruleset->title);
        $out->setAttribute('schemaVersion', $ruleset->version);
        $template->appendChild($out);

        // ⚠️ SVRL carries the prefix bindings of the rules in the report itself. Without them a reader
        // sees paths like `cbc:ID` with no way to know which namespace that is - and this project's own
        // importer takes them as the proof that it is looking at a Schematron stylesheet at all.
        foreach ($ruleset->namespaces as $prefix => $uri) {
            $binding = $dom->createElementNS(self::SVRL_NS, 'svrl:ns-prefix-in-attribute-values');
            $binding->setAttribute('prefix', $prefix);
            $binding->setAttribute('uri', $uri);
            $out->appendChild($binding);
        }

        foreach ($ruleset->globalLets as $let) {
            $variable = $dom->createElementNS(self::XSL_NS, 'xsl:variable');
            $variable->setAttribute('name', $let->name);
            $variable->setAttribute('select', $this->xpath->write($let->expr));
            $out->appendChild($variable);
        }

        foreach ($ruleset->patterns as $index => $pattern) {
            // ⚠️ id/name are written as xsl:attribute children, not as plain attributes. That is the
            // shape real Schematron compilers emit, and it is what this project's own importer reads -
            // so an exported stylesheet can be imported again, which is exactly how XsltExportTest
            // proves the export did not lose anything.
            $active = $dom->createElementNS(self::SVRL_NS, 'svrl:active-pattern');
            $active->appendChild($this->staticAttribute($dom, 'id', $pattern->id));
            $active->appendChild($this->staticAttribute($dom, 'name', $pattern->name));
            $out->appendChild($active);

            $apply = $dom->createElementNS(self::XSL_NS, 'xsl:apply-templates');
            $apply->setAttribute('select', '/');
            $apply->setAttribute('mode', 'M' . ($index + 1));
            $out->appendChild($apply);
        }

        return $template;
    }

    /**
     * @return list<\DOMElement>
     */
    private function patternTemplates(\DOMDocument $dom, Pattern $pattern, string $mode): array
    {
        $templates = [];

        foreach ($pattern->rules as $rule) {
            $templates[] = $this->ruleTemplate($dom, $rule, $mode);
        }

        // Keep walking the tree in this mode, otherwise only the first matching level is visited.
        $recurse = $dom->createElementNS(self::XSL_NS, 'xsl:template');
        $recurse->setAttribute('match', '@*|node()');
        $recurse->setAttribute('mode', $mode);
        $recurse->setAttribute('priority', '-2');

        $apply = $dom->createElementNS(self::XSL_NS, 'xsl:apply-templates');
        $apply->setAttribute('select', '@*|node()');
        $apply->setAttribute('mode', $mode);
        $recurse->appendChild($apply);

        $templates[] = $recurse;

        return $templates;
    }

    private function ruleTemplate(\DOMDocument $dom, Rule $rule, string $mode): \DOMElement
    {
        $template = $dom->createElementNS(self::XSL_NS, 'xsl:template');
        $template->setAttribute('match', $this->xpath->write($rule->context));
        $template->setAttribute('mode', $mode);
        $template->setAttribute('priority', (string) $rule->priority);

        foreach ($rule->lets as $let) {
            $variable = $dom->createElementNS(self::XSL_NS, 'xsl:variable');
            $variable->setAttribute('name', $let->name);
            $variable->setAttribute('select', $this->xpath->write($let->expr));
            $template->appendChild($variable);
        }

        foreach ($rule->assertions as $assertion) {
            $template->appendChild($this->assertionBlock($dom, $assertion));
        }

        // A rule that matched still has to let the other patterns see the subtree.
        $apply = $dom->createElementNS(self::XSL_NS, 'xsl:apply-templates');
        $apply->setAttribute('select', '@*|node()');
        $apply->setAttribute('mode', $mode);
        $template->appendChild($apply);

        return $template;
    }

    /**
     * `assert` reports when its test is FALSE, `report` when it is TRUE - so the condition written
     * here is negated for an assert and used as-is for a report.
     */
    private function assertionBlock(\DOMDocument $dom, Assertion $assertion): \DOMElement
    {
        $test = $this->xpath->write($assertion->test);
        $condition = $assertion->kind === 'assert' ? "not($test)" : $test;

        $if = $dom->createElementNS(self::XSL_NS, 'xsl:if');
        $if->setAttribute('test', $condition);

        $element = $assertion->kind === 'assert' ? 'svrl:failed-assert' : 'svrl:successful-report';
        $node = $dom->createElementNS(self::SVRL_NS, $element);
        $node->setAttribute('test', $test);

        if ($assertion->id !== null) {
            $node->appendChild($this->staticAttribute($dom, 'id', $assertion->id));
        }

        $node->appendChild($this->staticAttribute($dom, 'flag', $assertion->flag->value));

        $location = $dom->createElementNS(self::XSL_NS, 'xsl:attribute');
        $location->setAttribute('name', 'location');
        $locationValue = $dom->createElementNS(self::XSL_NS, 'xsl:value-of');
        $locationValue->setAttribute('select', 'string-join(ancestor-or-self::*/name(), \'/\')');
        $location->appendChild($locationValue);
        $node->appendChild($location);

        $text = $dom->createElementNS(self::SVRL_NS, 'svrl:text');

        foreach ($assertion->message as $segment) {
            if ($segment->literal !== null) {
                $text->appendChild($dom->createTextNode($segment->literal));
                continue;
            }

            $valueOf = $dom->createElementNS(self::XSL_NS, 'xsl:value-of');
            $valueOf->setAttribute('select', $segment->expr !== null ? $this->xpath->write($segment->expr) : '.');
            $text->appendChild($valueOf);
        }

        $node->appendChild($text);
        $if->appendChild($node);

        return $if;
    }
}

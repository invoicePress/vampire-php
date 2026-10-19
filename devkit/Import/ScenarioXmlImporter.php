<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Task 4.1 — reads the real KoSIT `scenarios.xml` (vendor-sources/kosit-config/, see
 * docs/developer/sources.md) into `Pipeline\Scenario[]`. Maps KoSIT's own resource paths (its OWN packaging
 * layout, e.g. `resources/ubl/2.1/xsd/maindoc/UBL-Invoice-2.1.xsd`) to this project's local
 * `vendor-sources/xsd/` layout and to the `rulesets/<id>@<version>.json` files Task 3.1 already
 * generated - by FILE BASENAME, not by mechanically rewriting the path prefix, since the two
 * layouts genuinely differ in structure (KoSIT's has version/edition path segments this project's
 * `vendor-sources/xsd/` does not) - see docs/notes/decisions.md.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Import;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Exception\ImportException;
use EinvoiceValidator\Pipeline\Scenario;

final class ScenarioXmlImporter
{
    private const SCENARIOS_NS = 'http://www.xoev.de/de/validator/framework/1/scenarios';

    /**
     * KoSIT XSD resource basename => this project's own local path, relative to the
     * `vendor-sources/xsd` root passed into `import()`. Verified present for all three real
     * basenames scenarios.xml references (docs/notes/decisions.md).
     */
    private const XSD_BASENAME_MAP = [
        'UBL-Invoice-2.1.xsd' => 'ubl/maindoc/UBL-Invoice-2.1.xsd',
        'UBL-CreditNote-2.1.xsd' => 'ubl/maindoc/UBL-CreditNote-2.1.xsd',
        'CrossIndustryInvoice_100pD16B.xsd' => 'cii/CrossIndustryInvoice_100pD16B.xsd',
        // Factur-X / ZUGFeRD ship their own, profile-specific CII schema (Task 4.1b): EXTENDED really
        // is a wider schema than EN16931, so the profiles must not share one XSD.
        'Factur-X_1.09.2_EN16931.xsd' => 'facturx/en16931/Factur-X_1.09.2_EN16931.xsd',
        'Factur-X_1.09.2_EXTENDED.xsd' => 'facturx/extended/Factur-X_1.09.2_EXTENDED.xsd',
        // Task 8.0a - the three remaining profiles. Each really has its own schema: MINIMUM does not
        // even allow invoice lines, so sharing one XSD across profiles would accept documents the
        // profile forbids.
        'Factur-X_1.09.2_MINIMUM.xsd' => 'facturx/minimum/Factur-X_1.09.2_MINIMUM.xsd',
        'Factur-X_1.09.2_BASICWL.xsd' => 'facturx/basicwl/Factur-X_1.09.2_BASICWL.xsd',
        'Factur-X_1.09.2_BASIC.xsd' => 'facturx/basic/Factur-X_1.09.2_BASIC.xsd',
    ];

    /**
     * KoSIT Schematron-XSLT resource basename => this project's own `rulesets/<id>@<version>`
     * (matches the ids/versions `devkit/Cli/import-ruleset.php`, Task 3.1, already produced).
     */
    private const RULESET_BASENAME_MAP = [
        'EN16931-UBL-validation.xsl' => 'EN16931-UBL@1.3.16',
        'EN16931-CII-validation.xsl' => 'EN16931-CII@1.3.16',
        'XRechnung-UBL-validation.xsl' => 'XRechnung-UBL@3.0.2',
        'XRechnung-CII-validation.xsl' => 'XRechnung-CII@3.0.2',
        // Task 4.1a - Peppol BIS Billing 3.0.21. ⚠️ Its distribution carries its OWN copy of the CEN
        // EN 16931 rules, and it is NOT the same file as the 1.3.16 one already imported: different
        // version, different rule count. Mapping it onto EN16931-UBL@1.3.16 would validate Peppol
        // documents against rules their own configuration does not use.
        'CEN-EN16931-UBL.xslt' => 'CEN-EN16931-UBL@3.0.21',
        'PEPPOL-EN16931-UBL.xslt' => 'PEPPOL-EN16931-UBL@3.0.21',
        // Task 4.1b - Factur-X / ZUGFeRD 2.5.2 (CII)
        'FACTUR-X_EN16931.xslt' => 'FACTURX-EN16931-CII@1.09.2',
        'FACTUR-X_EXTENDED.xslt' => 'FACTURX-EXTENDED-CII@1.09.2',
        // Task 8.0a
        'FACTUR-X_MINIMUM.xslt' => 'FACTURX-MINIMUM-CII@1.09.2',
        'FACTUR-X_BASIC-WL.xslt' => 'FACTURX-BASICWL-CII@1.09.2',
        'FACTUR-X_BASIC.xslt' => 'FACTURX-BASIC-CII@1.09.2',
        // Task 8.2 - EU-CIUS
        'EN16931-CIUS_RO-UBL-validation.xslt' => 'CIUS-RO-UBL@1.0.9',
        'urn_feap.gov.pt_CIUS-PT_2.1.1.xslt' => 'CIUS-PT-UBL@2.1.1',
        'GLOBALUBL.BE.xslt' => 'UBLBE-UBL@1.31',
        'EN16931-UBL-srbdt-validation.xslt' => 'CIUS-RS-UBL@1.0.0',
        'AGID-EN16931-UBL.xslt' => 'AGID-EN16931-UBL@3.0.2',
    ];

    /**
     * @return Scenario[]
     */
    public static function import(string $file): array
    {
        $dom = new \DOMDocument();
        $dom->load($file, LIBXML_NONET);

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('s', self::SCENARIOS_NS);

        $scenarios = [];
        $seenIds = [];
        foreach (self::query($xpath, '/s:scenarios/s:scenario') as $node) {
            assert($node instanceof \DOMElement);
            $scenario = self::importScenario($xpath, $node);
            if (isset($seenIds[$scenario->id])) {
                throw new ImportException("duplicate derived scenario id \"{$scenario->id}\" - slugs collide");
            }
            $seenIds[$scenario->id] = true;
            $scenarios[] = $scenario;
        }
        if ($scenarios === []) {
            throw new ImportException('no s:scenario found - not a real scenarios.xml?');
        }

        return $scenarios;
    }

    private static function importScenario(\DOMXPath $xpath, \DOMElement $node): Scenario
    {
        $name = self::text($xpath, 's:name', $node);
        $match = self::text($xpath, 's:match', $node);

        $namespaces = [];
        foreach (self::query($xpath, 's:namespace[@prefix]', $node) as $nsNode) {
            assert($nsNode instanceof \DOMElement);
            $namespaces[$nsNode->getAttribute('prefix')] = trim($nsNode->textContent);
        }

        $xsdLocation = self::query($xpath, 's:validateWithXmlSchema[1]/s:resource[1]/s:location', $node)->item(0);
        $xsdPath = null;
        if ($xsdLocation !== null) {
            assert($xsdLocation instanceof \DOMNode);
            $xsdPath = self::mapXsdPath(trim($xsdLocation->textContent));
        }

        $rulesetRefs = [];
        foreach (self::query($xpath, 's:validateWithSchematron/s:resource/s:location', $node) as $locNode) {
            assert($locNode instanceof \DOMNode);
            $rulesetRefs[] = self::mapRulesetRef(trim($locNode->textContent));
        }

        $customLevels = [];
        foreach (self::query($xpath, 's:createReport/s:customLevel[@level]', $node) as $levelNode) {
            assert($levelNode instanceof \DOMElement);
            $customLevels[trim($levelNode->textContent)] = $levelNode->getAttribute('level');
        }

        return new Scenario(
            self::slug($name),
            $name,
            Parser::parse($match),
            $namespaces,
            $xsdPath,
            $rulesetRefs,
            $customLevels
        );
    }

    /**
     * ⚠️ Points into `rulesets/xsd/`, NOT `vendor-sources/xsd/` - `vendor-sources/` is git-ignored
     * build-only material (see docs/notes/decisions.md, 0.2/3.1), but `DOMDocument::schemaValidate()`
     * needs the actual XSD bytes at RUNTIME, unlike the compiled Schematron, which gets consumed
     * into `rulesets/<id>@<version>.json` and never needs the source XSLT again. Section B's own
     * target structure already names this: "`rulesets/` # erzeugte Regelpakete (JSON) + XSDs" - the
     * whole local `vendor-sources/xsd/` tree (20 files, ~760 KB, official freely-redistributable
     * OASIS/UN-CEFACT schemas) is copied into `rulesets/xsd/` once, keeping its own relative
     * structure so the XSDs' own `xsd:import` paths keep resolving.
     */
    private static function mapXsdPath(string $kositLocation): string
    {
        $basename = basename($kositLocation);
        $local = self::XSD_BASENAME_MAP[$basename] ?? null;
        if ($local === null) {
            throw new ImportException(
                "no local XSD mapping for KoSIT resource \"$kositLocation\" (basename \"$basename\")"
            );
        }

        return "rulesets/xsd/$local";
    }

    private static function mapRulesetRef(string $kositLocation): string
    {
        $basename = basename($kositLocation);
        $ref = self::RULESET_BASENAME_MAP[$basename] ?? null;
        if ($ref === null) {
            throw new ImportException(
                "no local ruleset mapping for KoSIT resource \"$kositLocation\" (basename \"$basename\")"
            );
        }

        return $ref;
    }

    /**
     * Deterministic, human-readable scenario id derived from its KoSIT display name (scenarios.xml
     * itself has no explicit id) - e.g. "EN16931 XRechnung (UBL Invoice)" -> "en16931-xrechnung-ubl-invoice".
     */
    private static function slug(string $name): string
    {
        $slug = strtolower($name);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }

    private static function text(\DOMXPath $xpath, string $query, \DOMNode $context): string
    {
        $node = self::query($xpath, $query, $context)->item(0);
        if ($node === null) {
            throw new ImportException("scenario is missing required element matching \"$query\"");
        }
        assert($node instanceof \DOMNode);

        return trim($node->textContent);
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

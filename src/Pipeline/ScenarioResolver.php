<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Pipeline;

use EinvoiceValidator\Xpath\Interpreter;

/**
 * Task 1.3 — resolves which scenario (which rule package applies) a document belongs to. First
 * matching scenario, in list order, wins; no match is a deliberate, reportable outcome
 * (`resolve()` returns null), never a silent fallback to some default scenario.
 */
final class ScenarioResolver
{
    /**
     * @param Scenario[] $scenarios
     */
    public function __construct(private readonly array $scenarios)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_map(Scenario::fromArray(...), $data));
    }

    public static function fromJsonFile(string $path): self
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("cannot read scenario file: $path");
        }

        /** @var array<int, array<string, mixed>> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    /**
     * @param string|null $forceScenarioId Task 5.7 — the application may name the scenario itself.
     *                                     ⚠️ This wins over everything else, including the document's
     *                                     own date: an application that says "check this against
     *                                     XRechnung 3.0.2" means it, and silently choosing another
     *                                     package would make the report answer a different question.
     *                                     An unknown id is an error, not a fallback.
     */
    public function resolve(\DOMDocument $document, ?string $forceScenarioId = null): ?Scenario
    {
        if ($forceScenarioId !== null) {
            foreach ($this->scenarios as $scenario) {
                if ($scenario->id === $forceScenarioId) {
                    return $scenario;
                }
            }

            throw new \RuntimeException("Unbekanntes Szenario \"$forceScenarioId\" vorgegeben.");
        }

        $issueDate = self::issueDate($document);
        $matching = [];

        foreach ($this->scenarios as $scenario) {
            // A fresh Interpreter per scenario: each scenario's `match` may use a different
            // prefix for the same or a different namespace (e.g. "invoice" for UBL, "rsm" for
            // CII), so the namespace map cannot be shared across scenarios.
            $interpreter = new Interpreter($scenario->namespaces);
            $holds = $interpreter->evaluate($scenario->match, $document)->effectiveBooleanValue();

            if ($holds) {
                $matching[] = $scenario;
            }
        }

        if ($matching === []) {
            return null;
        }

        // Task 5.7: among the scenarios whose `match` fits, the invoice date decides; without a usable
        // date (or without any validity periods at all) the FIRST match wins, which is the shipped
        // order and therefore the most specific configuration - see the import order in
        // devkit/Cli/import-scenarios.php.
        foreach ($matching as $scenario) {
            if ($scenario->appliesOn($issueDate)) {
                return $scenario;
            }
        }

        return $matching[0];
    }

    /**
     * BT-2, the invoice issue date, in both syntaxes. Returned as `YYYY-MM-DD` so it can be compared
     * as a string; anything unexpected yields null, which means "no date" rather than a guess.
     *
     * Public for the version cascade ( `Cascade\Cascade` ), which picks an XRechnung edition by the same
     * date; a second reading of BT-2 could disagree with this one.
     */
    public static function issueDate(\DOMDocument $document): ?string
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');
        $xpath->registerNamespace('udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100');

        $ubl = $xpath->evaluate('string(/*/cbc:IssueDate)');

        if (is_string($ubl) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($ubl)) === 1) {
            return trim($ubl);
        }

        // CII writes it as 102-formatted YYYYMMDD.
        $cii = $xpath->evaluate('string(//ram:IssueDateTime/udt:DateTimeString)');

        if (is_string($cii) && preg_match('/^(\d{4})(\d{2})(\d{2})$/', trim($cii), $m) === 1) {
            return "$m[1]-$m[2]-$m[3]";
        }

        return null;
    }
}

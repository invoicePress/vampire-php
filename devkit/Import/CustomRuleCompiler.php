<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Import;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Exception\ImportException;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Ruleset\Assertion;
use EinvoiceValidator\Ruleset\MessageSegment;
use EinvoiceValidator\Ruleset\Pattern;
use EinvoiceValidator\Ruleset\Rule;
use EinvoiceValidator\Ruleset\Ruleset;

/**
 * Tasks 5.5 / 5.5a — turns hand-written rules into a normal runtime rule set.
 *
 * Authoring format (docs/developer/custom-rules.md), one file per rule set:
 *
 *   {
 *     "id": "haus-regeln", "version": "1.0", "syntax": "ubl",
 *     "rules": [
 *       { "id": "HAUS-01", "flag": "warning", "contextBt": "BT-1",
 *         "test": "string-length(normalize-space(.)) >= 3",
 *         "message": "Die Rechnungsnummer sollte mindestens drei Zeichen haben.",
 *         "fields": ["BT-1"] }
 *     ]
 *   }
 *
 * ⚠️ Compiling happens HERE, in devkit, not at runtime: `src/` must not depend on `devkit/`, and the
 * XPath parser lives in devkit. That is the same path the shipped rule sets take - authored as text,
 * compiled to an AST, shipped as JSON - so a custom rule set is not a second class of rule with its
 * own execution path, it is the same thing from a different source.
 *
 * ⚠️ `contextBt` is resolved through the binding table for the DECLARED SYNTAX. A rule set written
 * against UBL cannot be run over a CII document by accident: the compiled ruleset carries UBL paths,
 * and Task 5.4's syntax check refuses to switch it on for a CII scenario.
 */
final class CustomRuleCompiler
{
    /**
     * @param array<string, array{ubl?: string, cii?: string}> $bindings BT/BG => paths per syntax
     */
    public function __construct(private readonly array $bindings)
    {
    }

    public static function withBindingsFrom(string $bindingFile): self
    {
        $raw = file_get_contents($bindingFile);

        if ($raw === false) {
            throw new ImportException("cannot read field bindings: $bindingFile");
        }

        /** @var array<string, array{ubl?: string, cii?: string}> $bindings */
        $bindings = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return new self($bindings);
    }

    /**
     * @param array<string, mixed> $authoring
     */
    public function compile(array $authoring, string $sourceFile = 'custom'): Ruleset
    {
        $id = $authoring['id'] ?? null;
        $version = $authoring['version'] ?? null;
        $syntax = strtolower((string) ($authoring['syntax'] ?? ''));

        if (!is_string($id) || $id === '' || !is_string($version) || $version === '') {
            throw new ImportException('custom ruleset needs "id" and "version"');
        }

        if ($syntax !== 'ubl' && $syntax !== 'cii') {
            throw new ImportException("custom ruleset \"$id\": \"syntax\" must be \"ubl\" or \"cii\"");
        }

        $rules = [];
        $priority = 1000;

        foreach ((array) ($authoring['rules'] ?? []) as $index => $ruleData) {
            if (!is_array($ruleData)) {
                throw new ImportException("custom ruleset \"$id\": rule #$index is not an object");
            }

            $rules[] = $this->compileRule($id, $syntax, $ruleData, $priority--);
        }

        if ($rules === []) {
            throw new ImportException("custom ruleset \"$id\" has no rules");
        }

        return new Ruleset(
            'custom-' . $id,
            $version,
            (string) ($authoring['title'] ?? "Eigene Regeln: $id"),
            $sourceFile,
            hash('sha256', (string) json_encode($authoring)),
            $this->namespacesFor($syntax),
            [],
            [new Pattern('custom', 'custom-' . $id, $rules)],
        );
    }

    /**
     * @param array<string, mixed> $ruleData
     */
    private function compileRule(string $rulesetId, string $syntax, array $ruleData, int $priority): Rule
    {
        $ruleId = $ruleData['id'] ?? null;

        if (!is_string($ruleId) || $ruleId === '') {
            throw new ImportException("custom ruleset \"$rulesetId\": every rule needs an \"id\"");
        }

        $context = $this->resolveContext($rulesetId, $ruleId, $syntax, $ruleData);

        $test = $ruleData['test'] ?? null;

        if (!is_string($test) || trim($test) === '') {
            throw new ImportException("rule \"$ruleId\": \"test\" is missing");
        }

        $message = $ruleData['message'] ?? null;

        if (!is_string($message) || trim($message) === '') {
            throw new ImportException("rule \"$ruleId\": \"message\" is missing");
        }

        $flag = Flag::tryFrom(strtolower((string) ($ruleData['flag'] ?? 'fatal')));

        if ($flag === null) {
            throw new ImportException("rule \"$ruleId\": unknown \"flag\"");
        }

        $fields = [];

        foreach ((array) ($ruleData['fields'] ?? []) as $field) {
            if (!is_string($field)) {
                throw new ImportException("rule \"$ruleId\": \"fields\" must be BT/BG references");
            }

            $fields[] = $field;
        }

        $kind = (string) ($ruleData['kind'] ?? 'assert');

        $assertion = new Assertion(
            $ruleId,
            $flag,
            $kind,
            Parser::parse($test),
            [MessageSegment::literal("[$ruleId] " . trim($message))],
            $fields,
        );

        return new Rule(Parser::parse($context), $priority, [], [$assertion]);
    }

    /**
     * @param array<string, mixed> $ruleData
     */
    private function resolveContext(string $rulesetId, string $ruleId, string $syntax, array $ruleData): string
    {
        $literal = $ruleData['context'] ?? null;

        if (is_string($literal) && trim($literal) !== '') {
            return $literal;
        }

        $bt = $ruleData['contextBt'] ?? null;

        if (!is_string($bt) || $bt === '') {
            throw new ImportException("rule \"$ruleId\": needs either \"context\" or \"contextBt\"");
        }

        $binding = $this->bindings[$bt] ?? null;

        if ($binding === null) {
            throw new ImportException("rule \"$ruleId\": \"$bt\" is not in the binding table");
        }

        $path = $binding[$syntax] ?? null;

        if (!is_string($path) || $path === '') {
            throw new ImportException(
                "rule \"$ruleId\": \"$bt\" has no $syntax path - the field does not exist in that syntax"
            );
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function namespacesFor(string $syntax): array
    {
        if ($syntax === 'ubl') {
            return [
                'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
                'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
            ];
        }

        return [
            'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
            'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
            'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
        ];
    }
}

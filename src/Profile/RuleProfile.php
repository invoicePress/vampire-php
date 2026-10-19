<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Profile;

use EinvoiceValidator\Ruleset\Ruleset;

/**
 * A checked rule profile of the Rule Studio. Only `RuleProfileValidator` creates one; format and
 * semantics: `docs/developer/profile-format.md`.
 *
 * ⚠️ NOT THE SAME THING AS `Profile`. That class is the operator profile (switch off, re-rank, include
 * rule sets) and it really does not evaluate a disabled rule. A rule profile is an overlay on the
 * result: the official rule sets run complete, and what a profile switches off is counted and listed in
 * the report. `RuleProfileOverlay` says why.
 */
final readonly class RuleProfile
{
    public string $ref;

    /**
     * @param list<array{ruleset: string, versions: string}> $extends
     * @param array<string, string> $namespaces
     * @param list<array{name: string, expr: array<string, mixed>|null}> $globalLets
     * @param list<array{id: string, flag: string, kind: string, context: array<string, mixed>|null,
     *     lets: list<array{name: string, expr: array<string, mixed>|null}>, test: array<string, mixed>|null,
     *     message: list<array<string, mixed>>, fields: list<string>}> $rules
     * @param list<array{rule: string, reason: string|null}> $disable
     * @param list<array{rule: string, by: string, reason: string|null}> $replaces
     * @param list<array{rule: string, level: string, reason: string|null}> $severity
     */
    public function __construct(
        public string $name,
        public string $version,
        public ?string $title,
        public string $syntax,
        public string $prefix,
        public array $extends,
        public array $namespaces,
        public array $globalLets,
        public array $rules,
        public array $disable,
        public array $replaces,
        public array $severity,
    ) {
        $this->ref = $name . '@' . $version;
    }

    /**
     * The rule set for ONE rule of this profile, in the shape `Engine` runs.
     *
     * ⚠️ ONE PATTERN PER RULE: inside a pattern the first rule claims a node and every later rule skips
     * it, so two customer rules on the same context would leave the second one silent. ⚠️ AND ONE RULE
     * SET PER RULE, so that a rule that throws at run time costs itself and not the whole profile.
     */
    public function rulesetFor(int $index): Ruleset
    {
        $rule = $this->rules[$index];

        return Ruleset::fromArray([
            'schemaVersion' => 2,
            'id' => 'profile:' . $this->name,
            'version' => $this->version,
            'title' => $this->title ?? $this->ref,
            'source' => ['file' => 'profile', 'sha256' => ''],
            'namespaces' => $this->namespaces,
            'globalLets' => $this->globalLets,
            'patterns' => [[
                'id' => $rule['id'],
                'name' => 'profile',
                'rules' => [[
                    'context' => $rule['context'],
                    'priority' => 1000,
                    'lets' => $rule['lets'],
                    'assertions' => [[
                        'id' => $rule['id'],
                        'flag' => $rule['flag'],
                        'kind' => $rule['kind'],
                        'test' => $rule['test'],
                        'message' => $rule['message'],
                        'fieldRefs' => $rule['fields'],
                    ]],
                ]],
            ]],
        ]);
    }
}

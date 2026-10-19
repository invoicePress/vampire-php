<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Tasks 5.5 / 5.5a — compiles hand-written rule sets into `rulesets/custom-<id>@<version>.json`.
 *
 *   php devkit/Cli/compile-custom-rules.php <datei.json> [weitere.json ...] [--apply]
 *
 * Without `--apply` it only reports what it would write. Format: docs/developer/custom-rules.md.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Devkit\Import\CustomRuleCompiler;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
// ⚠️ `$argv` exists only with `register_argc_argv` switched on - the CLI default, but not every
// configuration's. Read from `$_SERVER` it is the same list, with a type static analysis can
// follow.
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));

$apply = in_array('--apply', $argv, true);
$files = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--apply'));

if ($files === []) {
    fwrite(STDERR, "usage: php devkit/Cli/compile-custom-rules.php <datei.json> [...] [--apply]\n");
    exit(1);
}

$compiler = CustomRuleCompiler::withBindingsFrom($root . '/rulesets/field-bindings.json');

foreach ($files as $file) {
    $raw = file_get_contents($file);

    if ($raw === false) {
        fwrite(STDERR, "cannot read $file\n");
        exit(1);
    }

    /** @var array<string, mixed> $authoring */
    $authoring = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    $ruleset = $compiler->compile($authoring, $file);
    $ref = $ruleset->id . '@' . $ruleset->version;

    $rules = 0;

    foreach ($ruleset->patterns as $pattern) {
        $rules += count($pattern->rules);
    }

    printf("%-40s %d Regeln%s\n", $ref, $rules, $apply ? '' : ' (Trockenlauf)');

    if ($apply) {
        RulesetJsonCodec::writeFile($root . "/rulesets/$ref.json", $ruleset);
    }
}

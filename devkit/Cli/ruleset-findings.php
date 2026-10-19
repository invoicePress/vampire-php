<?php

/**
 * Runs ONE rule set over a list of documents and writes the rule ids it finds, as JSON.
 *
 *   php devkit/Cli/ruleset-findings.php <ruleset.json> <out.json> <invoice.xml> [...]
 *
 * ⚠️ **ONE RULE SET, NOT THE SCENARIO.** `bin/einvoice-validator validate` resolves a scenario and
 * runs every rule set it names - the right thing for a user and the wrong thing for a comparison:
 * the JavaScript side has no scenario resolver yet, and a difference in the SELECTION would look
 * like a difference in the EVALUATION.
 *
 * ⚠️ AND IT WRITES ONLY IDS AND FLAGS, not messages. Message text carries the values a rule quoted,
 * and comparing those means comparing number formatting - a real question, but a different one.
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use EinvoiceValidator\Engine\Engine;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;

$arguments = array_map('strval', (array) ($_SERVER['argv'] ?? []));

$rulesetPath = $arguments[1] ?? '';
$outPath     = $arguments[2] ?? '';
$documents   = array_slice($arguments, 3);

if ($rulesetPath === '' || $outPath === '' || $documents === []) {
    fwrite(STDERR, "Aufruf: php ruleset-findings.php <ruleset.json> <out.json> <invoice.xml> [...]\n");
    exit(2);
}

$ruleset = RulesetJsonCodec::decode((string) file_get_contents($rulesetPath));

// ⚠️ The Factur-X rule sets read their code lists with fn:document() from `rulesets/codedb/`, as
// `Validator::runRulesets()` does. Without the directory every one of them throws on the first code list
// rule ( measured 09.10.2026 ); the other rule sets never call fn:document().
$codeDb = dirname($rulesetPath) . '/codedb';
$engine = new Engine(is_dir($codeDb) ? $codeDb : null);

$out = [
    'ruleset' => $ruleset->id . ' ' . $ruleset->version,
    'findings' => [],
];

foreach ($documents as $path) {
    $dom = new DOMDocument();

    // Whitespace is significant for `normalize-space()` and for text-node tests; dropping it here
    // would quietly change what the rules see.
    $dom->preserveWhiteSpace = true;

    if (!@$dom->load($path)) {
        $out['findings'][basename($path)] = ['::unreadable::'];
        continue;
    }

    $ids = [];

    // ⚠️ A rule set that aborts on one document is recorded as `::error::` for that document instead of ending the
    // whole run. The parity measurement of VAMPIRE JS runs every national rule set over the whole corpus, also over
    // documents of another syntax, and some of them abort there ( CIUS-PT on a document with two amounts where it
    // expects one ). VAMPIRE JS must then abort as well.
    try {
        $found = $engine->run($ruleset, $dom);
    } catch (\Throwable $e) {
        $out['findings'][basename($path)] = ['::error::', $e->getMessage()];
        continue;
    }

    foreach ($found as $finding) {
        if ($finding->ruleId !== null) {
            $ids[] = $finding->ruleId;
        }
    }

    $ids = array_values(array_unique($ids));
    sort($ids);

    $out['findings'][basename($path)] = $ids;
}

file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo 'Regelwerk : ' . $out['ruleset'] . PHP_EOL;
echo 'Belege    : ' . count($out['findings']) . PHP_EOL;
echo 'Datei     : ' . $outPath . PHP_EOL;

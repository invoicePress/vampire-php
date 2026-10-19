<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Tasks 6.3 / 6.4 — exports the ADDITIONAL rules of a profile as a package for the KoSIT validator.
 *
 *   php devkit/Cli/export-profile.php <profil-id> <ausgabeverzeichnis> [--apply]
 *
 * Export variant (a) of Task 6.4: only what the profile ADDS - its own rules and the foreign rules it
 * switches on - as a separate validation step. The official files stay untouched.
 *
 * ⚠️ Variant (b), replacing the official rule files, is deliberately NOT built. It is recorded as an
 * extension point in docs/notes/decisions.md: rewriting an official rule file means the report no longer
 * says what the official rules say, and whoever reads it cannot tell.
 *
 * ⚠️ What a profile does that the export CANNOT do is listed in the generated README, not silently
 * dropped: switching an official rule off and re-ranking an official rule both happen in our own
 * engine, above the rule sets. An exported package runs as an additional step next to the official
 * ones, so it cannot reach into them.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Devkit\Export\SchematronWriter;
use EinvoiceValidator\Devkit\Export\XsltWriter;
use EinvoiceValidator\Profile\ProfileLoader;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
// ⚠️ `$argv` exists only with `register_argc_argv` switched on - the CLI default, but not every
// configuration's. Read from `$_SERVER` it is the same list, with a type static analysis can
// follow.
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));

$apply = in_array('--apply', $argv, true);
$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--apply'));

if (count($args) < 2) {
    fwrite(STDERR, "usage: php devkit/Cli/export-profile.php <profil-id> <ausgabeverzeichnis> [--apply]\n");
    exit(1);
}

[$profileId, $outDir] = $args;

$profileDir = is_dir($root . '/profiles') ? $root . '/profiles' : $root . '/tests/fixtures/profiles';
$profile = (new ProfileLoader($profileDir))->load($profileId);

if ($profile->include === []) {
    fwrite(STDERR, "Profil \"$profileId\" schaltet keine Regeln zu - es gibt nichts zu exportieren.\n");
    exit(1);
}

$schematron = new SchematronWriter();
$xslt = new XsltWriter();

$written = [];

foreach ($profile->include as $ref => $ruleIds) {
    $ruleset = RulesetJsonCodec::readFile($root . "/rulesets/$ref.json");

    // Only the rules the profile actually names travel with the export.
    if ($ruleIds !== []) {
        $ruleset = filter_ruleset($ruleset, $ruleIds);
    }

    $base = str_replace(['@', '/'], ['_', '_'], $ref);

    $written["$base.sch"] = $schematron->write($ruleset);
    $written["$base.xslt"] = $xslt->write($ruleset);
}

$written['scenarios-snippet.xml'] = scenario_snippet($profileId, array_keys($profile->include));
$written['README.txt'] = readme($profileId, $profile->disable, array_keys($profile->severity), array_keys($profile->include));

printf("%s: %d Dateien für Profil \"%s\"\n", $apply ? 'geschrieben' : 'Trockenlauf', count($written), $profileId);

foreach ($written as $name => $content) {
    printf("  %-40s %6d Bytes\n", $name, strlen($content));
}

if (!$apply) {
    exit(0);
}

if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "cannot create $outDir\n");
    exit(1);
}

foreach ($written as $name => $content) {
    file_put_contents($outDir . '/' . $name, $content);
}

echo "\nnach $outDir geschrieben\n";

/**
 * @param list<string> $ruleIds
 */
function filter_ruleset(\EinvoiceValidator\Ruleset\Ruleset $ruleset, array $ruleIds): \EinvoiceValidator\Ruleset\Ruleset
{
    $patterns = [];

    foreach ($ruleset->patterns as $pattern) {
        $rules = [];

        foreach ($pattern->rules as $rule) {
            $assertions = array_values(array_filter(
                $rule->assertions,
                static fn ($a): bool => $a->id !== null && in_array($a->id, $ruleIds, true)
            ));

            if ($assertions === []) {
                continue;
            }

            $rules[] = new \EinvoiceValidator\Ruleset\Rule($rule->context, $rule->priority, $rule->lets, $assertions);
        }

        if ($rules !== []) {
            $patterns[] = new \EinvoiceValidator\Ruleset\Pattern($pattern->id, $pattern->name, $rules);
        }
    }

    return new \EinvoiceValidator\Ruleset\Ruleset(
        $ruleset->id,
        $ruleset->version,
        $ruleset->title,
        $ruleset->sourceFile,
        $ruleset->sourceHash,
        $ruleset->namespaces,
        $ruleset->globalLets,
        $patterns,
    );
}

/**
 * @param list<string> $refs
 */
function scenario_snippet(string $profileId, array $refs): string
{
    $lines = [
        '<!-- Aufgabe 6.3: in die <validateWithSchematron>-Liste des passenden Szenarios einfuegen. -->',
        '<!-- Zielversion: KoSIT Validator >= 1.5.0 (empfohlen 1.6.x). -->',
        sprintf('<!-- Zusatzregeln aus Pruefprofil "%s". -->', $profileId),
    ];

    foreach ($refs as $ref) {
        $base = str_replace(['@', '/'], ['_', '_'], $ref);
        $lines[] = '<resource>';
        $lines[] = sprintf('  <name>Zusatzregeln %s</name>', $ref);
        $lines[] = sprintf('  <location>%s.xslt</location>', $base);
        $lines[] = '</resource>';
    }

    return implode("\n", $lines) . "\n";
}

/**
 * @param list<string> $disabled
 * @param list<string> $reranked
 * @param list<string> $refs
 */
function readme(string $profileId, array $disabled, array $reranked, array $refs): string
{
    $text = "Zusatzregeln aus dem Pruefprofil \"$profileId\"\n";
    $text .= str_repeat('=', 40) . "\n\n";
    $text .= "Zielversion: KoSIT Validator >= 1.5.0, empfohlen 1.6.x.\n\n";
    $text .= "Einbau\n------\n";
    $text .= "1. Die .xslt-Dateien in das resources-Verzeichnis der Konfiguration legen.\n";
    $text .= "2. Den Inhalt von scenarios-snippet.xml in das passende Szenario der scenarios.xml\n";
    $text .= "   aufnehmen, hinter die bereits vorhandenen <resource>-Eintraege.\n";
    $text .= "3. Konfiguration neu laden und einen bekannten Beleg pruefen.\n\n";
    $text .= "Die .sch-Dateien sind die lesbare Fassung derselben Regeln. Der Validator braucht sie\n";
    $text .= "nicht; sie liegen bei, damit nachvollziehbar bleibt, was die .xslt tut.\n\n";
    $text .= "Enthaltene Regelpakete\n----------------------\n";

    foreach ($refs as $ref) {
        $text .= "  - $ref\n";
    }

    $text .= "\nWas dieser Export NICHT leisten kann\n";
    $text .= "------------------------------------\n";
    $text .= "Dieses Paket laeuft als ZUSAETZLICHER Pruefschritt neben den amtlichen Regeln. Es kann\n";
    $text .= "deshalb nichts an ihnen aendern:\n\n";

    if ($disabled !== []) {
        $text .= "  * Abgeschaltete Regeln wirken hier nicht - sie werden vom amtlichen Regelwerk\n";
        $text .= "    weiterhin geprueft und gemeldet:\n";

        foreach ($disabled as $ruleId) {
            $text .= "      - $ruleId\n";
        }

        $text .= "\n";
    }

    if ($reranked !== []) {
        $text .= "  * Umgestufte Regeln behalten im amtlichen Regelwerk ihre urspruengliche Einstufung:\n";

        foreach ($reranked as $ruleId) {
            $text .= "      - $ruleId\n";
        }

        $text .= "\n";
    }

    if ($disabled === [] && $reranked === []) {
        $text .= "  (Dieses Profil schaltet keine amtliche Regel ab und stuft keine um -\n";
        $text .= "   es gibt hier also nichts, was verloren ginge.)\n\n";
    }

    $text .= "Wer das braucht, muss die amtlichen Dateien selbst ersetzen. Genau das ist die bewusst\n";
    $text .= "nicht gebaute Exportart (b), siehe docs/notes/decisions.md.\n";

    return $text;
}

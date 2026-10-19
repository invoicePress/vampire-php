<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Writes this library's NOTICE and its folder licences/ from the provenance table.
 *
 *   php devkit/Cli/write-notice.php                       NOTICE and licences/ for every rule set in rulesets/
 *   php devkit/Cli/write-notice.php --check               writes nothing, exit code 1 if NOTICE is not current
 *   php devkit/Cli/write-notice.php --trial-open-licences writes even if a licence text is missing ( marked TRIAL )
 *
 * ⚠️ **GENERATED, NEVER HAND-WRITTEN.** A hand-written NOTICE named the EN 16931 rules and the Peppol rules as
 * Apache-2.0, both wrong. The table `rule-provenance.json` is the one source for
 * VAMPIRE JS and VAMPIRE PHP; the part about code lists, schemas and test material comes from
 * `devkit/Licence/notice-tail.txt`.
 *
 * ⚠️ **licences/ IS A COPY, AND THE COPY IS WRITTEN HERE.** The texts live under
 * `licences/`. `export-runtime.php` copies them into the runtime package
 * itself; this folder only exists so that the work tree of the library is complete on its own.
 *
 * ⚠️ **A MISSING LICENCE TEXT STOPS THE RUN.** EUPL-1.2 article 5 and Apache-2.0 section 4(a) require the
 * text itself; without it in `licences/` no NOTICE is written.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Devkit\Licence\RuleProvenance;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$base = dirname(__DIR__, 2);
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));
$check = in_array('--check', $argv, true);
$trialAllowed = in_array('--trial-open-licences', $argv, true);

$prov = new RuleProvenance(RuleProvenance::defaultTable());

$rulesets = [];

foreach (glob($base . '/rulesets/*@*.json') ?: [] as $file) {
    $rulesets[] = RuleProvenance::readHead($file);
}

// ⚠️ A rule set without an entry, or with an entry whose status is 'open', is listed in section E and not in the
// list of rule sets. The library carries them in its work tree, and a NOTICE that is silent about them would leave
// a gap; but an 'open' entry in the list would stop this script ( check() reports it ), although no package carries
// these rule sets. Of the national CIUS, Romania and Croatia are 'cleared' and appear in the list; Belgium,
// Portugal, Peppol Italy, Serbia and Turkey are 'open' and stay in E.
$shippable = static function ($r) use ($prov): bool {
    $entry = $prov->entryFor($r->sourceFile);

    return $entry !== null && in_array($entry['status'] ?? 'open', ['cleared', 'pending'], true);
};
$known = array_values(array_filter($rulesets, $shippable));
$unknown = array_values(array_filter($rulesets, static fn ($r): bool => !$shippable($r)));

$state = $prov->check($known);
$trial = array_merge(
    array_map(static fn (string $r): string => 'Open licence question: ' . $r . '.', $state['open']),
    array_map(static fn (string $t): string => 'Licence text missing from the build machine: ' . $t . '.', $state['missing']),
);

if ($trial !== [] && !$trialAllowed && !$check) {
    fwrite(STDERR, "NOTICE nicht geschrieben:\n  " . implode("\n  ", $trial) . "\n  Lizenztexte gehören nach " . $prov->licenceDir() . "\n");
    exit(1);
}

$tail = (string) file_get_contents(dirname(__DIR__) . '/Licence/notice-tail.txt');

if ($unknown !== []) {
    $tail = "E. Rule sets in rulesets/ without a cleared entry in the provenance table\n"
        . "   These rule sets are in the development tree only. No package may carry\n"
        . "   them before their licence is documented and their entry is cleared:\n"
        . implode('', array_map(static function ($r) use ($prov): string {
            $entry = $prov->entryFor($r->sourceFile);

            return '   - ' . $r->id . '@' . $r->version . ( $entry === null ? ' ( no entry )' : ' ( status ' . (string) ($entry['status'] ?? 'open') . ' )' ) . "\n";
        }, $unknown))
        . "\n" . $tail;
}

$text = $prov->noticeText('VAMPIRE PHP ( invoicepress/vampire-php )', $known, [], $trial, $tail);

if ($check) {
    $current = (string) @file_get_contents($base . '/NOTICE');
    echo $current === $text ? "NOTICE ist aktuell.\n" : "NOTICE ist NICHT aktuell - php devkit/Cli/write-notice.php\n";
    exit($current === $text ? 0 : 1);
}

file_put_contents($base . '/NOTICE', $text);

if (!is_dir($base . '/licences')) {
    mkdir($base . '/licences', 0777, true);
}

$copied = 0;

// ⚠️ The licence texts lie in this package itself; copying a file onto itself would empty it on some systems.
foreach (array_diff($state['needed'], $state['missing']) as $t) {
    if (realpath($prov->licenceDir() . '/' . $t) === realpath($base . '/licences/' . $t)) {
        $copied++;
        continue;
    }

    copy($prov->licenceDir() . '/' . $t, $base . '/licences/' . $t);
    $copied++;
}

printf(
    "NOTICE geschrieben: %d Regelwerke mit Herkunft, %d ohne freigegebenen Eintrag, %d Byte.\n%d Lizenztext(e) nach licences/%s\n",
    count($known),
    count($unknown),
    strlen($text),
    $copied,
    $trial !== [] ? "\nPROBE: " . implode(' ', $trial) : ''
);

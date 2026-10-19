<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Is the exported XSLT the same check as our own runtime?
 *
 *   php devkit/Cli/kosit-export-check.php <profil-id> [--group=xrechnung-standard]
 *
 * The question this answers: a customer who stays with the KoSIT validator gets the additional rules
 * of a profile as an XSLT package. Does that package produce the same findings as this library does
 * under the same profile? A JavaScript XSLT 2.0 processor could answer that too, but there is none with
 * an acceptable licence (`docs/developer/licenses.md`). The local KoSIT validator settles it instead, and it is
 * the better witness anyway: it is the program the export is actually FOR.
 *
 * What happens here:
 *   1. the profile's additional rules are exported (`export-profile.php`),
 *   2. the KoSIT XRechnung configuration is copied to a scratch directory,
 *   3. the exported `.xslt` is hooked into every scenario of the copy as one more
 *      `validateWithSchematron` step,
 *   4. the test bed runs through the validator with that patched configuration,
 *   5. the same documents run through THIS library with the same profile,
 *   6. only the findings of the profile's own rule ids are compared - everything else in the report
 *      comes from the official rules and says nothing about the export.
 *
 * ⚠️ The original configuration is never touched; everything happens in a copy under the system's
 * temp directory, and the copy is removed at the end.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Profile\ProfileLoader;
use EinvoiceValidator\Validator;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// The installation of the KoSIT validator 1.6.x; set KOSIT_HOME to its folder.
define('KOSIT_HOME_6_5', rtrim((string) (getenv('KOSIT_HOME') ?: dirname(__DIR__, 2) . '/vendor-sources/kosit-validator'), '/\\'));
const S_NS = 'http://www.xoev.de/de/validator/framework/1/scenarios';

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function copy_tree(string $from, string $to): void
{
    if (!is_dir($to) && !mkdir($to, 0777, true)) {
        fail("cannot create $to");
    }

    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        $target = $to . '/' . $items->getSubPathName();

        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0777, true)) {
                fail("cannot create $target");
            }

            continue;
        }

        copy($item->getPathname(), $target);
    }
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($dir);
}

$root = dirname(__DIR__, 2);
// ⚠️ `$argv` exists only with `register_argc_argv` switched on - the CLI default, but not every
// configuration's. Read from `$_SERVER` it is the same list, with a type static analysis can
// follow.
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));

$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$group = 'xrechnung-standard';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--group=')) {
        $group = substr($arg, 8);
    }
}

if ($args === []) {
    fail('usage: php devkit/Cli/kosit-export-check.php <profil-id> [--group=<gruppe>]');
}

$profileId = $args[0];
$profileDir = is_dir($root . '/profiles') ? $root . '/profiles' : $root . '/tests/fixtures/profiles';
$profile = (new ProfileLoader($profileDir))->load($profileId);

if ($profile->include === []) {
    fail("Profil \"$profileId\" schaltet keine Regeln zu - es gibt nichts zu vergleichen.");
}

// Which rule ids the export carries. Only these are compared.
$exportedIds = [];

foreach ($profile->include as $ruleIds) {
    foreach ($ruleIds as $ruleId) {
        $exportedIds[$ruleId] = true;
    }
}

if ($exportedIds === []) {
    fail("Profil \"$profileId\" schaltet ganze Regelwerke zu, ohne Regel-IDs zu nennen - für diesen "
        . 'Vergleich bitte ein Profil mit ausdrücklicher Regelliste.');
}

$scratch = sys_get_temp_dir() . '/kosit-export-check-' . getmypid();
$exportDir = $scratch . '/export';
$configDir = $scratch . '/config';
$outDir = $scratch . '/reports';

foreach ([$exportDir, $outDir] as $dir) {
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
        fail("cannot create $dir");
    }
}

// --- 1. export ----------------------------------------------------------------------------------

exec(sprintf('php "%s/devkit/Cli/export-profile.php" %s "%s" --apply', $root, escapeshellarg($profileId), $exportDir), $out, $code);

if ($code !== 0) {
    fail("Export fehlgeschlagen:\n" . implode("\n", $out));
}

$exportedXslt = glob($exportDir . '/*.xslt') ?: [];

if ($exportedXslt === []) {
    fail('Der Export hat keine .xslt erzeugt.');
}

// --- 2./3. configuration copy with the extra step -------------------------------------------------

copy_tree(KOSIT_HOME_6_5 . '/config/xrechnung', $configDir);

foreach ($exportedXslt as $file) {
    copy($file, $configDir . '/' . basename($file));
}

$dom = new \DOMDocument();
$dom->preserveWhiteSpace = false;
$dom->formatOutput = true;

if (!$dom->load($configDir . '/scenarios.xml')) {
    fail('scenarios.xml der Kopie nicht lesbar.');
}

$xpath = new \DOMXPath($dom);
$xpath->registerNamespace('s', S_NS);

$patched = 0;

foreach ($xpath->query('//s:scenario') ?: [] as $scenario) {
    if (!$scenario instanceof \DOMElement) {
        continue;
    }

    $steps = $xpath->query('s:validateWithSchematron', $scenario);

    if ($steps === false || $steps->length === 0) {
        continue;
    }

    $last = $steps->item($steps->length - 1);

    if (!$last instanceof \DOMNode || $last->parentNode === null) {
        continue;
    }

    foreach ($exportedXslt as $file) {
        $step = $dom->createElementNS(S_NS, 's:validateWithSchematron');
        $resource = $dom->createElementNS(S_NS, 's:resource');
        $resource->appendChild($dom->createElementNS(S_NS, 's:name', 'Zusatzregeln ' . $profileId));
        $resource->appendChild($dom->createElementNS(S_NS, 's:location', basename($file)));
        $step->appendChild($resource);

        // ⚠️ Behind the last Schematron step, not at the end of the scenario: the element order of
        // s:scenario is fixed by its schema (createReport and acceptMatch follow), and appending
        // would make the configuration fail to load with a cvc-complex-type error.
        $last->parentNode->insertBefore($step, $last->nextSibling);
    }

    $patched++;
}

if ($patched === 0) {
    fail('Kein Szenario mit Schematron-Schritt gefunden - Konfiguration unerwartet aufgebaut.');
}

$dom->save($configDir . '/scenarios.xml');

printf("Konfigurationskopie: %d Szenarien um %d Zusatzschritt(e) erweitert\n", $patched, count($exportedXslt));

// --- 4. the validator's verdict ------------------------------------------------------------------

$files = glob($root . "/tests/fixtures/invoices/$group/*.{xml,XML}", GLOB_BRACE) ?: [];
sort($files);

if ($files === []) {
    fail("Gruppe \"$group\" enthält keine Belege.");
}

foreach (array_chunk($files, 20) as $chunk) {
    $fileArgs = '';

    foreach ($chunk as $file) {
        $fileArgs .= ' "' . str_replace('/', '\\', $file) . '"';
    }

    $command = sprintf(
        'java -jar "%s/bin/validator.jar" -r "%s" -s "%s/scenarios.xml" -o "%s"%s',
        KOSIT_HOME_6_5,
        str_replace('/', '\\', $configDir),
        str_replace('/', '\\', $configDir),
        str_replace('/', '\\', $outDir),
        $fileArgs
    );

    $out = [];
    exec($command . ' 2>&1', $out, $code);
}

// --- 5./6. comparison ----------------------------------------------------------------------------

$resolver = ScenarioResolver::fromJsonFile($root . '/rulesets/scenarios.json');
$validator = new Validator(scenarioResolver: $resolver, baseDir: $root, profile: $profile);

$compared = 0;
$fired = 0;
$differences = [];
$skipped = [];

foreach ($files as $file) {
    $stem = pathinfo($file, PATHINFO_FILENAME);
    $reportPath = $outDir . '/' . $stem . '-report.xml';

    if (!is_file($reportPath)) {
        foreach (glob($outDir . '/*-report.xml') ?: [] as $candidate) {
            if (rawurldecode(basename($candidate)) === $stem . '-report.xml') {
                $reportPath = $candidate;
                break;
            }
        }
    }

    if (!is_file($reportPath)) {
        $differences[] = basename($file) . ': kein Bericht erzeugt';
        continue;
    }

    $reportDom = new \DOMDocument();
    $reportDom->load($reportPath);
    $reportXpath = new \DOMXPath($reportDom);
    $reportXpath->registerNamespace('rep', 'http://www.xoev.de/de/validator/varl/1');

    $fromXslt = [];

    foreach ($reportXpath->query('//rep:message') ?: [] as $node) {
        if (!$node instanceof \DOMElement) {
            continue;
        }

        $code = $node->getAttribute('code');

        if (isset($exportedIds[$code])) {
            $fromXslt[$code] = true;
        }
    }

    try {
        $result = $validator->validateFile($file);
    } catch (\RuntimeException $e) {
        // ⚠️ Expected, not an error: Task 5.4 refuses to switch a UBL-only rule set onto a CII
        // document. Such a document is outside this comparison - the export would not run on it
        // either. Counted and named, never silently dropped.
        $skipped[] = basename($file) . ': ' . $e->getMessage();
        continue;
    }

    $fromPhp = [];

    foreach ($result->messages() as $message) {
        if ($message->ruleId !== null && isset($exportedIds[$message->ruleId])) {
            $fromPhp[$message->ruleId] = true;
        }
    }

    ksort($fromXslt);
    ksort($fromPhp);

    $compared++;

    if ($fromXslt !== [] || $fromPhp !== []) {
        $fired++;
    }

    if (array_keys($fromXslt) !== array_keys($fromPhp)) {
        $differences[] = sprintf(
            '%s: XSLT [%s] vs. PHP [%s]',
            basename($file),
            implode(',', array_keys($fromXslt)) ?: '-',
            implode(',', array_keys($fromPhp)) ?: '-'
        );
    }
}

printf(
    "\nProfil \"%s\", Gruppe \"%s\": %d Belege verglichen, Regeln %s\n",
    $profileId,
    $group,
    $compared,
    implode(', ', array_keys($exportedIds))
);

if ($skipped !== []) {
    printf(
        "%d Belege übersprungen (Syntax passt nicht zum zugeschalteten Regelwerk):\n  %s\n",
        count($skipped),
        implode("\n  ", array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? "\n  …" : '')
    );
}

remove_tree($scratch);

if ($differences !== []) {
    fwrite(STDERR, "\nAbweichungen:\n  " . implode("\n  ", $differences) . "\n");
    exit(1);
}

// ⚠️ "No difference" is worthless when the rule did not fire on a single document: then both sides
// stayed silent, and that proves neither equality nor that anything ran at all. Exactly this trap
// can make a comparison test worthless without anyone noticing.
if ($fired === 0) {
    fail(sprintf(
        "\nKein Beleg der Gruppe \"%s\" löst eine der exportierten Regeln aus (%s).\n"
        . "Damit ist nichts gezeigt: beide Seiten haben geschwiegen. Eine Gruppe wählen, in der die\n"
        . 'Regel wirklich anschlägt.',
        $group,
        implode(', ', array_keys($exportedIds))
    ));
}

printf(
    "Auf %d von %d Belegen hat mindestens eine exportierte Regel angeschlagen - "
    . "beide Seiten melden dieselben.\n",
    $fired,
    $compared
);
echo "Keine Abweichung: der exportierte XSLT-Schritt und die PHP-Laufzeit melden dieselben Regeln.\n";

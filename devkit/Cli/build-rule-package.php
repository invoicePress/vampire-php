<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Builds a rule package update: one ZIP with the rule sets, the scenarios, the schemas, the code lists,
 * NOTICE and licences/, and a manifest - but without the engine.
 *
 *   php devkit/Cli/build-rule-package.php <ziel.zip> [--core|--rulesets=a,b] [--without=peppol]
 *                                         [--edition=2026-10-09] [--versions] [--trial-open-licences]
 *
 * The ZIP is what a customer downloads from the shop account and uploads in the plugin
 * "eInvoice Validator for VAMPIRE" ( settings page, section "Rule packages" ). A Composer customer can
 * unpack it anywhere and hand the folder to `new Validator( baseDir: … )` with a `ScenarioResolver` on
 * its `rulesets/scenarios.json`; the layout is the same as in the runtime export.
 *
 * ⚠️ **THE CONTENT COMES FROM `export-runtime.php`, NOT FROM A SECOND COPY OF ITS LOGIC.** The export
 * decides which rule sets, which scenarios, which XSD families and which licence texts belong together,
 * and it holds the licence gate. A second implementation here would drift from it at the first change.
 * This script runs the export into a work folder, drops `src/` and `LICENSE`, and adds the manifest.
 *
 * ⚠️ **`src/` AND `LICENSE` STAY OUT ON PURPOSE.** A rule package must not replace the engine: the plugin
 * would otherwise run code that came in through an upload form. The rule sets are data, and the manifest
 * names the oldest engine edition that reads them ( `engine.minVersion` ).
 *
 * ⚠️ **THE LICENCE GATE OF THE EXPORT APPLIES HERE AS WELL.** Without `EUPL-1.2.txt` on the build machine
 * the export stops, and so does this script. `--trial-open-licences` lets it through for a measurement;
 * the manifest then says `"trial": true`, NOTICE says "TRIAL BUILD", and the plugin refuses such a
 * package unless the site defines `SPPV_ALLOW_TRIAL_RULE_PACKAGES` ( for tests only ).
 *
 * ⚠️ **EVERY FILE IS LISTED WITH ITS SHA-256 IN THE MANIFEST.** The plugin checks each one before it
 * switches over. A package that was cut short by a broken download then fails at the upload, and not
 * later at the first invoice with a missing schema import.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Devkit\Licence\RuleProvenance;
use EinvoiceValidator\Validator;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/** The format name the plugin looks for; a ZIP without it is not a rule package. */
const PACKAGE_FORMAT = 'vampire-rule-package';

/** Raised only when the meaning of a field changes; the plugin refuses a format it does not know. */
const PACKAGE_FORMAT_VERSION = 1;

/** The manifest's file name at the root of the ZIP. */
const MANIFEST = 'rule-package.json';

function remove_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }

    rmdir($dir);
}

$base = dirname(__DIR__, 2);
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));
$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));

if ($args === [] || !str_ends_with(strtolower($args[0]), '.zip')) {
    fwrite(STDERR, "usage: php devkit/Cli/build-rule-package.php <ziel.zip> [--core|--rulesets=a,b] [--without=peppol] [--edition=JJJJ-MM-TT] [--versions] [--trial-open-licences]\n");
    exit(1);
}

$zipPath = str_replace('\\', '/', $args[0]);
$trial = in_array('--trial-open-licences', $argv, true);
$edition = gmdate('Y-m-d');
$passThrough = [];

foreach ($argv as $arg) {
    // `--versions` adds the edition packages of the cascade ( rulesets/versions/ ). The plugin accepts them
    // in `SPPV_Rule_Packages::allowed_path()`; a plugin without that path refuses such a package as a whole.
    if ($arg === '--core' || $arg === '--versions' || $arg === '--trial-open-licences' || str_starts_with($arg, '--rulesets=') || str_starts_with($arg, '--without=')) {
        $passThrough[] = $arg;
    }

    if (str_starts_with($arg, '--edition=')) {
        $edition = substr($arg, 10);
    }
}

// ⚠️ The edition is shown to the operator and compared by nobody; it only has to say which package is
// installed. A free text would end up in the settings page unescaped by the first careless caller.
if (preg_match('/^[0-9A-Za-z][0-9A-Za-z.\-]{0,31}$/', $edition) !== 1) {
    fwrite(STDERR, "--edition darf nur Buchstaben, Ziffern, Punkt und Bindestrich enthalten ( höchstens 32 Zeichen ).\n");
    exit(1);
}

$work = $zipPath . '.work';
remove_tree($work);

// --- 1. the runtime export into the work folder -------------------------------------------------------

// ⚠️ PHP_BINARY, not "php": the PATH of this machine has no PHP CLI, and a CI runner may have a
// different one than the one running this script.
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/export-runtime.php') . ' ' . escapeshellarg($work)
    . ($passThrough === [] ? '' : ' ' . implode(' ', array_map('escapeshellarg', $passThrough)));

passthru($command, $exit);

if ($exit !== 0) {
    remove_tree($work);
    fwrite(STDERR, "\nRegelpaket nicht gebaut: der Export hat angehalten ( Rückgabewert $exit ).\n");
    exit(1);
}

// --- 2. engine out, manifest in ------------------------------------------------------------------------

remove_tree($work . '/src');

if (is_file($work . '/LICENSE')) {
    unlink($work . '/LICENSE');
}

$notice = (string) file_get_contents($work . '/NOTICE');

// ⚠️ The flag is taken from what the export actually wrote, not only from the switch: a trial switch
// without an open question writes an ordinary NOTICE, and that package is no trial.
$isTrial = $trial && str_contains($notice, 'TRIAL BUILD');

$files = [];
$rulesets = [];

$items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($work, \FilesystemIterator::SKIP_DOTS));

foreach ($items as $item) {
    if (!$item->isFile()) {
        continue;
    }

    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($work) + 1));
    $files[$relative] = hash_file('sha256', $item->getPathname());
}

ksort($files, SORT_STRING);

foreach (glob($work . '/rulesets/*.json') ?: [] as $file) {
    $name = basename($file);

    if ($name === 'scenarios.json' || $name === 'field-bindings.json') {
        continue;
    }

    $head = RuleProvenance::readHead($file);

    // ⚠️ readHead() leaves `license` empty; it only reads what the provenance lookup needs. The export has
    // just stamped the field right after `title`, so it is inside the first 16 KB.
    $licence = preg_match('/"license"\s*:\s*"([^"]+)"/', (string) file_get_contents($file, false, null, 0, 16384), $m) === 1
        ? $m[1]
        : 'NOASSERTION';

    $rulesets[] = [
        'id'      => $head->id,
        'version' => $head->version,
        'file'    => 'rulesets/' . $name,
        'license' => $licence,
        'sha256'  => $files['rulesets/' . $name],
    ];
}

$scenarios = json_decode((string) file_get_contents($work . '/rulesets/scenarios.json'), true);

$manifest = [
    'format'        => PACKAGE_FORMAT,
    'formatVersion' => PACKAGE_FORMAT_VERSION,
    'edition'       => $edition,
    'created'       => gmdate('Y-m-d\TH:i:s\Z'),
    'engine'        => [
        'name'       => Validator::ENGINE_NAME,
        // ⚠️ The edition of the engine that built the package is the oldest one known to read it. When the
        // rule model changes in a way an older engine cannot read, the library's VERSION goes up first.
        'minVersion' => Validator::VERSION,
    ],
    'trial'         => $isTrial,
    'scenarios'     => is_array($scenarios) ? count($scenarios) : 0,
    'rulesets'      => $rulesets,
    'files'         => $files,
];

$json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// --- 3. the ZIP ------------------------------------------------------------------------------------------

if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new \ZipArchive();

if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
    remove_tree($work);
    fwrite(STDERR, "ZIP nicht anlegbar: $zipPath\n");
    exit(1);
}

// ⚠️ addFromString(), NOT addFile(). addFile() opens the files only at close(), and on Windows the deep
// XSD paths plus a long work folder can pass MAX_PATH there, where ZipArchive fails without saying which
// file. Reading each file here keeps the error at the file that causes it.
$zip->addFromString(MANIFEST, $json . "\n");

foreach (array_keys($files) as $relative) {
    $zip->addFromString($relative, (string) file_get_contents($work . '/' . $relative));
}

$zip->close();
remove_tree($work);

printf(
    "\nRegelpaket %s geschrieben: %d Regelwerke, %d Szenarien, %d Dateien, %.1f MB%s\n",
    $zipPath,
    count($rulesets),
    $manifest['scenarios'],
    count($files) + 1,
    filesize($zipPath) / 1048576,
    $isTrial ? "\nPROBE, NICHT AUSLIEFERN: das Manifest trägt \"trial\": true." : ''
);

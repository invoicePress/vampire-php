<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Takes the test documents out of the phive-rules working copy and puts them into this project's
 * fixture tree.
 *
 *   php devkit/Cli/import-phive-testfiles.php            dry run: lists what would be copied
 *   php devkit/Cli/import-phive-testfiles.php --apply
 *
 * Source: a checkout of phive-rules, named by the environment variable PHIVE_RULES_DIR. No Java is executed - `CTestFiles.java` is read
 * AS TEXT, which is the whole point: the file list comes from the upstream project, not from a glob of
 * whatever happens to lie in the directory. A file that upstream stopped listing must not quietly stay
 * in our test bed.
 *
 * ⚠️ **Every phive test file is a GOOD case.** Measured 21.09.2026 across all 24 `CTestFiles.java` in
 * the working copy: `PhiveTestFile.createGoodCase` appears everywhere, `createBadCase` **nowhere** (0
 * hits). So there are no expected rule ids to import - the expectation this script can derive is
 * "accept", nothing finer. Negative cases keep coming from the mutation generator.
 *
 * ⚠️ **Only versions whose rule material matches ours.** phive ships the test files of every version
 * it ever supported; a document that was valid under EN16931 1.3.5 may legitimately fail under 1.3.16.
 * Importing those would manufacture failures that say nothing about this library. The table below
 * therefore pins one version per module, and for Peppol that version was established by COMPARING
 * SHA-256 OF THE COMPILED XSLT, not by reading a version number:
 *
 *   phive openpeppol/2026.5/xslt/CEN-EN16931-UBL.xslt      a8a54aef3699057a…
 *   our   vendor-sources/peppol-config/…/3.0.21/CEN-EN16931-UBL.xslt   a8a54aef3699057a…   identical
 *   phive openpeppol/2026.5/xslt/PEPPOL-EN16931-UBL.xslt   8c6b935b1bc0bb17…
 *   our   vendor-sources/peppol-config/…/3.0.21/PEPPOL-EN16931-UBL.xslt 8c6b935b1bc0bb17…  identical
 *
 * So "Peppol 3.0.21" (KoSIT's name) and "openpeppol 2026.5" (phive's name) are the same artefact.
 *
 * ⚠️ **XRechnung 3.0.2 is deliberately NOT imported.** phive carries 72 instances of it; we already
 * carry the complete official KoSIT suite with 86, the same documents from the same publisher. Adding
 * phive's subset would duplicate fixtures under a second name.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// The phive-rules checkout; set PHIVE_RULES_DIR to its folder.
define('PHIVE_ROOT', rtrim((string) (getenv('PHIVE_RULES_DIR') ?: dirname(__DIR__, 2) . '/vendor-sources/phive-rules-master'), '/\\'));

/**
 * What to import. `java` is the module's `CTestFiles.java`, `prefix` the classpath prefix as it is
 * written in that file, `group` the fixture group this lands in.
 *
 * `only` (Peppol) keeps just the entries of one sub-directory: the module's file lists mix Billing BIS
 * with Order, Catalogue, Despatch Advice and Self-Billing, and we hold rule material for Billing only.
 * Self-Billing in particular runs `PEPPOL-EN16931-UBL-SB.xslt`, a rule set this library does not have.
 *
 * @var array<string, array{module: string, prefix: string, only?: string, group: string, count?: int, file?: string, enum?: string}>
 */
const SETS = [
    'en16931-cii' => [
        'module' => 'en16931',
        'prefix' => '1.3.16/cii/',
        'group' => 'phive-en16931-cii',
    ],
    'en16931-ubl' => [
        'module' => 'en16931',
        'prefix' => '1.3.16/ubl/',
        'group' => 'phive-en16931-ubl',
    ],
    'peppol-billing' => [
        'module' => 'peppol',
        'prefix' => 'openpeppol/2026.5/',
        'only' => 'billing/',
        'group' => 'phive-peppol-billing',
    ],
    // ZUGFeRD lists its files by COUNT, not by name: `_createListFacturX("2.5.2", 25, EN16931)` means
    // factur-x-1.xml … factur-x-25.xml. The count is read out of the Java source all the same.
    'zugferd-en16931' => [
        'module' => 'zugferd',
        'prefix' => '2.5.2/en16931/',
        'file' => 'factur-x',
        'group' => 'phive-zugferd-en16931',
    ],
    'zugferd-extended' => [
        'module' => 'zugferd',
        'prefix' => '2.5.2/extended/',
        'file' => 'factur-x',
        'group' => 'phive-zugferd-extended',
    ],
    // Task 8.0a: the three remaining profiles of the same version.
    'zugferd-minimum' => [
        'module' => 'zugferd',
        'prefix' => '2.5.2/minimum/',
        'file' => 'factur-x',
        'group' => 'phive-zugferd-minimum',
    ],
    'zugferd-basicwl' => [
        'module' => 'zugferd',
        'prefix' => '2.5.2/basicwl/',
        'file' => 'factur-x',
        'group' => 'phive-zugferd-basicwl',
        // ⚠️ The directory is `basicwl`, the Java enum constant is `BASIC_WL`. Deriving one from the
        // other found nothing and said so - which is why the script refuses an empty result instead
        // of importing zero files quietly.
        'enum' => 'BASIC_WL',
    ],
    'zugferd-basic' => [
        'module' => 'zugferd',
        'prefix' => '2.5.2/basic/',
        'file' => 'factur-x',
        'group' => 'phive-zugferd-basic',
    ],
];

/**
 * Task 8.2 — the EU-CIUS modules. Their `CTestFiles.java` files are built differently again (one
 * `_getAll()` per VESID with paths that carry no shared prefix), so the file list is not parsed out of
 * the Java here. Instead the module's whole test-file directory is taken, filtered to the documents
 * the scenario can actually resolve.
 *
 * ⚠️ That filter is not cosmetic: these modules ship Order, DespatchAdvice and ApplicationResponse
 * documents next to the invoices, and none of them is an invoice. Importing them would produce fixtures
 * that no scenario matches, and the expectation generator would report them as failures of this
 * library.
 *
 * @var array<string, array{module: string, version?: string, group: string, customization: string}>
 */
const CIUS_SETS = [
    'cius-ro' => [
        'module' => 'cius-ro',
        'group' => 'phive-cius-ro',
        'customization' => 'urn:efactura.mfinante.ro:CIUS-RO:',
    ],
    'cius-pt' => [
        'module' => 'cius-pt',
        'group' => 'phive-cius-pt',
        'customization' => 'urn:feap.gov.pt:CIUS-PT:',
    ],
    'ublbe' => [
        'module' => 'ublbe',
        'group' => 'phive-ublbe',
        'customization' => 'urn:UBL.BE:',
    ],
    'serbia' => [
        'module' => 'serbia',
        'group' => 'phive-cius-rs',
        'customization' => 'urn:mfin.gov.rs:srbdt',
    ],
    'peppol-italy' => [
        'module' => 'peppol-italy',
        'group' => 'phive-agid-it',
        'customization' => 'urn:www.agid.gov.it:trns:fattura',
    ],
];

function phive_java_file(string $module): string
{
    $matches = glob(PHIVE_ROOT . "/phive-rules-$module/src/test/java/com/helger/phive/*/mock/CTestFiles.java") ?: [];

    if ($matches === []) {
        fwrite(STDERR, "CTestFiles.java für Modul $module nicht gefunden.\n");
        exit(1);
    }

    return $matches[0];
}

/**
 * Reads the file names belonging to one classpath prefix out of the Java source.
 *
 * ⚠️ Two different shapes have to be handled, and guessing one of them would silently import nothing:
 *   a) a literal list -  `sPrefix0 + "1.3.16/cii/"` … `new String [] { "a.xml", "b.xml" }`
 *      or `_getAll ("openpeppol/2026.5/", "billing/base-example.xml", …)`
 *   b) a counted list -  `_createListFacturX ("2.5.2", 25, EZugferdProfile.EN16931)`
 *
 * @return list<string>
 */
function phive_files_for(string $java, string $prefix, ?string $only, ?string $filePrefix, ?string $enum = null): array
{
    $text = (string) file_get_contents($java);
    $names = [];

    // Shape (b): counted list. The prefix here is "<version>/<profile>/".
    if ($filePrefix !== null) {
        [$version, $profile] = explode('/', trim($prefix, '/'));
        $constant = $enum ?? strtoupper(str_replace('-', '_', $profile));
        $pattern = '/_createListFacturX\s*\(\s*sVersion\s*,\s*(\d+)\s*,\s*EZugferdProfile\.' . preg_quote($constant, '/') . '\s*\)/';

        // The version is bound a few lines above as `final String sVersion = "2.5.2";` - so the block
        // for that version has to be cut out first, or the regex would match another version's count.
        $blockStart = strpos($text, 'final String sVersion = "' . $version . '";');

        if ($blockStart === false) {
            return [];
        }

        $blockEnd = strpos($text, 'final String sVersion = "', $blockStart + 10);
        $block = substr($text, $blockStart, $blockEnd === false ? strlen($text) : $blockEnd - $blockStart);

        if (preg_match($pattern, $block, $m) !== 1) {
            return [];
        }

        for ($i = 1; $i <= (int) $m[1]; $i++) {
            $names[] = "$filePrefix-$i.xml";
        }

        return $names;
    }

    // Shape (a). Cut out every block that mentions this prefix, then take the quoted *.xml names.
    $offset = 0;

    while (($pos = strpos($text, '"' . $prefix . '"', $offset)) !== false) {
        $offset = $pos + 1;

        $chunk = substr($text, $pos, 8000);

        // ⚠️ The chunk must end at the first ";" AFTER the first file name, not at the first ";" at
        // all. In the XRechnung module the prefix sits in its own statement
        // (`final String sPrefix = sPrefix0 + "3.0.2/cii/";`) and cutting at that semicolon threw the
        // whole list away - the first version of this script imported nothing and said so, which is
        // the only reason it was noticed.
        if (preg_match('/"[^"]+\.(?:xml|XML)"/', $chunk, $first, PREG_OFFSET_CAPTURE) !== 1) {
            continue;
        }

        $end = strpos($chunk, ';', (int) $first[0][1]);
        $chunk = $end === false ? $chunk : substr($chunk, 0, $end);

        if (preg_match_all('/"([^"]+\.(?:xml|XML))"/', $chunk, $m) < 1) {
            continue;
        }

        foreach ($m[1] as $name) {
            if ($only !== null && !str_starts_with($name, $only)) {
                continue;
            }

            $names[$name] = true;
        }
    }

    $names = array_keys($names);
    sort($names);

    return $names;
}

$base = dirname(__DIR__, 2);
// ⚠️ `$argv` exists only with `register_argc_argv` switched on - the CLI default, but not every
// configuration's. Read from `$_SERVER` it is the same list, with a type static analysis can
// follow.
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));

$apply = in_array('--apply', $argv, true);

$copied = 0;
$missing = [];
$report = [];

foreach (SETS as $setId => $set) {
    $java = phive_java_file($set['module']);
    $names = phive_files_for($java, $set['prefix'], $set['only'] ?? null, $set['file'] ?? null, $set['enum'] ?? null);

    if ($names === []) {
        fwrite(STDERR, "Keine Dateien für $setId gefunden - Java-Quelle geändert?\n");
        exit(1);
    }

    $sourceDir = PHIVE_ROOT . "/phive-rules-{$set['module']}/src/test/resources/external/test-files/" . $set['prefix'];
    $targetDir = $base . "/tests/fixtures/invoices/{$set['group']}";

    if ($apply && !is_dir($targetDir) && !mkdir($targetDir, 0777, true)) {
        fwrite(STDERR, "cannot create $targetDir\n");
        exit(1);
    }

    $setCount = 0;

    foreach ($names as $name) {
        $source = $sourceDir . $name;

        if (!is_file($source)) {
            $missing[] = "$setId: $name";
            continue;
        }

        // A name may carry a sub-directory ("billing/base-example.xml") - the fixture tree stays flat,
        // because the group already says where the document came from.
        $target = $targetDir . '/' . basename($name);

        if ($apply) {
            copy($source, $target);
        }

        $copied++;
        $setCount++;
    }

    $report[$setId] = ['group' => $set['group'], 'files' => $setCount, 'source' => $set['prefix']];
    printf("%-18s %3d Dateien -> tests/fixtures/invoices/%s\n", $setId, $setCount, $set['group']);
}

// --- Task 8.2: the EU-CIUS modules, filtered by the document's own customization id ---------------

foreach (CIUS_SETS as $setId => $set) {
    $sourceRoot = PHIVE_ROOT . "/phive-rules-{$set['module']}/src/test/resources/external/test-files";

    if (!is_dir($sourceRoot)) {
        fwrite(STDERR, "Kein Testverzeichnis für $setId: $sourceRoot\n");
        exit(1);
    }

    $targetDir = $base . "/tests/fixtures/invoices/{$set['group']}";

    if ($apply && !is_dir($targetDir) && !mkdir($targetDir, 0777, true)) {
        fwrite(STDERR, "cannot create $targetDir\n");
        exit(1);
    }

    $setCount = 0;
    $seen = [];

    /** @var \SplFileInfo $file */
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($sourceRoot)) as $file) {
        if (strtolower($file->getExtension()) !== 'xml') {
            continue;
        }

        $xml = (string) file_get_contents($file->getPathname());

        // Only invoices of this very CIUS - measured on the document, not guessed from its path.
        if (!str_contains($xml, $set['customization'])) {
            continue;
        }

        if (preg_match('~<(\w+:)?Invoice[\s>]~', $xml) !== 1) {
            continue;
        }

        $name = basename($file->getPathname());

        // ⚠️ The same file name occurs in several version directories of one module. Without this the
        // later copy would overwrite the earlier one and the count would silently be too low.
        if (isset($seen[$name])) {
            continue;
        }

        $seen[$name] = true;

        if ($apply) {
            copy($file->getPathname(), $targetDir . '/' . $name);
        }

        $copied++;
        $setCount++;
    }

    if ($setCount === 0) {
        fwrite(STDERR, "Keine passenden Belege für $setId - Kennung \"{$set['customization']}\" nicht gefunden.\n");
        exit(1);
    }

    printf("%-18s %3d Dateien -> tests/fixtures/invoices/%s\n", $setId, $setCount, $set['group']);
}

printf("\n%s: %d Dateien\n", $apply ? 'kopiert' : 'Trockenlauf', $copied);

if ($missing !== []) {
    // Named, not skipped (rule A.6): a file the Java source lists but the resource tree does not have
    // means the working copy is incomplete, and silently importing the rest would hide that.
    fwrite(STDERR, "\nIn der Java-Quelle gelistet, aber nicht vorhanden:\n  " . implode("\n  ", $missing) . "\n");
    exit(1);
}

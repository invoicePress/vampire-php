<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * How the validator behaves on large invoices.
 *
 *   php devkit/Cli/benchmark.php              measures and prints
 *   php devkit/Cli/benchmark.php --apply      also writes docs/developer/performance.md
 *
 * Measured is the whole pipeline (wellformed, XSD, scenario, Schematron) on invoices with 1, 10, 50,
 * 200, 500 and 1000 line items, each run three times; the MEDIAN is reported.
 *
 * ⚠️ **The generated invoice stays valid.** A document that fails XSD stops the pipeline before the
 * Schematron stage (`XsdMode::Stop`), and the measurement would then time the XSD stage alone while
 * claiming to time a validation. The generator therefore brings the document to N lines (adding
 * copies with fresh ids, or removing spare ones) and then RE-SUMS every monetary total from what is
 * actually there; the script checks `isAccepted()` and refuses to report a number for a rejected
 * document.
 *
 * ⚠️ The numbers belong to ONE machine and say nothing in absolute terms. What they are for is the
 * SHAPE of the curve: whether ten times the lines cost ten times the time or a hundred times.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Validator;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

const UBL_NS = [
    'ubl' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
    'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
    'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
];

const SIZES = [1, 10, 50, 200, 500, 1000];
const RUNS = 3;

/**
 * The first element an expression selects, or null. `DOMXPath::query()` returns `false` on a bad
 * expression and its items are typed `DOMNode|DOMNameSpaceNode`, so writing this out at each call
 * site costs two type errors every time.
 */
function first_element(\DOMXPath $xpath, string $expression, ?\DOMNode $context = null): ?\DOMElement
{
    $nodes = $context === null ? $xpath->query($expression) : $xpath->query($expression, $context);

    if ($nodes === false || $nodes->length === 0) {
        return null;
    }

    $node = $nodes->item(0);

    return $node instanceof \DOMElement ? $node : null;
}

/**
 * Builds an invoice with `$lines` line items out of the original.
 */
function grow_invoice(string $sourceFile, int $lines): string
{
    $dom = new \DOMDocument();
    $dom->preserveWhiteSpace = true;
    $dom->load($sourceFile);

    $xpath = new \DOMXPath($dom);

    foreach (UBL_NS as $prefix => $uri) {
        $xpath->registerNamespace($prefix, $uri);
    }

    $template = first_element($xpath, '/ubl:Invoice/cac:InvoiceLine');

    if (!$template instanceof \DOMElement) {
        fwrite(STDERR, "Keine cac:InvoiceLine in $sourceFile\n");
        exit(1);
    }

    $parent = $template->parentNode;

    if ($parent === null) {
        fwrite(STDERR, "InvoiceLine ohne Elternknoten\n");
        exit(1);
    }

    // ⚠️ The original carries TWO lines, not one. A first version assumed one, took its amount times
    // N as the new total and left the second line in place - BR-CO-10 fired on every single size,
    // and the "measurement" would have been of the error path. Grows AND shrinks, and the totals are
    // summed afterwards from what is really there.
    $existing = $xpath->query('/ubl:Invoice/cac:InvoiceLine');

    if ($existing === false) {
        fwrite(STDERR, "InvoiceLine-Abfrage fehlgeschlagen\n");
        exit(1);
    }

    for ($i = $existing->length; $i > $lines; $i--) {
        $node = $existing->item($i - 1);

        if ($node instanceof \DOMElement) {
            $node->parentNode?->removeChild($node);
        }
    }

    for ($i = $existing->length + 1; $i <= $lines; $i++) {
        $copy = $template->cloneNode(true);

        if (!$copy instanceof \DOMElement) {
            continue;
        }

        // Every line needs its own BT-126.
        $idNode = first_element($xpath, 'cbc:ID', $copy);

        if ($idNode !== null) {
            $idNode->textContent = (string) $i;
        }

        $parent->insertBefore($copy, $template->nextSibling);
    }

    scale_totals($xpath);

    return (string) $dom->saveXML();
}

/**
 * A number from the document, or "0" when it is not one. BCMath refuses anything else, and a document
 * with a malformed amount should fail the validator's own rules, not this script.
 *
 * @return numeric-string
 */
function numeric(string $value): string
{
    return is_numeric($value) ? $value : '0';
}

/**
 * Sums the line amounts that are actually in the document and writes every total that has to add up,
 * so the arithmetic rules (BR-CO-10 ff.) stay satisfied.
 */
function scale_totals(\DOMXPath $xpath): void
{
    $net = '0';

    foreach ($xpath->query('/ubl:Invoice/cac:InvoiceLine/cbc:LineExtensionAmount') ?: [] as $node) {
        if ($node instanceof \DOMElement) {
            $net = bcadd($net, numeric(trim($node->textContent)), 2);
        }
    }

    $percentNode = first_element($xpath, '/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent');
    $taxPercent = $percentNode === null ? '0' : numeric(trim($percentNode->textContent));

    $tax = bcdiv(bcmul($net, $taxPercent, 4), '100', 2);
    $gross = bcadd($net, $tax, 2);

    $assignments = [
        '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount' => $net,
        '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount' => $net,
        '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount' => $gross,
        '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount' => $gross,
        '/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount' => $tax,
        '/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount' => $net,
        '/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount' => $tax,
    ];

    foreach ($assignments as $path => $value) {
        foreach ($xpath->query($path) ?: [] as $node) {
            if ($node instanceof \DOMElement) {
                $node->textContent = $value;
            }
        }
    }
}

$base = dirname(__DIR__, 2);
// ⚠️ `$argv` exists only with `register_argc_argv` switched on - the CLI default, but not every
// configuration's. Read from `$_SERVER` it is the same list, with a type static analysis can
// follow.
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));

$apply = in_array('--apply', $argv, true);
$source = $base . '/tests/fixtures/invoices/xrechnung-standard/01.01a-INVOICE_ubl.xml';

$resolver = ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json');
$validator = new Validator(scenarioResolver: $resolver, baseDir: $base);

$rows = [];

foreach (SIZES as $lines) {
    $xml = grow_invoice($source, $lines);
    $timings = [];
    $peak = 0;
    $accepted = null;

    for ($run = 0; $run < RUNS; $run++) {
        $before = memory_get_peak_usage(true);
        $start = hrtime(true);
        $result = $validator->validateString($xml);
        $timings[] = (hrtime(true) - $start) / 1e6;
        $peak = max($peak, memory_get_peak_usage(true) - $before);
        $accepted ??= $result->isAccepted();

        // Named, not swallowed: a rejected document means the generator broke the invoice, and the
        // numbers would describe the error path.
        if ($accepted === false && $run === 0) {
            $blocking = [];

            foreach ($result->messages() as $message) {
                if ($message->flag->value === 'fatal') {
                    $blocking[$message->ruleId ?? substr($message->text, 0, 60)] = true;
                }
            }

            fwrite(STDERR, sprintf("  %d Positionen -> %s\n", $lines, implode(', ', array_keys($blocking))));
        }
    }

    sort($timings);
    $median = $timings[intdiv(count($timings), 2)];

    $rows[] = [
        'lines' => $lines,
        'bytes' => strlen($xml),
        'ms' => $median,
        'min' => $timings[0],
        'max' => $timings[count($timings) - 1],
        'perLine' => $median / $lines,
        'accepted' => $accepted,
    ];

    printf(
        "%5d Positionen  %8.1f KB  %9.1f ms  %6.2f ms/Position  %s\n",
        $lines,
        strlen($xml) / 1024,
        $median,
        $median / $lines,
        $accepted ? 'angenommen' : 'ABGELEHNT'
    );
}

$rejected = array_filter($rows, static fn (array $r): bool => $r['accepted'] !== true);

if ($rejected !== []) {
    fwrite(
        STDERR,
        "\nMindestens ein erzeugter Beleg wurde abgelehnt. Die Zahlen messen dann den Fehlerpfad,\n"
        . "nicht eine vollständige Prüfung - nichts geschrieben.\n"
    );
    exit(1);
}

printf("\nSpitzenspeicher beim größten Beleg: %.1f MB\n", memory_get_peak_usage(true) / 1048576);

if (!$apply) {
    echo "\nTrockenlauf - docs/developer/performance.md nicht geschrieben.\n";
    exit(0);
}

$first = $rows[0];
$last = $rows[count($rows) - 1];

$md = "# Laufzeit bei großen Rechnungen (Aufgabe 8.6)\n\n"
    . 'Gemessen am ' . date('d.m.Y') . ' mit `devkit/Cli/benchmark.php`, PHP ' . PHP_VERSION
    . ", Median aus " . RUNS . " Läufen je Größe.\n\n"
    . "Grundlage ist die offizielle XRechnung-Testrechnung `01.01a-INVOICE_ubl.xml`. Ihre "
    . "Rechnungspositionen werden auf die gewünschte Zahl gebracht (vervielfacht oder entfernt) und "
    . "alle Summen aus dem gezählt, was danach wirklich dasteht, damit der Beleg **gültig bleibt**.\n\n"
    . "| Positionen | Größe | Zeit (Median) | schnellster/langsamster Lauf | je Position | Urteil |\n"
    . "|---:|---:|---:|---:|---:|---|\n";

foreach ($rows as $row) {
    $md .= sprintf(
        "| %d | %.1f KB | %.1f ms | %.1f / %.1f ms | %.2f ms | %s |\n",
        $row['lines'],
        $row['bytes'] / 1024,
        $row['ms'],
        $row['min'],
        $row['max'],
        $row['perLine'],
        $row['accepted'] ? 'angenommen' : 'abgelehnt'
    );
}

$cheapest = $rows[0];

foreach ($rows as $row) {
    if ($row['perLine'] < $cheapest['perLine']) {
        $cheapest = $row;
    }
}

// ⚠️ The sentence is built from the numbers, not from an expectation about them. A fixed wording
// ("first cheaper, then dearer") contradicted itself in a noisy run: the minimum lay on the last
// line there, and the text still claimed a rise after it.
$md .= sprintf(
    "\n**Form der Kurve.** Bei einer Position kostet die Prüfung %.0f ms, und davon ist fast alles "
    . "Fixaufwand — Regelpakete laden, Szenario auflösen, XSD. Die Zeit je Position wird deshalb "
    . "zunächst **billiger**; am günstigsten ist sie bei %d Positionen mit %.2f ms. %s"
    . "Insgesamt kosten %d-mal so viele Positionen das %.0f-fache an Zeit — %s.\n\n",
    $first['ms'],
    $cheapest['lines'],
    $cheapest['perLine'],
    $cheapest['lines'] === $last['lines']
        ? 'In dieser Messung bleibt es dabei, sie steigt bis zur größten Größe nicht wieder an. '
        : sprintf('Danach steigt sie wieder, auf %.2f ms bei %d Positionen. ', $last['perLine'], $last['lines']),
    (int) ($last['lines'] / $first['lines']),
    $last['ms'] / $first['ms'],
    $last['ms'] / $first['ms'] > $last['lines'] / $first['lines'] ? 'mehr als linear' : 'weniger als linear'
);

$md .= sprintf(
    "**Spitzenspeicher** beim größten Beleg (%d Positionen, %.1f KB): %.1f MB.\n\n",
    $last['lines'],
    $last['bytes'] / 1024,
    memory_get_peak_usage(true) / 1048576
);

$spread = 0.0;

foreach ($rows as $row) {
    $spread = max($spread, $row['min'] > 0 ? $row['max'] / $row['min'] : 1.0);
}

$md .= sprintf(
    "⚠️ **Was das praktisch heißt.** Zehn Positionen sind in %.1f Sekunden geprüft, fünfzig in %.1f, "
    . "zweihundert in %.0f. Ein Beleg mit tausend Positionen braucht rund %.0f Sekunden und %.0f MB "
    . "Speicher — mehr, als ein `memory_limit` von 128 MB hergibt. Wer solche Belege im Web-Request "
    . "prüfen will, braucht einen Hintergrundlauf, kein schnelleres Hosting.\n\n",
    $rows[1]['ms'] / 1000,
    $rows[2]['ms'] / 1000,
    $rows[3]['ms'] / 1000,
    $last['ms'] / 1000,
    memory_get_peak_usage(true) / 1048576
);

$md .= sprintf(
    "⚠️ **Absolut sagen diese Zahlen nichts** — sie stammen von einem Rechner, einer PHP-Version, "
    . "%d Läufen je Größe. Die Spalte „schnellster/langsamster Lauf\" zeigt, wie weit dieselbe "
    . "Messung auseinandergeht, wenn der Rechner nebenbei etwas anderes tut: hier bis zum "
    . "%.1f-fachen. Wofür die Zahlen da sind, ist die **Form** der Kurve. Wer vergleichen will, misst "
    . "neu — auf einem ruhigen Rechner.\n\n",
    RUNS,
    $spread
);

$md .= "⚠️ **Der erzeugte Beleg muss gültig bleiben.** Ein Beleg, der schon am XSD scheitert, beendet "
    . "die Kette vor der Schematron-Stufe — gemessen würde dann der Fehlerpfad, und die Zahl sähe "
    . "hervorragend aus. Das Skript prüft deshalb `isAccepted()` und schreibt bei einer Ablehnung "
    . "nichts.\n";

file_put_contents($base . '/docs/developer/performance.md', $md);

echo "\ndocs/developer/performance.md geschrieben\n";

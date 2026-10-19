<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Licence;

use EinvoiceValidator\Ruleset\Ruleset;

/**
 * Origin and licence of the rule sets, read from `vampire-php/rule-provenance.json`.
 *
 * Used by `import-ruleset.php` ( stamps `license` and `notice` into each rule set it writes ),
 * `export-runtime.php` ( the same for the export, plus NOTICE and the licence texts ) and
 * `write-notice.php` ( this library's own NOTICE ).
 *
 * ⚠️ **ONE TABLE FOR BOTH ENGINES.** Two sources drift: a hand-written NOTICE here named the EN 16931 and the
 * Peppol rules as Apache-2.0, while the JS add-on wrote its NOTICE from the table and said EUPL-1.2 and
 * "no licence known". The table lives with the JS library because that build read it first; it is not a JS
 * file, and nothing here copies it.
 *
 * ⚠️ **THE TABLE IS FOUND RELATIVE TO THE PLUGINS FOLDER.** This devkit runs only in the work tree; the
 * runtime package ( `export-runtime.php` ) carries no devkit and needs no table, because the export writes
 * everything the runtime needs into the rule sets and the NOTICE.
 *
 * ⚠️ **THE TEXT OF NOTICE FOLLOWS `noticeText()` IN `build-vampire-addon.mjs`.** Not character for
 * character - the JS NOTICE speaks of `rules/<ref>.json.js`, this one of `rulesets/<ref>.json` - but entry
 * for entry the same fields in the same order, so that one reader can compare the two. A change to one
 * belongs into the other in the same step.
 */
final class RuleProvenance
{
    /** @var list<array<string, mixed>> */
    private array $sources;

    public function __construct(public readonly string $table)
    {
        $data = is_readable($table) ? json_decode((string) file_get_contents($table), true) : null;

        if (!is_array($data) || !isset($data['sources']) || !is_array($data['sources'])) {
            throw new \RuntimeException('rule-provenance.json fehlt oder ist unlesbar: ' . $table);
        }

        /** @var list<array<string, mixed>> $sources */
        $sources = array_values(array_filter($data['sources'], 'is_array'));
        $this->sources = $sources;
    }

    public static function defaultTable(): string
    {
        return dirname(__DIR__, 2) . '/rule-provenance.json';
    }

    /** The folder of the licence texts, beside the table. */
    public function licenceDir(): string
    {
        return dirname($this->table) . '/licences';
    }

    /**
     * The entry of a rule set, by its source file: the first `match` that occurs in it.
     *
     * ⚠️ Matched on the source file, not on the id, for the reason `build-vampire-addon.mjs` gives: the CEN
     * copy inside Peppol has its own entry.
     *
     * @return array<string, mixed>|null
     */
    public function entryFor(string $sourceFile): ?array
    {
        $file = str_replace('\\', '/', $sourceFile);

        foreach ($this->sources as $source) {
            if (isset($source['match']) && str_contains($file, (string) $source['match'])) {
                return $source;
            }
        }

        return null;
    }

    /**
     * The entry or an exception naming the rule set - a rule set without an entry must never ship silently.
     *
     * @return array<string, mixed>
     */
    public function requireEntry(Ruleset $ruleset): array
    {
        $entry = $this->entryFor($ruleset->sourceFile);

        if ($entry === null) {
            throw new \RuntimeException(sprintf(
                'Keine Herkunft in %s für %s@%s ( Quelle %s ). Erst Lizenz prüfen und eintragen.',
                $this->table,
                $ruleset->id,
                $ruleset->version,
                $ruleset->sourceFile
            ));
        }

        return $entry;
    }

    /**
     * Only the head of a rule set file - id, version, title and source - as a `Ruleset` without rules.
     *
     * ⚠️ FOR NOTICE AND CHECKS ONLY, NEVER FOR VALIDATING. The files reach 24 MB, and the NOTICE of 21 rule
     * sets would otherwise decode some 60 MB of syntax trees to read five strings each. The exporter writes
     * these keys first ( `Ruleset::toArray()` ), within the first 1000 bytes; 16 KB leaves room for the
     * visible notice in two languages. A head without them is an error, not an empty field.
     */
    public static function readHead(string $path): Ruleset
    {
        $head = (string) file_get_contents($path, false, null, 0, 16384);

        $string = static function (string $key) use ($head, $path): string {
            if (!preg_match('/"' . $key . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $head, $m)) {
                throw new \RuntimeException("Kein \"$key\" im Kopf von $path");
            }

            $value = json_decode('"' . $m[1] . '"');

            return is_string($value) ? $value : $m[1];
        };

        return new Ruleset($string('id'), $string('version'), $string('title'), $string('file'), $string('sha256'), [], [], []);
    }

    /** The rule set with `license` and `notice` from its entry; unchanged when there is no entry. */
    public function stamp(Ruleset $ruleset): Ruleset
    {
        $entry = $this->entryFor($ruleset->sourceFile);

        if ($entry === null) {
            return $ruleset;
        }

        /** @var array<string, string>|null $notice */
        $notice = isset($entry['visibleNotice']) && is_array($entry['visibleNotice']) ? $entry['visibleNotice'] : null;

        return $ruleset->withLicence((string) ($entry['packageLicence'] ?? 'NOASSERTION'), $notice);
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    public static function textsOf(array $entry): array
    {
        return array_values(array_map('strval', (array) ($entry['licenceTexts'] ?? [])));
    }

    /**
     * The NOTICE for a set of rule sets.
     *
     * @param list<Ruleset> $rulesets
     * @param list<string> $dropped groups left out on purpose ( `--without` )
     * @param list<string> $trial reasons why this is a measurement build; empty for a real one
     * @param string $tail the part about other third-party material ( code lists, XSD, scenarios, tests )
     */
    public function noticeText(string $heading, array $rulesets, array $dropped, array $trial, string $tail): string
    {
        $L = [];
        $rule = str_repeat('-', 78);

        $L[] = $heading . ' - NOTICE';
        $L[] = '';

        if ($trial !== []) {
            $L[] = '*** TRIAL BUILD FOR MEASUREMENT ONLY - NOT FOR DISTRIBUTION. ***';

            foreach ($trial as $reason) {
                $L[] = '*** ' . $reason;
            }

            $L[] = '';
        }

        array_push(
            $L,
            'The engine ( src/ ) is free software, copyright 2026 Ralf Fuhrmann, specialPress,',
            'licensed under the GNU General Public License, version 2 or later',
            '( GPL-2.0-or-later, see LICENSE ). That licence covers the engine only.',
            '',
            'The rule packages in rulesets/ are NOT original work of specialPress. Each one',
            'is a rule model derived by machine from a published Schematron rule set: rule',
            'identifiers, contexts, test expressions and message texts are taken from the',
            'upstream files, and the message texts are reproduced verbatim. Each rule',
            'package names in its head ( "license" ) the SPDX identifier of the licence',
            'under which it is passed on, and the list below says the same per package,',
            'with origin, publisher and copyright. NOASSERTION means that the rights',
            'holder states no licence; the entry then says on what terms the package is',
            'included. The full text of every licence named below is in licences/.',
            '',
            'Modifications: the upstream rules were compiled into a JSON rule model. The',
            'business terms that a message names ( BT-n, BG-n ) were extracted into a',
            'list per rule, and catalogue keys for the merging of equal rules were added.',
            'The rule logic and the message texts were not changed.',
        );

        if ($dropped !== []) {
            array_push(
                $L,
                '',
                'Left out of this package on purpose: the rule sets of the group(s) ' . implode(', ', $dropped) . '.',
                'An invoice that needs them gets no verdict; the report says that the rule set',
                'is not included.',
            );
        }

        array_push($L, '', $rule, 'Rule sets in this package', $rule);

        $field = static function (string $label, string $value) use (&$L): void {
            $lead = '   ' . str_pad($label . ':', 12);
            $line = $lead;

            foreach (explode(' ', trim((string) preg_replace('/\s+/', ' ', $value))) as $word) {
                if (trim($line) !== $label . ':' && strlen($line) + 1 + strlen($word) > 78) {
                    $L[] = $line;
                    $line = str_repeat(' ', strlen($lead));
                }

                $line .= (str_ends_with($line, ' ') ? '' : ' ') . $word;
            }

            $L[] = $line;
        };

        foreach ($rulesets as $i => $ruleset) {
            $entry = $this->requireEntry($ruleset);

            $L[] = '';
            // The edition packages of the cascade lie one folder deeper ( export-runtime.php --versions ).
            $sub = str_contains(str_replace('\\', '/', $ruleset->sourceFile), 'vendor-sources/zugferd-versions/') ? 'versions/' : '';
            $L[] = ($i + 1) . '. rulesets/' . $sub . $ruleset->id . '@' . $ruleset->version . '.json';
            $field('Title', $ruleset->title);
            $field('Version', $ruleset->version);
            $field('Upstream', (string) ($entry['upstream'] ?? ''));
            $field('Publisher', (string) ($entry['publisher'] ?? ''));
            $field('Source', (string) ($entry['url'] ?? ''));
            $field('File', basename(str_replace('\\', '/', $ruleset->sourceFile)));
            $field('SHA-256', $ruleset->sourceHash);
            $field('Package', 'SPDX-License-Identifier: ' . (string) ($entry['packageLicence'] ?? 'NOASSERTION'));
            $field('Licence', (string) ($entry['licenceStatement'] ?? $entry['licence'] ?? 'NOT KNOWN'));

            if (self::textsOf($entry) !== []) {
                $field('Texts', implode(', ', array_map(static fn (string $t): string => 'licences/' . $t, self::textsOf($entry))));
            }

            $field('Copyright', (string) ($entry['copyright'] ?? ''));

            if (!empty($entry['upstreamNotice'])) {
                $field('Notice', 'The upstream files state: ' . (string) $entry['upstreamNotice']);
            }

            foreach ((array) ($entry['obligations'] ?? []) as $obligation) {
                $field('Condition', (string) $obligation);
            }

            if (isset($entry['visibleNotice']['en'])) {
                $field('Shown as', (string) $entry['visibleNotice']['en']);
            }

            if (($entry['status'] ?? '') === 'pending') {
                $field('Pending', (string) ($entry['question'] ?? ''));
            }

            if (!in_array($entry['status'] ?? 'open', ['cleared', 'pending'], true)) {
                $field('OPEN', (string) ($entry['question'] ?? ''));
            }
        }

        if ($tail !== '') {
            array_push($L, '', $rule, 'Other third-party material', $rule, '', ...explode("\n", str_replace("\r\n", "\n", rtrim($tail))));
        }

        $text = implode("\r\n", array_map(static fn (string $z): string => rtrim($z), $L)) . "\r\n";

        // ⚠️ Plain ASCII, as the JS NOTICE: an older Notepad reads UTF-8 without BOM as ANSI.
        if (preg_match('/[^\x09\x0a\x0d\x20-\x7e]/', $text)) {
            throw new \RuntimeException('NOTICE enthält ein Zeichen ausserhalb von ASCII. Herkunftstabelle und Anhang müssen ASCII bleiben.');
        }

        return $text;
    }

    /**
     * Which licence texts the given rule sets need, and which of them are missing on this machine.
     *
     * @param list<Ruleset> $rulesets
     * @return array{needed: list<string>, missing: list<string>, open: list<string>}
     */
    public function check(array $rulesets): array
    {
        $needed = [];
        $open = [];

        foreach ($rulesets as $ruleset) {
            $entry = $this->requireEntry($ruleset);

            if (!in_array($entry['status'] ?? 'open', ['cleared', 'pending'], true)) {
                $open[] = $ruleset->id . '@' . $ruleset->version;
            }

            foreach (self::textsOf($entry) as $t) {
                $needed[$t] = true;
            }
        }

        $needed = array_keys($needed);
        $missing = array_values(array_filter($needed, fn (string $t): bool => !is_file($this->licenceDir() . '/' . $t)));

        return ['needed' => $needed, 'missing' => $missing, 'open' => $open];
    }
}

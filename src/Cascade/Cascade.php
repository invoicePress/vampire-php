<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Cascade;

use EinvoiceValidator\Exception\DocumentTooLargeException;
use EinvoiceValidator\Exception\NotWellformedException;
use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Pipeline\XmlLoader;
use EinvoiceValidator\Report\JsonReport;
use EinvoiceValidator\Validator;
use EinvoiceValidator\Xpath\Ast\AstSerializer;
use EinvoiceValidator\Xpath\Interpreter;

/**
 * The version cascade and the multi-edition check.
 *
 * The resolution of a document to ONE scenario ( `ScenarioResolver::resolve()` ) answers "which rule package";
 * it cannot answer "which EDITION", because a ZUGFeRD invoice does not say which edition it follows: seven
 * editions share the same profile id and the same namespace ( measured against the KoSIT validator ).
 * The cascade sits one level above `resolve()`: it picks the family ( XRechnung 3.0, ZUGFeRD / Factur-X ), then
 * the edition, and runs `resolve()` and the pipeline inside the chosen edition.
 *
 *   - `validity`     ( XRechnung ): the invoice date picks exactly one edition. Inside one customisation id the
 *                    periods do not overlap, see the table in rulesets/versions/cascade.json.
 *   - `newest-first` ( ZUGFeRD / Factur-X ): every carried edition from the newest down; the first that ACCEPTS is
 *                    the result; if none accepts, the report of the newest stands.
 *
 * With `$full = true` every carried edition runs ( the full matrix ); the chosen edition follows the same
 * rule, so switching the matrix on never changes the answer, only how much is shown.
 *
 * ⚠️ **"ACCEPTED" IS THE VERDICT, NOT "NO FINDINGS".** A warning keeps the invoice accepted ( KoSIT: `valid="false"`
 * with `<rep:accept>`, measured on X10_01 against 2.5.2 ). The cascade stops at an edition that accepts with
 * warnings - and the matrix shows the warning, because it is the one case in which a NEWER edition says more.
 *
 * ⚠️ **NEVER "AGAINST THE NEWEST, THE REST FOLLOWS".** In 2 of the 17 invoices of the matrix that differ per
 * edition, a newer edition is STRICTER ( X10_01 and X15_01 get a warning from 2.5 on ). Each step is its own run.
 *
 * ⚠️ **ONE VALIDATOR PER EDITION, NOT ONE VALIDATOR WITH ALL SCENARIOS.** All editions carry the same `match`; in
 * one scenario list the first edition would win every time ( and KoSIT, given two matching configurations in one
 * call, answers `noScenarioMatched` ). The editions therefore never meet in one resolver.
 *
 * ⚠️ **THE TWIN IS `src/Cascade.mjs` IN VAMPIRE JS** - same table, same order, same reason codes, same texts.
 * `toArray()` is compared with the JS result over the corpus ( devkit/Cli/cascade-findings.php and
 * the parity test of VAMPIRE JS ); a change on one side alone breaks that comparison.
 */
final class Cascade
{
    public const VERSION = 1;

    /** @var array<string, mixed> */
    private array $table;

    /** @var array<string, Validator> */
    private array $validators = [];

    /**
     * @param array<string, mixed> $table   the decoded rulesets/versions/cascade.json
     * @param string               $baseDir the package root ( rulesets/ lies below it )
     * @param array<string, mixed> $options `lang` ( 'de' | 'en' ), `ruleReport` ( bool ), `versionsDir`
     *                                      ( relative to `$baseDir`, default `rulesets/versions` )
     */
    public function __construct(array $table, private readonly string $baseDir, private readonly array $options = [])
    {
        if (($table['cascadeVersion'] ?? null) !== self::VERSION) {
            throw new \RuntimeException('Unbekannte Fassung der Kaskadentabelle: ' . var_export($table['cascadeVersion'] ?? null, true));
        }

        $this->table = $table;
    }

    /**
     * The package's own table, or null when this package carries no edition packages ( the default export ).
     *
     * @param array<string, mixed> $options see the constructor
     */
    public static function fromPackage(string $baseDir = __DIR__ . '/../..', array $options = []): ?self
    {
        $dir = $options['versionsDir'] ?? 'rulesets/versions';
        $file = $baseDir . '/' . $dir . '/cascade.json';

        if (!is_file($file)) {
            return null;
        }

        /** @var array<string, mixed> $table */
        $table = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

        return new self($table, $baseDir, $options);
    }

    /**
     * @return array<string, mixed> see `CascadeResult` in docs/developer/report-formats.md
     */
    public function validateFile(string $path, bool $full = false): array
    {
        $xml = is_file($path) ? file_get_contents($path) : false;

        if ($xml === false) {
            throw new \RuntimeException("cannot read $path");
        }

        return $this->validateString($xml, $full, basename($path));
    }

    /**
     * @return array<string, mixed>
     */
    public function validateString(string $xml, bool $full = false, ?string $name = null): array
    {
        $lang = ($this->options['lang'] ?? 'de') === 'en' ? 'en' : 'de';

        try {
            $document = (new XmlLoader())->loadString($xml);
        } catch (NotWellformedException | DocumentTooLargeException) {
            // ⚠️ The default validator writes the report of a broken file; there is nothing to cascade over.
            $report = $this->report($this->validator(null, null), $xml, null, $name);

            return CascadeResult::build(null, null, null, 'not-wellformed', [], $report, $full, $lang);
        }

        $family = $this->familyOf($document);

        if ($family === null) {
            $step = $this->runStep(null, null, $document, $xml, $name);

            return CascadeResult::build(null, ScenarioResolver::issueDate($document), null, 'no-family', [$step], $step['report'], $full, $lang);
        }

        $issueDate = ScenarioResolver::issueDate($document);

        /** @var list<array<string, mixed>> $versions */
        $versions = $family['versions'];
        $carried = array_values(array_filter($versions, static fn (array $v): bool => ($v['carried'] ?? false) === true));

        if ($carried === []) {
            $step = $this->runStep(null, null, $document, $xml, $name);

            return CascadeResult::build($family, $issueDate, null, 'no-carried', [$step], $step['report'], $full, $lang);
        }

        if ($family['order'] === 'validity') {
            [$choice, $code, $valid] = self::chooseByValidity($versions, $carried, $issueDate);

            $steps = [];
            $chosenReport = null;

            foreach ($carried as $edition) {
                $isChoice = $edition['version'] === $choice['version'];

                if (!$isChoice && !$full) {
                    $steps[] = CascadeResult::notRun($edition);
                    continue;
                }

                $step = $this->runStep($family, $edition, $document, $xml, $name);
                $steps[] = $step;

                if ($isChoice) {
                    $chosenReport = $step['report'];
                }
            }

            return CascadeResult::build($family, $issueDate, ['version' => $choice['version'], 'valid' => $valid], $code, $steps, $chosenReport, $full, $lang);
        }

        if ($family['order'] !== 'newest-first') {
            throw new \RuntimeException('Unbekannte Reihenfolge in der Kaskadentabelle: ' . (string) $family['order']);
        }

        // ⚠️ The table lists the editions newest first; the cascade relies on that order and does not sort by
        // version number ( 2.3.2 and 2.3.3 name their files differently from their neighbours, and a later
        // edition 3.0 may well come with a different numbering ).
        $steps = [];
        $chosen = null;

        foreach ($carried as $edition) {
            if ($chosen !== null && !$full) {
                $steps[] = CascadeResult::notRun($edition);
                continue;
            }

            $step = $this->runStep($family, $edition, $document, $xml, $name);
            $steps[] = $step;

            if ($chosen === null && $step['accepted'] === true) {
                $chosen = $step;
            }
        }

        $code = $chosen !== null ? 'first-accepting' : 'none-accepting';
        $chosen ??= $steps[0];

        return CascadeResult::build($family, $issueDate, ['version' => $chosen['version'], 'valid' => null], $code, $steps, $chosen['report'], $full, $lang);
    }

    /**
     * The edition the invoice date picks, and why.
     *
     * ⚠️ AN EDITION THAT IS NOT CARRIED IS NOT SKIPPED IN SILENCE. The date picks it; the run then takes the next
     * NEWER carried edition, because a bug-fix edition of the same customisation id replaces its predecessor
     * ( 3.0.2 corrects 3.0.1 ). The reason code `validity-not-carried` and the field `valid` say which edition the
     * date really picked, so the report never claims "checked with 3.0.1".
     *
     * @param list<array<string, mixed>> $versions all editions of the family, newest first
     * @param list<array<string, mixed>> $carried  the carried ones, newest first
     * @return array{0: array<string, mixed>, 1: string, 2: ?string}
     */
    public static function chooseByValidity(array $versions, array $carried, ?string $issueDate): array
    {
        if ($issueDate === null) {
            return [$carried[0], 'no-date', null];
        }

        foreach ($versions as $i => $v) {
            $from = $v['validFrom'] ?? null;
            $until = $v['validUntil'] ?? null;

            if (($from !== null && $issueDate < $from) || ($until !== null && $issueDate > $until)) {
                continue;
            }

            if (($v['carried'] ?? false) === true) {
                return [$v, 'validity', $v['version']];
            }

            // The next newer carried edition: walk back up the list ( newest first ).
            for ($j = $i - 1; $j >= 0; $j--) {
                if (($versions[$j]['carried'] ?? false) === true) {
                    return [$versions[$j], 'validity-not-carried', $v['version']];
                }
            }

            // No newer edition is carried; the oldest carried one is the closest there is.
            return [$carried[count($carried) - 1], 'validity-not-carried', $v['version']];
        }

        // Before the first period of the family ( or after the last, which an open end rules out ).
        return [$carried[count($carried) - 1], 'outside-validity', null];
    }

    /** @return array<string, mixed>|null */
    private function familyOf(\DOMDocument $document): ?array
    {
        /** @var list<array<string, mixed>> $families */
        $families = $this->table['families'] ?? [];

        foreach ($families as $family) {
            /** @var array<string, string> $namespaces */
            $namespaces = $family['namespaces'] ?? [];
            /** @var array<string, mixed> $match */
            $match = $family['match'];

            // ⚠️ A fresh interpreter per family, for the reason ScenarioResolver gives: the prefixes differ.
            if ((new Interpreter($namespaces))->evaluate(AstSerializer::fromArray($match), $document)->effectiveBooleanValue()) {
                return $family;
            }
        }

        return null;
    }

    /**
     * One run of the pipeline in one edition.
     *
     * @param array<string, mixed>|null $family
     * @param array<string, mixed>|null $edition null, or an edition without own scenarios: the default package
     * @return array<string, mixed>
     */
    private function runStep(?array $family, ?array $edition, \DOMDocument $document, string $xml, ?string $name): array
    {
        // ⚠️ Own scenarios need a family for the cache key; an edition without one ( the default package ) never has them.
        $own = $family !== null && $edition !== null && is_array($edition['scenarios'] ?? null);
        $validator = $this->validator($own ? (string) $family['id'] : null, $own ? $edition : null);
        $started = microtime(true);
        $report = $this->report($validator, $xml, $document, $name);
        $ms = (int) round((microtime(true) - $started) * 1000);

        // The report names the scenario, not its id ( JsonReport has no id ); the names are unique per list.
        $scenarioId = null;

        foreach ($own ? $edition['scenarios'] : $this->defaultScenarios() as $s) {
            if (($s['name'] ?? null) === $report['scenario']) {
                $scenarioId = $s['id'];
                break;
            }
        }

        return CascadeResult::step($edition, $scenarioId, $report, $ms);
    }

    /** @var list<array<string, mixed>>|null */
    private ?array $defaultScenarioList = null;

    /** @return list<array<string, mixed>> */
    private function defaultScenarios(): array
    {
        if ($this->defaultScenarioList === null) {
            /** @var list<array<string, mixed>> $list */
            $list = json_decode((string) file_get_contents($this->baseDir . '/rulesets/scenarios.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->defaultScenarioList = $list;
        }

        return $this->defaultScenarioList;
    }

    /**
     * @return array<string, mixed>
     */
    private function report(Validator $validator, string $xml, ?\DOMDocument $document, ?string $name): array
    {
        // ⚠️ validateString() when the positive report is on: its checksum is taken over the bytes, and a DOM has none.
        $result = $document !== null && !($this->options['ruleReport'] ?? false)
            ? $validator->validateDom($document)
            : $validator->validateString($xml);

        $result->setDocumentReference($name);

        return (new JsonReport())->toArray($result);
    }

    /**
     * @param array<string, mixed>|null $edition
     */
    private function validator(?string $familyId, ?array $edition): Validator
    {
        $key = $edition === null ? '-' : $familyId . '@' . (string) $edition['version'];

        if (isset($this->validators[$key])) {
            return $this->validators[$key];
        }

        $dir = $this->options['versionsDir'] ?? 'rulesets/versions';
        $lang = ($this->options['lang'] ?? 'de') === 'en' ? 'en' : 'de';
        $ruleReport = (bool) ($this->options['ruleReport'] ?? false);

        $validator = $edition === null
            ? new Validator(
                scenarioResolver: ScenarioResolver::fromJsonFile($this->baseDir . '/rulesets/scenarios.json'),
                baseDir: $this->baseDir,
                lang: $lang,
                ruleReport: $ruleReport,
            )
            : new Validator(
                scenarioResolver: ScenarioResolver::fromArray($edition['scenarios']),
                baseDir: $this->baseDir,
                // ⚠️ The edition packages are searched FIRST and only there would a ZUGFERD-…@2.1 be found; the
                // default rule sets keep their own folder and cannot be shadowed by an edition package.
                rulesetDirs: [$this->baseDir . '/' . $dir],
                lang: $lang,
                ruleReport: $ruleReport,
                documentDir: $dir . '/codedb',
            );

        return $this->validators[$key] = $validator;
    }
}

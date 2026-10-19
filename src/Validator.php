<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator;

use EinvoiceValidator\Engine\Engine;
use EinvoiceValidator\Engine\Finding;
use EinvoiceValidator\Profile\Profile;
use EinvoiceValidator\Profile\RuleProfile;
use EinvoiceValidator\Profile\RuleProfileOverlay;
use EinvoiceValidator\Exception\DocumentTooLargeException;
use EinvoiceValidator\Exception\NotWellformedException;
use EinvoiceValidator\Pipeline\Scenario;
use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Pipeline\XmlLoader;
use EinvoiceValidator\Pipeline\XsdMode;
use EinvoiceValidator\Pipeline\XsdStage;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Result\Message;
use EinvoiceValidator\Result\Result;
use EinvoiceValidator\Result\RuleReport;
use EinvoiceValidator\Result\Stage;
use EinvoiceValidator\Result\StageStatus;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;

/**
 * Task 1.5/4.1 — the public entry point. A resolved `Scenario` (Task 4.1) now DOES select its own
 * XSD and `rulesets/<id>@<version>.json` set dynamically, overriding a constructor-supplied
 * `$xsdStage` (kept for the case with no `$scenarioResolver`, or one whose scenario carries no
 * `xsdPath` - see `ValidatorTest`'s pre-existing fixed-schema tests). `$baseDir` resolves the
 * scenario's own paths (always relative to the package root, e.g. "rulesets/xsd/ubl/maindoc/...")
 * - overridable for tests, defaults to this file's own real package root.
 */
final class Validator
{
    public function __construct(
        private readonly XmlLoader $xmlLoader = new XmlLoader(),
        private readonly ?XsdStage $xsdStage = null,
        private readonly ?ScenarioResolver $scenarioResolver = null,
        private readonly string $baseDir = __DIR__ . '/..',
        private readonly ?Profile $profile = null,
        /** Task 5.7: the application's explicit choice, which beats the automatic one. */
        private readonly ?string $forceScenarioId = null,
        /**
         * Task 4x.3 — extra directories searched for `<ref>.json` BEFORE `$baseDir/rulesets/`.
         * For rule sets that are not part of the shipped package: an application's own rules, and
         * this project's own test fixtures. Shipped rule sets stay where they are; nothing here
         * can shadow them unless the caller deliberately puts a same-named file in front.
         *
         * @var list<string>
         */
        private readonly array $rulesetDirs = [],
        /**
         * Rule profiles of the Rule Studio, applied in this order on top of the official rule sets
         * (`RuleProfileLoader::fromDirectory()`). Not to be confused with `$profile`, the operator
         * profile; both may be given, and they do different things (`RuleProfileOverlay` explains the
         * difference).
         *
         * @var list<RuleProfile>
         */
        private readonly array $ruleProfiles = [],
        /** The language of the notices the rule profiles add: 'de' (default) or 'en'. */
        private readonly string $lang = 'de',
        /**
         * The positive validation report: every rule of every rule set used, with `passed`, `failed`,
         * `not-applicable` or `not-checked`, plus checksum, time and rule set editions
         * ( `Result\RuleReport`, the section `ruleReport` of the JSON report ). Off by default; off leaves
         * the report exactly as it is without the switch. Part of every edition of VAMPIRE, not an add-on.
         */
        private readonly bool $ruleReport = false,
        /**
         * Where `fn:document()` finds the side files of the rule sets, relative to `$baseDir`.
         * Null keeps `rulesets/codedb`, the folder of the shipped package. The version cascade passes
         * `rulesets/versions/codedb`, because the edition packages read code databases of their own
         * edition ( `Cascade`, devkit/Cli/import-zugferd-versions.php ).
         *
         * ⚠️ ONE FOLDER PER VALIDATOR, NOT ONE PER RULE SET. `fn:document()` accepts a plain file name
         * only, so the edition packages carry renamed literals ( `zugferd-2.1-basic_codedb.xml` ) and can
         * never pick up the code list of another edition by accident.
         */
        private readonly ?string $documentDir = null,
    ) {
    }

    /** The engine name in the positive report; VAMPIRE JS writes 'VAMPIRE JS' in the same field. */
    public const ENGINE_NAME = 'VAMPIRE PHP';

    /**
     * The edition of this library in the positive report.
     *
     * ⚠️ KEEP IN STEP WITH `version` IN composer.json. The runtime copy inside a plugin has no composer
     * metadata to read it from, so the constant is the only place the shipped code can know its own edition.
     */
    public const VERSION = '1.2.0';

    public function validateFile(string $path): Result
    {
        $result = new Result();
        $result->setDocumentReference($path);

        if ($this->ruleReport) {
            // ⚠️ The checksum of the FILE, read before parsing: it must match what the recipient computes
            // over the same file, not over a re-serialised DOM. basename(): a server path does not belong
            // into a report that travels further than the invoice ( docs/developer/report-formats.md, last section ).
            $hash = is_file($path) ? hash_file('sha256', $path) : false;
            $size = is_file($path) ? filesize($path) : false;
            $this->attachRuleReport($result, basename($path), $hash !== false ? $hash : null, $size !== false ? $size : null);
        }

        try {
            $dom = $this->xmlLoader->loadFile($path);
        } catch (NotWellformedException | DocumentTooLargeException $e) {
            $this->recordLoadFailure($result, $e);
            return $result;
        }

        return $this->runFrom($dom, $result);
    }

    public function validateString(string $xml): Result
    {
        $result = new Result();

        if ($this->ruleReport) {
            $this->attachRuleReport($result, null, hash('sha256', $xml), strlen($xml));
        }

        try {
            $dom = $this->xmlLoader->loadString($xml);
        } catch (NotWellformedException | DocumentTooLargeException $e) {
            $this->recordLoadFailure($result, $e);
            return $result;
        }

        return $this->runFrom($dom, $result);
    }

    /**
     * A `\DOMDocument` object is, by construction, already wellformed - Wellformed passes
     * trivially here, there is nothing left to check that `XmlLoader` would have caught.
     */
    public function validateDom(\DOMDocument $document): Result
    {
        $result = new Result();
        $result->setStageStatus(Stage::Wellformed, StageStatus::Passed);

        if ($this->ruleReport) {
            // ⚠️ No checksum: a DOM has no bytes of its own, and one over saveXML() would match no file
            // anybody holds. The field stays null rather than carrying a number that proves nothing.
            $this->attachRuleReport($result, null, null, null);
        }

        return $this->runPipeline($document, $result);
    }

    private function attachRuleReport(Result $result, ?string $name, ?string $sha256, ?int $bytes): void
    {
        // ⚠️ UTC with a `Z`, not the server's zone: the same moment must read the same in VAMPIRE JS, which
        // runs in the browser's zone, and a report filed next to an invoice must not depend on php.ini.
        $result->setRuleReport(new RuleReport(self::ENGINE_NAME, self::VERSION, $name, $sha256, $bytes, gmdate('Y-m-d\TH:i:s\Z')));
    }

    /**
     * The effective severity of a rule for the positive report: the same chain a finding goes through in
     * `runRulesets()` - the scenario's re-ranking, then the operator profile, then a rule profile.
     *
     * @param array<string, string> $customLevels
     * @param array<string, Flag>   $severity
     * @return \Closure(?string, Flag): string
     */
    private static function severityOf(array $customLevels, array $severity = [], ?RuleProfileOverlay $overlay = null): \Closure
    {
        return static function (?string $id, Flag $own) use ($customLevels, $severity, $overlay): string {
            $flag = self::applyCustomLevel($own, $id, $customLevels);

            if ($id !== null) {
                $flag = $severity[$id] ?? $flag;
            }

            $level = $overlay?->official($id)['level'];

            return is_string($level) ? $level : $flag->value;
        };
    }

    /**
     * The run stopped before the rule sets ( schema failed in `XsdMode::Stop`, or a rule set of the
     * scenario is missing ): every rule of the rule sets that ARE there is listed as `not-checked`.
     *
     * ⚠️ LOADED ONLY FOR THE LISTING, AND ONLY WITH THE SWITCH ON. Without it the run stops here and reads
     * none of the up to 24 MB of rule set files.
     *
     * @param list<string>          $refs
     * @param list<string>          $missing
     * @param array<string, string> $customLevels
     */
    private function listRulesNotRun(RuleReport $report, array $refs, array $missing, array $customLevels): void
    {
        $severityOf = self::severityOf($customLevels);

        foreach ($refs as $ref) {
            if (in_array($ref, $missing, true)) {
                $report->rulesetNotIncluded($ref);
                continue;
            }

            $report->addRuleset($ref, RulesetJsonCodec::readFile($this->resolveRulesetFile($ref)), $severityOf);
        }

        $report->notRun(RuleReport::REASON_STOPPED);
    }

    /**
     * The edition a document names in BT-24, when it is NEWER than every edition this suite knows rules for:
     * XRechnung after 3.0, EN 16931-1 after 2017. Null for everything else.
     *
     * Only asked when no scenario matched. A carried edition always matches its scenario first, so the two
     * thresholds below never need raising when the rules of 4.0 arrive: a 4.0 document then has its scenario,
     * and only a still newer one ( 4.1, 5.0 ) comes here.
     *
     * ⚠️ OLDER EDITIONS ARE LEFT ALONE ON PURPOSE. XRechnung 2.3 is out of force since 2024; "no scenario,
     * rejected" is what the KoSIT validator says about it as well, and that is a different statement from
     * "the rules have not been published yet".
     *
     * ⚠️ THE TWIN IS `editionNotIncluded()` IN VAMPIRE JS, same expressions, same labels.
     *
     * @return array{label: string, id: string}|null
     */
    public static function editionNotIncluded(\DOMDocument $document): ?array
    {
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100');
        $xpath->registerNamespace('ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100');

        $id = trim((string) $xpath->evaluate('string(/*/cbc:CustomizationID)'));

        if ($id === '') {
            $id = trim((string) $xpath->evaluate('string(/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID)'));
        }

        if ($id === '') {
            return null;
        }

        // ⚠️ The XRechnung edition first: under 2026 it is the more precise name of the same document.
        if (preg_match('/kosit:(?:standard:)?xrechnung_(\d+)\.(\d+)/', $id, $m) === 1
            && ((int) $m[1] > 3 || ((int) $m[1] === 3 && (int) $m[2] > 0))) {
            return ['label' => 'XRechnung ' . $m[1] . '.' . $m[2], 'id' => $id];
        }

        if (preg_match('/^urn:cen\.eu:en16931:(\d{4})(?:#|$)/', $id, $m) === 1 && (int) $m[1] > 2017) {
            return ['label' => 'EN 16931-1:' . $m[1], 'id' => $id];
        }

        return null;
    }

    private function runFrom(\DOMDocument $document, Result $result): Result
    {
        $result->setStageStatus(Stage::Wellformed, StageStatus::Passed);

        return $this->runPipeline($document, $result);
    }

    private function runPipeline(\DOMDocument $document, Result $result): Result
    {
        // ⚠️ Every rule profile is listed, also when the run stops before the rule sets: a reader must
        // be able to tell "no profile" from "a profile that did not get to run".
        $result->setProfiles(array_map(RuleProfileOverlay::stub(...), $this->ruleProfiles));

        $xsdStage = $this->xsdStage;
        $rulesetRefs = [];
        $scenario = null;
        $ruleReport = $result->ruleReport();
        $ruleReport?->readDocument($document);
        $ruleReport?->setScenario(null, $this->scenarioResolver !== null);

        if ($this->scenarioResolver !== null) {
            $scenario = $this->scenarioResolver->resolve($document, $this->forceScenarioId);
            $ruleReport?->setScenario($scenario?->id);

            if ($scenario === null) {
                /*
                 * ⚠️ A NEWER EDITION THAN ANY THIS PACKAGE CARRIES IS "NOT INCLUDED", NOT "REJECTED". A document
                 * naming XRechnung 4.0 or EN 16931-1:2026 in BT-24 matches no scenario, because the scenarios
                 * compare BT-24 literally. Answered as "Kein Szenario passt" and ABGELEHNT, it would tell the
                 * sender the invoice is wrong when only the rules are missing. It gets the answer of a rule set
                 * that is not part of the package instead: no verdict, and a sentence naming the edition.
                 * ⚠️ NO RULES OF 4.0 ARE INVENTED HERE, AND THE DOCUMENT IS NEVER CHECKED AGAINST 3.0 INSTEAD.
                 * VAMPIRE JS answers the same way ( `editionNotIncluded()` in src/Validator.mjs ).
                 */
                $edition = self::editionNotIncluded($document);

                if ($edition !== null) {
                    $result->setMissingRulesets([$edition['label']]);
                    $result->addMessage(new Message(
                        stage: Stage::Business,
                        ruleId: null,
                        flag: Flag::Information,
                        text: $this->lang === 'en'
                            ? sprintf('Edition not included: the document names %s in BT-24 ( %s ). This package contains no rule set for that edition yet, so this document was not checked. There is no verdict.', $edition['label'], $edition['id'])
                            : sprintf('Fassung nicht enthalten: Das Dokument nennt in BT-24 die Fassung %s ( %s ). Dieses Paket enthält für diese Fassung noch kein Regelwerk, darum wurde das Dokument nicht geprüft. Es gibt kein Urteil.', $edition['label'], $edition['id']),
                        location: null,
                        line: null,
                        ruleset: 'scenario',
                    ));

                    return $result;
                }

                $result->addMessage(new Message(
                    stage: Stage::Business,
                    ruleId: null,
                    flag: Flag::Fatal,
                    text: 'Kein Szenario passt auf dieses Dokument.',
                    location: null,
                    line: null,
                    ruleset: 'scenario',
                ));
                return $result;
            }
            $result->setScenarioName($scenario->name);

            /*
             * ⚠️ A RULE SET THIS PACKAGE DOES NOT CARRY MEANS "NOT INCLUDED", NOT "REJECTED".
             * `export-runtime.php` keeps every scenario, also those whose rule sets it left out
             * ( `--without=peppol`, `--core` ): the first match wins, and a dropped scenario would hand its
             * documents to the next match or to "Kein Szenario passt" - which rejected nine valid Peppol
             * invoices in the measurement of 09.10.2026. Checked BEFORE the schema stage: without the rules
             * there is no verdict, and a schema error alone would read like one. VAMPIRE JS answers the
             * same case in the same way ( `missingRulesets`, `accepted: null` ).
             */
            $missing = array_values(array_filter(
                $scenario->rulesetRefs,
                fn (string $ref): bool => $this->findRulesetFile($ref) === null
            ));

            if ($missing !== []) {
                $result->setRulesets(array_values($scenario->rulesetRefs));
                $result->setMissingRulesets($missing);
                $result->addMessage(new Message(
                    stage: Stage::Business,
                    ruleId: null,
                    flag: Flag::Information,
                    text: $this->lang === 'en'
                        ? sprintf('Rule set not included: this package does not contain %s, so this document was not checked. There is no verdict.', implode(', ', $missing))
                        : sprintf('Regelwerk nicht enthalten: Dieses Paket enthält %s nicht, darum wurde dieses Dokument nicht geprüft. Es gibt kein Urteil.', implode(', ', $missing)),
                    location: null,
                    line: null,
                    ruleset: 'scenario',
                ));

                if ($ruleReport !== null) {
                    $this->listRulesNotRun($ruleReport, array_values($scenario->rulesetRefs), $missing, $scenario->customLevels);
                }

                return $result;
            }

            if ($scenario->xsdPath !== null) {
                $xsdStage = new XsdStage($this->resolvePath($scenario->xsdPath));
            }
            $rulesetRefs = $scenario->rulesetRefs;
        }

        if ($xsdStage !== null) {
            $xsdStage->run($document, $result);

            // XsdMode::Stop (the default, see its own docblock): a fatal XSD failure means the
            // document does not even conform to its own schema - running Schematron rules against
            // it would evaluate real business-rule XPath against a structurally-unexpected tree,
            // producing possibly-nonsensical findings instead of a clear stop.
            if ($xsdStage->mode === XsdMode::Stop && $result->stageStatus(Stage::Xsd) === StageStatus::Failed) {
                // ⚠️ The rules of a document that failed its schema were NOT evaluated. Listed as
                // `not-checked`, never as `not-applicable` and never left out.
                if ($ruleReport !== null) {
                    $this->listRulesNotRun($ruleReport, array_values($rulesetRefs), [], $scenario !== null ? $scenario->customLevels : []);
                }

                return $result;
            }
        }

        $this->runRulesets($rulesetRefs, $document, $result, $scenario !== null ? $scenario->customLevels : []);

        return $result;
    }

    /**
     * ⚠️ A scenario may RE-RANK individual rules, and ignoring that produces false rejects on genuinely
     * valid documents. Measured 21.09.2026 against the official XRechnung 3.0.2 test suite: without the
     * override, 4 of 86 official instances were rejected - the three Extension cases (BR-CL-10/-21 on the
     * Extension's own `XR01`/`XR03` scheme ids, BR-CO-16 and UBL-CR-470 on a prepaid amount) and the CVD
     * case (BR-CL-13 on `listID="CVD"`). The KoSIT configuration downgrades exactly those rules to
     * `information` for exactly those scenarios; the rule sets themselves are shared and unchanged, which
     * is why the ruleset mapping looked right and the result still was not.
     *
     * A level KoSIT states but we do not model ('error' is its wording for our Fatal) is mapped here
     * rather than silently ignored.
     *
     * @param string[]              $rulesetRefs
     * @param array<string, string> $customLevels ruleId => level from the resolved scenario
     */
    private function runRulesets(array $rulesetRefs, \DOMDocument $document, Result $result, array $customLevels = []): void
    {
        if ($rulesetRefs === []) {
            return;
        }

        $engine = new Engine($this->resolvePath($this->documentDir ?? 'rulesets/codedb'));
        $syntaxFailed = false;
        $businessFailed = false;

        // The scenario's own rule sets, loaded before anything runs: Tasks 5.8b and 5.8c need to know
        // what the OTHER rule sets contain before the first one is evaluated.
        $loaded = [];

        foreach ($rulesetRefs as $ref) {
            $loaded[$ref] = RulesetJsonCodec::readFile($this->resolveRulesetFile($ref));

            // ⚠️ FeRD's condition for the ZUGFeRD Schematron: the user must SEE that the check applies the
            // publication. The rule set carries the sentence ( `notice`, from rule-provenance.json ).
            $notice = $loaded[$ref]->notice[$this->lang] ?? $loaded[$ref]->notice['en'] ?? null;

            if (is_string($notice)) {
                $result->addNotice($notice);
            }
        }

        /** @var array<string, list<string>> $onlyRulesPerRef rule sets that contribute only some rules */
        $onlyRulesPerRef = [];

        // Task 5.4: rule sets the scenario does not run, switched on by the profile. Their syntax has
        // to match the scenario's own - a UBL-only rule against a CII document would evaluate to
        // nothing and pretend the document had been checked.
        foreach (($this->profile !== null ? $this->profile->include : []) as $extraRef => $wantedRules) {
            if (in_array($extraRef, $rulesetRefs, true)) {
                continue;
            }

            self::assertSyntaxFits($extraRef, $rulesetRefs);
            $candidate = RulesetJsonCodec::readFile($this->resolveRulesetFile($extraRef));

            // Task 5.8c - checked BEFORE the rule set joins the run, not while it runs. A rule set
            // whose every named rule is already covered is not switched on at all: it would otherwise
            // appear in the report as a validation step that checked nothing.
            if ($wantedRules !== []) {
                $kept = $this->refuseRedundantIncludes($extraRef, $wantedRules, $candidate, $loaded, $result);

                if ($kept === []) {
                    continue;
                }

                $onlyRulesPerRef[$extraRef] = $kept;
            }

            $loaded[$extraRef] = $candidate;
            $rulesetRefs[] = $extraRef;
        }

        // array_values: `$rulesetRefs` arrives as `string[]`, and Result promises a list.
        $rulesetRefs = array_values($rulesetRefs);
        $result->setRulesets($rulesetRefs);

        $idsByKey = self::ruleIdsByDuplicateKey($loaded);
        $disable = self::expandIdsByDuplicateKey($this->profile !== null ? $this->profile->disable : [], $loaded, $idsByKey);
        $severity = self::expandSeverityByDuplicateKey($this->profile !== null ? $this->profile->severity : [], $loaded, $idsByKey);

        /** @var array<string, int> $mergeIndex duplicateKey|location => index of the message to extend */
        $mergeIndex = [];

        // The rule profiles, as an overlay on the findings (see RuleProfileOverlay).
        $overlay = new RuleProfileOverlay($this->ruleProfiles, $rulesetRefs, $loaded, $this->lang);
        $result->setProfiles($overlay->entries);

        // Every rule of the rule sets about to run is registered before the first one runs.
        $ruleReport = $result->ruleReport();

        if ($ruleReport !== null) {
            $severityOf = self::severityOf($customLevels, $severity, $overlay);

            foreach ($rulesetRefs as $ref) {
                $ruleReport->addRuleset($ref, $loaded[$ref], $severityOf, $onlyRulesPerRef[$ref] ?? null, $disable);
            }
        }

        /** @var array<string, true> $suppressedKeys duplicateKey|location of findings a profile kept out */
        $suppressedKeys = [];

        // The official verdict starts from what the pipeline already found (a schema error in XsdMode::Continue).
        $officialFatal = false;

        foreach ($result->messages() as $earlier) {
            if ($earlier->flag === Flag::Fatal) {
                $officialFatal = true;
            }
        }

        foreach ($rulesetRefs as $ref) {
            $ruleset = $loaded[$ref];
            $onlyRules = $onlyRulesPerRef[$ref] ?? null;

            foreach ($engine->run($ruleset, $document, $disable, $ruleReport) as $finding) {
                // A rule switched on explicitly brings only the listed ids with it; the rest of that
                // ruleset stays out, which is what "switch a rule on" means.
                //
                // ⚠️ `null` means "this rule set contributes everything", and that is NOT the same as
                // an empty list. With the empty list for both, a profile whose every named rule was
                // refused as a duplicate would run the WHOLE rule set - the exact opposite of what was
                // decided one line earlier. `$onlyRulesPerRef` therefore only ever holds non-empty lists.
                if ($onlyRules !== null && !in_array((string) $finding->ruleId, $onlyRules, true)) {
                    continue;
                }

                $stage = self::stageForPattern($finding->patternName);
                $flag = self::applyCustomLevel($finding->flag, $finding->ruleId, $customLevels);

                // ⚠️ A finding without a rule id gets no severity override. `$finding->ruleId` is
                // nullable, and PHP would quietly turn `null` into the array key `''` - so an
                // anonymous finding would pick up whatever an entry under the empty key happens to
                // say. No profile writes such an entry today; the lookup is guarded anyway, because
                // "works as long as nobody writes that key" is not a rule.
                if ($finding->ruleId !== null) {
                    $flag = $severity[$finding->ruleId] ?? $flag;
                }

                if ($flag === Flag::Fatal) {
                    $officialFatal = true;
                }

                // ⚠️ A rule switched off by a rule profile was evaluated and is COUNTED, not dropped:
                // it stays out of the findings, and the profile's report entry says how often it fired.
                $said = $overlay->official($finding->ruleId);

                if ($said['disabled'] !== null) {
                    $key = $finding->duplicateKey !== null ? $finding->duplicateKey . '|' . $finding->location : null;

                    if ($key === null || !isset($suppressedKeys[$key])) {
                        $overlay->countSuppressed($said['disabled']);
                    }

                    if ($key !== null) {
                        $suppressedKeys[$key] = true;
                    }

                    continue;
                }

                $severityBy = null;

                if ($said['level'] !== null) {
                    $flag = Flag::from($said['level']);
                    $severityBy = $said['ref'];
                }

                // Task 5.8a: the same rule (same duplicateKey) on the same context node, fired by a
                // second rule set, is ONE finding with two origins - not two findings. Without the
                // location in the key, two genuinely different violations of the same rule on two
                // different nodes would collapse into one, and the report would understate the
                // document's problems.
                $mergeKey = $finding->duplicateKey !== null
                    ? $finding->duplicateKey . '|' . $finding->location
                    : null;

                if ($mergeKey !== null && isset($mergeIndex[$mergeKey])) {
                    $index = $mergeIndex[$mergeKey];
                    $result->replaceMessage($index, $result->messages()[$index]->withOrigin($ref));
                    continue;
                }

                $result->addMessage(self::findingToMessage($finding, $stage, $ref, $flag, $severityBy));

                if ($mergeKey !== null) {
                    $mergeIndex[$mergeKey] = count($result->messages()) - 1;
                }

                if ($flag === Flag::Fatal) {
                    if ($stage === Stage::Syntax) {
                        $syntaxFailed = true;
                    } else {
                        $businessFailed = true;
                    }
                }
            }

            $ruleReport?->markRan($ref);
        }

        // The rule profiles after all official findings, in the order given: first a profile's notices,
        // then its own findings. ⚠️ A profile that does not apply leaves its notice too; a customer whose
        // rules did not run must read that, not infer it from silence.
        foreach ($this->ruleProfiles as $profile) {
            $origin = 'profile:' . $profile->ref;

            foreach ($overlay->notices[$profile->ref] ?? [] as $notice) {
                $result->addMessage(new Message(
                    stage: Stage::Business,
                    ruleId: null,
                    flag: Flag::Information,
                    text: $notice,
                    location: null,
                    line: null,
                    ruleset: $origin,
                    profile: $profile->ref,
                ));
            }

            if (!in_array($profile, $overlay->applied, true)) {
                continue;
            }

            $rulesetRefs[] = $origin;

            foreach ($profile->rules as $i => $rule) {
                // ⚠️ One rule set object per rule, built once: the positive report counts by assertion
                // object, and a second `rulesetFor()` would hand the engine objects nobody registered.
                $profileRuleset = $profile->rulesetFor($i);
                $ruleReport?->addRuleset($origin, $profileRuleset, static fn (?string $id, Flag $own): string => $own->value, null, [], true);

                try {
                    $found = $engine->run($profileRuleset, $document, [], $ruleReport);
                } catch (\Throwable $e) {
                    // The rule did not run to the end, so it is `not-checked` with the reason `error`.
                    $ruleReport?->failedToRun($profileRuleset);

                    // ⚠️ A rule that throws costs itself, not the report: it is reported with its own
                    // severity and the reason, never passed in silence.
                    $found = [new Finding(
                        $rule['id'],
                        'profile',
                        $rule['id'],
                        Flag::from($rule['flag']),
                        $overlay->ruleErrorText($profile->ref, $rule['id'], $e->getMessage()),
                        '',
                        null,
                        $rule['fields'],
                    )];
                }

                foreach ($found as $finding) {
                    $result->addMessage(new Message(
                        stage: Stage::Business,
                        ruleId: $finding->ruleId,
                        flag: $finding->flag,
                        text: $finding->text,
                        location: $finding->location !== '' ? $finding->location : null,
                        line: $finding->line,
                        ruleset: $origin,
                        bt: $finding->fieldRefs,
                        ruleFlag: $finding->flag,
                        profile: $profile->ref,
                    ));

                    if ($finding->flag === Flag::Fatal) {
                        $businessFailed = true;
                    }
                }
            }

            $ruleReport?->markRan($origin);
        }

        $result->setRulesets($rulesetRefs);
        $result->setProfiles($overlay->entries);
        $result->setOfficialAccepted($overlay->applied !== [] ? !$officialFatal : null);

        $result->setStageStatus(Stage::Syntax, $syntaxFailed ? StageStatus::Failed : StageStatus::Passed);
        $result->setStageStatus(Stage::Business, $businessFailed ? StageStatus::Failed : StageStatus::Passed);
    }

    /**
     * Task 5.8b — every rule id that shares a `duplicateKey`, keyed by that key, across the rule sets
     * about to run.
     *
     * @param array<string, \EinvoiceValidator\Ruleset\Ruleset> $loaded
     * @return array<string, list<string>>
     */
    private static function ruleIdsByDuplicateKey(array $loaded): array
    {
        $byKey = [];

        foreach ($loaded as $ruleset) {
            foreach ($ruleset->patterns as $pattern) {
                foreach ($pattern->rules as $rule) {
                    foreach ($rule->assertions as $assertion) {
                        if ($assertion->duplicateKey === null || $assertion->id === null) {
                            continue;
                        }

                        if (!in_array($assertion->id, $byKey[$assertion->duplicateKey] ?? [], true)) {
                            $byKey[$assertion->duplicateKey][] = $assertion->id;
                        }
                    }
                }
            }
        }

        return $byKey;
    }

    /**
     * The `duplicateKey` of a rule id, or null when no loaded rule set knows one.
     *
     * @param array<string, \EinvoiceValidator\Ruleset\Ruleset> $loaded
     */
    private static function duplicateKeyOf(string $ruleId, array $loaded): ?string
    {
        foreach ($loaded as $ruleset) {
            foreach ($ruleset->patterns as $pattern) {
                foreach ($pattern->rules as $rule) {
                    foreach ($rule->assertions as $assertion) {
                        if ($assertion->id === $ruleId && $assertion->duplicateKey !== null) {
                            return $assertion->duplicateKey;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Task 5.8b — a profile naming one rule id means the RULE, not that one spelling of it.
     *
     * ⚠️ The same rule carries different ids in different rule sets (`UBL-CR-400` in five of them,
     * `ubl-CR-400` in UBL.BE). Switching it off by id alone left it running everywhere else, and the
     * profile looked as though it had done nothing. The `duplicateKey` is what makes them the same
     * rule; ids without a key stay exactly as written.
     *
     * @param list<string> $ids
     * @param array<string, \EinvoiceValidator\Ruleset\Ruleset> $loaded
     * @param array<string, list<string>> $idsByKey
     * @return list<string>
     */
    private static function expandIdsByDuplicateKey(array $ids, array $loaded, array $idsByKey): array
    {
        $out = $ids;

        foreach ($ids as $id) {
            $key = self::duplicateKeyOf($id, $loaded);

            if ($key === null) {
                continue;
            }

            foreach ($idsByKey[$key] ?? [] as $sibling) {
                if (!in_array($sibling, $out, true)) {
                    $out[] = $sibling;
                }
            }
        }

        return $out;
    }

    /**
     * Task 5.8b, same idea for the re-ranking: a severity set for one id applies to every spelling of
     * the same rule.
     *
     * @param array<string, Flag> $severity
     * @param array<string, \EinvoiceValidator\Ruleset\Ruleset> $loaded
     * @param array<string, list<string>> $idsByKey
     * @return array<string, Flag>
     */
    private static function expandSeverityByDuplicateKey(array $severity, array $loaded, array $idsByKey): array
    {
        foreach ($severity as $id => $flag) {
            $key = self::duplicateKeyOf($id, $loaded);

            if ($key === null) {
                continue;
            }

            foreach ($idsByKey[$key] ?? [] as $sibling) {
                // ⚠️ An explicit entry for the sibling wins. Someone who re-ranks two spellings
                // differently means it, however odd it looks.
                $severity[$sibling] ??= $flag;
            }
        }

        return $severity;
    }

    /**
     * Task 5.8c — a rule the profile switches on that is ALREADY running, under whatever id, in one of
     * the scenario's own rule sets, is refused rather than run a second time.
     *
     * ⚠️ Refused, but not in silence (rule A.6): the report gets an `information` entry naming the
     * rule, the rule set it is already covered by, and the fact that nothing was added. A profile that
     * quietly does nothing is worse than one that says so.
     *
     * @param list<string> $wanted
     * @param array<string, \EinvoiceValidator\Ruleset\Ruleset> $running the rule sets already in the run
     * @return list<string>
     */
    private function refuseRedundantIncludes(
        string $ref,
        array $wanted,
        \EinvoiceValidator\Ruleset\Ruleset $candidate,
        array $running,
        Result $result
    ): array {
        $others = $running;
        unset($others[$ref]);

        $kept = [];

        foreach ($wanted as $id) {
            $key = self::duplicateKeyOf($id, [$ref => $candidate]);
            $coveredBy = null;

            if ($key !== null) {
                foreach ($others as $otherRef => $otherRuleset) {
                    if (self::containsDuplicateKey($otherRuleset, $key)) {
                        $coveredBy = $otherRef;
                        break;
                    }
                }
            }

            if ($coveredBy === null) {
                $kept[] = $id;
                continue;
            }

            $result->addMessage(new Message(
                stage: Stage::Business,
                ruleId: $id,
                flag: Flag::Information,
                text: sprintf(
                    'Regel %s aus "%s" wurde nicht zugeschaltet: dieselbe Regel läuft bereits in "%s".',
                    $id,
                    $ref,
                    $coveredBy
                ),
                location: null,
                line: null,
                ruleset: 'profile',
            ));
        }

        return $kept;
    }

    private static function containsDuplicateKey(\EinvoiceValidator\Ruleset\Ruleset $ruleset, string $key): bool
    {
        foreach ($ruleset->patterns as $pattern) {
            foreach ($pattern->rules as $rule) {
                foreach ($rule->assertions as $assertion) {
                    if ($assertion->duplicateKey === $key) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Task 5.4 — a rule set switched on by a profile must speak the same syntax as the ones the
     * scenario already runs. Derived from the ruleset reference itself (…-UBL@…, …-CII@…), which is
     * how every shipped reference is named.
     *
     * @param string[] $scenarioRefs
     */
    private static function assertSyntaxFits(string $extraRef, array $scenarioRefs): void
    {
        $syntaxOf = static function (string $ref): ?string {
            if (str_contains($ref, '-UBL@')) {
                return 'UBL';
            }

            return str_contains($ref, '-CII@') ? 'CII' : null;
        };

        $wanted = $syntaxOf($extraRef);

        if ($wanted === null) {
            return;
        }

        foreach ($scenarioRefs as $ref) {
            $syntax = $syntaxOf($ref);

            if ($syntax !== null && $syntax !== $wanted) {
                throw new \RuntimeException(sprintf(
                    'Profil schaltet "%s" (%s) zu, das Szenario prüft aber %s-Belege.',
                    $extraRef,
                    $wanted,
                    $syntax
                ));
            }
        }
    }

    /**
     * ⚠️ Assumption (Regel E.1, see docs/notes/decisions.md): a pattern whose NAME contains "syntax"
     * (case-insensitive) is the ruleset's own structural/syntax-binding pattern (Stage::Syntax);
     * every other pattern is a business-semantic one (Stage::Business). Grounded in real, measured
     * pattern names - EN16931-UBL/CII both literally have a "...-Syntax"/"...-syntax" pattern
     * alongside their business-rule one(s) (Task 3.1's own measured pattern lists) - but XRechnung's
     * own patterns (variable-pattern, ubl-pattern, ...) never use this convention, so they always
     * fall to Business, a defensible default since XRechnung's CIUS rules genuinely are business-
     * semantic additions, not syntax rules. Revisit if a future ruleset's pattern naming disagrees.
     */
    private static function stageForPattern(string $patternName): Stage
    {
        return stripos($patternName, 'syntax') !== false ? Stage::Syntax : Stage::Business;
    }

    /**
     * @param array<string, string> $customLevels
     */
    private static function applyCustomLevel(Flag $flag, ?string $ruleId, array $customLevels): Flag
    {
        if ($ruleId === null || !isset($customLevels[$ruleId])) {
            return $flag;
        }

        $level = strtolower(trim($customLevels[$ruleId]));

        return match ($level) {
            'information', 'info', 'notice' => Flag::Information,
            'warning', 'warn' => Flag::Warning,
            // KoSIT writes "error" where the rule sets write "fatal" - same blocking severity.
            'error', 'fatal' => Flag::Fatal,
            default => throw new \RuntimeException(
                sprintf('Unknown customLevel "%s" for rule %s in the resolved scenario.', $customLevels[$ruleId], $ruleId)
            ),
        };
    }

    private static function findingToMessage(Finding $finding, Stage $stage, string $rulesetRef, ?Flag $flag = null, ?string $severityBy = null): Message
    {
        return new Message(
            stage: $stage,
            ruleId: $finding->ruleId,
            flag: $flag ?? $finding->flag,
            text: $finding->text,
            location: $finding->location,
            line: $finding->line,
            ruleset: $rulesetRef,
            bt: $finding->fieldRefs,
            // The rule set's own severity is kept alongside the effective one - the KoSIT-shaped
            // report needs it (Task 4.6, see Message::nativeFlag()).
            ruleFlag: $finding->flag,
            severityBy: $severityBy,
        );
    }

    private function resolvePath(string $relativePath): string
    {
        return $this->baseDir . '/' . $relativePath;
    }

    /**
     * Task 4x.3 — where a rule set file is looked for: the extra directories first, the package's own
     * `rulesets/` last.
     *
     * ⚠️ A missing file is NOT silently skipped (rule A.6). Returning the package path as a fallback
     * would make `RulesetJsonCodec` report "rulesets/x.json not found" even when the caller pointed
     * somewhere else entirely - so the error names every place that was actually searched.
     */
    /** The file of a rule set, or null when no searched place has it ( see `resolveRulesetFile()` ). */
    private function findRulesetFile(string $ref): ?string
    {
        try {
            return $this->resolveRulesetFile($ref);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function resolveRulesetFile(string $ref): string
    {
        /**
         * ⚠️ TWO SPELLINGS PER DIRECTORY: the reference literally (`EN16931-UBL@1.3.16.json`) and with a
         * hyphen instead of the at sign (`EN16931-UBL-1.3.16.json`).
         *
         * The reason is the delivery in a WordPress plugin: WordPress' Plugin Check rejects special
         * characters in file names (`badly_named_files`), and some hosts and security scanners dislike an
         * `@` in a path just as much. `export-runtime.php` therefore writes the hyphen spelling into the
         * delivery, while the work tree keeps the literal one - the reference in `scenarios.json` and in
         * every profile stays `EN16931-UBL@1.3.16`, and nobody has to know two spellings.
         *
         * The literal spelling is searched first: in the development tree exactly the file that carries
         * that name should be taken.
         */
        $spellings = [$ref];
        $dashed = str_replace('@', '-', $ref);

        if ($dashed !== $ref) {
            $spellings[] = $dashed;
        }

        $candidates = [];

        foreach ($this->rulesetDirs as $dir) {
            foreach ($spellings as $spelling) {
                $candidates[] = rtrim($dir, '/\\') . '/' . $spelling . '.json';
            }
        }

        foreach ($spellings as $spelling) {
            $candidates[] = $this->resolvePath("rulesets/$spelling.json");
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(sprintf(
            'Regelpaket "%s" nicht gefunden. Gesucht in: %s',
            $ref,
            implode(', ', $candidates)
        ));
    }

    private function recordLoadFailure(Result $result, NotWellformedException|DocumentTooLargeException $e): void
    {
        if ($e instanceof DocumentTooLargeException) {
            $result->addMessage(new Message(
                stage: Stage::Wellformed,
                ruleId: null,
                flag: Flag::Fatal,
                text: $e->getMessage(),
                location: null,
                line: null,
                ruleset: 'wellformed',
            ));
        } else {
            foreach ($e->errors as $error) {
                $result->addMessage(new Message(
                    stage: Stage::Wellformed,
                    ruleId: null,
                    flag: Flag::Fatal,
                    text: trim($error->message),
                    location: null,
                    line: $error->line > 0 ? $error->line : null,
                    ruleset: 'wellformed',
                ));
            }
        }

        $result->setStageStatus(Stage::Wellformed, StageStatus::Failed);
    }
}

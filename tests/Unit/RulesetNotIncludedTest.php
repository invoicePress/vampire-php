<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Report\HtmlReport;
use EinvoiceValidator\Report\JsonReport;
use EinvoiceValidator\Result\Stage;
use EinvoiceValidator\Result\StageStatus;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\TestCase;
use EinvoiceValidator\Tests\Support\Corpus;

/**
 * A package without the rule sets of a scenario ( `export-runtime.php --without=peppol` ) and the visible
 * notice of the Factur-X rule sets.
 *
 * ⚠️ THE CASE THAT GOES WRONG WHEN AN EXPORT DROPS SCENARIOS: an export without Peppol that dropped the Peppol
 * scenarios returned a valid Peppol invoice as "Kein Szenario passt" and rejected. The first test reproduces the
 * export's situation with the real scenario list and an empty `rulesets/` folder: every scenario resolves as in
 * the full package, and none of its rule sets is there.
 */
final class RulesetNotIncludedTest extends TestCase
{
    private string $empty;

    protected function setUp(): void
    {
        $this->empty = sys_get_temp_dir() . '/einv-not-included-' . bin2hex(random_bytes(4));
        mkdir($this->empty . '/rulesets', 0777, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->empty . '/rulesets');
        @rmdir($this->empty);
    }

    private static function peppolInvoice(): string
    {
        $file = Corpus::examples() . '/invoices/UBL/base-example.xml';

        if (!is_file($file)) {
            self::markTestSkipped('VAMPIRE_EXAMPLES ist nicht gesetzt.');
        }

        return $file;
    }

    public function testAPeppolInvoiceWithoutThePeppolRuleSetsGetsNoVerdictAndNoRejection(): void
    {
        $base = dirname(__DIR__, 2);
        $resolver = ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json');

        $result = (new Validator(scenarioResolver: $resolver, baseDir: $this->empty))->validateFile(self::peppolInvoice());

        self::assertFalse($result->hasVerdict());
        self::assertSame(['CEN-EN16931-UBL@3.0.21', 'PEPPOL-EN16931-UBL@3.0.21'], $result->missingRulesets());
        self::assertNotNull($result->scenarioName(), 'the Peppol scenario still resolves');
        self::assertSame(StageStatus::NotRun, $result->stageStatus(Stage::Xsd), 'no schema stage without the rules');

        $json = (new JsonReport())->toArray($result);

        self::assertNull($json['accepted'], 'no verdict, never "rejected"');
        self::assertSame(0, $json['counts']['fatal']);
        self::assertStringContainsString('nicht enthalten', $json['findings'][0]['text']);
        self::assertStringContainsString('Kein Urteil', (new HtmlReport())->render($result));
        self::assertStringNotContainsString('Abgelehnt', (new HtmlReport())->render($result));
    }

    public function testTheSameInvoiceWithAllRuleSetsIsAcceptedAndCarriesNoMissingField(): void
    {
        $base = dirname(__DIR__, 2);
        $resolver = ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json');

        $result = (new Validator(scenarioResolver: $resolver, baseDir: $base))->validateFile(self::peppolInvoice());
        $json = (new JsonReport())->toArray($result);

        self::assertTrue($json['accepted']);
        self::assertSame([], $json['missingRulesets']);
        self::assertSame([], $json['notices'], 'Peppol has no visible notice');
    }

    public function testAFacturXCheckCarriesFeRDsVisibleNoticeInTheReport(): void
    {
        $base = dirname(__DIR__, 2);
        $resolver = ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json');
        $files = glob($base . '/tests/fixtures/invoices/phive-zugferd-extended/*.xml') ?: [];

        self::assertNotSame([], $files);

        $result = (new Validator(scenarioResolver: $resolver, baseDir: $base))->validateFile($files[0]);

        self::assertSame(['Diese Prüfung wendet die Veröffentlichung Factur-X / ZUGFeRD von FNFE-MPE und FeRD an.'], $result->notices());
        self::assertStringContainsString('Factur-X / ZUGFeRD von FNFE-MPE und FeRD', (new HtmlReport())->render($result));

        $en = (new Validator(scenarioResolver: $resolver, baseDir: $base, lang: 'en'))->validateFile($files[0]);

        self::assertSame(['This check applies the publication Factur-X / ZUGFeRD of FNFE-MPE and FeRD.'], $en->notices());
    }
}

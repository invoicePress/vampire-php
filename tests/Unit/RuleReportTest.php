<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Profile\Profile;
use EinvoiceValidator\Profile\ProfileLoader;
use EinvoiceValidator\Report\HtmlReport;
use EinvoiceValidator\Report\JsonReport;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\TestCase;
use EinvoiceValidator\Tests\Support\Corpus;

/**
 * The positive validation report, `new Validator( ruleReport: true )`.
 *
 * ⚠️ THE CASES THAT MATTER ARE THE ONES WHERE NOTHING RAN. A report that lists 1 055 rules as `passed` for a
 * good invoice is easy; the requirement without room for interpretation is that a rule the engine did not
 * execute is `not-checked` and never missing. Four tests below stop the run at four different places and
 * check exactly that.
 */
final class RuleReportTest extends TestCase
{
    private static function base(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function validator(bool $on, ?Profile $profile = null): Validator
    {
        return new Validator(
            scenarioResolver: ScenarioResolver::fromJsonFile(self::base() . '/rulesets/scenarios.json'),
            baseDir: self::base(),
            profile: $profile,
            ruleReport: $on,
        );
    }

    private static function invoice(): string
    {
        return self::base() . '/tests/fixtures/invoices/valid-ubl-invoice.xml';
    }

    public function testWithoutTheSwitchTheReportHasNoNewKeyAndIsUnchanged(): void
    {
        $off = (new JsonReport())->toArray(self::validator(false)->validateFile(self::invoice()));
        $on = (new JsonReport())->toArray(self::validator(true)->validateFile(self::invoice()));

        self::assertArrayNotHasKey('ruleReport', $off, 'absent, not null');
        self::assertArrayHasKey('ruleReport', $on);

        // ⚠️ The switch counts; it must not change a single finding, the verdict or a stage.
        unset($on['ruleReport']);
        self::assertSame($off, $on);
    }

    public function testAValidInvoiceListsEveryRuleOfBothRuleSetsWithHeader(): void
    {
        $rr = (new JsonReport())->toArray(self::validator(true)->validateFile(self::invoice()))['ruleReport'];

        self::assertSame(1, $rr['formatVersion']);
        self::assertSame('VAMPIRE PHP', $rr['engine']['name']);
        self::assertSame(hash_file('sha256', self::invoice()), $rr['document']['sha256']);
        self::assertSame(filesize(self::invoice()), $rr['document']['bytes']);
        self::assertSame('valid-ubl-invoice.xml', $rr['document']['name'], 'the file name, never the path');
        self::assertSame('UBL', $rr['document']['syntax']);
        self::assertSame('urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', $rr['document']['specificationId']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $rr['validatedAt']);

        self::assertSame(['EN16931-UBL@1.3.16', 'XRechnung-UBL@3.0.2'], array_column($rr['rulesets'], 'ref'));
        self::assertSame(['1.3.16', '3.0.2'], array_column($rr['rulesets'], 'version'));
        self::assertSame([true, true], array_column($rr['rulesets'], 'ran'));

        // One line per rule: 979 + 76 rule ids, measured on the rule set files ( no id occurs twice in either ).
        self::assertCount(979 + 76, $rr['rules']);
        self::assertSame(979 + 76, array_sum($rr['counts']));
        self::assertSame(0, $rr['counts']['not-checked']);
        self::assertGreaterThan(0, $rr['counts']['passed']);
        self::assertGreaterThan(0, $rr['counts']['not-applicable']);

        self::assertSame(['wellformed:passed', 'scenario:passed', 'schema:passed', 'rules:passed'], array_map(
            static fn (array $s): string => $s['step'] . ':' . $s['result'],
            $rr['steps']
        ));

        $br01 = self::line($rr, 'EN16931-UBL@1.3.16', 'BR-01');
        self::assertSame('passed', $br01['result']);
        self::assertSame(1, $br01['checked']);
        self::assertSame(0, $br01['failed']);
        self::assertNull($br01['reason']);
        self::assertStringStartsWith('[BR-01]', $br01['text']);
    }

    public function testAFiringRuleIsFailedAndCountsItsLocations(): void
    {
        $xml = (string) file_get_contents(self::invoice());
        // BR-DE-15 ( buyer reference ) fires when BT-10 is empty; the schema still holds.
        $xml = str_replace('<cbc:BuyerReference>04011000-12345-03</cbc:BuyerReference>', '<cbc:BuyerReference> </cbc:BuyerReference>', $xml);

        $result = self::validator(true)->validateString($xml);
        $rr = (new JsonReport())->toArray($result)['ruleReport'];

        self::assertFalse($result->isAccepted());
        self::assertSame('failed', self::line($rr, 'XRechnung-UBL@3.0.2', 'BR-DE-15')['result']);
        self::assertSame(1, self::line($rr, 'XRechnung-UBL@3.0.2', 'BR-DE-15')['failed']);
        self::assertSame(hash('sha256', $xml), $rr['document']['sha256']);
        self::assertNull($rr['document']['name'], 'a string has no file name');
        self::assertSame('rules:failed', $rr['steps'][3]['step'] . ':' . $rr['steps'][3]['result']);
    }

    public function testASchemaErrorLeavesEveryRuleNotCheckedAndNoneNotApplicable(): void
    {
        $xml = str_replace(
            '<cbc:ProfileID>',
            '<cbc:NotInTheSchema>x</cbc:NotInTheSchema><cbc:ProfileID>',
            (string) file_get_contents(self::invoice())
        );

        $rr = (new JsonReport())->toArray(self::validator(true)->validateString($xml))['ruleReport'];

        self::assertSame('schema:failed', $rr['steps'][2]['step'] . ':' . $rr['steps'][2]['result']);
        self::assertSame(['step' => 'rules', 'result' => 'not-checked', 'reason' => 'stopped'], $rr['steps'][3]);
        self::assertCount(979 + 76, $rr['rules'], 'every rule is still listed');
        self::assertSame(['passed' => 0, 'failed' => 0, 'not-applicable' => 0, 'not-checked' => 979 + 76], $rr['counts']);
        self::assertSame(['stopped'], array_values(array_unique(array_column($rr['rules'], 'reason'))));
        self::assertSame([false, false], array_column($rr['rulesets'], 'ran'));
    }

    public function testANotWellFormedFileHasChecksumAndEveryLaterStepNotChecked(): void
    {
        $rr = (new JsonReport())->toArray(self::validator(true)->validateString('<Invoice><unclosed></Invoice>'))['ruleReport'];

        self::assertSame(hash('sha256', '<Invoice><unclosed></Invoice>'), $rr['document']['sha256']);
        self::assertSame([
            ['step' => 'wellformed', 'result' => 'failed', 'reason' => null],
            ['step' => 'scenario', 'result' => 'not-checked', 'reason' => 'stopped'],
            ['step' => 'schema', 'result' => 'not-checked', 'reason' => 'stopped'],
            ['step' => 'rules', 'result' => 'not-checked', 'reason' => 'stopped'],
        ], $rr['steps']);
        self::assertSame([], $rr['rules']);
    }

    public function testAMissingRuleSetIsAnEntryAndTheOthersAreNotChecked(): void
    {
        // A package with only the CEN rule set of Peppol: the scenario needs two, one is there.
        $dir = sys_get_temp_dir() . '/einv-rr-' . bin2hex(random_bytes(4));
        mkdir($dir . '/rulesets', 0777, true);

        try {
            copy(self::base() . '/rulesets/CEN-EN16931-UBL@3.0.21.json', $dir . '/rulesets/CEN-EN16931-UBL@3.0.21.json');

            $validator = new Validator(
                scenarioResolver: ScenarioResolver::fromJsonFile(self::base() . '/rulesets/scenarios.json'),
                baseDir: $dir,
                ruleReport: true,
            );
            $peppol = Corpus::examples() . '/invoices/UBL/base-example.xml';

            if (!is_file($peppol)) {
                self::markTestSkipped('VAMPIRE_EXAMPLES ist nicht gesetzt.');
            }

            $json = (new JsonReport())->toArray($validator->validateFile($peppol));
            $rr = $json['ruleReport'];

            self::assertNull($json['accepted']);
            self::assertSame([
                ['CEN-EN16931-UBL@3.0.21', false, 'stopped', 979],
                ['PEPPOL-EN16931-UBL@3.0.21', false, 'not-included', null],
            ], array_map(static fn (array $e): array => [$e['ref'], $e['ran'], $e['reason'], $e['rules']], $rr['rulesets']));
            self::assertSame(['step' => 'rules', 'result' => 'not-checked', 'reason' => 'not-included'], $rr['steps'][3]);
            self::assertSame(979, $rr['counts']['not-checked']);
            self::assertSame(979, array_sum($rr['counts']));
        } finally {
            @unlink($dir . '/rulesets/CEN-EN16931-UBL@3.0.21.json');
            @rmdir($dir . '/rulesets');
            @rmdir($dir);
        }
    }

    public function testARuleSwitchedOffByTheOperatorProfileIsNotCheckedNotPassed(): void
    {
        $profile = (new ProfileLoader(self::base() . '/tests/fixtures/profiles'))->load('test-disable');
        $rr = (new JsonReport())->toArray(self::validator(true, $profile)->validateFile(self::invoice()))['ruleReport'];

        $br02 = self::line($rr, 'EN16931-UBL@1.3.16', 'BR-02');

        self::assertSame('not-checked', $br02['result']);
        self::assertSame('disabled', $br02['reason']);
        self::assertSame(0, $br02['checked'], 'the engine skips it before evaluation');
    }

    public function testFacturXAssertionsWithoutIdGetAPositionalKey(): void
    {
        $files = glob(self::base() . '/tests/fixtures/invoices/phive-zugferd-extended/*.xml') ?: [];
        self::assertNotSame([], $files);

        $rr = (new JsonReport())->toArray(self::validator(true)->validateFile($files[0]))['ruleReport'];
        $keys = array_column(array_filter($rr['rules'], static fn (array $l): bool => $l['rule'] === null), 'key');

        // 521 assertions without id in FACTURX-EXTENDED-CII 1.09.2, measured on the rule set file.
        self::assertCount(521, $keys);
        self::assertSame(count($keys), count(array_unique($keys)));
        self::assertMatchesRegularExpression('/^#\d+\.\d+\.\d+$/', $keys[0]);
    }

    /**
     * The printable HTML report carries the section only with the switch, and without it the page is
     * unchanged ( the timestamp of the section is the only clock in the page, so the two runs compare equal ).
     */
    public function testTheHtmlReportShowsTheSectionOnlyWithTheSwitch(): void
    {
        $off = (new HtmlReport())->render(self::validator(false)->validateFile(self::invoice()));
        $on = (new HtmlReport())->render(self::validator(true)->validateFile(self::invoice()));

        self::assertStringNotContainsString('Geprüfte Regeln', $off);
        self::assertStringContainsString('<h2>Geprüfte Regeln</h2>', $on);
        self::assertStringContainsString('Er ist kein Testat.', $on);
        $sha = hash_file('sha256', self::invoice());
        self::assertIsString($sha);
        self::assertStringContainsString($sha, $on);
        self::assertSame(979 + 76, substr_count($on, '<td class="rule">EN16931-UBL@1.3.16</td>') + substr_count($on, '<td class="rule">XRechnung-UBL@3.0.2</td>'));

        // Everything before the section is the page without the switch.
        $at = strpos($on, '<h2>Geprüfte Regeln</h2>');
        self::assertNotFalse($at);
        self::assertSame(substr($off, 0, -strlen("</body>\n</html>\n")), substr($on, 0, $at));
    }

    /**
     * ⚠️ The case that must never look harmless on paper: a schema error leaves every rule "nicht geprüft", and
     * the page says so in every row - none of them reads "erfüllt" or "nicht anwendbar".
     */
    public function testTheHtmlReportShowsEveryNotCheckedRuleAfterASchemaError(): void
    {
        $xml = str_replace('<cbc:ProfileID>', '<cbc:NotInTheSchema>x</cbc:NotInTheSchema><cbc:ProfileID>', (string) file_get_contents(self::invoice()));
        $html = (new HtmlReport())->render(self::validator(true)->validateString($xml));

        self::assertSame(979 + 76, substr_count($html, '<tr class="not-checked">'));
        self::assertSame(0, substr_count($html, '<tr class="passed">') + substr_count($html, '<tr class="not-applicable">'));
        self::assertStringContainsString('1055 Regeln: 0 verletzt, 1055 nicht geprüft, 0 erfüllt, 0 nicht anwendbar', $html);
    }

    /**
     * @param array<string, mixed> $rr
     * @return array<string, mixed>
     */
    private static function line(array $rr, string $ruleset, string $rule): array
    {
        foreach ($rr['rules'] as $line) {
            if ($line['ruleset'] === $ruleset && $line['rule'] === $rule) {
                return $line;
            }
        }

        self::fail("no line for $rule in $ruleset");
    }
}

<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Profile;

use EinvoiceValidator\Exception\RuleProfileException;
use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Profile\RuleProfile;
use EinvoiceValidator\Profile\RuleProfileLoader;
use EinvoiceValidator\Profile\RuleProfileValidator;
use EinvoiceValidator\Profile\VersionRange;
use EinvoiceValidator\Report\HtmlReport;
use EinvoiceValidator\Report\JsonReport;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use EinvoiceValidator\Tests\Support\Corpus;

/**
 * Rule profiles of the Rule Studio.
 *
 * ⚠️ The example profiles, their script forms and the refusal cases under
 * `tests/fixtures/rule-profiles/` are the same files VAMPIRE JS tests against
 * ( the rule profile test of VAMPIRE JS ). The corpus parity is measured on the JS
 * side against `devkit/Cli/rule-profile-findings.php`'s recording; this test checks that the recording
 * still describes what this side reports, on three documents.
 */
final class RuleProfileTest extends TestCase
{
    private static function baseDir(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function fixtures(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/rule-profiles';
    }

    private static function corpus(): string
    {
        $dir = Corpus::examples() . '/invoices';

        if (!is_dir($dir)) {
            self::markTestSkipped('VAMPIRE_EXAMPLES ist nicht gesetzt.');
        }

        return $dir;
    }

    private static function profileText(string $name): string
    {
        return (string) file_get_contents(self::fixtures() . "/$name.json");
    }

    /**
     * @param list<RuleProfile> $profiles
     */
    private static function validator(array $profiles, string $lang = 'de'): Validator
    {
        return new Validator(
            scenarioResolver: ScenarioResolver::fromJsonFile(self::baseDir() . '/rulesets/scenarios.json'),
            baseDir: self::baseDir(),
            ruleProfiles: $profiles,
            lang: $lang,
        );
    }

    public function testBothExampleProfilesAreAccepted(): void
    {
        $a = RuleProfileValidator::validate(self::profileText('muster-ust'));
        $b = RuleProfileValidator::validate(self::profileText('kunde-lockerung'));

        self::assertSame('muster-ust@1.0.0', $a->ref);
        self::assertCount(6, $a->rules);
        self::assertSame('report', $a->rules[5]['kind']);
        self::assertSame('kunde-lockerung@2.1', $b->ref);
        self::assertSame([['rule' => 'BR-DE-TMP-32', 'by' => 'KUNDE-UST-010', 'reason' => $b->replaces[0]['reason']]], $b->replaces);
    }

    /**
     * ⚠️ The `.js` files were written by the JS writer (`profileScript()`); the JS test asserts that it
     * still writes exactly these bytes. Reading them here without running them is the other half.
     */
    public function testTheJsWriterAndThisReaderAgree(): void
    {
        $fromScripts = RuleProfileLoader::fromDirectory(self::fixtures() . '/vampire-profiles');

        self::assertSame(['muster-ust@1.0.0', 'kunde-lockerung@2.1'], array_map(static fn (RuleProfile $p): string => $p->ref, $fromScripts));

        foreach ($fromScripts as $profile) {
            self::assertEquals(RuleProfileLoader::fromFile(self::fixtures() . "/{$profile->name}.json"), $profile);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function scriptVariants(): array
    {
        $good = (string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/rule-profiles/vampire-profiles/muster-ust.js');

        return [
            'code before the registration' => ["alert(1);\n" . $good],
            'code after the registration' => [$good . "alert(1);\n"],
            'an object instead of a string' => [(string) preg_replace('/="(?:[^"\\\\]|\\\\.)*+";/', '={"format":"vampire-profile"};', $good)],
            'a line separator in the comment ends the comment in JavaScript' => [str_replace('// VAMPIRE', "// VAMPIRE\u{2028}alert(1)//", $good)],
            'another registry key' => [str_replace('vampire/profiles/muster-ust.json', 'vampire/rules/index.json', $good)],
            'a concatenated string' => [str_replace('";})', '"+x;})', $good)],
        ];
    }

    #[DataProvider('scriptVariants')]
    public function testAScriptThatDiffersFromTheFixedFormIsNotRead(string $text): void
    {
        try {
            RuleProfileLoader::parseScript($text);
            self::fail('must be refused');
        } catch (RuleProfileException $e) {
            self::assertSame(['script-form'], $e->codes());
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidCases(): array
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/rule-profiles/invalid-cases.json'), true, 512, JSON_THROW_ON_ERROR);
        $out = [];

        foreach ($data['cases'] as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('invalidCases')]
    public function testSharedRefusalCases(array $case): void
    {
        // ⚠️ Decoded into objects, so that `{}` stays an object and the operations work as in JS.
        $doc = json_decode(self::profileText((string) $case['base']), false, 512, JSON_THROW_ON_ERROR);

        foreach ($case['ops'] as $op) {
            $parts = explode('.', (string) $op['path']);
            $last = array_pop($parts);
            $parent = $doc;

            foreach ($parts as $k) {
                $parent = is_array($parent) ? $parent[(int) $k] : $parent->{$k};
            }

            $apply = static function (mixed &$slot) use ($op): void {
                if ($op['op'] === 'set') {
                    $slot = json_decode((string) json_encode($op['value']), false);
                } elseif ($op['op'] === 'nest') {
                    for ($i = 0; $i < $op['levels']; $i++) {
                        $slot = (object) ['node' => 'ParenExpr', 'inner' => $slot];
                    }
                }
            };

            // Lists are PHP arrays (copied by value), objects are handles - walk back in for arrays.
            if (is_array($parent)) {
                self::fail('a case path must end in an object field: ' . $op['path']);
            }

            if ($op['op'] === 'delete') {
                unset($parent->{$last});
                continue;
            }

            $slot = $parent->{$last} ?? null;
            $apply($slot);
            $parent->{$last} = $slot;
        }

        try {
            RuleProfileValidator::validate(json_encode($doc, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            self::fail('must be refused: ' . $case['name']);
        } catch (RuleProfileException $e) {
            $codes = $e->codes();
            $expected = $case['codes'];
            sort($codes);
            sort($expected);

            self::assertSame($expected, $codes, $e->getMessage());
        }
    }

    public function testEditionsAreComparedAsNumbers(): void
    {
        self::assertSame(1, VersionRange::compare('3.0.10', '3.0.9'));
        self::assertSame(0, VersionRange::compare('1.09.2', '1.9.2'));
        self::assertSame(0, VersionRange::compare('3.0', '3.0.0'));
        self::assertTrue(VersionRange::contains('3.0.2', '>=3.0.2 <3.1'));
        self::assertFalse(VersionRange::contains('3.1.0', '>=3.0.2 <3.1'));
        self::assertTrue(VersionRange::contains('1.3.16', '1.3.x'));
        self::assertFalse(VersionRange::contains('1.4.0', '1.3.x'));
        self::assertFalse(VersionRange::contains('1.3.16', '1.3.15'));
        self::assertTrue(VersionRange::contains('7', '*'));
        self::assertFalse(VersionRange::contains('3.0.2', '^3.0'));
    }

    /**
     * The overlay on the XRechnung CII example: a replaced rule is counted and listed, an own rule
     * fires, the other edition is noticed, the UBL profile says it does not apply - and all of it is
     * marked in the JSON and the HTML report.
     */
    public function testTheOverlayIsVisibleInBothReports(): void
    {
        $profiles = RuleProfileLoader::fromDirectory(self::fixtures() . '/vampire-profiles');
        $result = self::validator($profiles)->validateFile(self::corpus() . '/XRechnung/01.13a-INVOICE_uncefact.xml');
        $json = (new JsonReport())->toArray($result);

        self::assertTrue($json['accepted']);
        self::assertTrue($json['officialAccepted']);
        self::assertContains('profile:kunde-lockerung@2.1', $json['rulesets']);
        self::assertNotContains('profile:muster-ust@1.0.0', $json['rulesets']);

        $byRule = [];

        foreach ($json['findings'] as $f) {
            $byRule[$f['rule'] ?? '-'][] = $f;
        }

        self::assertArrayNotHasKey('BR-DE-TMP-32', $byRule, 'a replaced official rule is not a finding');
        self::assertSame('profile', $byRule['KUNDE-UST-010'][0]['origin']);
        self::assertSame('kunde-lockerung@2.1', $byRule['KUNDE-UST-010'][0]['profile']);
        self::assertSame('warning', $byRule['KUNDE-UST-010'][0]['severity']);

        $notices = array_column($byRule['-'], 'text');
        self::assertContains('Regelprofil muster-ust@1.0.0 gilt nicht für dieses Szenario; es ist für XRechnung-UBL >=3.0.2 <3.1, EN16931-UBL 1.3.x geschrieben.', $notices);
        self::assertContains(
            'Regelprofil kunde-lockerung@2.1 wurde für EN16931-CII 1.3.15 geschrieben, geprüft wurde mit EN16931-CII@1.3.16. Ob seine Regeln und Abschaltungen zu dieser Fassung passen, ist nicht geprüft.',
            $notices
        );

        $entry = $json['profiles'][1];
        self::assertTrue($entry['applied']);
        self::assertSame(['CII-SR-475', 'BR-DE-TMP-32'], array_column($entry['disabled'], 'rule'));
        self::assertSame([0, 1], array_column($entry['disabled'], 'suppressed'));
        self::assertSame('KUNDE-UST-010', $entry['disabled'][1]['replacedBy']);

        $html = (new HtmlReport())->render($result);
        self::assertStringContainsString('<h2>Regelprofile</h2>', $html);
        self::assertStringContainsString('BR-DE-TMP-32 ersetzt durch KUNDE-UST-010', $html);
        self::assertStringContainsString('Amtliche Regelwerke allein: angenommen', $html);
    }

    public function testARuleThatThrowsIsReportedWithItsOwnSeverity(): void
    {
        $doc = json_decode(self::profileText('muster-ust'), true, 512, JSON_THROW_ON_ERROR);
        $doc['rules'][1]['test'] = [
            'node' => 'BinaryOp', 'op' => '>',
            'left' => ['node' => 'FunctionCall', 'name' => ['prefix' => 'xs', 'local' => 'decimal'], 'args' => [['node' => 'Literal', 'kind' => 'string', 'value' => 'kein Betrag']]],
            'right' => ['node' => 'Literal', 'kind' => 'integer', 'value' => '0'],
        ];

        $result = self::validator([RuleProfileValidator::validate($doc)])
            ->validateFile(self::corpus() . '/XRechnung/01.13a-INVOICE_ubl.xml');

        $found = array_values(array_filter($result->messages(), static fn ($m): bool => $m->ruleId === 'MUSTER-UST-010'));

        self::assertCount(1, $found);
        self::assertSame('warning', $found[0]->flag->value);
        self::assertSame('profile', $found[0]->origin());
        self::assertStringStartsWith('Die Regel MUSTER-UST-010 aus Regelprofil muster-ust@1.0.0 konnte nicht ausgewertet werden: ', $found[0]->text);
        self::assertTrue($result->isAccepted());
    }

    public function testAFileWhoseNameDiffersFromTheProfileIsRefused(): void
    {
        $tmp = sys_get_temp_dir() . '/rule-profile-' . bin2hex(random_bytes(4));
        mkdir($tmp);

        try {
            copy(self::fixtures() . '/muster-ust.json', "$tmp/anders.json");
            $this->expectException(RuleProfileException::class);
            RuleProfileLoader::fromFile("$tmp/anders.json");
        } finally {
            @unlink("$tmp/anders.json");
            @rmdir($tmp);
        }
    }

    /**
     * ⚠️ The JS parity test compares against a recording of this side. Three documents of it are
     * checked here against a fresh run, so that a change on this side that makes the recording stale
     * fails HERE, where the change was made, and not later in the other package.
     *
     * @return array<string, array{0: string}>
     */
    public static function recordedDocuments(): array
    {
        return [
            'XRechnung UBL' => ['XRechnung/01.13a-INVOICE_ubl.xml'],
            'XRechnung CII' => ['XRechnung/01.13a-INVOICE_uncefact.xml'],
            'Peppol, with an error' => ['UBL/allowance-example_fehler.xml'],
        ];
    }

    #[DataProvider('recordedDocuments')]
    public function testTheRecordingForVampireJsIsCurrent(string $rel): void
    {
        if (!is_file(Corpus::jsRecordings() . '/rule-profiles.json')) {
            self::markTestSkipped('VAMPIRE_JS_RECORDINGS ist nicht gesetzt.');
        }

        $recording = json_decode(
            (string) file_get_contents(Corpus::jsRecordings() . '/rule-profiles.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_file(self::corpus() . '/' . $rel)) {
            self::markTestSkipped("$rel is not in the corpus");
        }

        $r = (new JsonReport())->toArray(
            self::validator(RuleProfileLoader::fromDirectory(self::fixtures() . '/vampire-profiles'))->validateFile(self::corpus() . '/' . $rel)
        );

        $findings = array_map(
            static fn (array $f): string => implode('|', [$f['severity'], $f['rule'] ?? '', $f['origin'], $f['profile'] ?? '', $f['severityBy'] ?? '', implode('+', $f['rulesets'])]),
            $r['findings']
        );
        sort($findings);

        self::assertSame($recording['documents'][$rel]['findings'], $findings);
        self::assertSame($recording['documents'][$rel]['officialAccepted'], $r['officialAccepted']);
    }
}

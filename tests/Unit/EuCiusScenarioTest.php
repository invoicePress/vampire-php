<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Task 8.2 — every EU-CIUS document reaches the scenario it belongs to.
 *
 * ⚠️ This test asserts **resolution**, not acceptance, and that is the whole point. Which rules a
 * document breaks depends on the rule VERSION it is measured against, and the sample documents these
 * modules ship belong to older versions than the rule sets we run (numbers and causes in
 * `docs/developer/eu-cius.md`). Which scenario a document lands in does not depend on that at all - it depends
 * only on the document's own customization id, and getting it wrong means checking a Romanian invoice
 * against Belgian rules without anyone noticing.
 *
 * ⚠️ The order of the scenarios is under test here too, implicitly but decisively: the Italian AGID
 * id BEGINS with the Peppol Billing id, and the Peppol scenario matches with `starts-with()`. Were the
 * Peppol configuration read first, every Italian invoice would land in "Peppol Billing BIS 3" and this
 * test would say so.
 */
final class EuCiusScenarioTest extends TestCase
{
    /** Fixture group => the scenario every document in it must resolve to. */
    private const GROUPS = [
        'phive-cius-ro' => 'CIUS-RO eFactura (UBL Invoice)',
        'phive-cius-pt' => 'CIUS-PT (UBL Invoice)',
        'phive-ublbe' => 'UBL.BE (UBL Invoice)',
        'phive-cius-rs' => 'CIUS-RS srbdt (UBL Invoice)',
        'phive-agid-it' => 'Peppol Italia AGID (UBL Invoice)',
    ];

    private static function baseDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function documentProvider(): array
    {
        $cases = [];

        foreach (self::GROUPS as $group => $scenario) {
            $files = glob(dirname(__DIR__) . "/fixtures/invoices/$group/*.{xml,XML}", GLOB_BRACE);
            // ⚠️ A group whose rule set is not part of this package carries no fixtures either; it is left out.
            if ($files === false || $files === []) {
                continue;
            }

            sort($files);

            foreach ($files as $file) {
                $cases["$group/" . basename($file)] = [$file, $scenario];
            }
        }

        return $cases;
    }

    #[DataProvider('documentProvider')]
    public function testTheDocumentResolvesToItsOwnScenario(string $file, string $expected): void
    {
        $validator = new Validator(
            scenarioResolver: ScenarioResolver::fromJsonFile(self::baseDir() . '/rulesets/scenarios.json'),
            baseDir: self::baseDir(),
        );

        $result = $validator->validateFile($file);

        self::assertSame($expected, $result->scenarioName());
    }
}

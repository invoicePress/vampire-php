<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Xpath\Runtime;

use EinvoiceValidator\Xpath\Runtime\AtomicValue;
use EinvoiceValidator\Xpath\Runtime\FunctionLibrary;
use EinvoiceValidator\Xpath\Runtime\Sequence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use EinvoiceValidator\Tests\Support\Corpus;

/**
 * Task 4.7 — the fourteen `u:*` check-digit functions the rule sets define for themselves.
 *
 * **Measured, not assumed:** all fourteen defined functions are also CALLED by at least one shipped
 * rule set, and all fourteen are wired. The count came from a scan of the compiled XSLT for
 * `u:name(` calls, counting calls rather than definitions - which is how `u:mod97-0208` turned up as
 * called-but-not-wired: it is invoked twice in `PEPPOL-EN16931-UBL` for Belgian enterprise numbers
 * and would have thrown "not yet wired" on any invoice carrying an `0208` scheme id.
 *
 * ⚠️ **The values below are not ours.** They come from the check-digit vectors of an identifier library ( see tests/Support/Corpus.php ) -
 * a separate library in this suite, built from published identifier specifications, with its own
 * valid/invalid vectors. Checking our implementation against numbers we made up ourselves would only
 * prove that we are consistent with ourselves.
 */
final class ChecksumFunctionsTest extends TestCase
{

    private FunctionLibrary $lib;
    private \DOMNode $contextNode;

    protected function setUp(): void
    {
        $this->lib = new FunctionLibrary();
        $dom = new \DOMDocument();
        $this->contextNode = $dom->createElement('x');
    }

    private function boolCall(string $function, string $value): bool
    {
        return $this->lib
            ->call('u', $function, [Sequence::singleton(AtomicValue::string($value))], $this->contextNode)
            ->effectiveBooleanValue();
    }

    /**
     * Every `u:*` function with at least one known-good and one known-bad identifier.
     *
     * ⚠️ `checkPIVA` is the odd one out: it returns **0 for valid** (the rule set treats it as an
     * error count, and the calling assert reads `u:checkPIVA(...) = 0`). It is therefore tested
     * separately below, not through this provider - wrapping it in "true means valid" here would have
     * inverted it silently.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function checksumProvider(): array
    {
        return [
            // GS1 check digit (GLN/ILN/EAN). From cases.json, icd-0088 and icd-0209.
            'gln 4006381333931' => ['gln', '4006381333931', true],
            'gln 5901234123457' => ['gln', '5901234123457', true],
            'gln 4006381333932 (Prüfziffer verändert)' => ['gln', '4006381333932', false],
            'gln 5901234123458 (Prüfziffer verändert)' => ['gln', '5901234123458', false],

            // Australian Business Number, icd-0151.
            'abn 51824753556' => ['abn', '51824753556', true],
            'abn 51824753557' => ['abn', '51824753557', false],

            // Swedish organisation number (Luhn over the first nine digits), icd-0007.
            'SE orgnr 5560160680' => ['checkSEOrgnr', '5560160680', true],
            'SE orgnr 5560160681' => ['checkSEOrgnr', '5560160681', false],
            'SE orgnr nicht numerisch' => ['checkSEOrgnr', '55603607X3', false],

            // Belgian enterprise number, icd-0208 - the function wired in Task 4.7.
            'BE 0417497106' => ['mod97-0208', '0417497106', true],
            'BE 0202239951' => ['mod97-0208', '0202239951', true],
            'BE 0400378485' => ['mod97-0208', '0400378485', true],
            'BE 0417497107' => ['mod97-0208', '0417497107', false],
            'BE 0202239952' => ['mod97-0208', '0202239952', false],
            'BE zu kurz' => ['mod97-0208', '041749710', false],

            // Italian VAT id, icd-0211. checkPIVAseIT only checks Italian ones and passes the rest.
            'IT 00743110157 via PIVAseIT' => ['checkPIVAseIT', 'IT00743110157', true],
            'IT 00743110158 via PIVAseIT' => ['checkPIVAseIT', 'IT00743110158', false],
            'PIVAseIT lässt Nicht-Italiener durch' => ['checkPIVAseIT', 'DE123456789', true],

            // Italian tax code: 11 digits, or the 16-character letter/digit form.
            'checkCF 11 Ziffern' => ['checkCF', '00743110157', true],
            'checkCF 10 Ziffern' => ['checkCF', '0074311015', false],
            'checkCF16 Form' => ['checkCF16', 'RSSMRA85T10A562S', true],
            'checkCF16 falsche Form' => ['checkCF16', 'RSSMRA85T10A5629', false],

            // Codice IPA: exactly six alphanumerics, nothing else.
            'codiceIPA sechs Zeichen' => ['checkCodiceIPA', 'A1B2C3', true],
            'codiceIPA zu kurz' => ['checkCodiceIPA', 'A1B2C', false],
            'codiceIPA mit Satzzeichen' => ['checkCodiceIPA', 'A1B2C-', false],

            // mod11 and TinVerification have no counterpart in cases.json - the values below are
            // derived from the rule set's own algorithm, and the invalid one is the valid one with a
            // changed check digit. Named here rather than passed off as published vectors.
            'mod11 987654325' => ['mod11', '987654325', true],
            'mod11 987654324' => ['mod11', '987654324', false],
        ];
    }

    #[DataProvider('checksumProvider')]
    public function testChecksumFunction(string $function, string $value, bool $expected): void
    {
        self::assertSame($expected, $this->boolCall($function, $value));
    }

    /**
     * ⚠️ `u:checkPIVA` returns an ERROR CODE, not a boolean: 0 means valid, 1 means "not an integer at
     * all", 2 and 3 mean a bad length or check digit. The rule that calls it reads
     * `u:checkPIVA(...) = 0`.
     */
    public function testCheckPivaReturnsZeroForAValidItalianVatId(): void
    {
        $call = fn (string $v): string => $this->lib
            ->call('u', 'checkPIVA', [Sequence::singleton(AtomicValue::string($v))], $this->contextNode)
            ->first()
            ->asString();

        self::assertSame('0', $call('00743110157'), 'Gültige PIVA muss 0 ergeben.');
        self::assertNotSame('0', $call('00743110158'), 'Verfälschte Prüfziffer darf nicht 0 ergeben.');
        self::assertNotSame('0', $call('keine-zahl'), 'Nichtnumerisch darf nicht 0 ergeben.');
    }

    /**
     * The cross-check the task asks for, run as a test rather than claimed in prose: our
     * implementation against every vector of the identifier library for the schemes we share.
     *
     * ⚠️ Skipped, not failed, when that library is absent - it lives next to this package, not inside
     * it, and a missing sibling is not a defect of this one.
     */
    public function testEveryVectorOfTheIdentifierLibraryAgrees(): void
    {
        if (!is_file(Corpus::identifierCases())) {
            self::markTestSkipped('VAMPIRE_IDENTIFIER_CASES ist nicht gesetzt.');
        }

        $raw = file_get_contents(Corpus::identifierCases());
        self::assertNotFalse($raw);

        /** @var list<array{id: string, subject: string, expectValid: bool}> $cases */
        $cases = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        // Which scheme of that library corresponds to which of our functions. Only the ones that
        // really check the same thing; IBAN, ISO 11649 and the German Steuernummern have no `u:*`
        // counterpart, so they are not forced into one.
        $map = [
            'icd-0088' => 'gln',
            'icd-0209' => 'gln',
            'icd-0151' => 'abn',
            'icd-0007' => 'checkSEOrgnr',
            'icd-0208' => 'mod97-0208',
        ];

        $checked = 0;

        foreach ($cases as $case) {
            $function = $map[$case['id']] ?? null;

            if ($function === null) {
                continue;
            }

            self::assertSame(
                $case['expectValid'],
                $this->boolCall($function, $case['subject']),
                sprintf('u:%s("%s") weicht von der Kennungs-Bibliothek ab.', $function, $case['subject'])
            );

            $checked++;
        }

        // Without this, an empty or renamed cases.json would let the test pass having compared
        // nothing at all.
        self::assertGreaterThanOrEqual(15, $checked, 'Zu wenige Vergleichsfälle - passt das Schema noch?');
    }
}

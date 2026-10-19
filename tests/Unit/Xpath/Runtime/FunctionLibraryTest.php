<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Xpath\Runtime;

use EinvoiceValidator\Exception\UnsupportedConstructException;
use EinvoiceValidator\Xpath\Runtime\AtomicValue;
use EinvoiceValidator\Xpath\Runtime\Decimal;
use EinvoiceValidator\Xpath\Runtime\FunctionLibrary;
use EinvoiceValidator\Xpath\Runtime\Sequence;
use PHPUnit\Framework\TestCase;

final class FunctionLibraryTest extends TestCase
{
    private FunctionLibrary $lib;
    private \DOMNode $contextNode;

    protected function setUp(): void
    {
        $this->lib = new FunctionLibrary();
        $dom = new \DOMDocument();
        $this->contextNode = $dom->createElement('x', '  hello  ');
    }

    private function str(string $s): Sequence
    {
        return Sequence::singleton(AtomicValue::string($s));
    }

    public function testNormalizeSpace(): void
    {
        $result = $this->lib->call('', 'normalize-space', [$this->str('  a   b  ')], $this->contextNode);
        self::assertSame('a b', $result->first()->asString());
    }

    public function testNormalizeSpaceDefaultsToContextNode(): void
    {
        $result = $this->lib->call('', 'normalize-space', [], $this->contextNode);
        self::assertSame('hello', $result->first()->asString());
    }

    public function testUpperCase(): void
    {
        $result = $this->lib->call('', 'upper-case', [$this->str('abc')], $this->contextNode);
        self::assertSame('ABC', $result->first()->asString());
    }

    public function testConcat(): void
    {
        $result = $this->lib->call('', 'concat', [$this->str('a'), $this->str('b'), $this->str('c')], $this->contextNode);
        self::assertSame('abc', $result->first()->asString());
    }

    public function testStartsWithContainsEndsWith(): void
    {
        self::assertTrue($this->lib->call('', 'starts-with', [$this->str('hello'), $this->str('he')], $this->contextNode)->first()->asBoolean());
        self::assertTrue($this->lib->call('', 'contains', [$this->str('hello'), $this->str('ell')], $this->contextNode)->first()->asBoolean());
        self::assertTrue($this->lib->call('', 'ends-with', [$this->str('hello'), $this->str('lo')], $this->contextNode)->first()->asBoolean());
    }

    public function testSubstringAfterAndBefore(): void
    {
        self::assertSame('bc', $this->lib->call('', 'substring-after', [$this->str('a-bc'), $this->str('-')], $this->contextNode)->first()->asString());
        self::assertSame('a', $this->lib->call('', 'substring-before', [$this->str('a-bc'), $this->str('-')], $this->contextNode)->first()->asString());
    }

    public function testSubstringOneAndTwoArgument(): void
    {
        // fn:substring('abcde', 2) -> from position 2 (1-based) to the end -> "bcde".
        self::assertSame('bcde', $this->lib->call('', 'substring', [$this->str('abcde'), $this->str('2')], $this->contextNode)->first()->asString());
        // fn:substring('abcde', 2, 2) -> 2 characters starting at position 2 -> "bc".
        self::assertSame('bc', $this->lib->call('', 'substring', [$this->str('abcde'), $this->str('2'), $this->str('2')], $this->contextNode)->first()->asString());
    }

    public function testStringLength(): void
    {
        $result = $this->lib->call('', 'string-length', [$this->str('hello')], $this->contextNode);
        self::assertSame('5', $result->first()->asString());
    }

    public function testNotTrueFalseBoolean(): void
    {
        self::assertFalse($this->lib->call('', 'not', [Sequence::singleton(AtomicValue::boolean(true))], $this->contextNode)->first()->asBoolean());
        self::assertTrue($this->lib->call('', 'true', [], $this->contextNode)->first()->asBoolean());
        self::assertFalse($this->lib->call('', 'false', [], $this->contextNode)->first()->asBoolean());
    }

    public function testExists(): void
    {
        self::assertTrue($this->lib->call('', 'exists', [$this->str('x')], $this->contextNode)->first()->asBoolean());
        self::assertFalse($this->lib->call('', 'exists', [Sequence::empty()], $this->contextNode)->first()->asBoolean());
    }

    public function testCountAndSum(): void
    {
        $seq = Sequence::of([AtomicValue::decimal(Decimal::fromInt(1)), AtomicValue::decimal(Decimal::fromInt(2))]);
        self::assertSame('2', $this->lib->call('', 'count', [$seq], $this->contextNode)->first()->asString());
        self::assertSame('3', $this->lib->call('', 'sum', [$seq], $this->contextNode)->first()->asString());
    }

    public function testRoundAndAbs(): void
    {
        self::assertSame('3', $this->lib->call('', 'round', [$this->str('2.5')], $this->contextNode)->first()->asString());
        self::assertSame('5', $this->lib->call('', 'abs', [$this->str('-5')], $this->contextNode)->first()->asString());
    }

    /**
     * ⚠️ `fn:matches()` is a SUBSTRING test, not a whole-string one. The mistake is easy to make and hard
     * to see, because nearly every pattern in the rule corpus is written with explicit `^…$` — where
     * anchoring twice changes nothing.
     *
     * The three examples below are the spec's own (XPath 2.0 F&O 7.6.2). The fourth is the rule that
     * exposed it: Romania's `BR-RO-010` asks whether the invoice number CONTAINS a digit. Anchored,
     * that demanded an invoice number of exactly one digit, and the rule fired on all eleven Romanian
     * sample invoices while the reference validator reported it on none ( measured 21.09.2026 ).
     */
    public function testMatchesLooksForTheePatternAnywhereUnlessAnchored(): void
    {
        $matches = fn (string $subject, string $pattern): bool => $this->lib
            ->call('', 'matches', [$this->str($subject), $this->str($pattern)], $this->contextNode)
            ->first()
            ->asBoolean();

        self::assertTrue($matches('abracadabra', 'bra'), 'Teilstring genügt.');
        self::assertTrue($matches('abracadabra', '^a.*a$'), 'Mit Ankern wie erwartet.');
        self::assertFalse($matches('abracadabra', '^bra'), 'Ein gesetzter Anker gilt.');

        self::assertTrue($matches('abc', 'bc'), 'Teilstring, kein Gesamttreffer nötig.');
        self::assertTrue($matches('abc', 'a.c'));
        self::assertTrue($matches('12345', '\d+'));

        // BR-RO-010 in plain words: "does the invoice number contain a digit?"
        self::assertTrue($matches('123456XX', '([0-9])'), 'Rechnungsnummer mit Ziffern.');
        self::assertFalse($matches('ABCDEF', '([0-9])'), 'Rechnungsnummer ganz ohne Ziffer.');
    }

    /**
     * Task 8.2 — ⚠️ CIUS-PT 2.1.1 really contains `^(.{1,6826666})$`. PCRE caps a `{n,m}` upper bound
     * at 65535 and refuses to compile beyond it; `preg_match()` then returns `false`, which
     * `fn:matches()` would report as "does not match" for EVERY document. The rule asserting the
     * pattern fires on perfectly fine invoices, and nothing says why.
     */
    public function testMatchesSurvivesAQuantifierBiggerThanPcreAllows(): void
    {
        $pattern = '^(.{1,6826666})$';

        $result = $this->lib->call('', 'matches', [$this->str('irgendein Text'), $this->str($pattern)], $this->contextNode);
        self::assertTrue($result->effectiveBooleanValue(), 'Ein normaler Wert muss passen.');

        $empty = $this->lib->call('', 'matches', [$this->str(''), $this->str($pattern)], $this->contextNode);
        self::assertFalse($empty->effectiveBooleanValue(), 'Die untere Grenze 1 gilt weiter.');

        // And the upper bound does not disappear, it only moves out of the pattern.
        $short = $this->lib->call('', 'matches', [$this->str('abcd'), $this->str('^(.{1,70000})$')], $this->contextNode);
        self::assertTrue($short->effectiveBooleanValue());

        $tooLong = $this->lib->call(
            '',
            'matches',
            [$this->str(str_repeat('x', 70001)), $this->str('^(.{1,70000})$')],
            $this->contextNode
        );
        self::assertFalse($tooLong->effectiveBooleanValue(), 'Zu lang bleibt zu lang.');
    }

    /**
     * Task 8.2 — `fn:index-of()` is 1-based and returns a SEQUENCE. Called by CIUS-PT.
     */
    public function testIndexOfReturnsEveryOneBasedPosition(): void
    {
        $haystack = Sequence::of([
            AtomicValue::string('a'),
            AtomicValue::string('b'),
            AtomicValue::string('a'),
        ]);

        $result = $this->lib->call('', 'index-of', [$haystack, $this->str('a')], $this->contextNode);
        self::assertSame(['1', '3'], array_map(static fn ($v) => $v->asString(), $result->items()));

        $none = $this->lib->call('', 'index-of', [$haystack, $this->str('z')], $this->contextNode);
        self::assertTrue($none->isEmpty(), 'Kein Treffer heißt leere Sequenz, nicht 0 und nicht -1.');
    }

    /**
     * Task 8.2 — `fn:data()` atomises. Called by UBL.BE.
     */
    public function testDataAtomisesNodesAndValues(): void
    {
        $dom = new \DOMDocument();
        $dom->loadXML('<r><a>1</a><a>2</a></r>');
        $nodes = Sequence::of(iterator_to_array($dom->documentElement?->childNodes ?? []));

        $result = $this->lib->call('', 'data', [$nodes], $this->contextNode);

        self::assertSame(['1', '2'], array_map(static fn ($v) => $v->asString(), $result->items()));
    }

    public function testReplace(): void
    {
        $result = $this->lib->call('', 'replace', [$this->str('2024-01-15'), $this->str('-'), $this->str('/')], $this->contextNode);
        self::assertSame('2024/01/15', $result->first()->asString());
    }

    public function testTokenize(): void
    {
        $result = $this->lib->call('', 'tokenize', [$this->str('a b  c'), $this->str('\s+')], $this->contextNode);
        self::assertSame(['a', 'b', 'c'], array_map(static fn ($v) => $v->asString(), $result->items()));
    }

    public function testDistinctValues(): void
    {
        $seq = Sequence::of([AtomicValue::string('a'), AtomicValue::string('b'), AtomicValue::string('a')]);
        $result = $this->lib->call('', 'distinct-values', [$seq], $this->contextNode);
        self::assertSame(2, $result->count());
    }

    public function testUnknownFunctionThrows(): void
    {
        $this->expectException(UnsupportedConstructException::class);
        $this->lib->call('', 'no-such-function', [], $this->contextNode);
    }

    public function testAnUnwiredCustomExtensionFunctionStillThrows(): void
    {
        $this->expectException(UnsupportedConstructException::class);
        $this->lib->call('u', 'noSuchChecksum', [], $this->contextNode);
    }

    /**
     * The checksum helpers the Peppol BIS rule set calls. Each case carries a value whose validity is
     * decided by the algorithm itself, not by this implementation: the valid GLNs and ABNs below are
     * published examples, the invalid ones are the same value with the check digit changed.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function checksumProvider(): array
    {
        return [
            'gln valid' => ['gln', '4035600210708', true],
            'gln wrong check digit' => ['gln', '4035600210709', false],
            'mod11 valid' => ['mod11', '987654325', true],
            'mod11 wrong check digit' => ['mod11', '987654324', false],
            'abn valid' => ['abn', '51824753556', true],
            'abn wrong' => ['abn', '51824753557', false],
            'codiceIPA six alphanumerics' => ['checkCodiceIPA', 'A1B2C3', true],
            'codiceIPA too short' => ['checkCodiceIPA', 'A1B2C', false],
            'codiceIPA with punctuation' => ['checkCodiceIPA', 'A1B2C-', false],
            'checkCF 11 digits' => ['checkCF', '12345678901', true],
            'checkCF wrong length' => ['checkCF', '1234567890', false],
            'SE orgnr valid' => ['checkSEOrgnr', '5560360793', true],
            'SE orgnr wrong check digit' => ['checkSEOrgnr', '5560360792', false],
            'SE orgnr not numeric' => ['checkSEOrgnr', '55603607X3', false],
            'PIVAseIT passes non-italian' => ['checkPIVAseIT', 'DE123456789', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('checksumProvider')]
    public function testChecksumFunction(string $function, string $value, bool $expected): void
    {
        $result = $this->lib->call('u', $function, [Sequence::singleton(AtomicValue::string($value))], $this->contextNode);

        self::assertSame($expected, $result->effectiveBooleanValue());
    }

    public function testNumberReturnsTheEmptySequenceForNonNumericInput(): void
    {
        $result = $this->lib->call('', 'number', [Sequence::singleton(AtomicValue::string('abc'))], $this->contextNode);

        self::assertTrue($result->isEmpty());
        self::assertFalse($result->effectiveBooleanValue());
    }

    public function testNumberParsesADecimalString(): void
    {
        $result = $this->lib->call('', 'number', [Sequence::singleton(AtomicValue::string(' 42.50 '))], $this->contextNode);

        // Decimal normalises the lexical form; 42.50 and 42.5 are the same value.
        self::assertSame('42.5', $result->atomize()[0]->asDecimal()->toString());
    }

    public function testDocumentIsRefusedWithoutABaseDirectory(): void
    {
        $this->expectException(UnsupportedConstructException::class);
        $this->lib->call('', 'document', [Sequence::singleton(AtomicValue::string('codes.xml'))], $this->contextNode);
    }

    public function testDocumentRefusesAPathThatLeavesTheRulesetDirectory(): void
    {
        $lib = new FunctionLibrary(dirname(__DIR__, 4) . '/rulesets/codedb');

        $this->expectException(UnsupportedConstructException::class);
        $lib->call('', 'document', [Sequence::singleton(AtomicValue::string('../../composer.json'))], $this->contextNode);
    }

    public function testDocumentLoadsAShippedCodeList(): void
    {
        $lib = new FunctionLibrary(dirname(__DIR__, 4) . '/rulesets/codedb');

        $result = $lib->call(
            '',
            'document',
            [Sequence::singleton(AtomicValue::string('FACTUR-X_EN16931_codedb.xml'))],
            $this->contextNode
        );

        self::assertFalse($result->isEmpty());
    }

    // --- the nine functions VAMPIRE JS had and this library did not ---------------------------------

    private function dec(string $lexical): Sequence
    {
        return Sequence::singleton(AtomicValue::decimal(Decimal::fromLexical($lexical)));
    }

    /** @param list<string> $lexicals */
    private function decs(array $lexicals): Sequence
    {
        return Sequence::of(array_map(
            static fn (string $l): AtomicValue => AtomicValue::decimal(Decimal::fromLexical($l)),
            $lexicals
        ));
    }

    private function one(string $local, Sequence ...$args): string
    {
        $result = $this->lib->call('', $local, $args, $this->contextNode);
        self::assertSame(1, $result->count(), "$local() must yield exactly one item");

        return $result->first()?->asString() ?? '';
    }

    /**
     * ⚠️ `floor()` and `ceiling()` round towards minus and plus infinity, NOT towards zero. A version
     * that truncated would be right for every positive amount and wrong for every credit note.
     */
    public function testFloorAndCeiling(): void
    {
        self::assertSame('2', $this->one('floor', $this->dec('2.9')));
        self::assertSame('-3', $this->one('floor', $this->dec('-2.1')));
        self::assertSame('2', $this->one('floor', $this->dec('2')));
        self::assertSame('3', $this->one('ceiling', $this->dec('2.1')));
        self::assertSame('-2', $this->one('ceiling', $this->dec('-2.9')));
        self::assertSame('2', $this->one('ceiling', $this->dec('2')));
        // An untyped node value ( `ceiling(cbc:Amount)` ) is cast like everywhere else.
        self::assertSame('5', $this->one('ceiling', $this->str('4.01')));
    }

    public function testFloorAndCeilingOfTheEmptySequenceAreEmpty(): void
    {
        self::assertTrue($this->lib->call('', 'floor', [Sequence::empty()], $this->contextNode)->isEmpty());
        self::assertTrue($this->lib->call('', 'ceiling', [Sequence::empty()], $this->contextNode)->isEmpty());
    }

    public function testMinAndMax(): void
    {
        self::assertSame('-1.5', $this->one('min', $this->decs(['3', '-1.5', '2'])));
        self::assertSame('3', $this->one('max', $this->decs(['3', '-1.5', '2'])));
        // Numeric, not lexical: "10" is larger than "9" although it sorts before it as a string.
        self::assertSame('10', $this->one('max', Sequence::of([AtomicValue::string('9'), AtomicValue::string('10')])));
        self::assertSame('7', $this->one('min', $this->dec('7')));
    }

    public function testMinAndMaxOfTheEmptySequenceAreEmpty(): void
    {
        self::assertTrue($this->lib->call('', 'min', [Sequence::empty()], $this->contextNode)->isEmpty());
        self::assertTrue($this->lib->call('', 'max', [Sequence::empty()], $this->contextNode)->isEmpty());
    }

    public function testEmpty(): void
    {
        self::assertSame('true', $this->one('empty', Sequence::empty()));
        self::assertSame('false', $this->one('empty', $this->str('')), 'an empty STRING is still one item');
        self::assertSame('false', $this->one('empty', Sequence::singleton($this->contextNode)));
    }

    public function testLowerCase(): void
    {
        self::assertSame('abc', $this->one('lower-case', $this->str('AbC')));
        self::assertSame("\u{00E4}\u{00F6}\u{00FC}", $this->one('lower-case', $this->str("\u{00C4}\u{00D6}\u{00DC}")));
        self::assertSame('', $this->one('lower-case', Sequence::empty()));
    }

    /**
     * `position()` and `last()` read the focus the interpreter passes in; without one, both are 1.
     */
    public function testPositionAndLastReadTheFocus(): void
    {
        self::assertSame('1', $this->one('position'));
        self::assertSame('1', $this->one('last'));
        self::assertSame('2', $this->lib->call('', 'position', [], $this->contextNode, 2, 5)->first()?->asString());
        self::assertSame('5', $this->lib->call('', 'last', [], $this->contextNode, 2, 5)->first()?->asString());
    }

    public function testNamespaceUri(): void
    {
        $dom = new \DOMDocument();
        $dom->loadXML('<cac:Root xmlns:cac="urn:cac" plain="1"><child/></cac:Root>');
        $root = $dom->documentElement;
        self::assertNotNull($root);
        $child = $root->firstChild;
        self::assertNotNull($child);

        self::assertSame('urn:cac', $this->one('namespace-uri', Sequence::singleton($root)));
        self::assertSame('', $this->one('namespace-uri', Sequence::singleton($child)), 'no namespace');
        self::assertSame('', $this->one('namespace-uri', Sequence::empty()));
        // Without an argument, the context node.
        self::assertSame('urn:cac', $this->lib->call('', 'namespace-uri', [], $root)->first()?->asString());
        $attr = $root->getAttributeNode('plain');
        self::assertInstanceOf(\DOMAttr::class, $attr);
        self::assertSame('', $this->one('namespace-uri', Sequence::singleton($attr)));
    }

    public function testCodepointsToString(): void
    {
        self::assertSame('Hi', $this->one('codepoints-to-string', $this->decs(['72', '105'])));
        self::assertSame('', $this->one('codepoints-to-string', Sequence::empty()));
        // One codepoint above the Basic Multilingual Plane is one character, not two halves.
        self::assertSame("\u{1D11E}", $this->one('codepoints-to-string', $this->dec('119070')));
    }

    public function testCodepointsToStringRefusesANumberThatIsNoCodepoint(): void
    {
        $this->expectException(UnsupportedConstructException::class);
        $this->expectExceptionMessage('FOCH0001');
        $this->lib->call('', 'codepoints-to-string', [$this->dec('1114112')], $this->contextNode);
    }

    /**
     * ⚠️ A surrogate is no character. `mb_chr()` refuses it; JavaScript's `String.fromCodePoint()`
     * does not, which is why VAMPIRE JS checks the range itself.
     */
    public function testCodepointsToStringRefusesASurrogate(): void
    {
        $this->expectException(UnsupportedConstructException::class);
        $this->expectExceptionMessage('FOCH0001');
        $this->lib->call('', 'codepoints-to-string', [$this->dec('55296')], $this->contextNode);
    }

    public function testCodepointsToStringRefusesAFraction(): void
    {
        $this->expectException(UnsupportedConstructException::class);
        $this->lib->call('', 'codepoints-to-string', [$this->dec('65.5')], $this->contextNode);
    }
}

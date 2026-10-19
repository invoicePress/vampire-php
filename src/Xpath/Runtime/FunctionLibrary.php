<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Runtime;

use EinvoiceValidator\Exception\UnsupportedConstructException;

/**
 * Tasks 2.3.4/2.3.5 — the built-in function library, scoped to exactly what
 * docs/notes/xpath-whitelist.md lists as found in the real rule corpus (no more, no less). Dispatched
 * by qualified name from the Interpreter (Task 2.4). Every function receives already-evaluated
 * `Sequence` arguments and returns a `Sequence`.
 *
 * Regex functions (`matches`/`replace`/`tokenize`) translate XSD regex syntax to PCRE - the two
 * are close but not identical; see `translateXsdRegexToPcre()` for the concrete differences this
 * class actually had to handle (found empirically against the real rule corpus, not assumed).
 */
final class FunctionLibrary
{
    /** @var array<string, \DOMDocument> absolute path => parsed document, one parse per run */
    private array $documentCache = [];

    /**
     * @param string|null $documentBaseDir directory `fn:document()` resolves its argument against.
     *                                     Null forbids the function outright.
     */
    public function __construct(private readonly ?string $documentBaseDir = null)
    {
    }

    /**
     * @param Sequence[] $args
     * @param int $position the context position, 1-based - only `fn:position()` reads it
     * @param int $size the context size - only `fn:last()` reads it
     *
     * ⚠️ Position and size come from the Interpreter, which knows which predicate is running. The
     * defaults ( 1 and 1 ) are the focus of a single context item outside any predicate.
     */
    public function call(
        string $prefix,
        string $local,
        array $args,
        \DOMNode $contextNode,
        int $position = 1,
        int $size = 1
    ): Sequence {
        // Custom u:* extension functions (checksum algorithms etc., defined as xsl:function in
        // the compiled XSLT itself, see docs/notes/xpath-inventory.md). Only the ones actually CALLED
        // from a real assert/let in the two rulesets this project currently ships (XRechnung-UBL/
        // CII) are wired, reproducing their xsl:function body verbatim (Regel A.10: never copy
        // foreign code, but this is the ruleset's OWN definition, not a third-party library) -
        // measured via grep, not assumed: the other ~12 defined-but-unused u:* functions (Italian
        // tax-code/PIVA checks, GLN, mod11, ABN, ...) are never called by either real ruleset, so
        // they correctly stay "not yet wired" rather than being guessed at without a real rule to
        // verify against (Regel A.6/E.3).
        if ($prefix === 'u') {
            return match ($local) {
                'slack' => $this->uSlack($args),
                'decimalOrZero' => $this->uDecimalOrZero($args),
                // Task 4.1a: the Peppol BIS 3.0.21 rule set really calls these - measured, not
                // assumed (u:TinVerification 12x, u:mod11 4x, u:checkCF 4x, u:checkSEOrgnr 4x,
                // u:checkPIVAseIT 2x, u:abn 2x, u:gln 2x, u:checkCodiceIPA 2x, u:checkCF16 1x,
                // u:checkPIVA 1x). Each one below reproduces that rule set's OWN xsl:function body.
                'gln' => Sequence::singleton(AtomicValue::boolean($this->uGln($this->stringArg($args, 0)))),
                'mod11' => Sequence::singleton(AtomicValue::boolean($this->uMod11($this->stringArg($args, 0)))),
                // Task 4.7: really called - 3x in PEPPOL-EN16931-UBL, 1x each in XRechnung UBL/CII.
                'mod97-0208' => Sequence::singleton(AtomicValue::boolean($this->uMod97Belgian($this->stringArg($args, 0)))),
                // Task 8.2: Croatia (CIUS-HR-UBL@1.0.3) defines two of its own.
                'ctrlOIB' => Sequence::singleton(AtomicValue::boolean($this->uCtrlOib($this->stringArg($args, 0)))),
                'check3010' => Sequence::singleton(AtomicValue::boolean($this->uCheck3010($this->stringArg($args, 0)))),
                'abn' => Sequence::singleton(AtomicValue::boolean($this->uAbn($this->stringArg($args, 0)))),
                'TinVerification' => Sequence::singleton(AtomicValue::boolean($this->uTinVerification($this->stringArg($args, 0)))),
                'checkSEOrgnr' => Sequence::singleton(AtomicValue::boolean($this->uCheckSeOrgnr($this->stringArg($args, 0)))),
                'checkCodiceIPA' => Sequence::singleton(AtomicValue::boolean($this->uCheckCodiceIpa($this->stringArg($args, 0)))),
                'checkCF' => Sequence::singleton(AtomicValue::boolean($this->uCheckCf($this->stringArg($args, 0)))),
                'checkCF16' => Sequence::singleton(AtomicValue::boolean($this->uCheckCf16($this->stringArg($args, 0)))),
                'checkPIVAseIT' => Sequence::singleton(AtomicValue::boolean($this->uCheckPivaSeIt($this->stringArg($args, 0)))),
                'checkPIVA' => Sequence::singleton(AtomicValue::integer(Decimal::fromInt($this->uCheckPiva($this->stringArg($args, 0))))),
                'addPIVA' => Sequence::singleton(AtomicValue::integer(Decimal::fromInt(
                    $this->uAddPiva($this->stringArg($args, 0), (int) $this->stringArg($args, 1))
                ))),
                default => throw new UnsupportedConstructException(
                    "custom function u:$local (not yet wired)",
                    "u:$local(...)",
                    0
                ),
            };
        }

        // xs:decimal(...)/xs:date(...)/xs:integer(...)/xs:string(...) used as callable type
        // constructors (not the "cast as" keyword syntax, which the Interpreter handles
        // separately) - 45+ occurrences of xs:decimal(...) alone in the real corpus.
        if ($prefix === 'xs') {
            return $this->typeConstructor($local, $this->arg($args, 0));
        }

        $name = $prefix === '' || $prefix === 'fn' ? $local : "$prefix:$local";

        return match ($name) {
            'not' => $this->fnNot($args),
            'true' => Sequence::singleton(AtomicValue::boolean(true)),
            'false' => Sequence::singleton(AtomicValue::boolean(false)),
            'boolean' => $this->fnBoolean($args),
            'exists' => Sequence::singleton(AtomicValue::boolean(!$this->arg($args, 0)->isEmpty())),
            'normalize-space' => $this->fnNormalizeSpace($args, $contextNode),
            'upper-case' => Sequence::singleton(AtomicValue::string(mb_strtoupper($this->stringArg($args, 0)))),
            'string-length' => $this->fnStringLength($args, $contextNode),
            'concat' => $this->fnConcat($args),
            'substring' => $this->substring($args),
            'substring-after' => $this->fnSubstringAfter($args),
            'substring-before' => $this->fnSubstringBefore($args),
            'contains' => $this->fnContains($args),
            'starts-with' => $this->fnStartsWith($args),
            'ends-with' => $this->fnEndsWith($args),
            'string' => Sequence::singleton(AtomicValue::string($this->stringArgOrContext($args, 0, $contextNode))),
            'string-join' => Sequence::singleton(AtomicValue::string($this->stringJoin($args))),
            'translate' => $this->fnTranslate($args),
            'string-to-codepoints' => $this->stringToCodepoints($this->stringArg($args, 0)),
            'matches' => $this->fnMatches($args),
            'replace' => $this->fnReplace($args),
            'tokenize' => $this->tokenize($this->stringArg($args, 0), $this->stringArg($args, 1)),
            'count' => Sequence::singleton(AtomicValue::integer(Decimal::fromInt($this->arg($args, 0)->count()))),
            'sum' => $this->fnSum($args),
            'round' => $this->fnRound($args),
            'abs' => $this->fnAbs($args),
            'distinct-values' => $this->distinctValues($this->arg($args, 0)),
            // The ten names below follow VAMPIRE JS ( `src/runtime/FunctionLibrary.mjs` ). No rule set
            // of the corpus calls them except `last()`; they are here so that a rule set or rule profile
            // valid in one engine does not abort in the other.
            'last' => Sequence::singleton(AtomicValue::integer(Decimal::fromInt($size))),
            'position' => Sequence::singleton(AtomicValue::integer(Decimal::fromInt($position))),
            'empty' => Sequence::singleton(AtomicValue::boolean($this->arg($args, 0)->isEmpty())),
            'lower-case' => Sequence::singleton(AtomicValue::string(
                mb_strtolower($this->stringArgOrContext($args, 0, $contextNode))
            )),
            'floor' => $this->fnFloorOrCeiling($args, false),
            'ceiling' => $this->fnFloorOrCeiling($args, true),
            'min' => $this->fnMinOrMax($args, -1),
            'max' => $this->fnMinOrMax($args, 1),
            'namespace-uri' => Sequence::singleton(AtomicValue::string(
                // `??` reads like isset(): a missing node and a node without namespace both give ''.
                $this->nodeArgOrContext($args, 0, $contextNode)->namespaceURI ?? ''
            )),
            'codepoints-to-string' => $this->fnCodepointsToString($args),
            'name' => $this->fnName($args, $contextNode),
            'local-name' => $this->fnLocalName($args, $contextNode),
            'number' => $this->fnNumber($args, $contextNode),
            'document' => $this->fnDocument($args),
            // Task 8.2. `data()` atomises, `index-of()` returns the 1-based positions of the matches.
            'data' => $this->fnData($args, $contextNode),
            'index-of' => $this->fnIndexOf($args),
            default => throw new UnsupportedConstructException("unknown function \"$name\"", "$name(...)", 0),
        };
    }

    /**
     * @param Sequence[] $args
     */
    private function fnNot(array $args): Sequence
    {
        return Sequence::singleton(AtomicValue::boolean(!$this->arg($args, 0)->effectiveBooleanValue()));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnBoolean(array $args): Sequence
    {
        return Sequence::singleton(AtomicValue::boolean($this->arg($args, 0)->effectiveBooleanValue()));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnNormalizeSpace(array $args, \DOMNode $contextNode): Sequence
    {
        $normalized = $this->normalizeSpace($this->stringArgOrContext($args, 0, $contextNode));

        return Sequence::singleton(AtomicValue::string($normalized));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnStringLength(array $args, \DOMNode $contextNode): Sequence
    {
        $length = mb_strlen($this->stringArgOrContext($args, 0, $contextNode));

        return Sequence::singleton(AtomicValue::integer(Decimal::fromInt($length)));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnConcat(array $args): Sequence
    {
        $strings = array_map(fn (Sequence $s): string => $this->singleAtomicString($s), $args);

        return Sequence::singleton(AtomicValue::string(implode('', $strings)));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnSubstringAfter(array $args): Sequence
    {
        $result = $this->substringAfter($this->stringArg($args, 0), $this->stringArg($args, 1));

        return Sequence::singleton(AtomicValue::string($result));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnSubstringBefore(array $args): Sequence
    {
        $result = $this->substringBefore($this->stringArg($args, 0), $this->stringArg($args, 1));

        return Sequence::singleton(AtomicValue::string($result));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnContains(array $args): Sequence
    {
        $result = str_contains($this->stringArg($args, 0), $this->stringArg($args, 1));

        return Sequence::singleton(AtomicValue::boolean($result));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnStartsWith(array $args): Sequence
    {
        $result = str_starts_with($this->stringArg($args, 0), $this->stringArg($args, 1));

        return Sequence::singleton(AtomicValue::boolean($result));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnEndsWith(array $args): Sequence
    {
        $result = str_ends_with($this->stringArg($args, 0), $this->stringArg($args, 1));

        return Sequence::singleton(AtomicValue::boolean($result));
    }

    /**
     * @param Sequence[] $args
     */
    /**
     * `fn:translate($value, $map, $trans)` — replace characters, and DELETE the ones whose position in
     * `$map` has no counterpart in `$trans`.
     *
     * ⚠️ PHP's three-argument `strtr()` is not the same function: it ignores the surplus characters
     * instead of deleting them. That difference is invisible whenever both arguments are equally long,
     * and the first four rule sets never used the deleting form. Peppol's PEPPOL-COMMON-R042 does:
     * `string-length(translate(substring(...), '1234567890', '')) = 0` is the idiom "are these characters
     * all digits", and with `strtr()` it returned the input unchanged, so a perfectly valid Danish CVR
     * number was reported as malformed. Measured on `vat-category-E.xml`: `translate('12345678',
     * '1234567890', '')` gave `12345678` instead of the empty string.
     *
     * @param Sequence[] $args
     */
    private function fnTranslate(array $args): Sequence
    {
        $value = $this->stringArg($args, 0);
        $map = mb_str_split($this->stringArg($args, 1));
        $trans = mb_str_split($this->stringArg($args, 2));

        $result = '';

        foreach (mb_str_split($value) as $char) {
            $index = array_search($char, $map, true);

            if ($index === false) {
                $result .= $char;                      // not mentioned in $map: kept as is
                continue;
            }

            if (isset($trans[$index])) {
                $result .= $trans[$index];             // has a counterpart: replaced
            }
            // no counterpart: deleted - this is the branch strtr() gets wrong
        }

        return Sequence::singleton(AtomicValue::string($result));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnReplace(array $args): Sequence
    {
        $result = $this->replace($this->stringArg($args, 0), $this->stringArg($args, 1), $this->stringArg($args, 2));

        return Sequence::singleton(AtomicValue::string($result));
    }

    /**
     * @param Sequence[] $args
     */
    /**
     * `fn:number($arg?)` — the XPath 1.0 companion of `xs:decimal()`, used throughout the Factur-X
     * 1.09.2 rule sets (Task 4.1b) where the EN16931/XRechnung ones use `xs:decimal()`.
     *
     * ⚠️ It does NOT throw on non-numeric input; XPath says the result is NaN, and every comparison
     * against NaN is false. This runtime has no NaN, so non-numeric input yields the EMPTY SEQUENCE,
     * whose comparisons are false as well and whose effective boolean value is false - the same
     * outcome for every shape these rules use it in (`number(x) = y`, `number(x) > 0`, `not(...)`).
     * Throwing instead would abort a whole validation over a document that merely carries a
     * malformed amount, which is exactly what the rule is there to report.
     *
     * @param Sequence[] $args
     */
    private function fnNumber(array $args, \DOMNode $contextNode): Sequence
    {
        $value = $args === []
            ? $contextNode->textContent
            : implode('', array_map(static fn (AtomicValue $v): string => $v->asString(), $this->arg($args, 0)->atomize()));

        $value = trim($value);

        if (!is_numeric($value)) {
            return Sequence::empty();
        }

        return Sequence::singleton(AtomicValue::decimal(Decimal::fromLexical($value)));
    }

    /**
     * `fn:document($uri)` — loads a side file that ships WITH the rule set. The Factur-X 1.09.2 rule
     * sets (Task 4.1b) keep their code lists in `FACTUR-X_*_codedb.xml` and reference it 420 times in
     * the EXTENDED set alone; without this function those rule sets cannot run at all.
     *
     * ⚠️ Rule A.4 says no network access at runtime, so this is deliberately NOT a URI resolver: only
     * a plain file name is accepted, it is resolved inside the configured directory, the result must
     * still be inside it after realpath() (no `../` escape), and libxml runs with LIBXML_NONET and
     * without external entities. Anything else is refused rather than fetched.
     *
     * @param Sequence[] $args
     */
    private function fnDocument(array $args): Sequence
    {
        $name = $this->stringArg($args, 0);

        if ($this->documentBaseDir === null) {
            throw new UnsupportedConstructException(
                'fn:document() is not available without a document base directory',
                "document('$name')",
                0
            );
        }

        if ($name === '' || basename($name) !== $name) {
            throw new UnsupportedConstructException(
                'fn:document() accepts a plain file name from the ruleset directory only',
                "document('$name')",
                0
            );
        }

        $path = $this->documentBaseDir . '/' . $name;
        $real = realpath($path);
        $base = realpath($this->documentBaseDir);

        if ($real === false || $base === false || !str_starts_with($real, $base)) {
            throw new UnsupportedConstructException(
                "fn:document() cannot resolve \"$name\" inside the ruleset directory",
                "document('$name')",
                0
            );
        }

        if (!isset($this->documentCache[$real])) {
            $dom = new \DOMDocument();
            $dom->load($real, LIBXML_NONET | LIBXML_NOENT);
            $this->documentCache[$real] = $dom;
        }

        return Sequence::singleton($this->documentCache[$real]);
    }

    /**
     * @param Sequence[] $args
     */
    private function fnSum(array $args): Sequence
    {
        $decimals = array_map(fn (AtomicValue $v): Decimal => $v->asDecimal(), $this->arg($args, 0)->atomize());

        return Sequence::singleton(AtomicValue::decimal(Decimal::sum($decimals)));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnMatches(array $args): Sequence
    {
        $matches = $this->matches($this->stringArg($args, 0), $this->stringArg($args, 1));

        return Sequence::singleton(AtomicValue::boolean($matches));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnRound(array $args): Sequence
    {
        return Sequence::singleton(AtomicValue::decimal($this->singleAtomicDecimal($this->arg($args, 0))->round()));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnAbs(array $args): Sequence
    {
        $value = Decimal::abs($this->singleAtomicDecimal($this->arg($args, 0)));

        return Sequence::singleton(AtomicValue::decimal($value));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnName(array $args, \DOMNode $contextNode): Sequence
    {
        return Sequence::singleton(AtomicValue::string($this->name($this->nodeArgOrContext($args, 0, $contextNode))));
    }

    /**
     * @param Sequence[] $args
     */
    private function fnLocalName(array $args, \DOMNode $contextNode): Sequence
    {
        $node = $this->nodeArgOrContext($args, 0, $contextNode);

        return Sequence::singleton(AtomicValue::string($this->localName($node)));
    }

    /**
     * `fn:floor()` and `fn:ceiling()`: the largest integer not above, or the smallest not below.
     *
     * ⚠️ Towards minus and plus INFINITY, not towards zero: `floor(-2.1)` is -3 and `ceiling(-2.9)` is
     * -2. A truncation would be right for every positive amount and wrong for every negative one.
     *
     * The empty sequence gives the empty sequence. With more than one item the first one counts -
     * XPath would raise a type error there; VAMPIRE JS takes the first item, and so does this side, so
     * that both engines answer alike.
     *
     * @param Sequence[] $args
     */
    private function fnFloorOrCeiling(array $args, bool $ceiling): Sequence
    {
        $atomized = $this->arg($args, 0)->atomize();

        if ($atomized === []) {
            return Sequence::empty();
        }

        $value = $atomized[0]->asDecimal();
        $floor = $value->floor();

        if ($ceiling && !$floor->equals($value)) {
            $floor = $floor->add(Decimal::fromInt(1));
        }

        return Sequence::singleton(AtomicValue::decimal($floor));
    }

    /**
     * `fn:min()` / `fn:max()` over numbers; `$want` is -1 for the minimum and 1 for the maximum.
     *
     * ⚠️ NUMERIC ONLY, like VAMPIRE JS: every item is read as xs:decimal, so `max(('9', '10'))` is 10.
     * XPath would compare strings as strings; a string that is no number throws here, exactly as
     * `asDecimal()` does everywhere else. The empty sequence gives the empty sequence.
     *
     * @param Sequence[] $args
     */
    private function fnMinOrMax(array $args, int $want): Sequence
    {
        $values = array_map(
            static fn (AtomicValue $v): Decimal => $v->asDecimal(),
            $this->arg($args, 0)->atomize()
        );

        if ($values === []) {
            return Sequence::empty();
        }

        $best = $values[0];

        foreach ($values as $value) {
            if ($value->compareTo($best) === $want) {
                $best = $value;
            }
        }

        return Sequence::singleton(AtomicValue::decimal($best));
    }

    /**
     * `fn:codepoints-to-string()`: the inverse of `string-to-codepoints()`.
     *
     * ⚠️ A CODEPOINT, NOT A UTF-16 UNIT AND NOT A BYTE: 119070 is the one character U+1D11E. `mb_chr()`
     * gives exactly that; `chr()` would return one byte, and a JavaScript `String.fromCharCode()` two
     * halves. A number that is no codepoint ( above U+10FFFF, a surrogate, or a fraction ) is
     * err:FOCH0001, raised here instead of producing a broken string.
     *
     * @param Sequence[] $args
     */
    private function fnCodepointsToString(array $args): Sequence
    {
        $result = '';

        foreach ($this->arg($args, 0)->atomize() as $item) {
            $lexical = $item->asDecimal()->toString();
            $char = preg_match('/^\d{1,7}$/', $lexical) === 1 ? mb_chr((int) $lexical, 'UTF-8') : false;

            if ($char === false) {
                throw new UnsupportedConstructException(
                    "codepoints-to-string(): $lexical is not a valid codepoint (err:FOCH0001)",
                    'codepoints-to-string(...)',
                    0
                );
            }

            $result .= $char;
        }

        return Sequence::singleton(AtomicValue::string($result));
    }

    private function typeConstructor(string $type, Sequence $arg): Sequence
    {
        $atomized = $arg->atomize();
        if ($atomized === []) {
            return Sequence::empty();
        }
        $value = $atomized[0];

        return match ($type) {
            'decimal' => Sequence::singleton(AtomicValue::decimal($value->asDecimal())),
            'integer' => Sequence::singleton(AtomicValue::integer($value->asDecimal())),
            'string' => Sequence::singleton(AtomicValue::string($value->asString())),
            // lexical-form pass-through, see Interpreter::castToDate()'s own docblock
            'date' => Sequence::singleton($value),
            default => throw new UnsupportedConstructException(
                "unsupported type constructor xs:$type",
                "xs:$type(...)",
                0
            ),
        };
    }

    /**
     * @param Sequence[] $args
     */
    private function arg(array $args, int $index): Sequence
    {
        return $args[$index] ?? Sequence::empty();
    }

    /**
     * Task 8.2 — `fn:data($arg)`: the atomised value of a sequence. Called by UBL.BE.
     *
     * ⚠️ Atomising a node means its **string value**, and for an element that is the concatenation of
     * all descendant text. It is emphatically NOT "the node itself, later coerced": a comparison
     * against an un-atomised node would use node identity in some places and string value in others.
     * Without an argument, `data()` atomises the context node - the same defaulting as
     * `string-length()`.
     *
     * @param Sequence[] $args
     */
    private function fnData(array $args, \DOMNode $contextNode): Sequence
    {
        if ($args === []) {
            return Sequence::singleton(AtomicValue::untypedAtomic($contextNode->textContent ?? ''));
        }

        // `Sequence::atomize()` already does exactly this, node by node - reimplementing it here
        // would be a second place where "what is a node's value" is decided.
        return Sequence::of($this->arg($args, 0)->atomize());
    }

    /**
     * Task 8.2 — `fn:index-of($seq, $search)`: the 1-BASED positions at which `$search` occurs.
     * Called by CIUS-PT.
     *
     * ⚠️ One-based, and it returns a SEQUENCE, not a single number: a value occurring three times
     * yields three positions, and a value not occurring yields the empty sequence (not 0, and not -1).
     * A rule written as `index-of(...) = 1` therefore reads "occurs in first position", while
     * `exists(index-of(...))` reads "occurs at all".
     *
     * @param Sequence[] $args
     */
    private function fnIndexOf(array $args): Sequence
    {
        $needle = $this->arg($args, 1)->atomize();

        if ($needle === []) {
            return Sequence::empty();
        }

        $search = $needle[0]->asString();
        $positions = [];
        $position = 0;

        foreach ($this->arg($args, 0)->atomize() as $item) {
            $position++;

            if ($item->asString() === $search) {
                $positions[] = AtomicValue::integer(Decimal::fromInt($position));
            }
        }

        return Sequence::of($positions);
    }

    /**
     * Task 8.2 — `u:ctrlOIB($oib)`, the Croatian OIB check digit (ISO 7064 MOD 11,10), verbatim from
     * `HR-CIUS-EXT-EN16931-UBL.xslt`'s own `xsl:function`.
     *
     * ⚠️ Two details the rule set spells out and that are easy to lose:
     *   - an `HR` prefix is optional and, when present, skipped (`substring($oib, 3, 1)` instead of 1),
     *   - the running remainder starts at 10, and **whenever an intermediate sum is divisible by 10 the
     *     10 is used in its place** - a plain `(sum mod 10) * 2 mod 11` silently produces 0 there and
     *     validates the wrong numbers.
     * Finally, a computed check digit of 10 counts as 0.
     */
    private function uCtrlOib(string $oib): bool
    {
        $oib = trim($oib);
        $digits = str_starts_with($oib, 'HR') ? substr($oib, 2) : $oib;

        if (preg_match('/^[0-9]{11}$/', $digits) !== 1) {
            return false;
        }

        $remainder = 10;

        for ($i = 0; $i < 10; $i++) {
            $sum = ((int) $digits[$i] + $remainder) % 10;
            $remainder = (($sum === 0 ? 10 : $sum) * 2) % 11;
        }

        $check = 11 - $remainder;

        return ($check === 10 ? 0 : $check) === (int) $digits[10];
    }

    /**
     * Task 8.2 — `u:check3010($broj)`, verbatim from the Croatian rule set: fewer than 30 characters
     * in total and at most 10 digits after the decimal point.
     *
     * ⚠️ `substring-after($broj, '.')` is the EMPTY STRING when there is no dot at all, so a value
     * without decimals passes the second condition - as it should.
     */
    private function uCheck3010(string $broj): bool
    {
        return mb_strlen($broj) < 30 && mb_strlen((string) strstr($broj, '.')) - 1 <= 10;
    }

    /**
     * `u:slack($exp, $val, $slack)`, verbatim from the real XRechnung-UBL/CII ruleset's own
     * `xsl:function` body: `xs:decimal($exp + $slack) >= $val and xs:decimal($exp - $slack) <=
     * $val` - a tolerance comparison, true iff `$val` lies within `[$exp-$slack, $exp+$slack]`.
     * Used by real rules reconciling a calculated amount against a stated one with a currency-
     * dependent rounding tolerance (`$slackValue`, a global `let` - Task 3.1).
     *
     * @param Sequence[] $args
     */
    private function uSlack(array $args): Sequence
    {
        $exp = $this->singleDecimalArg($args, 0);
        $val = $this->singleDecimalArg($args, 1);
        $slack = $this->singleDecimalArg($args, 2);

        $holds = $exp->add($slack)->compareTo($val) >= 0 && $exp->subtract($slack)->compareTo($val) <= 0;

        return Sequence::singleton(AtomicValue::boolean($holds));
    }

    /**
     * `u:decimalOrZero($element)`, verbatim from the real XRechnung-CII ruleset's own
     * `xsl:function` body: `if (boolean($element)) then xs:decimal($element) else 0` - the
     * `boolean(...)` here is `fn:boolean()`'s SEQUENCE-level effective-boolean-value rule (a
     * sequence starting with a node is always true, regardless of that node's own text), i.e.
     * "does this optional element exist"; 0 otherwise.
     *
     * @param Sequence[] $args
     */
    private function uDecimalOrZero(array $args): Sequence
    {
        $element = $this->arg($args, 0);
        if (!$element->effectiveBooleanValue()) {
            return Sequence::singleton(AtomicValue::decimal(Decimal::zero()));
        }

        return $this->typeConstructor('decimal', $element);
    }

    /**
     * `u:gln($val)` — GS1 check digit (also used for ILN/EAN). Verbatim from the Peppol rule set's own
     * xsl:function: digits reversed, weights alternating 3 and 1, check digit = (10 - sum mod 10) mod 10.
     *
     * ⚠️ The XSLT uses `substring($val, 0, $length + 1)`, i.e. a start index of ZERO. XPath's substring
     * is 1-based and clamps a 0 start, so that expression means "all characters except the last one".
     * Translating it as `substr($val, 0, $length)` in PHP is the same thing - but only because PHP's
     * offset is 0-based. Getting this off by one silently turns every GLN check into nonsense.
     */
    private function uGln(string $val): bool
    {
        $length = mb_strlen($val) - 1;

        if ($length < 1) {
            return false;
        }

        $digits = array_reverse(array_map('intval', mb_str_split(mb_substr($val, 0, $length))));
        $sum = 0;

        foreach ($digits as $i => $digit) {
            $sum += $digit * (1 + ((($i + 1) % 2) * 2));
        }

        return (10 - ($sum % 10)) % 10 === (int) mb_substr($val, $length, 1);
    }

    /** `u:mod11($val)` — weights 2..7 cycling, check digit = (11 - sum mod 11) mod 11. */
    private function uMod11(string $val): bool
    {
        $length = mb_strlen($val) - 1;

        if ($length < 1 || (float) $val <= 0) {
            return false;
        }

        $digits = array_reverse(array_map('intval', mb_str_split(mb_substr($val, 0, $length))));
        $sum = 0;

        foreach ($digits as $i => $digit) {
            $sum += $digit * (($i % 6) + 2);
        }

        return (11 - ($sum % 11)) % 11 === (int) mb_substr($val, $length, 1);
    }

    /**
     * `u:mod97-0208($val)` — Belgian enterprise number (ICD 0208). The rule set's own body:
     *
     *   checkdigits        := substring($val, 9, 2)
     *   calculated_digits  := 97 - (xs:integer(substring($val, 1, 8)) mod 97)
     *   result             := number($checkdigits) = number($calculated_digits)
     *
     * ⚠️ This one was **defined but not wired** until Task 4.7, and unlike the other dormant helpers
     * it really is called: twice in `PEPPOL-EN16931-UBL`, in the rule that checks an `0208` scheme id.
     * (The XRechnung rule sets define it too but never call it.) A Belgian party with such an id would
     * have run into "custom function u:mod97-0208 (not yet wired)" - a hard error on a perfectly
     * ordinary invoice. Nothing in the test bed carries that scheme id, which is why nobody noticed;
     * the measurement that found it counted CALLS, not definitions.
     */
    private function uMod97Belgian(string $val): bool
    {
        // The XSLT would raise a type error on a non-numeric argument; the calling rule guards with
        // `matches(…, '^[0-9]{10}$')` first, and this guard keeps that promise if a caller does not.
        if (preg_match('/^[0-9]{10}$/', $val) !== 1) {
            return false;
        }

        return (int) substr($val, 8, 2) === 97 - ((int) substr($val, 0, 8) % 97);
    }

    /** `u:abn($val)` — Australian Business Number: first digit minus 1, weights 1,3,5,…,19, mod 89. */
    private function uAbn(string $val): bool
    {
        if (mb_strlen($val) !== 11) {
            return false;
        }

        $weights = [10, 1, 3, 5, 7, 9, 11, 13, 15, 17, 19];
        $sum = 0;

        foreach (mb_str_split($val) as $i => $char) {
            $digit = mb_ord($char) - ($i === 0 ? 49 : 48);
            $sum += $digit * ($i === 0 ? 10 : $weights[$i]);
        }

        return $sum % 89 === 0;
    }

    /** `u:TinVerification($val)` — weights 256,128,…,2 over the first eight digits, (sum mod 11) mod 10. */
    private function uTinVerification(string $val): bool
    {
        if (mb_strlen($val) < 9) {
            return false;
        }

        $digits = array_map('intval', mb_str_split($val));
        $checksum = 0;

        foreach ([256, 128, 64, 32, 16, 8, 4, 2] as $i => $weight) {
            $checksum += $digits[$i] * $weight;
        }

        return ($checksum % 11) % 10 === $digits[8];
    }

    /** `u:checkSEOrgnr($number)` — Luhn over the first nine digits of a Swedish organisation number. */
    private function uCheckSeOrgnr(string $number): bool
    {
        if (preg_match('/^\d+$/', $number) !== 1 || mb_strlen($number) < 10) {
            return false;
        }

        $main = mb_substr($number, 0, 9);
        $check = (int) mb_substr($number, 9, 1);
        $sum = 0;

        for ($pos = 1; $pos <= 9; $pos++) {
            $digit = (int) mb_substr($main, 9 - $pos, 1);
            $sum += $pos % 2 === 1 ? intdiv($digit * 2, 10) + (($digit * 2) % 10) : $digit;
        }

        return (10 - $sum % 10) % 10 === $check;
    }

    /** `u:checkCodiceIPA($arg)` — exactly six alphanumeric characters. */
    private function uCheckCodiceIpa(string $arg): bool
    {
        return mb_strlen($arg) === 6 && preg_match('/^[A-Za-z0-9]{6}$/', $arg) === 1;
    }

    /** `u:checkCF($arg)` — Italian tax code: either the 16-character form or 11 digits. */
    private function uCheckCf(string $arg): bool
    {
        $length = mb_strlen($arg);

        if ($length === 16) {
            return $this->uCheckCf16($arg);
        }

        return $length === 11 && preg_match('/^-?\d+$/', $arg) === 1;
    }

    /** `u:checkCF16($arg)` — the letter/digit shape of the 16-character Italian tax code. */
    private function uCheckCf16(string $arg): bool
    {
        if (mb_strlen($arg) !== 16) {
            return false;
        }

        return preg_match('/^[A-Za-z]{6}/', $arg) === 1
            && preg_match('/^-?\d+$/', mb_substr($arg, 6, 2)) === 1
            && preg_match('/^[A-Za-z]$/', mb_substr($arg, 8, 1)) === 1
            && preg_match('/^-?\d+$/', mb_substr($arg, 9, 2)) === 1
            && preg_match('/^-?\d+$/', mb_substr($arg, 14, 1)) === 1
            && preg_match('/^[A-Za-z]$/', mb_substr($arg, 15, 1)) === 1;
    }

    /** `u:checkPIVAseIT($arg)` — only Italian VAT ids are checked; anything else passes. */
    private function uCheckPivaSeIt(string $arg): bool
    {
        if (strtoupper(mb_substr($arg, 0, 2)) !== 'IT') {
            return true;
        }

        $code = mb_substr($arg, 2);

        return mb_strlen($code) === 11 && $this->uCheckPiva($code) === 0;
    }

    /** `u:checkPIVA($arg)` — 0 means valid; 1 when the argument is not an integer at all. */
    private function uCheckPiva(string $arg): int
    {
        if (preg_match('/^-?\d+$/', $arg) !== 1) {
            return 1;
        }

        return $this->uAddPiva($arg, 0) % 10;
    }

    /**
     * `u:addPIVA($arg, $pari)` — the recursive Luhn-like sum behind `u:checkPIVA`, digit by digit,
     * alternating between the plain digit and a doubled-and-folded one via the '0246813579' map.
     */
    private function uAddPiva(string $arg, int $pari): int
    {
        if ($arg === '' || preg_match('/^-?\d+$/', $arg) !== 1) {
            return 0;
        }

        $first = (int) mb_substr($arg, 0, 1);
        $mapped = $pari === 1 ? (int) mb_substr('0246813579', $first, 1) : $first;

        return $mapped + $this->uAddPiva(mb_substr($arg, 1), $pari === 0 ? 1 : 0);
    }

    /**
     * @param Sequence[] $args
     */
    private function singleDecimalArg(array $args, int $index): Decimal
    {
        $atomized = $this->arg($args, $index)->atomize();
        if (count($atomized) !== 1) {
            throw new UnsupportedConstructException('expected a single numeric value', 'u:slack', 0);
        }

        return $atomized[0]->asDecimal();
    }

    /**
     * @param Sequence[] $args
     */
    private function stringArg(array $args, int $index): string
    {
        return $this->singleAtomicString($this->arg($args, $index));
    }

    /**
     * fn:normalize-space/fn:string-length/fn:string with zero arguments operate on the context
     * item's string value - the "." default several call sites in the real corpus rely on.
     *
     * @param Sequence[] $args
     */
    private function stringArgOrContext(array $args, int $index, \DOMNode $contextNode): string
    {
        if (!isset($args[$index])) {
            return $contextNode->textContent ?? '';
        }

        return $this->singleAtomicString($args[$index]);
    }

    /**
     * @param Sequence[] $args
     */
    private function nodeArgOrContext(array $args, int $index, \DOMNode $contextNode): ?\DOMNode
    {
        if (!isset($args[$index])) {
            return $contextNode;
        }
        $item = $args[$index]->first();

        return $item instanceof \DOMNode ? $item : null;
    }

    private function name(?\DOMNode $node): string
    {
        return $node === null ? '' : $node->nodeName;
    }

    private function localName(?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        return $node instanceof \DOMElement || $node instanceof \DOMAttr ? $node->localName ?? '' : $node->nodeName;
    }

    private function singleAtomicString(Sequence $sequence): string
    {
        $atomized = $sequence->atomize();
        if ($atomized === []) {
            return '';
        }

        return $atomized[0]->asString();
    }

    private function singleAtomicDecimal(Sequence $sequence): Decimal
    {
        $atomized = $sequence->atomize();
        if ($atomized === []) {
            return Decimal::zero();
        }

        return $atomized[0]->asDecimal();
    }

    private function normalizeSpace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    /**
     * @param Sequence[] $args
     */
    private function substring(array $args): Sequence
    {
        $string = $this->stringArg($args, 0);
        // fn:substring's start/length are 1-based and use xs:double round-half-to-even semantics
        // on non-integer positions in the full spec; the real corpus only ever calls it with
        // already-integer literal positions, so this uses plain integer arithmetic rather than
        // implementing that generality speculatively.
        $start = (int) round((float) $this->stringArg($args, 1));
        $length = isset($args[2]) ? (int) round((float) $this->stringArg($args, 2)) : null;

        $phpStart = max(0, $start - 1);
        if ($length === null) {
            return Sequence::singleton(AtomicValue::string(mb_substr($string, $phpStart)));
        }

        $end = $start + $length - 1;
        $phpLength = max(0, $end - $phpStart);

        return Sequence::singleton(AtomicValue::string(mb_substr($string, $phpStart, $phpLength)));
    }

    private function substringAfter(string $haystack, string $needle): string
    {
        if ($needle === '') {
            return $haystack;
        }
        $pos = strpos($haystack, $needle);

        return $pos === false ? '' : substr($haystack, $pos + strlen($needle));
    }

    private function substringBefore(string $haystack, string $needle): string
    {
        if ($needle === '') {
            return '';
        }
        $pos = strpos($haystack, $needle);

        return $pos === false ? '' : substr($haystack, 0, $pos);
    }

    /**
     * @param Sequence[] $args
     */
    private function stringJoin(array $args): string
    {
        $items = array_map(fn (AtomicValue $v): string => $v->asString(), $this->arg($args, 0)->atomize());
        $separator = isset($args[1]) ? $this->stringArg($args, 1) : '';

        return implode($separator, $items);
    }

    private function stringToCodepoints(string $value): Sequence
    {
        // mb_ord() on PHP >= 8.0 throws \ValueError for an invalid sequence rather than
        // returning false (a PHP 8.0 behaviour change from earlier versions) - it genuinely
        // cannot return false against our PHP >= 8.2 floor, so there is nothing to guard here.
        $codepoints = [];
        $length = mb_strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($value, $i, 1);
            $codepoints[] = AtomicValue::integer(Decimal::fromInt(mb_ord($char)));
        }

        return Sequence::of($codepoints);
    }

    /**
     * `fn:matches($input, $pattern)` — true when `$input` **CONTAINS** a substring matching the
     * pattern. Anchoring happens only through an explicit `^` or `$` in the pattern itself.
     *
     * ⚠️ **This was wrong for a long time and nothing noticed.** The earlier implementation anchored
     * the pattern, on the reasoning that XPath matching follows XSD semantics. It does not: XSD's
     * `xs:pattern` FACET is implicitly anchored, `fn:matches()` is not. XPath 2.0 F&O 7.6.2 settles
     * it with its own examples - `fn:matches("abracadabra", "bra")` is **true**, and
     * `fn:matches("abracadabra", "^bra")` is false.
     *
     * Why it stayed hidden: almost every pattern in the rule corpus is written with explicit `^…$`,
     * and anchoring an already-anchored pattern changes nothing. The Romanian CIUS is the first rule
     * set here with an unanchored one - `matches(normalize-space(cbc:ID), '([0-9])')`, "the invoice
     * number must contain a digit". Anchored, that demands an invoice number consisting of exactly
     * one digit, so `BR-RO-010` fired on all eleven Romanian sample invoices. The reference validator
     * did not report it on a single one, which is how the difference surfaced (Task 4.6).
     */
    private function matches(string $subject, string $xsdPattern): bool
    {
        [$pattern, $maxLength] = self::relaxHugeQuantifiers($xsdPattern);

        if ($maxLength !== null && mb_strlen($subject) > $maxLength) {
            return false;
        }

        return preg_match('~' . self::escapeDelimiter($pattern) . '~u', $subject) === 1;
    }

    /**
     * Task 8.2 — PCRE refuses a `{n,m}` quantifier with m above 65535 ("number too big in {}
     * quantifier"), and XSD regex has no such limit.
     *
     * ⚠️ **This was not a theoretical worry.** CIUS-PT 2.1.1 really contains `^(.{1,6826666})$` — a
     * maximum length of nearly seven million characters. `preg_match()` returned `false` for it, PHP
     * raised a warning, and `fn:matches()` therefore answered "no match" for **every** document. A
     * rule asserting "the value matches this pattern" then fires on documents that are perfectly
     * fine, and nothing in the report hints at a broken regex.
     *
     * The rewrite drops the upper bound from the pattern and returns it separately, so the caller
     * enforces it as a plain length check - which is exactly what such a pattern means.
     *
     * @return array{string, ?int} the pattern PCRE can compile, and the largest upper bound removed
     */
    private static function relaxHugeQuantifiers(string $xsdPattern): array
    {
        $maxLength = null;

        $relaxed = preg_replace_callback(
            '/\{(\d+),(\d+)\}/',
            static function (array $m) use (&$maxLength): string {
                if ((int) $m[2] <= 65535) {
                    return $m[0];
                }

                $maxLength = max($maxLength ?? 0, (int) $m[2]);

                return '{' . $m[1] . ',}';
            },
            $xsdPattern
        );

        return [$relaxed ?? $xsdPattern, $maxLength];
    }

    private function replace(string $subject, string $xsdPattern, string $replacement): string
    {
        // XSD/XPath backreferences in the replacement use "$1", which happens to already be
        // PCRE's own syntax - no translation needed for the replacement string itself.
        $result = preg_replace(self::translateXsdRegexToPcre($xsdPattern), $replacement, $subject);

        return $result ?? $subject;
    }

    private function tokenize(string $subject, string $xsdPattern): Sequence
    {
        $parts = preg_split(self::translateXsdRegexToPcre($xsdPattern), $subject) ?: [$subject];

        return Sequence::of(array_map(static fn (string $p): AtomicValue => AtomicValue::string($p), $parts));
    }

    /**
     * XSD regex -> PCRE, unanchored (fn:replace()/fn:tokenize() match anywhere in the subject).
     * Verified empirically against the actual patterns found in the real rule corpus
     * (docs/notes/xpath-inventory.md's `fn:matches` examples), not the full XSD-regex grammar: XSD's
     * `\s`/`\d`/`\w` etc. character-class shorthands are a strict subset of PCRE's and need no
     * translation; the delimiter is "~" (not "/", which appears inside real patterns unescaped,
     * e.g. path-like content) with the `u` (Unicode) modifier for correct multi-byte handling.
     */
    private static function translateXsdRegexToPcre(string $xsdPattern): string
    {
        // Same quantifier limit as in matches(); here the upper bound is simply dropped, because
        // fn:replace()/fn:tokenize() search ANYWHERE in the subject and a length check on the whole
        // string would mean something else.
        [$pattern] = self::relaxHugeQuantifiers($xsdPattern);

        return '~' . self::escapeDelimiter($pattern) . '~u';
    }

    private static function escapeDelimiter(string $xsdPattern): string
    {
        return str_replace('~', '\~', $xsdPattern);
    }

    private function distinctValues(Sequence $sequence): Sequence
    {
        $seen = [];
        $result = [];
        foreach ($sequence->atomize() as $value) {
            $key = $value->asString();
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $value;
            }
        }

        return Sequence::of($result);
    }
}

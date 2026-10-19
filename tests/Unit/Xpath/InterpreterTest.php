<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Xpath;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Exception\UnsupportedConstructException;
use EinvoiceValidator\Xpath\Interpreter;
use PHPUnit\Framework\TestCase;

final class InterpreterTest extends TestCase
{
    private const NAMESPACES = ['cac' => 'urn:cac', 'cbc' => 'urn:cbc'];

    private function dom(string $xml): \DOMElement
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $root = $dom->documentElement;
        self::assertNotNull($root);

        return $root;
    }

    private function interpreter(): Interpreter
    {
        return new Interpreter(self::NAMESPACES);
    }

    public function testPositionalPredicateSelectsOnlyThatItem(): void
    {
        $root = $this->dom(
            '<cac:Root xmlns:cac="urn:cac"><cac:Item>a</cac:Item><cac:Item>b</cac:Item><cac:Item>c</cac:Item></cac:Root>'
        );

        $result = $this->interpreter()->evaluate(Parser::parse('cac:Item[2]'), $root);

        self::assertSame(1, $result->count());
        $first = $result->first();
        self::assertInstanceOf(\DOMNode::class, $first);
        self::assertSame('b', $first->textContent);
    }

    public function testArithmeticAddSubtractMultiplyDiv(): void
    {
        $root = $this->dom('<r xmlns="urn:x"/>');
        $i = $this->interpreter();

        self::assertSame('3', $i->evaluate(Parser::parse('1 + 2'), $root)->first()->asString());
        self::assertSame('-1', $i->evaluate(Parser::parse('1 - 2'), $root)->first()->asString());
        self::assertSame('6', $i->evaluate(Parser::parse('2 * 3'), $root)->first()->asString());
        self::assertSame('2', $i->evaluate(Parser::parse('4 div 2'), $root)->first()->asString());
        self::assertSame('1', $i->evaluate(Parser::parse('7 mod 3'), $root)->first()->asString());
        self::assertSame('2', $i->evaluate(Parser::parse('7 idiv 3'), $root)->first()->asString());
    }

    public function testForExpr(): void
    {
        $root = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item>1</cac:Item><cac:Item>2</cac:Item></cac:Root>');

        $result = $this->interpreter()->evaluate(Parser::parse('for $x in cac:Item return xs:decimal($x) * 2'), $root);

        self::assertSame(['2', '4'], array_map(static fn ($v) => $v->asString(), $result->items()));
    }

    public function testIfExpr(): void
    {
        $root = $this->dom('<r xmlns="urn:x"/>');

        self::assertSame('yes', $this->interpreter()->evaluate(Parser::parse("if (1 = 1) then 'yes' else 'no'"), $root)->first()->asString());
        self::assertSame('no', $this->interpreter()->evaluate(Parser::parse("if (1 = 2) then 'yes' else 'no'"), $root)->first()->asString());
    }

    public function testLetExpr(): void
    {
        $root = $this->dom('<r xmlns="urn:x"/>');

        $result = $this->interpreter()->evaluate(Parser::parse('let $x := 5 return $x + 1'), $root);

        self::assertSame('6', $result->first()->asString());
    }

    public function testQuantifiedExprSomeAndEvery(): void
    {
        $root = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item>1</cac:Item><cac:Item>2</cac:Item></cac:Root>');
        $i = $this->interpreter();

        self::assertTrue($i->evaluate(Parser::parse('some $x in cac:Item satisfies xs:decimal($x) = 2'), $root)->effectiveBooleanValue());
        self::assertFalse($i->evaluate(Parser::parse('every $x in cac:Item satisfies xs:decimal($x) = 2'), $root)->effectiveBooleanValue());
    }

    public function testCastableAsAndCastAs(): void
    {
        $root = $this->dom('<r xmlns="urn:x"/>');
        $i = $this->interpreter();

        self::assertTrue($i->evaluate(Parser::parse("'5' castable as xs:integer"), $root)->effectiveBooleanValue());
        self::assertFalse($i->evaluate(Parser::parse("'abc' castable as xs:integer"), $root)->effectiveBooleanValue());
        self::assertSame('5', $i->evaluate(Parser::parse("'5' cast as xs:string"), $root)->first()->asString());
    }

    /**
     * ⚠️ `cast as xs:boolean` must use the XML Schema lexical rule ({"true","1"}->true,
     * {"false","0"}->false, anything else a cast error), NOT effective-boolean-value's "any
     * non-empty string is true" - see AtomicValue::asBoolean()/castToBoolean() for the real bug
     * this distinction fixes (a "false"-valued node wrongly comparing equal to true()).
     */
    public function testCastAsBooleanUsesTheLexicalRuleNotEffectiveBooleanValue(): void
    {
        $root = $this->dom('<r xmlns="urn:x"/>');
        $i = $this->interpreter();

        self::assertFalse($i->evaluate(Parser::parse("'false' cast as xs:boolean"), $root)->first()->asBoolean());
        self::assertTrue($i->evaluate(Parser::parse("'true' cast as xs:boolean"), $root)->first()->asBoolean());
        self::assertFalse($i->evaluate(Parser::parse("'0' cast as xs:boolean"), $root)->first()->asBoolean());
        self::assertTrue($i->evaluate(Parser::parse("'1' cast as xs:boolean"), $root)->first()->asBoolean());
        self::assertFalse($i->evaluate(Parser::parse("'not-a-boolean' castable as xs:boolean"), $root)->effectiveBooleanValue());
    }

    /**
     * ⚠️ Real bug, found via the official XRechnung test suite (BR-CO-10 on invoice 01.13a): a
     * path step whose PRIMARY expression yields atomic values (a function-call step, e.g.
     * `xs:decimal(cbc:LineExtensionAmount)`) must keep every duplicate - the result is a plain
     * XPath SEQUENCE, not a node-set. `dedupeNodes()` wrongly deduped by value
     * (`'#' . $item->asString()`), collapsing three real "400" invoice-line amounts down to one and
     * making `sum(...)` under-count by 800 on a genuinely valid invoice.
     */
    public function testDuplicateAtomicValuesFromAFunctionCallStepAreAllKept(): void
    {
        $root = $this->dom(
            '<cac:Root xmlns:cac="urn:cac" xmlns:cbc="urn:cbc">'
            . '<cac:Item><cbc:Amount>400</cbc:Amount></cac:Item>'
            . '<cac:Item><cbc:Amount>240</cbc:Amount></cac:Item>'
            . '<cac:Item><cbc:Amount>400</cbc:Amount></cac:Item>'
            . '</cac:Root>'
        );

        $sequence = $this->interpreter()->evaluate(Parser::parse('cac:Item/xs:decimal(cbc:Amount)'), $root);
        self::assertSame(3, $sequence->count(), 'both "400" occurrences must be kept, not deduped to one');

        $sum = $this->interpreter()->evaluate(Parser::parse('sum(cac:Item/xs:decimal(cbc:Amount))'), $root);
        self::assertSame('1040', $sum->first()->asString());
    }

    /**
     * ⚠️ Real bug, found via the official XRechnung test suite (BR-CO-13 on every CII invoice
     * lacking the OPTIONAL `ram:AllowanceTotalAmount`): an arithmetic operator (`+`/`-`/`*`/`div`/
     * `idiv`/`mod`, and unary +/-) with an empty-sequence operand must propagate an EMPTY result
     * (XPath 2.0 §3.4), not throw - only a genuinely MULTI-item operand is still an error.
     */
    public function testArithmeticWithAnEmptyOperandPropagatesEmptyInsteadOfThrowing(): void
    {
        $root = $this->dom('<cac:Root xmlns:cac="urn:cac" xmlns:cbc="urn:cbc"><cbc:Amount>5</cbc:Amount></cac:Root>');
        $i = $this->interpreter();

        foreach (['+', '-', '*', 'div'] as $op) {
            $result = $i->evaluate(Parser::parse("xs:decimal(cbc:Amount) $op xs:decimal(cbc:Missing)"), $root);
            self::assertTrue($result->isEmpty(), "xs:decimal(cbc:Amount) $op xs:decimal(cbc:Missing) should be empty");
        }

        $unary = $i->evaluate(Parser::parse('-xs:decimal(cbc:Missing)'), $root);
        self::assertTrue($unary->isEmpty());

        // a genuinely present-but-multi-item operand must still be a real error, not silently
        // reduced to empty.
        $this->expectException(UnsupportedConstructException::class);
        $i->evaluate(Parser::parse('(1, 2) + 3'), $root);
    }

    /**
     * ⚠️ `idiv` truncates towards zero and `mod` takes the sign of the dividend ( XPath 2.0 F&O
     * §6.2.5 and §6.2.6 ). `floor()` agrees with truncation for two positive operands only - which is
     * why no rule set shows the difference.
     */
    public function testIdivTruncatesAndModTakesTheSignOfTheDividend(): void
    {
        $root = $this->dom('<r xmlns="urn:x"/>');
        $i = $this->interpreter();

        self::assertSame('-3', $i->evaluate(Parser::parse('-7 idiv 2'), $root)->first()?->asString());
        self::assertSame('-3', $i->evaluate(Parser::parse('7 idiv -2'), $root)->first()?->asString());
        self::assertSame('-1', $i->evaluate(Parser::parse('-7 mod 2'), $root)->first()?->asString());
        self::assertSame('1', $i->evaluate(Parser::parse('7 mod -2'), $root)->first()?->asString());
        self::assertSame('-1.5', $i->evaluate(Parser::parse('-7.5 mod 2'), $root)->first()?->asString());
    }

    /**
     * ⚠️ A value comparison with an empty operand is the EMPTY SEQUENCE ( XPath 2.0 §3.5.1 ), not an
     * error. A throwing official rule ends the whole validation. More than one item per side stays a
     * type error.
     */
    public function testValueComparisonWithAnEmptyOperandIsTheEmptySequence(): void
    {
        $root = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:V>1</cac:V><cac:V>2</cac:V></cac:Root>');
        $i = $this->interpreter();

        self::assertTrue($i->evaluate(Parser::parse('cac:Missing eq 1'), $root)->isEmpty());
        self::assertTrue($i->evaluate(Parser::parse('1 le cac:Missing'), $root)->isEmpty());
        self::assertTrue($i->evaluate(Parser::parse('not(cac:Missing ne 1)'), $root)->effectiveBooleanValue());

        $this->expectException(UnsupportedConstructException::class);
        $i->evaluate(Parser::parse('cac:V eq 1'), $root);
    }

    public function testUnboundNamespacePrefixThrows(): void
    {
        $root = $this->dom('<r xmlns="urn:x"/>');

        $this->expectException(UnsupportedConstructException::class);
        $this->interpreter()->evaluate(Parser::parse('unknownprefix:Foo'), $root);
    }

    public function testUndefinedVariableThrows(): void
    {
        $root = $this->dom('<r xmlns="urn:x"/>');

        $this->expectException(UnsupportedConstructException::class);
        $this->interpreter()->evaluate(Parser::parse('$doesNotExist'), $root);
    }

    public function testParentAndAncestorAxes(): void
    {
        $root = $this->dom('<cac:A xmlns:cac="urn:cac"><cac:B><cac:C/></cac:B></cac:A>');
        $b = $root->getElementsByTagNameNS('urn:cac', 'B')->item(0);
        self::assertNotNull($b);
        $c = $root->getElementsByTagNameNS('urn:cac', 'C')->item(0);
        self::assertNotNull($c);

        $i = $this->interpreter();
        self::assertTrue($i->evaluate(Parser::parse('parent::cac:B'), $c)->effectiveBooleanValue());
        self::assertTrue($i->evaluate(Parser::parse('ancestor::cac:A'), $c)->effectiveBooleanValue());
        self::assertFalse($i->evaluate(Parser::parse('ancestor::cac:NoSuchAncestor'), $c)->effectiveBooleanValue());
    }

    /**
     * `ancestor-or-self::` is the context node plus every ancestor. CIUS-HR-UBL 1.0.4 uses it in a rule
     * context, and without the axis the whole rule set aborts.
     */
    public function testAncestorOrSelfAxisContainsTheNodeItselfAndEveryAncestor(): void
    {
        $root = $this->dom('<cac:A xmlns:cac="urn:cac"><cac:B><cac:C/></cac:B><cac:D/></cac:A>');
        $c = $root->getElementsByTagNameNS('urn:cac', 'C')->item(0);
        self::assertNotNull($c);

        $i = $this->interpreter();
        self::assertTrue($i->evaluate(Parser::parse('ancestor-or-self::cac:C'), $c)->effectiveBooleanValue());
        self::assertTrue($i->evaluate(Parser::parse('ancestor-or-self::cac:B'), $c)->effectiveBooleanValue());
        self::assertTrue($i->evaluate(Parser::parse('ancestor-or-self::cac:A'), $c)->effectiveBooleanValue());
        // A sibling of an ancestor is not on the axis.
        self::assertFalse($i->evaluate(Parser::parse('ancestor-or-self::cac:D'), $c)->effectiveBooleanValue());
        // ⚠️ `ancestor::` must stay WITHOUT the node itself - the two axes differ by exactly that.
        self::assertFalse($i->evaluate(Parser::parse('ancestor::cac:C'), $c)->effectiveBooleanValue());

        // C, B, A and the document node: `node()` matches the document node, `*` does not.
        self::assertSame('4', $i->evaluate(Parser::parse('count(ancestor-or-self::node())'), $c)->first()->asString());
        self::assertSame('3', $i->evaluate(Parser::parse('count(ancestor-or-self::*)'), $c)->first()->asString());
    }

    /**
     * ⚠️ A REVERSE AXIS NUMBERS ITS POSITIONS FROM THE CONTEXT NODE OUTWARDS ( XPath 2.0 §3.2.2 ).
     * `[1]` is the nearest node, not the first one in document order. A version that sorted the axis
     * into document order would pass every test above and fail every one here.
     */
    public function testPositionalPredicateOnAncestorOrSelfCountsFromTheContextNodeOutwards(): void
    {
        $root = $this->dom(
            '<cac:X xmlns:cac="urn:cac" id="outer"><cac:X id="inner"><cac:Y id="leaf"/></cac:X></cac:X>'
        );
        $y = $root->getElementsByTagNameNS('urn:cac', 'Y')->item(0);
        self::assertNotNull($y);
        $inner = $y->parentNode;
        self::assertInstanceOf(\DOMElement::class, $inner);

        $i = $this->interpreter();
        $id = static fn (string $xpath, \DOMNode $context): string
            => $i->evaluate(Parser::parse($xpath), $context)->first()?->textContent ?? '';

        self::assertSame('leaf', $id('ancestor-or-self::*[1]/@id', $y));
        self::assertSame('inner', $id('ancestor-or-self::*[2]/@id', $y));
        self::assertSame('outer', $id('ancestor-or-self::*[3]/@id', $y));
        self::assertTrue($i->evaluate(Parser::parse('ancestor-or-self::*[4]'), $y)->isEmpty());

        // With a name test the positions count only the matching nodes, still nearest first.
        self::assertSame('inner', $id('ancestor-or-self::cac:X[1]/@id', $y));
        self::assertSame('outer', $id('ancestor-or-self::cac:X[2]/@id', $y));
        // From the inner X itself, position 1 is the node itself - the "-or-self" part.
        self::assertSame('inner', $id('ancestor-or-self::cac:X[1]/@id', $inner));
        // `ancestor::` from the same node skips it, so its position 1 is the outer X.
        self::assertSame('outer', $id('ancestor::cac:X[1]/@id', $inner));
    }

    /**
     * The rule context of CIUS-HR-UBL 1.0.4, pattern M12, word for word: empty leaf elements, except
     * those inside the signature block. The signature leaf must be excluded although it is itself
     * no `sig:UBLDocumentSignatures` - only its ancestor is, and the `-or-self` keeps the block's own
     * element out when it happens to be empty.
     */
    public function testCiusHrRuleContextExcludesEmptyLeavesInsideTheSignatureBlock(): void
    {
        $dom = new \DOMDocument();
        $dom->loadXML(
            '<Invoice xmlns="urn:inv" xmlns:cbc="urn:cbc" xmlns:sig="urn:sig">'
            . '<cbc:Note/>'
            . '<cbc:ID>1</cbc:ID>'
            . '<sig:UBLDocumentSignatures><cbc:Empty/></sig:UBLDocumentSignatures>'
            . '<sig:UBLDocumentSignatures/>'
            . '</Invoice>'
        );
        $root = $dom->documentElement;
        self::assertNotNull($root);

        $i = new Interpreter([...self::NAMESPACES, 'sig' => 'urn:sig']);
        $result = $i->evaluate(
            Parser::parse(
                '//*[not(*) and not(normalize-space()) and not(ancestor-or-self::sig:UBLDocumentSignatures)]'
            ),
            $root
        );

        $names = array_map(
            static fn ($n): string => $n instanceof \DOMNode ? (string) $n->localName : '',
            $result->items()
        );
        self::assertSame(['Note'], $names);
    }

    public function testAttributeAxis(): void
    {
        $root = $this->dom('<r xmlns="urn:x" schemeID="EM">value</r>');

        $result = $this->interpreter()->evaluate(Parser::parse('@schemeID'), $root);

        self::assertSame('EM', $result->first()->textContent);
    }
}

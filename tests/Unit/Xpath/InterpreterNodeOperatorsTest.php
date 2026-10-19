<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Xpath;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Exception\UnsupportedConstructException;
use EinvoiceValidator\Xpath\Interpreter;
use EinvoiceValidator\Xpath\PathCache;
use EinvoiceValidator\Xpath\Runtime\Sequence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parts of XPath 2.0 that VAMPIRE JS evaluated and this interpreter refused: the node comparison
 * `is`, the set operators `intersect` and `except`, the kind tests `element()` and `attribute()`, and
 * `position()` / `last()` inside a predicate over nodes.
 *
 * ⚠️ Every test runs twice, with and without a `PathCache`. The cache is what the engine uses; a
 * result that differs between the two runs is a cache key that misses the focus or the operands.
 */
final class InterpreterNodeOperatorsTest extends TestCase
{
    private const NAMESPACES = ['cac' => 'urn:cac', 'cbc' => 'urn:cbc'];

    private const ITEMS = '<cac:Root xmlns:cac="urn:cac" xmlns:cbc="urn:cbc">'
        . '<cac:Item id="a"><cbc:ID>1</cbc:ID></cac:Item>'
        . '<cac:Item id="b"><cbc:ID>2</cbc:ID></cac:Item>'
        . '<cac:Item id="c"><cbc:ID>3</cbc:ID></cac:Item>'
        . '<cac:Other><cbc:ID>2</cbc:ID></cac:Other>'
        . '<cac:Other><cbc:ID>3</cbc:ID></cac:Other>'
        . '</cac:Root>';

    /** @return iterable<string, array{bool}> */
    public static function cacheModes(): iterable
    {
        yield 'without path cache' => [false];
        yield 'with path cache' => [true];
    }

    private function interpreter(bool $cached): Interpreter
    {
        return new Interpreter(self::NAMESPACES, pathCache: $cached ? new PathCache() : null);
    }

    private function root(string $xml = self::ITEMS): \DOMElement
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $root = $dom->documentElement;
        self::assertNotNull($root);

        return $root;
    }

    private function eval(bool $cached, string $xpath, \DOMNode $context): Sequence
    {
        return $this->interpreter($cached)->evaluate(Parser::parse($xpath), $context);
    }

    /** @return list<string> the `@id` of every item, in sequence order */
    private function ids(Sequence $sequence): array
    {
        return array_map(
            static fn ($n): string => $n instanceof \DOMElement ? $n->getAttribute('id') : '?',
            $sequence->items()
        );
    }

    /** @return list<string> */
    private function select(bool $cached, string $xpath, \DOMNode $context): array
    {
        return $this->ids($this->eval($cached, $xpath, $context));
    }

    private function value(bool $cached, string $xpath, \DOMNode $context): string
    {
        return (string) $this->eval($cached, $xpath, $context)->first()?->asString();
    }

    private function bool(bool $cached, string $xpath, \DOMNode $context): bool
    {
        $result = $this->eval($cached, $xpath, $context);
        self::assertSame(1, $result->count(), "$xpath must yield exactly one boolean");

        return $result->first()?->asString() === 'true';
    }

    // --- is ---------------------------------------------------------------------------------------

    #[DataProvider('cacheModes')]
    public function testIsComparesNodeIdentityNotValue(bool $cached): void
    {
        $root = $this->root();

        self::assertTrue($this->bool($cached, 'cac:Item[1] is cac:Item[1]', $root));
        self::assertFalse($this->bool($cached, 'cac:Item[1] is cac:Item[2]', $root));
        // Same node reached on two different paths.
        self::assertTrue($this->bool($cached, 'cac:Item[2]/preceding-sibling::cac:Item[1] is cac:Item[1]', $root));
        // ⚠️ Equal string value, different nodes: `=` says true, `is` must say false.
        self::assertTrue($this->bool($cached, 'cac:Item[2]/cbc:ID = cac:Other[1]/cbc:ID', $root));
        self::assertFalse($this->bool($cached, 'cac:Item[2]/cbc:ID is cac:Other[1]/cbc:ID', $root));
    }

    /**
     * ⚠️ XPath 2.0 §3.5.3: an empty operand gives the EMPTY SEQUENCE, not false. Inside `not()` both
     * read alike, which is why the shape below still answers true.
     */
    #[DataProvider('cacheModes')]
    public function testIsWithAnEmptyOperandIsTheEmptySequence(bool $cached): void
    {
        $root = $this->root();

        self::assertTrue($this->eval($cached, 'cac:Missing is .', $root)->isEmpty());
        self::assertTrue($this->eval($cached, '. is cac:Missing', $root)->isEmpty());
        self::assertTrue($this->bool($cached, 'not(cac:Missing is .)', $root));
        // ⚠️ The one shape where empty and false differ: a general comparison with an empty operand
        // is false. An engine answering false for the empty operand would say true here.
        self::assertFalse($this->bool($cached, '(cac:Missing is .) = false()', $root));
    }

    #[DataProvider('cacheModes')]
    public function testIsWithMoreThanOneNodeIsATypeError(bool $cached): void
    {
        $this->expectException(UnsupportedConstructException::class);
        $this->expectExceptionMessage('XPTY0004');

        $this->eval($cached, 'cac:Item is cac:Item[1]', $this->root());
    }

    #[DataProvider('cacheModes')]
    public function testIsWithMoreThanOneNodeOnTheRightIsATypeError(bool $cached): void
    {
        $this->expectException(UnsupportedConstructException::class);
        $this->expectExceptionMessage('XPTY0004');

        $this->eval($cached, 'cac:Item[1] is cac:Item', $this->root());
    }

    #[DataProvider('cacheModes')]
    public function testIsWithAnAtomicOperandIsATypeError(bool $cached): void
    {
        $this->expectException(UnsupportedConstructException::class);
        $this->expectExceptionMessage('XPTY0004');

        $this->eval($cached, "'a' is 'a'", $this->root());
    }

    /**
     * The shape the ZUGFeRD 2.4 and 2.5 EXTENDED rule sets really use, evaluated from one line: "is
     * there ANOTHER line next to this one". `$other` runs over every line, the context line included,
     * and `is` is what keeps the context line out.
     */
    #[DataProvider('cacheModes')]
    public function testTheZugferdExtendedShapeExcludesTheContextLineItself(bool $cached): void
    {
        $root = $this->root();
        $b = $root->getElementsByTagNameNS('urn:cac', 'Item')->item(1);
        self::assertNotNull($b);

        $other = 'some $other in ../cac:Item satisfies (not($other is .) and $other/cbc:ID = 3)';
        self::assertTrue($this->bool($cached, $other, $b));

        $self = 'some $other in ../cac:Item satisfies (not($other is .) and $other/cbc:ID = 2)';
        self::assertFalse($this->bool($cached, $self, $b), 'only the context line itself has ID 2');

        $single = $this->root('<cac:Root xmlns:cac="urn:cac"><cac:Item/></cac:Root>');
        $only = $single->firstChild;
        self::assertNotNull($only);
        self::assertFalse($this->bool($cached, 'some $other in ../cac:Item satisfies not($other is .)', $only));
    }

    // --- intersect / except -----------------------------------------------------------------------

    #[DataProvider('cacheModes')]
    public function testIntersectKeepsTheNodesOnBothSides(bool $cached): void
    {
        $root = $this->root();

        self::assertSame(['b'], $this->ids($this->eval($cached, 'cac:Item intersect cac:Item[2]', $root)));
        self::assertSame(['b', 'c'], $this->select($cached, 'cac:Item intersect cac:Item[position() > 1]', $root));
        self::assertSame([], $this->ids($this->eval($cached, 'cac:Item[1] intersect cac:Item[3]', $root)));
    }

    #[DataProvider('cacheModes')]
    public function testExceptKeepsTheLeftNodesThatAreNotOnTheRight(bool $cached): void
    {
        $root = $this->root();

        self::assertSame(['a', 'c'], $this->ids($this->eval($cached, 'cac:Item except cac:Item[2]', $root)));
        self::assertSame([], $this->ids($this->eval($cached, 'cac:Item except cac:Item', $root)));
    }

    #[DataProvider('cacheModes')]
    public function testIntersectAndExceptWithAnEmptySide(bool $cached): void
    {
        $root = $this->root();

        self::assertTrue($this->eval($cached, 'cac:Missing intersect cac:Item', $root)->isEmpty());
        self::assertTrue($this->eval($cached, 'cac:Item intersect cac:Missing', $root)->isEmpty());
        self::assertTrue($this->eval($cached, 'cac:Missing except cac:Item', $root)->isEmpty());
        self::assertSame(['a', 'b', 'c'], $this->ids($this->eval($cached, 'cac:Item except cac:Missing', $root)));
    }

    /**
     * ⚠️ The result is a node SET: a node named twice on the left appears once.
     */
    #[DataProvider('cacheModes')]
    public function testIntersectAndExceptRemoveDuplicates(bool $cached): void
    {
        $root = $this->root();

        self::assertSame(['a'], $this->select($cached, '(cac:Item[1], cac:Item[1]) except cac:Item[2]', $root));
        self::assertSame(['a'], $this->select($cached, '(cac:Item[1], cac:Item[1]) intersect cac:Item', $root));
    }

    #[DataProvider('cacheModes')]
    public function testIntersectWithAnAtomicOperandIsATypeError(bool $cached): void
    {
        $this->expectException(UnsupportedConstructException::class);
        $this->expectExceptionMessage('XPTY0004');

        $this->eval($cached, '(1, 2) intersect cac:Item', $this->root());
    }

    #[DataProvider('cacheModes')]
    public function testExceptWithAnAtomicOperandIsATypeError(bool $cached): void
    {
        $this->expectException(UnsupportedConstructException::class);
        $this->expectExceptionMessage('XPTY0004');

        $this->eval($cached, "cac:Item except 'x'", $this->root());
    }

    // --- element() / attribute() ----------------------------------------------------------------

    #[DataProvider('cacheModes')]
    public function testElementAndAttributeKindTests(bool $cached): void
    {
        $root = $this->root('<r xmlns:p="urn:p" a="1" p:b="2">text<x/><!-- c --><p:y/>more</r>');

        // node() sees the two texts and the comment as well, element() only the two elements.
        self::assertSame('5', $this->eval($cached, 'count(node())', $root)->first()?->asString());
        self::assertSame('2', $this->eval($cached, 'count(element())', $root)->first()?->asString());
        self::assertSame('2', $this->eval($cached, 'count(child::element())', $root)->first()?->asString());
        self::assertSame('2', $this->eval($cached, 'count(@attribute())', $root)->first()?->asString());
        self::assertSame('2', $this->eval($cached, 'count(attribute::attribute())', $root)->first()?->asString());
        // An attribute is never a child, and an element never sits on the attribute axis.
        self::assertSame('0', $this->eval($cached, 'count(child::attribute())', $root)->first()?->asString());
        self::assertSame('0', $this->eval($cached, 'count(@element())', $root)->first()?->asString());
        // The document node is not an element: from the root element up there is exactly one.
        self::assertSame('1', $this->eval($cached, 'count(ancestor-or-self::element())', $root)->first()?->asString());
        // ⚠️ The namespace declaration xmlns:p is not an attribute in the XPath data model. libxml does
        // not list it; a browser DOM and @xmldom/xmldom do, which is what VAMPIRE JS has to filter.
        self::assertSame('2', $this->eval($cached, 'count(@*)', $root)->first()?->asString());
        $defaultNs = $this->root('<r xmlns="urn:x" a="1"/>');
        self::assertSame('1', $this->eval($cached, 'count(@*)', $defaultNs)->first()?->asString());
    }

    #[DataProvider('cacheModes')]
    public function testProcessingInstructionKindTest(bool $cached): void
    {
        $root = $this->root('<r><?pi data?><x/></r>');

        self::assertSame('1', $this->eval($cached, 'count(processing-instruction())', $root)->first()?->asString());
    }

    /**
     * ⚠️ `element` and `attribute` are kind tests only when a `(` follows. Without it they are plain
     * element names, and an invoice may well carry an element called that.
     */
    #[DataProvider('cacheModes')]
    public function testElementWithoutParenthesesStaysANameTest(bool $cached): void
    {
        $root = $this->root('<r><element/><attribute/><other/></r>');

        self::assertSame('1', $this->eval($cached, 'count(element)', $root)->first()?->asString());
        self::assertSame('1', $this->eval($cached, 'count(attribute)', $root)->first()?->asString());
        self::assertSame('3', $this->eval($cached, 'count(element())', $root)->first()?->asString());
    }

    // --- position() / last() ----------------------------------------------------------------------

    #[DataProvider('cacheModes')]
    public function testLastAndPositionInAPredicateOverNodes(bool $cached): void
    {
        $root = $this->root();

        self::assertSame(['c'], $this->ids($this->eval($cached, 'cac:Item[last()]', $root)));
        self::assertSame(['b'], $this->ids($this->eval($cached, 'cac:Item[last() - 1]', $root)));
        self::assertSame(['c'], $this->ids($this->eval($cached, 'cac:Item[position() = last()]', $root)));
        self::assertSame(['b', 'c'], $this->ids($this->eval($cached, 'cac:Item[position() > 1]', $root)));
        self::assertSame(['a', 'c'], $this->ids($this->eval($cached, 'cac:Item[position() != 2]', $root)));
        $countBeforeLast = 'cac:Item[1]/count(../cac:Item[last()]/preceding-sibling::cac:Item) + 1';
        self::assertSame('3', $this->value($cached, $countBeforeLast, $root));
    }

    /**
     * ⚠️ A SECOND PREDICATE COUNTS OVER WHAT THE FIRST ONE LEFT, not over the whole axis.
     * `cac:Item[position() > 1][last()]` has a context size of two in its second predicate.
     */
    #[DataProvider('cacheModes')]
    public function testEachPredicateHasItsOwnContextSize(bool $cached): void
    {
        $root = $this->root();

        self::assertSame(['c'], $this->ids($this->eval($cached, 'cac:Item[position() > 1][last()]', $root)));
        self::assertSame(['b'], $this->ids($this->eval($cached, 'cac:Item[position() > 1][1]', $root)));
        self::assertSame(['b'], $this->select($cached, 'cac:Item[position() > 1][position() = last() - 1]', $root));
    }

    /**
     * ⚠️ AN INNER PREDICATE MUST NOT LEAVE ITS FOCUS BEHIND. In the outer predicate below, `last()`
     * after the inner `cac:Other[last()]` is the size of the ITEMS ( three ), not of the others ( two ).
     * With one field instead of a stack the inner predicate would overwrite it.
     */
    #[DataProvider('cacheModes')]
    public function testANestedPredicateRestoresTheOuterFocus(bool $cached): void
    {
        $root = $this->root();

        // The item whose ID is the ID of the last cac:Other ( 3 ), and only if it is the third of three.
        // ⚠️ `position() = last()` alone would not catch a leak: the leaked focus of the inner predicate
        // is ( 2, 2 ), and 2 = 2 holds as well. The literal 3 is what tells the two foci apart.
        $xpath = 'cac:Item[cbc:ID = ../cac:Other[last()]/cbc:ID and position() = 3 and last() = 3]';
        self::assertSame(['c'], $this->ids($this->eval($cached, $xpath, $root)));

        // The same with the order of the two tests swapped: the inner predicate runs AFTER the outer
        // `last()` here, so a leak would show up on the next item instead.
        $swapped = 'cac:Item[position() = last() - 1 or cbc:ID = ../cac:Other[last()]/cbc:ID]';
        self::assertSame(['b', 'c'], $this->ids($this->eval($cached, $swapped, $root)));
    }

    /**
     * Outside a predicate the focus is the single context item, so both functions answer 1 - the same
     * answer VAMPIRE JS gives.
     */
    #[DataProvider('cacheModes')]
    public function testPositionAndLastOutsideAPredicateAreOne(bool $cached): void
    {
        $root = $this->root();

        self::assertSame('1', $this->eval($cached, 'last()', $root)->first()?->asString());
        self::assertSame('1', $this->eval($cached, 'position()', $root)->first()?->asString());
    }

    /**
     * ⚠️ A predicate that throws must not leave the focus stack one level too deep. After the failed
     * evaluation the SAME interpreter must still select the last item correctly.
     */
    public function testAThrowingPredicateDoesNotCorruptTheFocus(): void
    {
        $root = $this->root();
        $i = $this->interpreter(false);

        try {
            $i->evaluate(Parser::parse('cac:Item[last() and $undefined]'), $root);
            self::fail('expected an undefined-variable error');
        } catch (UnsupportedConstructException) {
            // expected
        }

        self::assertSame(['c'], $this->ids($i->evaluate(Parser::parse('cac:Item[last()]'), $root)));
        self::assertSame('1', $i->evaluate(Parser::parse('last()'), $root)->first()?->asString());
    }

    /**
     * The atomic route of `filterPredicates()` ( a predicate on `tokenize()` ) answers `last()` on its
     * own and must keep doing so, now that the general one exists too.
     */
    #[DataProvider('cacheModes')]
    public function testLastOnTokenizedStringsStillWorks(bool $cached): void
    {
        $root = $this->root();

        self::assertSame('c', $this->eval($cached, "tokenize('a#b#c', '#')[last()]", $root)->first()?->asString());
        self::assertSame('b', $this->value($cached, "tokenize('a#b#c', '#')[position() = last() - 1]", $root));
    }
}

<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Engine;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Engine\Engine;
use EinvoiceValidator\Exception\UnsupportedConstructException;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Ruleset\Assertion;
use EinvoiceValidator\Ruleset\LetBinding;
use EinvoiceValidator\Ruleset\MessageSegment;
use EinvoiceValidator\Ruleset\Pattern;
use EinvoiceValidator\Ruleset\Rule;
use EinvoiceValidator\Ruleset\Ruleset;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    private const NAMESPACES = ['cac' => 'urn:cac', 'cbc' => 'urn:cbc'];

    private function dom(string $xml): \DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        return $dom;
    }

    private function ruleset(array $patterns, array $globalLets = []): Ruleset
    {
        return new Ruleset('T', '1.0', 'Test', 'test.xslt', 'x', self::NAMESPACES, $globalLets, $patterns);
    }

    public function testAFailingAssertionProducesAFinding(): void
    {
        $document = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item/></cac:Root>');
        $assertion = new Assertion(
            'X-1',
            Flag::Fatal,
            'assert',
            Parser::parse('false()'),
            [MessageSegment::literal('always fails')],
            []
        );
        $rule = new Rule(Parser::parse('//cac:Item'), 100, [], [$assertion]);
        $ruleset = $this->ruleset([new Pattern('p1', 'Pattern 1', [$rule])]);

        $findings = (new Engine())->run($ruleset, $document);

        self::assertCount(1, $findings);
        self::assertSame('X-1', $findings[0]->ruleId);
        self::assertSame(Flag::Fatal, $findings[0]->flag);
        self::assertSame('always fails', $findings[0]->text);
        self::assertSame('p1', $findings[0]->patternId);
    }

    public function testAPassingAssertionProducesNoFinding(): void
    {
        $document = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item/></cac:Root>');
        $assertion = new Assertion('X-1', Flag::Fatal, 'assert', Parser::parse('true()'), [MessageSegment::literal('x')], []);
        $rule = new Rule(Parser::parse('//cac:Item'), 100, [], [$assertion]);
        $ruleset = $this->ruleset([new Pattern('p1', 'Pattern 1', [$rule])]);

        $findings = (new Engine())->run($ruleset, $document);

        self::assertSame([], $findings);
    }

    /**
     * Two rules in one pattern both match cac:Item; the higher-priority (first-declared) rule
     * must be the ONLY one that fires for it - the lower-priority rule's own (also failing)
     * assertion must never appear.
     */
    public function testOnlyTheHighestPriorityMatchingRuleFiresPerNode(): void
    {
        $document = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item/></cac:Root>');
        $highPrioAssertion = new Assertion('HIGH', Flag::Fatal, 'assert', Parser::parse('false()'), [MessageSegment::literal('high')], []);
        $lowPrioAssertion = new Assertion('LOW', Flag::Fatal, 'assert', Parser::parse('false()'), [MessageSegment::literal('low')], []);
        $highPrio = new Rule(Parser::parse('//cac:Item'), 200, [], [$highPrioAssertion]);
        $lowPrio = new Rule(Parser::parse('//cac:Item'), 100, [], [$lowPrioAssertion]);
        $ruleset = $this->ruleset([new Pattern('p1', 'Pattern 1', [$highPrio, $lowPrio])]);

        $findings = (new Engine())->run($ruleset, $document);

        self::assertCount(1, $findings);
        self::assertSame('HIGH', $findings[0]->ruleId);
    }

    /**
     * The same node in a DIFFERENT pattern is evaluated independently - "first rule wins" is
     * scoped per pattern, not globally across the whole ruleset.
     */
    public function testTheSameNodeCanFireInEveryPatternIndependently(): void
    {
        $document = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item/></cac:Root>');
        $a1 = new Assertion('A', Flag::Fatal, 'assert', Parser::parse('false()'), [MessageSegment::literal('a')], []);
        $a2 = new Assertion('B', Flag::Fatal, 'assert', Parser::parse('false()'), [MessageSegment::literal('b')], []);
        $ruleset = $this->ruleset([
            new Pattern('p1', 'P1', [new Rule(Parser::parse('//cac:Item'), 100, [], [$a1])]),
            new Pattern('p2', 'P2', [new Rule(Parser::parse('//cac:Item'), 100, [], [$a2])]),
        ]);

        $findings = (new Engine())->run($ruleset, $document);

        self::assertCount(2, $findings);
        self::assertSame(['A', 'B'], array_map(static fn ($f) => $f->ruleId, $findings));
    }

    public function testGlobalLetsAreBoundOnceAndChainToLaterGlobals(): void
    {
        $document = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item/></cac:Root>');
        $globals = [
            new LetBinding('base', Parser::parse("'x'")),
            new LetBinding('derived', Parser::parse('concat($base, $base)')),
        ];
        $assertion = new Assertion(
            null,
            Flag::Information,
            'assert',
            Parser::parse("\$derived = 'xx'"),
            [MessageSegment::literal('ok')],
            []
        );
        $rule = new Rule(Parser::parse('//cac:Item'), 100, [], [$assertion]);
        $ruleset = $this->ruleset([new Pattern('p1', 'P1', [$rule])], $globals);

        $findings = (new Engine())->run($ruleset, $document);

        self::assertSame([], $findings, 'assertion should HOLD (derived = "xx"), producing no finding');
    }

    public function testRuleLevelLetsAreScopedPerContextNodeAndSeeGlobals(): void
    {
        $document = $this->dom(
            '<cac:Root xmlns:cac="urn:cac"><cac:Item cbc:v="1" xmlns:cbc="urn:cbc"/>'
            . '<cac:Item cbc:v="2" xmlns:cbc="urn:cbc"/></cac:Root>'
        );
        $globals = [new LetBinding('factor', Parser::parse('2'))];
        // assertion fails (finding fires) unless doubled = @cbc:v * factor - always true here,
        // this just proves "doubled" (a RULE-level let) actually sees @cbc:v of ITS OWN node and
        // "factor" (a GLOBAL let) at the same time.
        $assertion = new Assertion(
            null,
            Flag::Fatal,
            'assert',
            Parser::parse('$doubled = (@cbc:v * $factor)'),
            [MessageSegment::literal('mismatch')],
            []
        );
        $lets = [new LetBinding('doubled', Parser::parse('@cbc:v * 2'))];
        $rule = new Rule(Parser::parse('//cac:Item'), 100, $lets, [$assertion]);
        $ruleset = $this->ruleset([new Pattern('p1', 'P1', [$rule])], $globals);

        $findings = (new Engine())->run($ruleset, $document);

        self::assertSame([], $findings);
    }

    public function testMessageWithExpressionSegmentsIsRenderedAtFiringTime(): void
    {
        $document = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item cbc:v="42" xmlns:cbc="urn:cbc"/></cac:Root>');
        $message = [
            MessageSegment::literal('value is '),
            MessageSegment::expression(Parser::parse('string(@cbc:v)')),
            MessageSegment::literal('.'),
        ];
        $assertion = new Assertion(null, Flag::Warning, 'assert', Parser::parse('false()'), $message, []);
        $rule = new Rule(Parser::parse('//cac:Item'), 100, [], [$assertion]);
        $ruleset = $this->ruleset([new Pattern('p1', 'P1', [$rule])]);

        $findings = (new Engine())->run($ruleset, $document);

        self::assertCount(1, $findings);
        self::assertSame('value is 42.', $findings[0]->text);
    }

    /**
     * `report` fires when its test is TRUE - the opposite of `assert`. Implemented for Task 4.1b,
     * where the Factur-X rule sets brought the first real occurrences; until then the Engine threw on
     * this kind because no shipped rule set used it and the polarity would have been a guess.
     *
     * Both directions are asserted here on purpose: a mix-up shows up only in one of them.
     */
    public function testReportFiresWhenItsTestIsTrue(): void
    {
        $document = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item/></cac:Root>');
        $assertion = new Assertion(
            'R-1',
            Flag::Information,
            'report',
            Parser::parse('true()'),
            [MessageSegment::literal('reported')],
            []
        );
        $rule = new Rule(Parser::parse('//cac:Item'), 100, [], [$assertion]);

        $findings = (new Engine())->run($this->ruleset([new Pattern('p1', 'P1', [$rule])]), $document);

        self::assertCount(1, $findings);
        self::assertSame('reported', $findings[0]->text);
    }

    public function testReportStaysSilentWhenItsTestIsFalse(): void
    {
        $document = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item/></cac:Root>');
        $assertion = new Assertion(
            'R-2',
            Flag::Information,
            'report',
            Parser::parse('false()'),
            [MessageSegment::literal('should not appear')],
            []
        );
        $rule = new Rule(Parser::parse('//cac:Item'), 100, [], [$assertion]);

        $findings = (new Engine())->run($this->ruleset([new Pattern('p1', 'P1', [$rule])]), $document);

        self::assertSame([], $findings);
    }

    /**
     * An unknown kind never reaches the Engine: `Assertion` rejects it on construction. The Engine
     * keeps its own guard anyway (a ruleset could be built by other means), but this is where the
     * error actually surfaces, so this is where the test belongs.
     */
    public function testAnUnknownAssertionKindIsRejectedWhenTheAssertionIsBuilt(): void
    {
        $this->expectException(UnsupportedConstructException::class);

        new Assertion('X-1', Flag::Fatal, 'something-else', Parser::parse('true()'), [MessageSegment::literal('x')], []);
    }

    public function testFieldRefsAreCarriedThroughToTheFinding(): void
    {
        $document = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item/></cac:Root>');
        $assertion = new Assertion(
            'X',
            Flag::Fatal,
            'assert',
            Parser::parse('false()'),
            [MessageSegment::literal('x')],
            ['BT-1', 'BG-2']
        );
        $rule = new Rule(Parser::parse('//cac:Item'), 100, [], [$assertion]);
        $ruleset = $this->ruleset([new Pattern('p1', 'P1', [$rule])]);

        $findings = (new Engine())->run($ruleset, $document);

        self::assertSame(['BT-1', 'BG-2'], $findings[0]->fieldRefs);
    }
}

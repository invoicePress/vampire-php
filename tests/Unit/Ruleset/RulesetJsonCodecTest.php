<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Ruleset;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Ruleset\Assertion;
use EinvoiceValidator\Ruleset\LetBinding;
use EinvoiceValidator\Ruleset\MessageSegment;
use EinvoiceValidator\Ruleset\Pattern;
use EinvoiceValidator\Ruleset\Rule;
use EinvoiceValidator\Ruleset\Ruleset;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;
use PHPUnit\Framework\TestCase;

final class RulesetJsonCodecTest extends TestCase
{
    private function sampleRuleset(): Ruleset
    {
        $assertion = new Assertion(
            'BR-X',
            Flag::Fatal,
            'assert',
            Parser::parse('cbc:ID'),
            [
                MessageSegment::literal('[BR-X] needs an '),
                MessageSegment::expression(Parser::parse('name(.)')),
                MessageSegment::literal(' (BT-1).'),
            ],
            ['BT-1'],
        );
        $rule = new Rule(
            Parser::parse('//cac:Item'),
            42,
            [new LetBinding('x', Parser::parse("'1'"))],
            [$assertion],
        );
        $pattern = new Pattern('p1', 'Pattern One', [$rule]);

        return new Ruleset(
            'Test-Ruleset',
            '1.0.0',
            'A test ruleset',
            'vendor-sources/test.xslt',
            hash('sha256', 'test'),
            ['cac' => 'urn:cac', 'cbc' => 'urn:cbc'],
            [new LetBinding('global1', Parser::parse("'g'"))],
            [$pattern],
        );
    }

    public function testEncodeDecodeRoundTripPreservesEverything(): void
    {
        $original = $this->sampleRuleset();

        $decoded = RulesetJsonCodec::decode(RulesetJsonCodec::encode($original));

        self::assertSame($original->id, $decoded->id);
        self::assertSame($original->version, $decoded->version);
        self::assertSame($original->title, $decoded->title);
        self::assertSame($original->sourceFile, $decoded->sourceFile);
        self::assertSame($original->sourceHash, $decoded->sourceHash);
        self::assertSame($original->namespaces, $decoded->namespaces);
        self::assertCount(1, $decoded->globalLets);
        self::assertSame('global1', $decoded->globalLets[0]->name);

        self::assertCount(1, $decoded->patterns);
        $pattern = $decoded->patterns[0];
        self::assertSame('p1', $pattern->id);
        self::assertSame('Pattern One', $pattern->name);

        self::assertCount(1, $pattern->rules);
        $rule = $pattern->rules[0];
        self::assertSame(42, $rule->priority);
        self::assertCount(1, $rule->lets);
        self::assertSame('x', $rule->lets[0]->name);

        self::assertCount(1, $rule->assertions);
        $assertion = $rule->assertions[0];
        self::assertSame('BR-X', $assertion->id);
        self::assertSame(Flag::Fatal, $assertion->flag);
        self::assertSame('assert', $assertion->kind);
        self::assertSame(['BT-1'], $assertion->fieldRefs);

        self::assertCount(3, $assertion->message);
        self::assertTrue($assertion->message[0]->isLiteral());
        self::assertSame('[BR-X] needs an ', $assertion->message[0]->literal);
        self::assertFalse($assertion->message[1]->isLiteral());
        self::assertSame('FunctionCall', $assertion->message[1]->expr?->node());
        self::assertTrue($assertion->message[2]->isLiteral());
    }

    public function testWriteFileThenReadFileRoundTrips(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ruleset-test-') . '.json';
        RulesetJsonCodec::writeFile($path, $this->sampleRuleset());

        $decoded = RulesetJsonCodec::readFile($path);

        self::assertSame('Test-Ruleset', $decoded->id);
        unlink($path);
    }

    public function testDecodeRejectsUnsupportedSchemaVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RulesetJsonCodec::decode('{"schemaVersion": 99}');
    }
}

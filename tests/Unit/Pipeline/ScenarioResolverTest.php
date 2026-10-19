<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Pipeline;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Pipeline\Scenario;
use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Pipeline\XmlLoader;
use PHPUnit\Framework\TestCase;

final class ScenarioResolverTest extends TestCase
{
    /**
     * @return Scenario[]
     */
    private function scenarios(): array
    {
        return [
            new Scenario('cii', 'CII', Parser::parse("local-name(/*) = 'CrossIndustryInvoice'")),
            new Scenario('ubl-invoice', 'UBL Invoice', Parser::parse("local-name(/*) = 'Invoice'")),
            new Scenario('ubl-creditnote', 'UBL CreditNote', Parser::parse("local-name(/*) = 'CreditNote'")),
        ];
    }

    public function testFirstMatchingScenarioWins(): void
    {
        $resolver = new ScenarioResolver($this->scenarios());
        $dom = (new XmlLoader())->loadString('<CrossIndustryInvoice xmlns="urn:x"/>');

        $scenario = $resolver->resolve($dom);

        self::assertNotNull($scenario);
        self::assertSame('cii', $scenario->id);
    }

    public function testDistinguishesInvoiceFromCreditNote(): void
    {
        $resolver = new ScenarioResolver($this->scenarios());
        $dom = (new XmlLoader())->loadString('<CreditNote xmlns="urn:x"/>');

        self::assertSame('ubl-creditnote', $resolver->resolve($dom)?->id);
    }

    public function testNoMatchReturnsNull(): void
    {
        $resolver = new ScenarioResolver($this->scenarios());
        $dom = (new XmlLoader())->loadString('<SomethingElse xmlns="urn:x"/>');

        self::assertNull($resolver->resolve($dom));
    }

    public function testEarlierScenarioWinsOverLaterOneThatWouldAlsoMatch(): void
    {
        $resolver = new ScenarioResolver([
            new Scenario('first', 'First', Parser::parse("local-name(/*) = 'Invoice'")),
            new Scenario('second', 'Second', Parser::parse('true()')),
        ]);
        $dom = (new XmlLoader())->loadString('<Invoice xmlns="urn:x"/>');

        self::assertSame('first', $resolver->resolve($dom)?->id);
    }

    public function testFromJsonFileRoundTrips(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'einv-scn');
        self::assertIsString($path);
        $data = array_map(static fn (Scenario $s): array => $s->toArray(), $this->scenarios());
        file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));

        try {
            $resolver = ScenarioResolver::fromJsonFile($path);
            $dom = (new XmlLoader())->loadString('<Invoice xmlns="urn:x"/>');

            self::assertSame('ubl-invoice', $resolver->resolve($dom)?->id);
        } finally {
            unlink($path);
        }
    }

    /**
     * ⚠️ Real bug the switch from native DOMXPath (XPath 1.0) to our own Interpreter (XPath 2.0)
     * fixed: the real KoSIT scenarios' own `match` expressions use `exists(...)`, which
     * `DOMXPath::evaluate()` cannot evaluate at all ("Unregistered function").
     */
    public function testMatchUsingAnXPath2OnlyFunctionWorks(): void
    {
        $resolver = new ScenarioResolver([
            new Scenario('x', 'X', Parser::parse('exists(/Invoice/cbc:ID)'), ['cbc' => 'urn:cbc']),
        ]);
        $dom = (new XmlLoader())->loadString('<Invoice xmlns:cbc="urn:cbc"><cbc:ID>1</cbc:ID></Invoice>');

        self::assertSame('x', $resolver->resolve($dom)?->id);
    }
}

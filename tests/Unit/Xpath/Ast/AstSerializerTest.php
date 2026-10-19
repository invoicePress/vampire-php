<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Xpath\Ast;

use EinvoiceValidator\Xpath\Ast\AstSerializer;
use EinvoiceValidator\Xpath\Ast\BinaryOp;
use EinvoiceValidator\Xpath\Ast\CastableExpr;
use EinvoiceValidator\Xpath\Ast\ForExpr;
use EinvoiceValidator\Xpath\Ast\IfExpr;
use EinvoiceValidator\Xpath\Ast\Literal;
use EinvoiceValidator\Xpath\Ast\NodeTest;
use EinvoiceValidator\Xpath\Ast\PathExpr;
use EinvoiceValidator\Xpath\Ast\QName;
use EinvoiceValidator\Xpath\Ast\QuantifiedExpr;
use EinvoiceValidator\Xpath\Ast\Step;
use EinvoiceValidator\Xpath\Ast\VarRef;
use PHPUnit\Framework\TestCase;

final class AstSerializerTest extends TestCase
{
    public function testRoundTripsABinaryComparisonOfTwoPaths(): void
    {
        // cac:Foo/cbc:Bar = 'x'
        $path = new PathExpr(
            [new Step('child', new NodeTest('name', new QName('cac', 'Foo')), []), new Step('child', new NodeTest('name', new QName('cbc', 'Bar')), [])],
            false,
            0,
        );
        $root = new BinaryOp('=', $path, new Literal('string', 'x', 20), 0);

        $json = AstSerializer::toJson($root);
        $restored = AstSerializer::fromJson($json);

        self::assertEquals($root, $restored);
    }

    public function testRoundTripsForIfQuantifiedAndCastable(): void
    {
        // for $x in (1, 2) return if ($x castable as xs:integer) then $x else 0
        $inner = new IfExpr(
            new CastableExpr(new VarRef('x', 10), new QName('xs', 'integer'), false, 10),
            new VarRef('x', 40),
            new Literal('integer', '0', 50),
            0,
        );
        $for = new ForExpr('x', new VarRef('seq', 0), $inner, 0);
        $quantified = new QuantifiedExpr('every', 'y', new VarRef('items', 0), new VarRef('y', 0), 0);

        foreach ([$for, $quantified] as $root) {
            $restored = AstSerializer::fromJson(AstSerializer::toJson($root));
            self::assertEquals($root, $restored);
        }
    }

    public function testUnknownNodeTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AstSerializer::fromJson('{"astVersion":1,"root":{"node":"NoSuchNode","pos":0}}');
    }
}

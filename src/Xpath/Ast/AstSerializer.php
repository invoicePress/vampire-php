<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

/**
 * Central `node` → class dispatch for deserializing the JSON AST format (docs/developer/ast-format.md,
 * used by the ruleset cache, Aufgabe 2.5). Every node's own `fromArray()` delegates back here for
 * its child nodes, so adding a new node type only means adding one line to `$map` plus its class.
 */
final class AstSerializer
{
    private const MAP = [
        'PathExpr' => PathExpr::class,
        'BinaryOp' => BinaryOp::class,
        'UnaryOp' => UnaryOp::class,
        'FunctionCall' => FunctionCall::class,
        'VarRef' => VarRef::class,
        'Literal' => Literal::class,
        'SequenceExpr' => SequenceExpr::class,
        'RangeExpr' => RangeExpr::class,
        'IfExpr' => IfExpr::class,
        'ForExpr' => ForExpr::class,
        'QuantifiedExpr' => QuantifiedExpr::class,
        'LetExpr' => LetExpr::class,
        'CastableExpr' => CastableExpr::class,
        'CastExpr' => CastExpr::class,
        'ParenExpr' => ParenExpr::class,
    ];

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): Expr
    {
        $node = (string) ($data['node'] ?? '');
        $class = self::MAP[$node] ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException("unknown AST node type in cache: \"$node\"");
        }

        /** @var callable(array<string, mixed>): Expr $factory */
        $factory = [$class, 'fromArray'];

        return $factory($data);
    }

    public static function toJson(Expr $root): string
    {
        return json_encode(['astVersion' => 1, 'root' => $root->toArray()], JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): Expr
    {
        /** @var array{astVersion: int, root: array<string, mixed>} $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if ($data['astVersion'] !== 1) {
            throw new \InvalidArgumentException('unsupported AST cache version: ' . $data['astVersion']);
        }

        return self::fromArray($data['root']);
    }
}

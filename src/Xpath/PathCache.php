<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath;

use EinvoiceValidator\Xpath\Ast\Expr;
use EinvoiceValidator\Xpath\Runtime\Sequence;

/**
 * Task 2.5 — memoizes `PathExpr` evaluation results within ONE document. Keyed by the identity of
 * the parsed AST node (`spl_object_id`) plus the identity of the context node - both are only
 * meaningful within a single document/DOM tree, so a fresh `PathCache` is created per document
 * validation (Validator, Task 1.5), never reused across documents.
 *
 * Pays off once Phase 3's `Engine` evaluates several `assert`/`report` rules that share a common
 * `let`-bound sub-expression against the SAME context node within one pattern (a normal
 * Schematron shape) - within Phase 2's own scope (one AST evaluated once per context node) a miss
 * is the common case, which is why this is a pure memoization layer the Interpreter can be
 * constructed without (see Interpreter's optional constructor parameter), not a mandatory one.
 */
final class PathCache
{
    /** @var array<string, Sequence> */
    private array $entries = [];

    /**
     * document => (namespace fingerprint + AST id) => Sequence; see `rememberForDocument()`.
     *
     * @var \WeakMap<\DOMDocument, array<string, Sequence>>
     */
    private \WeakMap $perDocument;

    public function __construct()
    {
        $this->perDocument = new \WeakMap();
    }

    public function remember(Expr $expr, \DOMNode $contextNode, \Closure $compute): Sequence
    {
        // See NodeIdentity::of()'s own docblock for why spl_object_id($contextNode) alone is NOT
        // a safe key here (the original, measured cause of this class's Task 2.5 cross-iteration
        // wrong-cache-hit bug).
        $key = spl_object_id($expr) . ':' . NodeIdentity::of($contextNode);

        return $this->entries[$key] ??= $compute();
    }

    /**
     * ⚠️ **AN ABSOLUTE PATH WITHOUT A VARIABLE IS REMEMBERED ONCE PER DOCUMENT, NOT ONCE PER CONTEXT
     * NODE.** `remember()` keys by the context node, so an absolute path asked from 400 invoice lines
     * missed 400 times and walked the whole document each time. EN16931-CII's BR-CO-18 does exactly
     * that ( `//rsm:SupplyChainTradeTransaction/…` from every line ), and so does CII-DT-033 from every
     * quantity element. Measured on 08.10.2026 with a generated 400-line CII invoice: BR-CO-18 alone
     * took 10.7 s of the 16.5 s EN16931-CII needed, and the rule set grew with the square of the line
     * count. The caller ( `Interpreter::evalPath()` ) decides WHEN this is safe; this method only
     * stores. The same change was made first in VAMPIRE JS ( `Interpreter.path()` there ).
     *
     * ⚠️ **KEYED BY THE DOCUMENT OBJECT IN A `WeakMap`, NOT BY `NodeIdentity::of()`.** The document
     * node's path is "/" in every document, so a string key would let one invoice answer for the
     * next whenever a caller reuses a `PathCache` - `Engine` does not, but the class is public. The
     * `WeakMap` entry dies with its document, which is the same lifetime the JS side gets from its
     * `WeakMap` on the root node. ⚠️ A recycled object id cannot leak an old entry either: a
     * `WeakMap` drops the entry the moment the key object is destroyed, before the id is reused.
     *
     * @param string $namespaceKey fingerprint of the caller's prefix bindings - the same AST text
     *                             under other bindings selects other nodes, so it is part of the key
     *                             ( mirrors the namespace-map level of the JS cache ).
     * @param \Closure(): Sequence $compute
     */
    public function rememberForDocument(
        Expr $expr,
        \DOMDocument $document,
        string $namespaceKey,
        \Closure $compute
    ): Sequence {
        $entries = $this->perDocument[$document] ?? [];
        $key = $namespaceKey . ':' . spl_object_id($expr);

        if (isset($entries[$key])) {
            return $entries[$key];
        }

        $entries[$key] = $compute();
        $this->perDocument[$document] = $entries;

        return $entries[$key];
    }

    public function size(): int
    {
        $count = count($this->entries);

        foreach ($this->perDocument as $entries) {
            $count += count($entries);
        }

        return $count;
    }
}

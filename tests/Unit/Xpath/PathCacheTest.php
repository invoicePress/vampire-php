<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Xpath;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Xpath\Interpreter;
use EinvoiceValidator\Xpath\PathCache;
use PHPUnit\Framework\TestCase;

final class PathCacheTest extends TestCase
{
    private const NAMESPACES = ['cac' => 'urn:cac'];

    private function dom(string $xml): \DOMElement
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $root = $dom->documentElement;
        self::assertNotNull($root);

        return $root;
    }

    public function testCachedAndUncachedInterpreterAgreeOnAVariableFreePath(): void
    {
        $root = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item>x</cac:Item></cac:Root>');
        $ast = Parser::parse('cac:Item');

        $uncached = (new Interpreter(self::NAMESPACES))->evaluate($ast, $root);
        $cached = (new Interpreter(self::NAMESPACES, pathCache: new PathCache()))->evaluate($ast, $root);

        self::assertSame($uncached->count(), $cached->count());
    }

    public function testCacheActuallyGetsPopulatedForARepeatedVariableFreePath(): void
    {
        $root = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Item>x</cac:Item></cac:Root>');
        $ast = Parser::parse('cac:Item');
        $cache = new PathCache();
        $interpreter = new Interpreter(self::NAMESPACES, pathCache: $cache);

        self::assertSame(0, $cache->size());
        $interpreter->evaluate($ast, $root);
        self::assertSame(1, $cache->size());
        $interpreter->evaluate($ast, $root); // same AST object, same context node -> cache hit, no new entry
        self::assertSame(1, $cache->size());
    }

    /**
     * The correctness property PathCache::remember()'s own key design depends on: a path whose
     * PREDICATE reads a loop variable must give a DIFFERENT, correct result on each loop
     * iteration, never the first iteration's cached one. This is exactly the risk documented in
     * Interpreter::evalPath()'s own docblock - proven here, not just asserted in a comment.
     */
    public function testVariableDependentPredicateIsNeverWronglyCachedAcrossLoopIterations(): void
    {
        $root = $this->dom(
            '<cac:Root xmlns:cac="urn:cac"><cac:Item cac:key="1">a</cac:Item><cac:Item cac:key="2">b</cac:Item></cac:Root>'
        );
        $cache = new PathCache();
        $interpreter = new Interpreter(self::NAMESPACES, pathCache: $cache);

        // for $k in (1, 2) return cac:Item[@cac:key = $k] - the predicate reads the loop variable.
        $ast = Parser::parse('for $k in (1, 2) return cac:Item[@cac:key = $k]/text()');

        $result = $interpreter->evaluate($ast, $root);

        self::assertSame(['a', 'b'], array_map(static fn ($v) => $v->textContent, $result->items()));
    }

    /**
     * ⚠️ Real bug, found via the official XRechnung test suite (BR-DE-18 on invoice 01.10a):
     * `Interpreter::filterAtomicPredicates()` realizes each item of an atomic sequence (e.g. from
     * `tokenize(...)`) as a synthetic DOMText node so a predicate referencing "." can be evaluated
     * against it. An earlier draft left each synthetic node UNATTACHED - `NodeIdentity::of()` then
     * falls back to `spl_object_id()` (its `getNodePath()` returns null for a node with no
     * document position), reintroducing PathCache's own Task 2.5 identity-recycling bug: a
     * variable-free sub-expression of the predicate (here: bare ".") got cached keyed by one
     * synthetic node's id, then wrongly hit for a LATER synthetic node whose recycled id happened
     * to collide - collapsing distinct tokenized items' own "." values into one. Fixed by
     * appending each synthetic node as a real child of a shared synthetic root, giving it a
     * genuine, distinct `getNodePath()`.
     */
    public function testAtomicPredicateReferencingDotIsNeverWronglyCachedAcrossDifferentItems(): void
    {
        $root = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Note>#one#two#three#</cac:Note></cac:Root>');
        $cache = new PathCache();
        $interpreter = new Interpreter(self::NAMESPACES, pathCache: $cache);

        // tokenize into ["", "one", "two", "three", ""] then keep only non-empty ones whose OWN
        // text starts with a letter - each candidate's "." must reflect ITS OWN value, not an
        // earlier candidate's cached one.
        $ast = Parser::parse("tokenize(cac:Note, '#')[starts-with(., 'o') or starts-with(., 't')]");

        $result = $interpreter->evaluate($ast, $root);

        self::assertSame(['one', 'two', 'three'], array_map(static fn ($v) => $v->asString(), $result->items()));
    }

    /**
     * An absolute path without a variable is remembered ONCE per document, whichever node asks -
     * the fix for rules like EN16931-CII's BR-CO-18 that ask it from every invoice line.
     */
    public function testAbsolutePathIsRememberedOncePerDocumentNotPerContextNode(): void
    {
        /*
         * ⚠️ The document object is held in a variable ON PURPOSE. The cache is a `WeakMap` keyed by
         * that object; holding only the root element lets PHP drop the document's wrapper between
         * two calls and hand out a fresh one on the next `ownerDocument`, and every entry dies with
         * the old wrapper ( measured: size 0 instead of 1 ). That costs a miss, never a wrong answer,
         * and `Engine` always holds the document it runs on.
         */
        $document = new \DOMDocument();
        $document->loadXML('<cac:Root xmlns:cac="urn:cac"><cac:Item>a</cac:Item><cac:Item>b</cac:Item></cac:Root>');
        $root = $document->documentElement;
        self::assertNotNull($root);
        $ast = Parser::parse('//cac:Item');
        $cache = new PathCache();
        $interpreter = new Interpreter(self::NAMESPACES, pathCache: $cache);

        foreach ($root->childNodes as $item) {
            self::assertSame(2, $interpreter->evaluate($ast, $item)->count());
        }
        self::assertSame(2, $interpreter->evaluate($ast, $root)->count());

        self::assertSame(1, $cache->size());
    }

    /**
     * ⚠️ The document node's path is "/" in EVERY document. A cache keyed by that string would
     * answer for the second document with the first one's nodes when a caller reuses the cache.
     */
    public function testAbsolutePathIsNeverAnsweredFromAnotherDocument(): void
    {
        $cache = new PathCache();
        $interpreter = new Interpreter(self::NAMESPACES, pathCache: $cache);
        $ast = Parser::parse('/cac:Root/cac:Item/text()');

        // Both documents stay alive the whole time, so a key that confused them would be hit.
        $first = new \DOMDocument();
        $first->loadXML('<cac:Root xmlns:cac="urn:cac"><cac:Item>first</cac:Item></cac:Root>');
        $second = new \DOMDocument();
        $second->loadXML('<cac:Root xmlns:cac="urn:cac"><cac:Item>second</cac:Item></cac:Root>');

        $text = static fn ($s) => array_map(static fn ($n) => $n->textContent, $s->items());

        self::assertSame(['first'], $text($interpreter->evaluate($ast, $first)));
        self::assertSame(['second'], $text($interpreter->evaluate($ast, $second)));
        self::assertSame(['first'], $text($interpreter->evaluate($ast, $first)));
    }

    /** An absolute path whose predicate reads a loop variable must stay out of the per-document cache. */
    public function testAbsolutePathWithVariableIsNeverRememberedPerDocument(): void
    {
        $root = $this->dom(
            '<cac:Root xmlns:cac="urn:cac"><cac:Item cac:key="1">a</cac:Item><cac:Item cac:key="2">b</cac:Item></cac:Root>'
        );
        $interpreter = new Interpreter(self::NAMESPACES, pathCache: new PathCache());
        $ast = Parser::parse('for $k in (1, 2) return /cac:Root/cac:Item[@cac:key = $k]/text()');

        $result = $interpreter->evaluate($ast, $root->firstChild ?? $root);

        self::assertSame(['a', 'b'], array_map(static fn ($v) => $v->textContent, $result->items()));
    }

    /**
     * ⚠️ The made-up document behind a predicate on tokenized strings GROWS by one text node per
     * item. `count(//text())` inside such a predicate is therefore 1 for the first item, 2 for the
     * second and so on, and `[count(//text()) = 2]` keeps the second item - unless the first item's
     * answer was cached for the whole document, in which case none would match.
     */
    public function testAbsolutePathInsideAnAtomicPredicateIsNotRememberedPerDocument(): void
    {
        $root = $this->dom('<cac:Root xmlns:cac="urn:cac"><cac:Note>one#two#three</cac:Note></cac:Root>');
        $ast = Parser::parse("tokenize(cac:Note, '#')[count(//text()) = 2]");

        $uncached = (new Interpreter(self::NAMESPACES))->evaluate($ast, $root);
        $cached = (new Interpreter(self::NAMESPACES, pathCache: new PathCache()))->evaluate($ast, $root);

        $strings = static fn ($s) => array_map(static fn ($v) => $v->asString(), $s->items());

        self::assertSame(['two'], $strings($uncached));
        self::assertSame($strings($uncached), $strings($cached));
    }
}

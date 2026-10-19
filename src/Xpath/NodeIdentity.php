<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath;

/**
 * A stable, PHP-object-identity-independent key for a DOM node, valid within one document/DOM
 * tree. Needed anywhere a node's identity must survive a GAP between two separate DOM accesses -
 * `PathCache` (Task 2.5, keying a value stored in one evaluation and read back in a later one) and
 * `Engine` (Task 3.4, tracking which nodes an earlier, higher-priority rule already claimed across
 * several separate per-rule evaluations of the same pattern).
 */
final class NodeIdentity
{
    /**
     * ⚠️ `spl_object_id($node)` looks like a safe node-identity key and passes the obvious tests,
     * but is NOT stable: PHP's DOM extension hands out a fresh PHP wrapper object for the same
     * underlying libxml node on separate accesses (e.g. two calls to `$element->childNodes`), and
     * once an earlier wrapper array falls out of scope and is garbage collected, its id is recycled
     * - for a DIFFERENT node. Proven with a throwaway harness (Task 2.5): asking for
     * `$root->childNodes` twice, with `gc_collect_cycles()` between, yielded `<a>`=5,`<b>`=6` the
     * first time and `<a>`=3,`<b>`=5` the second time - `<b>`'s SECOND id collided with `<a>`'s
     * FIRST id. That collision is exactly what produced `PathCache`'s wrong-cache-hit bug, caught by
     * `PathCacheTest::testVariableDependentPredicateIsNeverWronglyCachedAcrossLoopIterations()`:
     * `evalPathUncached()` re-walks `childNodes` fresh on every `for`-loop iteration, so the
     * previous iteration's wrapper objects were eligible for GC before the next iteration ran.
     *
     * `DOMNode::getNodePath()` (a canonical, position-based XPath string like
     * `/cac:Root/cac:Item[2]/@cac:key`) does not depend on PHP object identity at all, so it stays
     * stable across repeated access - confirmed with the same harness. It returns null only for a
     * node without a path (e.g. a detached/document node with no owner); such nodes still need a
     * key, so this falls back to `spl_object_id()` for that rare case, accepting that a document
     * created and destroyed mid-request could theoretically recycle that id too - out of scope here
     * since both call sites are always short-lived (one document validation).
     *
     * By contrast, `Interpreter::dedupeNodes()`/`nodeSetUnion()` (Task 2.4) deliberately keep using
     * plain `spl_object_id()` - see their own docblocks for why that is safe THERE specifically (no
     * temporal gap: every item being compared is alive simultaneously within one merge), and why it
     * would stop being safe if either method ever changed to persist identity across separate
     * `evaluate()` calls the way `PathCache`/`Engine` do.
     */
    public static function of(\DOMNode $node): string
    {
        return $node->getNodePath() ?? ('#' . spl_object_id($node));
    }
}

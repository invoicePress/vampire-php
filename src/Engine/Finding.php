<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Engine;

use EinvoiceValidator\Result\Flag;

/**
 * One fired assertion - a `Rule`'s `test` evaluated to false against a context node it was chosen for.
 * Deliberately NOT a `Result\Message`: which `Result\Stage` a `Ruleset`'s patterns belong to is a
 * pipeline-wiring question outside Engine's own scope - whatever wires `Engine` into `Result` converts a
 * `Finding` into a `Message` once it knows that mapping.
 */
final readonly class Finding
{
    /**
     * @param string[] $fieldRefs
     * @param ?string  $duplicateKey Task 5.8 - the fired assertion's `duplicateKey`, carried through
     *                               so that whatever wires Engine into Result can recognise the SAME
     *                               rule coming out of two rule sets. `null` where the rule set knows
     *                               no key (a version-1 rule set, or a rule the catalogue does not
     *                               list); such findings are never merged.
     */
    public function __construct(
        public string $patternId,
        public string $patternName,
        public ?string $ruleId,
        public Flag $flag,
        public string $text,
        public string $location,
        public ?int $line,
        public array $fieldRefs,
        public ?string $duplicateKey = null,
    ) {
    }
}

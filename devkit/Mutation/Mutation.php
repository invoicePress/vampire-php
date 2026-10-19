<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Mutation;

/**
 * Task 4.2 — one targeted defect injected into a valid invoice, together with the rule it is meant
 * to trigger.
 *
 * ⚠️ `expectedRule` is declared BEFORE the mutation runs and is verified afterwards. Recording
 * whichever rule happened to fire would make the negative tests a mirror of the implementation: they
 * would pass by construction and prove nothing. If the declared rule does not fire, the generator
 * says so and writes nothing for that case.
 *
 * ⚠️ Mutations change TEXT and ATTRIBUTES, they do not delete elements unless the rule under test is
 * about a missing element. A removed mandatory element often fails XSD first, and the pipeline then
 * stops before Schematron (XsdMode::Stop) - the resulting negative test would prove the XSD stage
 * works, not the business rule. Where an element really must go, `xsdSafe` records that it was
 * checked to still pass XSD.
 */
final readonly class Mutation
{
    /**
     * @param 'ubl'|'cii'                  $syntax
     * @param 'setText'|'setAttribute'|'setTextAndAttribute'|'remove' $operation
     * @param ?string $attributeValue only for `setTextAndAttribute`: `$value` becomes the element's
     *                                text, this one the attribute's.
     * @param ?string $source path of the invoice to mutate, relative to `tests/fixtures/invoices/`.
     *                        `null` uses the default source of the syntax (the XRechnung example).
     *                        ⚠️ Task 4.7's identifier cases need a **Peppol** invoice: the checksum
     *                        rules are Peppol's own, and mutating an XRechnung document would produce
     *                        a defect that no rule set in that scenario ever looks at.
     */
    public function __construct(
        public string $id,
        public string $syntax,
        public string $xpath,
        public string $operation,
        public ?string $value,
        public ?string $attribute,
        public string $expectedRule,
        public string $why,
        public ?string $attributeValue = null,
        public ?string $source = null,
    ) {
    }
}

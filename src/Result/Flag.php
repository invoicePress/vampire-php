<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Result;

/**
 * Severity of a single rule violation. Names match the Schematron `flag`/`role` values actually
 * found in the compiled EN16931/XRechnung rule sets (see docs/notes/xpath-inventory.md) plus
 * `Information`, which the KoSIT configuration also uses for non-blocking notices.
 */
enum Flag: string
{
    case Fatal = 'fatal';
    case Warning = 'warning';
    case Information = 'information';

    public function blocksAcceptance(): bool
    {
        return $this === self::Fatal;
    }
}

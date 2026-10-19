<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Exception;

/**
 * The input was not wellformed XML. Carries every libxml error, not just the first, so the
 * caller can report all of them - nothing is skipped silently.
 */
final class NotWellformedException extends \RuntimeException
{
    /**
     * @param \LibXMLError[] $errors
     */
    public function __construct(public readonly array $errors)
    {
        $first = $errors[0] ?? null;
        $summary = $first !== null
            ? sprintf('not wellformed XML: %s (line %d)', trim($first->message), $first->line)
            : 'not wellformed XML';

        parent::__construct($summary);
    }
}

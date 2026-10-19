<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Exception;

final class DocumentTooLargeException extends \RuntimeException
{
    public function __construct(public readonly int $actualBytes, public readonly int $maxBytes)
    {
        parent::__construct(sprintf(
            'document is %d bytes, exceeds the configured limit of %d bytes',
            $actualBytes,
            $maxBytes
        ));
    }
}

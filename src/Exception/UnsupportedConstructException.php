<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Exception;

/**
 * Unknown XPath constructs lead to a clear error with its location. Thrown by the Parser (devkit) for a
 * construct outside docs/notes/xpath-whitelist.md, and by the Interpreter for a function/axis it does not
 * implement - never silently ignored, never guessed at.
 */
final class UnsupportedConstructException extends \RuntimeException
{
    public function __construct(string $construct, string $expression, public readonly int $pos)
    {
        parent::__construct(sprintf(
            'unsupported XPath construct "%s" at position %d in: %s',
            $construct,
            $pos,
            $expression
        ));
    }
}

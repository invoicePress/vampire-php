<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Xpath\Parser;

final readonly class Token
{
    public function __construct(public TokenKind $kind, public string $text, public int $pos)
    {
    }
}

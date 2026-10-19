<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Result;

/**
 * The three-stage pipeline (see konzept-php-validator-v1.md), plus Wellformed which runs first
 * and is not one of the three named stages but still needs its own status.
 */
enum Stage: string
{
    case Wellformed = 'wellformed';
    case Xsd = 'xsd';
    case Syntax = 'syntax';
    case Business = 'business';
}

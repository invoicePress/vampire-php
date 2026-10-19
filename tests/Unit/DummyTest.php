<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Task 0.1 acceptance check: `composer install` and `composer test` run green with a dummy test.
 * Replaced by real coverage as soon as Phase 1 introduces the first runtime classes.
 */
final class DummyTest extends TestCase
{
    public function testToolchainIsWired(): void
    {
        self::assertTrue(true);
    }
}

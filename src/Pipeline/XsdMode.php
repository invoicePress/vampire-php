<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Pipeline;

/**
 * Task 1.4 — what the orchestrator (Validator, Task 1.5) does when XsdStage finds a fatal error:
 * Stop (default) skips the Syntax/Business stages entirely; Continue runs them anyway against a
 * document that is known not to conform to its own schema (useful for "show me everything wrong
 * at once" tooling, at the cost of possibly-nonsensical downstream messages).
 */
enum XsdMode
{
    case Stop;
    case Continue;
}

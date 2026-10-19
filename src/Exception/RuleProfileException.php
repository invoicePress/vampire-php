<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Exception;

/**
 * A rule profile was refused. `problems` lists EVERY reason, each with a stable `code`, a `path` into
 * the profile and a `message`.
 *
 * ⚠️ The codes are shared with VAMPIRE JS (`RuleProfileError`) and
 * held to the same answers by `tests/fixtures/rule-profiles/invalid-cases.json`. The Rule Studio will
 * show its own text per code; the English message is for the log.
 */
final class RuleProfileException extends \RuntimeException
{
    /**
     * @param list<array{code: string, path: string, message: string}> $problems
     */
    public function __construct(public readonly array $problems, ?string $name = null)
    {
        $parts = array_map(
            static fn (array $p): string => ($p['path'] !== '' ? $p['path'] . ': ' : '') . $p['message'],
            array_slice($problems, 0, 3)
        );

        $more = count($problems) > 3 ? ' (and ' . (count($problems) - 3) . ' more)' : '';

        parent::__construct(
            'Rule profile' . ($name !== null ? ' "' . $name . '"' : '') . ' refused: ' . implode('; ', $parts) . $more
        );
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_values(array_unique(array_map(static fn (array $p): string => $p['code'], $this->problems)));
    }
}

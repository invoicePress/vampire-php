<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Profile;

/**
 * The edition range of a rule profile's `extends` entry. The twin of `parseRange()`, `compareVersions()`
 * and `versionInRange()` in VAMPIRE JS.
 *
 * Grammar: `*`, or terms separated by spaces that must all hold. A term is an edition with an optional
 * comparison (`>=3.0.2`, `<3.1`, `=1.3.16`, `1.3.16`) or a wildcard edition (`3.0.x`, `3.*`).
 *
 * ⚠️ NO `||`. An "or" is a second `extends` entry for the same rule set.
 */
final class VersionRange
{
    /**
     * @return list<array{op: string, v: string}>|null null when the text is not a range
     */
    public static function parse(string $text): ?array
    {
        $t = trim($text);

        if ($t === '*') {
            return [];
        }

        if ($t === '' || strlen($t) > 100) {
            return null;
        }

        $terms = [];

        foreach (preg_split('/\s+/', $t) ?: [] as $part) {
            if (preg_match('/^(>=|<=|>|<|=)?(\d{1,6}(?:\.\d{1,6}){0,3})$/', $part, $m) === 1) {
                $terms[] = ['op' => $m[1] !== '' ? $m[1] : '=', 'v' => $m[2]];
                continue;
            }

            if (preg_match('/^(\d{1,6}(?:\.\d{1,6}){0,2})\.[x*]$/', $part, $m) === 1) {
                $terms[] = ['op' => 'prefix', 'v' => $m[1]];
                continue;
            }

            return null;
        }

        return $terms;
    }

    /**
     * Segment by segment, numerically where both segments are digits.
     *
     * ⚠️ Not as strings: `3.0.10` is newer than `3.0.9`, and Factur-X writes `1.09.2`. A missing segment
     * counts as 0.
     */
    public static function compare(string $a, string $b): int
    {
        $pa = explode('.', self::core($a));
        $pb = explode('.', self::core($b));

        for ($i = 0, $n = max(count($pa), count($pb)); $i < $n; $i++) {
            $x = $pa[$i] ?? '0';
            $y = $pb[$i] ?? '0';

            if (ctype_digit($x) && ctype_digit($y)) {
                $d = (int) $x <=> (int) $y;

                if ($d !== 0) {
                    return $d;
                }
            } elseif ($x !== $y) {
                return strcmp($x, $y) < 0 ? -1 : 1;
            }
        }

        return 0;
    }

    /** The edition without a suffix after `-` or `+` (`3.0.2-rc1` → `3.0.2`), as `split( /[-+]/ )[ 0 ]` in JS. */
    private static function core(string $version): string
    {
        return substr($version, 0, strcspn($version, '-+'));
    }

    public static function contains(string $version, string $range): bool
    {
        $terms = self::parse($range);

        if ($terms === null) {
            return false;
        }

        foreach ($terms as $term) {
            if ($term['op'] === 'prefix') {
                $want = explode('.', $term['v']);
                $have = explode('.', self::core($version));

                foreach ($want as $i => $seg) {
                    if (!isset($have[$i]) || self::compare($seg, $have[$i]) !== 0) {
                        return false;
                    }
                }

                continue;
            }

            $c = self::compare($version, $term['v']);

            $holds = match ($term['op']) {
                '=' => $c === 0,
                '>=' => $c >= 0,
                '<=' => $c <= 0,
                '>' => $c > 0,
                default => $c < 0,
            };

            if (!$holds) {
                return false;
            }
        }

        return true;
    }
}

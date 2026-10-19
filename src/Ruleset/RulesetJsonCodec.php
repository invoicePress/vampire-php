<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Ruleset;

/**
 * `rulesets/<id>@<version>.json` <-> `Ruleset`. Used by both `devkit/Cli/import-ruleset.php` (writes) and
 * the runtime `Ruleset` loader (reads) - the same round trip `Xpath\Ast\AstSerializer` already established
 * for a single expression.
 *
 * ⚠️ **Written COMPACT, not pretty-printed.** Measured 21.09.2026 over all 18 rule packages:
 * pretty-printed **118.5 MB**, compact **18.6 MB** - 84 % of the size was indentation. These files
 * hold serialised XPath syntax trees; nobody reads them by hand, and whoever wants to look at one
 * pipes it through a formatter. The size is not cosmetic: every validation `json_decode()`s a whole
 * package, which is where the 174 MB memory peak of `docs/developer/performance.md` comes from, and a plugin
 * shipping them cannot carry a hundred megabytes of whitespace.
 */
final class RulesetJsonCodec
{
    public static function encode(Ruleset $ruleset): string
    {
        return json_encode(
            $ruleset->toArray(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    public static function decode(string $json): Ruleset
    {
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return Ruleset::fromArray($data);
    }

    public static function writeFile(string $path, Ruleset $ruleset): void
    {
        file_put_contents($path, self::encode($ruleset));
    }

    public static function readFile(string $path): Ruleset
    {
        $json = file_get_contents($path);
        if ($json === false) {
            throw new \RuntimeException("cannot read ruleset file: $path");
        }

        return self::decode($json);
    }
}

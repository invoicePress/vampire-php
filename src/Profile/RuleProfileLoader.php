<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Profile;

use EinvoiceValidator\Exception\RuleProfileException;

/**
 * Reads rule profiles from files: `<name>.json`, or `<name>.js` in the one fixed form the Rule Studio
 * writes for the apps (`vampire/profiles/<name>.js`).
 *
 *   $profiles = RuleProfileLoader::fromDirectory('/srv/kunde/profiles');
 *   $validator = new Validator(scenarioResolver: $resolver, ruleProfiles: $profiles);
 *
 * ⚠️ A `.js` FILE IS NEVER EXECUTED AND NEVER EVALUATED. It is matched character by character against
 * `SCRIPT_PATTERN`, the JSON string literal is taken out of it and decoded twice (the literal, then the
 * JSON text inside it). A file that differs from the fixed form by one character is refused - that is
 * what lets the same file serve the browser, where it has to be a script, and this side, where nothing
 * may run. The pattern is the twin of `SCRIPT_PATTERN` in VAMPIRE JS; the
 * test `testTheJsWriterAndThisReaderAgree` holds them together.
 *
 * ⚠️ THE LINE TERMINATORS U+2028 AND U+2029 ARE EXCLUDED FROM THE COMMENT LINES. JavaScript ends a `//`
 * comment there, so a pattern that only excluded `\n` would let a browser run code that this side
 * believed to be a comment.
 *
 * ⚠️ THE QUANTIFIERS INSIDE THE STRING LITERAL ARE POSSESSIVE (`++`, `*+`). With plain `*` PCRE keeps a
 * backtracking point per character; on the first real profile (11 KB) `preg_match()` hit its limit,
 * returned `false`, and the file was refused as "not in the fixed form" - a correct file, measured on
 * 08.10.2026. JavaScript's engine has no such limit, so only this side showed it.
 */
final class RuleProfileLoader
{
    public const SCRIPT_PATTERN = '~^(?://\x20[^\n\r\x{2028}\x{2029}]*\n)*'
        . '\(function\(g\)\{\(g\.einvoiceData=g\.einvoiceData\|\|\{\}\)\["vampire/profiles/([a-z0-9][a-z0-9-]{0,63})\.json"\]='
        . '("(?:[^"\\\\\n\r\x{2028}\x{2029}]++|\\\\[^\n\r\x{2028}\x{2029}])*+");'
        . '\}\)\(typeof globalThis!==\'undefined\'\?globalThis:this\);\n?$~u';

    /**
     * Every profile of a directory, in the order of its list.
     *
     * The list is `index.json` or `index.js` (as the Studio writes it). Without a list, every
     * `*.json` and `*.js` in the directory is read, sorted by name.
     *
     * ⚠️ A profile that the list names and that is missing or refused throws. Leaving it out would let
     * the customer believe its rules had run.
     *
     * @return list<RuleProfile>
     */
    public static function fromDirectory(string $directory): array
    {
        $dir = rtrim($directory, '/\\');

        if (!is_dir($dir)) {
            throw new RuleProfileException([['code' => 'missing', 'path' => '', 'message' => "profile directory not found: $dir"]]);
        }

        $entries = null;

        foreach (['index.json', 'index.js'] as $indexFile) {
            if (is_file("$dir/$indexFile")) {
                $entries = self::readIndex(self::payload("$dir/$indexFile", 'index'));
                break;
            }
        }

        $profiles = [];

        if ($entries !== null) {
            foreach ($entries as $entry) {
                $file = is_file("$dir/{$entry['name']}.json") ? "$dir/{$entry['name']}.json" : "$dir/{$entry['name']}.js";
                $profile = self::fromFile($file);

                if ($profile->name !== $entry['name'] || $profile->version !== $entry['version']) {
                    throw new RuleProfileException([[
                        'code' => 'index',
                        'path' => '',
                        'message' => basename($file) . ' holds ' . $profile->ref . ', the list expects ' . $entry['name'] . '@' . $entry['version'],
                    ]]);
                }

                $profiles[] = $profile;
            }

            return $profiles;
        }

        $files = array_merge(glob("$dir/*.json") ?: [], glob("$dir/*.js") ?: []);
        sort($files);

        $seen = [];

        foreach ($files as $file) {
            $profile = self::fromFile($file);

            // ⚠️ `muster.json` and `muster.js` side by side would apply the same profile twice.
            if (isset($seen[$profile->name])) {
                throw new RuleProfileException([['code' => 'index', 'path' => '', 'message' => "profile \"{$profile->name}\" is present twice in $dir"]]);
            }

            $seen[$profile->name] = true;
            $profiles[] = $profile;
        }

        return $profiles;
    }

    public static function fromFile(string $path): RuleProfile
    {
        $json = self::payload($path, null);
        $profile = RuleProfileValidator::validate($json);

        // ⚠️ The file name is the registry key in the browser; a renamed file would load under one name and report another.
        $base = (string) preg_replace('/\.(json|js)$/', '', basename($path));

        if ($base !== $profile->name) {
            throw new RuleProfileException([['code' => 'name', 'path' => 'name', 'message' => basename($path) . ' holds the profile "' . $profile->name . '"; file name and profile name must agree']]);
        }

        return $profile;
    }

    /**
     * The JSON text of a profile or list file.
     *
     * @param string|null $expectName `index` for the list; null for a profile
     */
    public static function payload(string $path, ?string $expectName): string
    {
        if (!is_file($path)) {
            throw new RuleProfileException([['code' => 'missing', 'path' => '', 'message' => 'profile file not found: ' . basename($path)]]);
        }

        if (filesize($path) > RuleProfileValidator::LIMIT_BYTES * 2) {
            throw new RuleProfileException([['code' => 'size', 'path' => '', 'message' => basename($path) . ' is too large to be a profile']]);
        }

        $text = (string) file_get_contents($path);

        // ⚠️ PowerShell and some editors write a BOM; `json_decode()` fails on it with "Syntax error".
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        if (!str_ends_with(strtolower($path), '.js')) {
            return $text;
        }

        return self::parseScript($text, $expectName);
    }

    /**
     * Reads a profile script without executing it - the twin of `parseProfileScript()`.
     *
     * @return string the JSON text inside the file
     */
    public static function parseScript(string $text, ?string $expectName = null): string
    {
        if (preg_match(self::SCRIPT_PATTERN, $text, $m) !== 1) {
            throw new RuleProfileException([['code' => 'script-form', 'path' => '', 'message' => 'the file is not in the fixed form the Rule Studio writes; it is not read']]);
        }

        if ($expectName !== null && $m[1] !== $expectName) {
            throw new RuleProfileException([['code' => 'script-form', 'path' => '', 'message' => 'the file registers "' . $m[1] . '", expected "' . $expectName . '"']]);
        }

        try {
            $json = json_decode($m[2], false, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $json = null;
        }

        if (!is_string($json)) {
            throw new RuleProfileException([['code' => 'script-form', 'path' => '', 'message' => 'the registered value is not a JSON string literal']]);
        }

        return $json;
    }

    /**
     * @return list<array{name: string, version: string}>
     */
    private static function readIndex(string $json): array
    {
        $bad = static fn (string $message): RuleProfileException => new RuleProfileException([['code' => 'index', 'path' => '', 'message' => $message]]);

        try {
            $data = json_decode($json, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw $bad('the profile list is not valid JSON');
        }

        if (
            !$data instanceof \stdClass
            || ($data->format ?? null) !== 'vampire-profile-index'
            || ($data->formatVersion ?? null) !== 1
            || !is_array($data->profiles ?? null)
        ) {
            throw $bad('the profile list is not a "vampire-profile-index" of version 1');
        }

        $out = [];
        $seen = [];

        foreach ($data->profiles as $i => $e) {
            $name = $e instanceof \stdClass ? ($e->name ?? null) : null;
            $version = $e instanceof \stdClass ? ($e->version ?? null) : null;

            if (
                !is_string($name) || preg_match(RuleProfileValidator::NAME_PATTERN, $name) !== 1
                || !is_string($version) || preg_match(RuleProfileValidator::VERSION_PATTERN, $version) !== 1
            ) {
                throw $bad("entry $i is not { name, version }");
            }

            if (isset($seen[$name])) {
                throw $bad("profile \"$name\" is listed twice");
            }

            $seen[$name] = true;
            $out[] = ['name' => $name, 'version' => $version];
        }

        return $out;
    }
}

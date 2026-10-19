<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Compiles a rule profile written with XPath TEXT into the profile format both engines read (compiled
 * trees). Until the Rule Studio exists, this is how a profile is made.
 *
 *   php devkit/Cli/compile-rule-profile.php <quelle.json> [weitere.json ...] [--out=<ordner>] [--apply]
 *
 * The source is a profile document (format: `docs/developer/profile-format.md`) in which every
 * expression field may be a string: `globalLets[].expr`, `rules[].context`, `rules[].lets[].expr`,
 * `rules[].test` and `rules[].message[].expr`. Each string is parsed with the devkit parser, replaced by
 * its tree, and kept as display text under `rules[].source`. The result is checked with
 * `RuleProfileValidator` before it is written as `<out>/<name>.json`; without `--out` next to the source
 * with `.source` removed from the name. Without `--apply` nothing is written.
 *
 * ⚠️ THE PARSER LIVES IN devkit, NOT IN THE RUNTIME, as for the official rule sets: a profile ships as
 * trees. The Rule Studio will build those trees itself, either from its condition tree directly or with
 * a port of this parser for its expert mode; it does not need this script.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Profile\RuleProfileValidator;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));

$apply = in_array('--apply', $argv, true);
$outDir = null;
$files = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        continue;
    }

    if (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
        continue;
    }

    $files[] = $arg;
}

if ($files === []) {
    fwrite(STDERR, "usage: php devkit/Cli/compile-rule-profile.php <quelle.json> [...] [--out=<ordner>] [--apply]\n");
    exit(1);
}

$compile = static function (mixed $v): mixed {
    return is_string($v) ? Parser::parse($v)->toArray() : $v;
};

foreach ($files as $file) {
    $raw = file_get_contents($file);

    if ($raw === false) {
        fwrite(STDERR, "cannot read $file\n");
        exit(1);
    }

    /** @var array<string, mixed> $profile */
    $profile = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    foreach ($profile['globalLets'] ?? [] as $i => $let) {
        $profile['globalLets'][$i]['expr'] = $compile($let['expr'] ?? null);
    }

    foreach ($profile['rules'] ?? [] as $i => $rule) {
        $source = [];

        foreach (['context', 'test'] as $k) {
            if (isset($rule[$k]) && is_string($rule[$k])) {
                $source[$k] = $rule[$k];
                $profile['rules'][$i][$k] = $compile($rule[$k]);
            }
        }

        foreach ($rule['lets'] ?? [] as $j => $let) {
            if (is_string($let['expr'] ?? null)) {
                $source['let:' . $let['name']] = $let['expr'];
            }

            $profile['rules'][$i]['lets'][$j]['expr'] = $compile($let['expr'] ?? null);
        }

        if (is_array($rule['message'] ?? null)) {
            foreach ($rule['message'] as $j => $segment) {
                if (($segment['kind'] ?? null) === 'expr') {
                    $profile['rules'][$i]['message'][$j]['expr'] = $compile($segment['expr'] ?? null);
                }
            }
        }

        if ($source !== []) {
            $profile['rules'][$i]['source'] = $source;
        }
    }

    $json = json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $checked = RuleProfileValidator::validate($json);

    $target = ($outDir ?? dirname($file)) . '/' . $checked->name . '.json';

    printf(
        "%-28s %d Regeln, %d abgeschaltet, %d ersetzt, %d umgestuft -> %s%s\n",
        $checked->ref,
        count($checked->rules),
        count($checked->disable),
        count($checked->replaces),
        count($checked->severity),
        $target,
        $apply ? '' : ' (Trockenlauf)'
    );

    if ($apply) {
        file_put_contents($target, $json . "\n");
    }
}

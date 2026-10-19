<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Profile;

use EinvoiceValidator\Exception\RuleProfileException;

/**
 * Checks a rule profile of the Rule Studio and returns the normalised `RuleProfile`, or throws
 * `RuleProfileException` with every problem.
 *
 * The twin of `validateRuleProfile()` in VAMPIRE JS. The format is described in
 * `docs/developer/profile-format.md`.
 *
 * ⚠️ A PROFILE IS CUSTOMER-AUTHORED DATA AND IS TREATED AS UNTRUSTED. Its rules are compiled XPath
 * trees; every node is walked here before it can reach the interpreter, and anything outside the
 * allow-lists refuses the whole profile. At run time an unknown function or an unbound prefix would
 * only throw when the rule fires - on every invoice where it does not fire, the profile would pass in
 * silence.
 *
 * ⚠️ THE ALLOW-LISTS ARE A SUBSET OF WHAT BOTH ENGINES KNOW and are copied word for word from the JS
 * twin. Both interpreters also evaluate `intersect`, `except`, `is`, `element()`, `attribute()`,
 * the axis `ancestor-or-self` and functions such as `min()` or `position()`; they stay refused here
 * because allowing them widens the profile format, which has to happen in both validators and in
 * `docs/developer/profile-format.md` at once. This side also knows `fn:document()`, which a profile
 * must never call because it opens a file. Changing a list here means changing it there in the same
 * step - `tests/fixtures/rule-profiles/invalid-cases.json` runs on both sides and fails otherwise.
 *
 * ⚠️ THE JSON IS DECODED INTO OBJECTS, NOT INTO ARRAYS. `json_decode(..., true)` turns `{}` and `[]`
 * into the same empty array, and an object with the keys "0" and "1" into a list. The checks below need
 * to know which of the two the author wrote, as JavaScript does.
 */
final class RuleProfileValidator
{
    public const FORMAT = 'vampire-profile';
    public const FORMAT_VERSION = 1;

    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';
    public const VERSION_PATTERN = '/^\d{1,6}(\.\d{1,6}){0,3}$/';
    public const PREFIX_PATTERN = '/^[A-Z][A-Z0-9]{1,15}$/';

    /** Measured on 08.10.2026 over all 18 rule sets, plus likely families - see the JS twin. */
    public const RESERVED_PREFIXES = [
        'BR', 'CII', 'DE', 'DK', 'DT', 'FX', 'GR', 'HR', 'IS', 'IT', 'NL', 'NO', 'PEPPOL', 'RSE', 'RSK', 'RSR', 'SE', 'UBL',
        'AT', 'BE', 'BG', 'BT', 'CEN', 'CIUS', 'EN', 'EN16931', 'ES', 'FR', 'PT', 'RO', 'RS', 'SK', 'TR', 'XR', 'XRECHNUNG', 'ZUGFERD', 'FACTURX',
    ];

    private const OWN_RULE_ID = '/^[A-Z][A-Z0-9]*(-[A-Za-z0-9]+)+$/';
    private const OFFICIAL_RULE_ID = '/^[A-Za-z][A-Za-z0-9._-]{0,63}$/';
    private const RULESET_ID = '/^[A-Za-z][A-Za-z0-9._-]{0,63}$/';
    private const PREFIX_NAME = '/^[A-Za-z][A-Za-z0-9_-]{0,31}$/';
    private const VARIABLE_NAME = '/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/';

    public const SEVERITIES = ['fatal', 'warning', 'information'];
    public const SYNTAXES = ['ubl', 'cii'];

    public const LIMIT_BYTES = 4 * 1024 * 1024;
    public const LIMIT_RULES = 2000;
    public const LIMIT_ENTRIES = 2000;
    public const LIMIT_DEPTH = 64;
    public const LIMIT_NODES = 200000;
    public const LIMIT_TEXT = 4000;
    public const LIMIT_PROBLEMS = 50;

    public const ALLOWED_NODES = [
        'PathExpr', 'BinaryOp', 'UnaryOp', 'FunctionCall', 'VarRef', 'Literal', 'SequenceExpr', 'RangeExpr',
        'IfExpr', 'ForExpr', 'QuantifiedExpr', 'LetExpr', 'CastableExpr', 'CastExpr', 'ParenExpr',
    ];

    public const ALLOWED_OPERATORS = [
        'and', 'or', '=', '!=', '<', '<=', '>', '>=', 'eq', 'ne', 'lt', 'le', 'gt', 'ge',
        '+', '-', '*', 'div', 'idiv', 'mod', '|', 'union',
    ];

    /**
     * ⚠️ `ancestor-or-self` is missing ON PURPOSE, although both engines know it. Allowing it widens the
     * profile format, so it has to happen in both twins, in `invalid-cases.json` and in
     * `docs/developer/profile-format.md` in one step - adding it here alone would make the PHP side
     * accept profiles the JS side refuses.
     */
    public const ALLOWED_AXES = [
        'child', 'attribute', 'self', 'parent', 'descendant', 'descendant-or-self', 'ancestor',
        'preceding-sibling', 'following-sibling', 'preceding', 'following',
    ];

    public const ALLOWED_KINDTESTS = ['node', 'text'];
    public const ALLOWED_CONSTRUCTORS = ['decimal', 'integer', 'string', 'date'];
    public const ALLOWED_CAST_TYPES = ['string', 'decimal', 'integer', 'boolean', 'date'];

    /**
     * Function => [min, max] arguments; `-1` as max means "no upper bound" (`concat`).
     *
     * ⚠️ `matches()` with flags and `replace()` with flags are refused: `fnMatches()` and `fnReplace()`
     * read only the first two or three arguments and would drop the flags in silence. `last()`,
     * `position()`, `empty()`, `lower-case()`, `floor()`, `ceiling()`, `min()`, `max()`,
     * `namespace-uri()` and `codepoints-to-string()` work in both engines and are missing ON PURPOSE,
     * for the same reason as `ancestor-or-self` above: allowing them widens the profile format, which
     * happens in both twins and in `profilformat.md` in one step.
     */
    public const ALLOWED_FUNCTIONS = [
        'not' => [1, 1], 'true' => [0, 0], 'false' => [0, 0], 'boolean' => [1, 1], 'exists' => [1, 1],
        'normalize-space' => [0, 1], 'upper-case' => [1, 1], 'string-length' => [0, 1], 'concat' => [2, -1],
        'substring' => [2, 3], 'substring-after' => [2, 2], 'substring-before' => [2, 2], 'contains' => [2, 2],
        'starts-with' => [2, 2], 'ends-with' => [2, 2], 'string' => [0, 1], 'string-join' => [1, 2],
        'translate' => [3, 3], 'string-to-codepoints' => [1, 1], 'matches' => [2, 2], 'replace' => [3, 3],
        'tokenize' => [2, 2], 'count' => [1, 1], 'sum' => [1, 1], 'round' => [1, 1], 'abs' => [1, 1],
        'distinct-values' => [1, 1], 'name' => [0, 1], 'local-name' => [0, 1], 'number' => [0, 1], 'data' => [0, 1],
        'index-of' => [2, 2],
        'u:slack' => [3, 3], 'u:decimalOrZero' => [1, 1], 'u:gln' => [1, 1], 'u:mod11' => [1, 1], 'u:abn' => [1, 1],
    ];

    private const KEYS = [
        'profile' => ['format', 'formatVersion', 'name', 'version', 'title', 'note', 'syntax', 'prefix', 'extends', 'namespaces',
            'globalLets', 'rules', 'disable', 'replaces', 'severity', 'studio'],
        'extends' => ['ruleset', 'versions'],
        'rule' => ['id', 'title', 'flag', 'kind', 'context', 'lets', 'test', 'message', 'fields', 'source', 'reference', 'hint'],
        'let' => ['name', 'expr'],
        'disable' => ['rule', 'reason'],
        'replaces' => ['rule', 'by', 'reason'],
        'severity' => ['rule', 'level', 'reason'],
        'segment' => ['kind', 'text', 'expr'],
        'step' => ['axis', 'test', 'predicates', 'primary', 'pos'],
        'nodetest' => ['kind', 'name', 'kindtestName'],
        'qname' => ['prefix', 'local'],
        'PathExpr' => ['node', 'pos', 'absolute', 'steps'],
        'BinaryOp' => ['node', 'pos', 'op', 'left', 'right'],
        'UnaryOp' => ['node', 'pos', 'op', 'operand'],
        'FunctionCall' => ['node', 'pos', 'name', 'args'],
        'VarRef' => ['node', 'pos', 'name'],
        'Literal' => ['node', 'pos', 'kind', 'value'],
        'SequenceExpr' => ['node', 'pos', 'items'],
        'RangeExpr' => ['node', 'pos', 'from', 'to'],
        'IfExpr' => ['node', 'pos', 'cond', 'then', 'else'],
        'ForExpr' => ['node', 'pos', 'var', 'in', 'return'],
        'QuantifiedExpr' => ['node', 'pos', 'quantifier', 'var', 'in', 'satisfies'],
        'LetExpr' => ['node', 'pos', 'var', 'value', 'return'],
        'CastableExpr' => ['node', 'pos', 'operand', 'type', 'optional'],
        'CastExpr' => ['node', 'pos', 'operand', 'type', 'optional'],
        'ParenExpr' => ['node', 'pos', 'inner'],
    ];

    /** @var list<array{code: string, path: string, message: string}> */
    private array $problems = [];

    private int $nodes = 0;

    /** @var array<string, string> */
    private array $namespaces = [];

    /**
     * @param string|array<string, mixed>|\stdClass $input the JSON text, or the decoded profile
     */
    public static function validate(string|array|\stdClass $input): RuleProfile
    {
        return (new self())->run($input);
    }

    /**
     * @param string|array<string, mixed>|\stdClass $input
     */
    private function run(string|array|\stdClass $input): RuleProfile
    {
        if (is_string($input)) {
            if (strlen($input) > self::LIMIT_BYTES) {
                throw new RuleProfileException([['code' => 'size', 'path' => '', 'message' => 'the profile is larger than ' . self::LIMIT_BYTES . ' bytes']]);
            }

            $text = $input;
        } else {
            // ⚠️ Through JSON once, so that a PHP array and a decoded file take the same path below.
            $text = json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($text === false) {
                throw new RuleProfileException([['code' => 'json', 'path' => '', 'message' => 'not serialisable as JSON']]);
            }
        }

        try {
            $data = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuleProfileException([['code' => 'json', 'path' => '', 'message' => 'not valid JSON: ' . $e->getMessage()]]);
        }

        if (!$data instanceof \stdClass) {
            throw new RuleProfileException([['code' => 'not-object', 'path' => '', 'message' => 'a profile is a JSON object']]);
        }

        $this->onlyKeys($data, self::KEYS['profile'], '');

        $get = static fn (\stdClass $o, string $k): mixed => property_exists($o, $k) ? $o->{$k} : null;
        $has = static fn (\stdClass $o, string $k): bool => property_exists($o, $k);

        if ($get($data, 'format') !== self::FORMAT) {
            $this->add('format', 'format', 'must be "' . self::FORMAT . '"');
        }

        if (!self::isNumberEqual($get($data, 'formatVersion'), self::FORMAT_VERSION)) {
            $this->add('format-version', 'formatVersion', 'must be ' . self::FORMAT_VERSION . '; this engine reads no other version');
        }

        $name = is_string($get($data, 'name')) ? $get($data, 'name') : null;

        // ⚠️ `index` is the registry key of the profile list in the browser; a profile of that name would overwrite it.
        if ($name === null || preg_match(self::NAME_PATTERN, $name) !== 1 || $name === 'index') {
            $this->add('name', 'name', 'lower-case letters, digits and hyphens, at most 64 characters, not "index"');
        }

        $version = $get($data, 'version');

        if (!is_string($version) || preg_match(self::VERSION_PATTERN, $version) !== 1) {
            $this->add('version', 'version', 'a version like 1.0 or 1.2.3');
        }

        $this->text($get($data, 'title'), 'title');
        $this->text($get($data, 'note'), 'note');

        $syntax = $get($data, 'syntax');

        if (!in_array($syntax, self::SYNTAXES, true)) {
            $this->add('syntax', 'syntax', 'must be "ubl" or "cii"');
        }

        $prefix = is_string($get($data, 'prefix')) ? $get($data, 'prefix') : null;

        if ($prefix === null || preg_match(self::PREFIX_PATTERN, $prefix) !== 1) {
            $this->add('prefix', 'prefix', 'upper-case letters and digits, 2 to 16 characters, starting with a letter');
        } elseif (in_array(strtoupper($prefix), self::RESERVED_PREFIXES, true)) {
            $this->add('prefix-reserved', 'prefix', '"' . $prefix . '" is used by official rule sets');
        }

        // --- extends ---

        $extends = [];
        $ext = $get($data, 'extends');

        if (!is_array($ext) || $ext === []) {
            $this->add('extends', 'extends', 'name at least one official rule set the profile was written for');
        } else {
            foreach ($ext as $i => $e) {
                $path = "extends[$i]";

                if (!$e instanceof \stdClass) {
                    $this->add('type', $path, 'must be an object');
                    continue;
                }

                $this->onlyKeys($e, self::KEYS['extends'], $path);

                $ruleset = $get($e, 'ruleset');

                if (!is_string($ruleset) || preg_match(self::RULESET_ID, $ruleset) !== 1 || str_contains($ruleset, '@')) {
                    $this->add('extends', $path . '.ruleset', 'a rule set id without edition, e.g. "XRechnung-UBL"');
                    continue;
                }

                $rsSyntax = self::syntaxOfRuleset($ruleset);

                if ($rsSyntax !== null && in_array($syntax, self::SYNTAXES, true) && $rsSyntax !== $syntax) {
                    $this->add(
                        'extends-syntax',
                        $path . '.ruleset',
                        '"' . $ruleset . '" checks ' . strtoupper($rsSyntax) . ', the profile is written for ' . strtoupper((string) $syntax)
                    );
                }

                $versions = $get($e, 'versions');

                if (!is_string($versions) || VersionRange::parse($versions) === null) {
                    $this->add('range', $path . '.versions', 'a range like "3.0.2", "3.0.x", ">=3.0.2 <3.1" or "*"');
                }

                $extends[] = ['ruleset' => $ruleset, 'versions' => is_string($versions) ? $versions : ''];
            }
        }

        // --- namespaces ---

        $namespaces = [];

        if ($has($data, 'namespaces')) {
            $ns = $get($data, 'namespaces');

            // ⚠️ `{}` decodes to an empty stdClass and `[]` to an array - the second is refused, as in JS.
            if (!$ns instanceof \stdClass) {
                $this->add('type', 'namespaces', 'must be an object of prefix → URI');
            } else {
                foreach (get_object_vars($ns) as $p => $uri) {
                    $p = (string) $p;

                    if (preg_match(self::PREFIX_NAME, $p) !== 1 || in_array($p, ['xs', 'u', 'fn'], true)) {
                        $this->add('namespace', 'namespaces.' . $p, 'not a usable prefix ( xs, u and fn are reserved )');
                    } elseif (!is_string($uri) || $uri === '' || mb_strlen($uri) > 512) {
                        $this->add('namespace', 'namespaces.' . $p, 'the URI must be a non-empty string');
                    } else {
                        $namespaces[$p] = $uri;
                    }
                }
            }
        }

        $this->namespaces = $namespaces;

        // --- global lets ---

        $globals = [];
        $globalLets = $this->lets($get($data, 'globalLets'), 'globalLets', $globals, $has($data, 'globalLets'));

        // --- rules ---

        $rules = [];
        $ownIds = [];
        $rulesIn = $get($data, 'rules');

        if ($has($data, 'rules') && !is_array($rulesIn)) {
            $this->add('type', 'rules', 'must be a list');
        } elseif (is_array($rulesIn) && count($rulesIn) > self::LIMIT_RULES) {
            $this->add('size', 'rules', 'more than ' . self::LIMIT_RULES . ' rules');
        } else {
            foreach ((array) $rulesIn as $i => $r) {
                $path = "rules[$i]";

                if (!$r instanceof \stdClass) {
                    $this->add('type', $path, 'must be an object');
                    continue;
                }

                $this->onlyKeys($r, self::KEYS['rule'], $path);

                $id = $get($r, 'id');

                if (!is_string($id) || preg_match(self::OWN_RULE_ID, $id) !== 1 || strlen($id) > 64) {
                    $this->add('rule-id', $path . '.id', 'an id like "' . ($prefix ?? 'MUSTER') . '-UST-001"');
                } elseif ($prefix !== null && !str_starts_with($id, $prefix . '-')) {
                    $this->add('rule-id-prefix', $path . '.id', 'must start with "' . $prefix . '-" so that the report can tell it from an official rule');
                } elseif (in_array($id, $ownIds, true)) {
                    $this->add('rule-id-duplicate', $path . '.id', '"' . $id . '" appears twice');
                } else {
                    $ownIds[] = $id;
                }

                $flag = $get($r, 'flag');

                if (!in_array($flag, self::SEVERITIES, true)) {
                    $this->add('flag', $path . '.flag', 'must be "fatal", "warning" or "information"');
                }

                $kind = $has($r, 'kind') ? $get($r, 'kind') : 'assert';

                if ($kind !== 'assert' && $kind !== 'report') {
                    $this->add('kind', $path . '.kind', 'must be "assert" or "report"');
                }

                $this->text($get($r, 'title'), $path . '.title');
                $this->text($get($r, 'reference'), $path . '.reference');
                $this->text($get($r, 'hint'), $path . '.hint');

                if ($has($r, 'source')) {
                    // Display only: never parsed, never evaluated.
                    $source = $get($r, 'source');

                    if (!$source instanceof \stdClass) {
                        $this->add('type', $path . '.source', 'must be an object');
                    } else {
                        foreach (get_object_vars($source) as $k => $v) {
                            if (!is_string($v) || mb_strlen($v) > self::LIMIT_TEXT) {
                                $this->add('type', $path . '.source.' . $k, 'must be a string');
                            }
                        }
                    }
                }

                // ⚠️ The context sees the globals only, the test also the rule's own lets - the order
                // in which `Engine` binds them.
                $context = $this->expression($get($r, 'context'), $path . '.context', $globals, 0);
                $scope = $globals;
                $ruleLets = $this->lets($get($r, 'lets'), $path . '.lets', $scope, $has($r, 'lets'));
                $test = $this->expression($get($r, 'test'), $path . '.test', $scope, 0);
                $message = $this->segments($get($r, 'message'), $path . '.message', $scope);

                $fields = [];

                if ($has($r, 'fields')) {
                    $fieldsIn = $get($r, 'fields');

                    if (!is_array($fieldsIn)) {
                        $this->add('type', $path . '.fields', 'must be a list of BT/BG references');
                    } else {
                        foreach ($fieldsIn as $j => $f) {
                            if (!is_string($f) || preg_match('/^B[TG]-\d{1,3}(-\d{1,2})?$/', $f) !== 1) {
                                $this->add('field', $path . ".fields[$j]", 'a reference like "BT-72" or "BG-14"');
                            } else {
                                $fields[] = $f;
                            }
                        }
                    }
                }

                $rules[] = [
                    'id' => is_string($id) ? $id : '',
                    'flag' => is_string($flag) ? $flag : 'fatal',
                    'kind' => is_string($kind) ? $kind : 'assert',
                    'context' => $context,
                    'lets' => $ruleLets,
                    'test' => $test,
                    'message' => $message,
                    'fields' => $fields,
                ];
            }
        }

        // --- disable, replaces, severity ---

        /** @var array<string, string> $touched rule id => the list that named it first */
        $touched = [];

        $entryList = function (string $key, callable $each) use ($data, $get, $has, $ownIds, $prefix, &$touched): array {
            $out = [];

            if (!$has($data, $key)) {
                return $out;
            }

            $list = $get($data, $key);

            if (!is_array($list)) {
                $this->add('type', $key, 'must be a list');
                return $out;
            }

            if (count($list) > self::LIMIT_ENTRIES) {
                $this->add('size', $key, 'more than ' . self::LIMIT_ENTRIES . ' entries');
                return $out;
            }

            foreach ($list as $i => $e) {
                $path = $key . "[$i]";

                if (!$e instanceof \stdClass) {
                    $this->add('type', $path, 'must be an object');
                    continue;
                }

                $this->onlyKeys($e, self::KEYS[$key], $path);

                $rule = $get($e, 'rule');

                if (!is_string($rule) || preg_match(self::OFFICIAL_RULE_ID, $rule) !== 1) {
                    $this->add('entry-rule', $path . '.rule', 'an official rule id like "BR-DE-15"');
                    continue;
                }

                if (in_array($rule, $ownIds, true) || ($prefix !== null && str_starts_with(strtoupper($rule), $prefix . '-'))) {
                    $this->add('own-rule-entry', $path . '.rule', '"' . $rule . '" is a rule of this profile, not an official one');
                    continue;
                }

                if (isset($touched[$rule])) {
                    $this->add(
                        $touched[$rule] === $key ? 'entry-duplicate' : 'conflict',
                        $path . '.rule',
                        '"' . $rule . '" is already named in "' . $touched[$rule] . '"; a rule is switched off, replaced or re-ranked, never two of these'
                    );
                    continue;
                }

                $touched[$rule] = $key;

                $out[] = $each($e, $path, $rule);
            }

            return $out;
        };

        $reason = function (\stdClass $e, string $path, bool $required) use ($get, $has): ?string {
            if (!$has($e, 'reason') && !$required) {
                return null;
            }

            $r = $get($e, 'reason');

            if (!is_string($r) || trim($r) === '') {
                $this->add('entry-reason', $path . '.reason', 'say why - the report quotes it next to the official rule');
                return null;
            }

            if (mb_strlen($r) > self::LIMIT_TEXT) {
                $this->add('size', $path . '.reason', 'longer than ' . self::LIMIT_TEXT . ' characters');
                return null;
            }

            return trim($r);
        };

        $disable = $entryList('disable', fn (\stdClass $e, string $path, string $rule): array => [
            'rule' => $rule,
            'reason' => $reason($e, $path, true),
        ]);

        $replaces = $entryList('replaces', function (\stdClass $e, string $path, string $rule) use ($get, $ownIds, $reason): array {
            $by = $get($e, 'by');

            if (!is_string($by) || !in_array($by, $ownIds, true)) {
                $this->add('replaces-by', $path . '.by', 'must name a rule of this profile');
            }

            return ['rule' => $rule, 'by' => is_string($by) ? $by : '', 'reason' => $reason($e, $path, true)];
        });

        $severity = $entryList('severity', function (\stdClass $e, string $path, string $rule) use ($get, $reason): array {
            $level = $get($e, 'level');

            if (!in_array($level, self::SEVERITIES, true)) {
                $this->add('severity-level', $path . '.level', 'must be "fatal", "warning" or "information"');
            }

            return ['rule' => $rule, 'level' => is_string($level) ? $level : '', 'reason' => $reason($e, $path, false)];
        });

        if ($rules === [] && $disable === [] && $replaces === [] && $severity === []) {
            $this->add('no-content', '', 'the profile neither adds, switches off, replaces nor re-ranks a rule');
        }

        if ($this->problems !== []) {
            throw new RuleProfileException($this->problems, $name);
        }

        /** @var string $name */
        /** @var string $version */
        /** @var string $syntax */
        /** @var string $prefix */
        $title = $get($data, 'title');

        return new RuleProfile(
            $name,
            $version,
            is_string($title) ? $title : null,
            $syntax,
            $prefix,
            $extends,
            $namespaces,
            $globalLets,
            $rules,
            $disable,
            $replaces,
            $severity,
        );
    }

    // ---- the walk -------------------------------------------------------------------------------

    private function add(string $code, string $path, string $message): void
    {
        if (count($this->problems) < self::LIMIT_PROBLEMS) {
            $this->problems[] = ['code' => $code, 'path' => $path, 'message' => $message];
        }
    }

    /**
     * @param list<string> $allowed
     */
    private function onlyKeys(\stdClass $obj, array $allowed, string $path): void
    {
        foreach (array_keys(get_object_vars($obj)) as $k) {
            if (!in_array((string) $k, $allowed, true)) {
                $this->add('unknown-key', ($path !== '' ? $path . '.' : '') . $k, 'unknown field');
            }
        }
    }

    private function text(mixed $v, string $path): void
    {
        if ($v === null) {
            return;
        }

        if (!is_string($v)) {
            $this->add('type', $path, 'must be a string');
        } elseif (mb_strlen($v) > self::LIMIT_TEXT) {
            $this->add('size', $path, 'longer than ' . self::LIMIT_TEXT . ' characters');
        }
    }

    /**
     * A JSON number that equals `$want`; `1.0` counts, as it does in JavaScript, where it is the same
     * number as `1`.
     */
    private static function isNumberEqual(mixed $v, int $want): bool
    {
        return (is_int($v) && $v === $want) || (is_float($v) && $v === (float) $want);
    }

    private static function isInteger(mixed $v): bool
    {
        return is_int($v) || (is_float($v) && floor($v) === $v && is_finite($v));
    }

    /**
     * @param list<string> $scope the variables bound so far; each name joins it after its own expression
     * @return list<array{name: string, expr: array<string, mixed>|null}>
     */
    private function lets(mixed $list, string $path, array &$scope, bool $present): array
    {
        $out = [];

        // ⚠️ Absent is "no bindings"; an explicit `null` is a mistake and refused, as in JS.
        if (!$present) {
            return $out;
        }

        if (!is_array($list)) {
            $this->add('type', $path, 'must be a list');
            return $out;
        }

        foreach ($list as $i => $l) {
            $p = $path . "[$i]";

            if (!$l instanceof \stdClass) {
                $this->add('type', $p, 'must be an object');
                continue;
            }

            $this->onlyKeys($l, self::KEYS['let'], $p);

            $expr = $this->expression(property_exists($l, 'expr') ? $l->expr : null, $p . '.expr', $scope, 0);
            $name = property_exists($l, 'name') ? $l->name : null;

            if (!is_string($name) || preg_match(self::VARIABLE_NAME, $name) !== 1) {
                $this->add('variable-name', $p . '.name', 'a variable name like "total"');
                continue;
            }

            $scope[] = $name;
            $out[] = ['name' => $name, 'expr' => $expr];
        }

        return $out;
    }

    /**
     * @param list<string> $scope
     * @return list<array<string, mixed>>
     */
    private function segments(mixed $message, string $path, array $scope): array
    {
        if (is_string($message)) {
            if (trim($message) === '') {
                $this->add('message', $path, 'the message must not be empty');
            } elseif (mb_strlen($message) > self::LIMIT_TEXT) {
                $this->add('size', $path, 'longer than ' . self::LIMIT_TEXT . ' characters');
            }

            return [['kind' => 'literal', 'text' => $message]];
        }

        if (!is_array($message) || $message === []) {
            $this->add('message', $path, 'a text, or a list of text and expression parts');
            return [];
        }

        $out = [];

        foreach ($message as $i => $s) {
            $p = $path . "[$i]";

            if (!$s instanceof \stdClass) {
                $this->add('type', $p, 'must be an object');
                continue;
            }

            $this->onlyKeys($s, self::KEYS['segment'], $p);

            $kind = property_exists($s, 'kind') ? $s->kind : null;

            if ($kind === 'literal') {
                $t = property_exists($s, 'text') ? $s->text : null;

                if (!is_string($t) || mb_strlen($t) > self::LIMIT_TEXT) {
                    $this->add('message', $p . '.text', 'must be a string');
                }

                $out[] = ['kind' => 'literal', 'text' => is_string($t) ? $t : ''];
                continue;
            }

            if ($kind === 'expr') {
                $out[] = ['kind' => 'expr', 'expr' => $this->expression(property_exists($s, 'expr') ? $s->expr : null, $p . '.expr', $scope, 0)];
                continue;
            }

            $this->add('message', $p . '.kind', 'must be "literal" or "expr"');
        }

        return $out;
    }

    /**
     * Checks one compiled XPath tree and returns it as the array `AstSerializer::fromArray()` reads -
     * with `pos` filled in where the author left it out (the JS cut drops it, the PHP classes need it).
     *
     * @param list<string> $scope
     * @return array<string, mixed>|null
     */
    private function expression(mixed $ast, string $path, array $scope, int $depth): ?array
    {
        if ($depth > self::LIMIT_DEPTH) {
            $this->add('depth', $path, 'nested deeper than ' . self::LIMIT_DEPTH . ' levels');
            return null;
        }

        if (++$this->nodes > self::LIMIT_NODES) {
            if ($this->nodes === self::LIMIT_NODES + 1) {
                $this->add('size', $path, 'more than ' . self::LIMIT_NODES . ' expression nodes');
            }

            return null;
        }

        if (!$ast instanceof \stdClass) {
            $this->add('missing', $path, 'a compiled expression is required here');
            return null;
        }

        $kind = property_exists($ast, 'node') ? $ast->node : null;

        if (!is_string($kind) || !in_array($kind, self::ALLOWED_NODES, true)) {
            $this->add('node-not-allowed', $path, 'expression node "' . (is_scalar($kind) ? (string) $kind : gettype($kind)) . '" is not supported by the validator');
            return null;
        }

        $this->onlyKeys($ast, self::KEYS[$kind], $path);

        $pos = property_exists($ast, 'pos') ? $ast->pos : 0;

        if (!self::isInteger($pos)) {
            $this->add('type', $path . '.pos', 'must be an integer');
            $pos = 0;
        }

        $pos = (int) $pos;
        $f = static fn (string $k): mixed => property_exists($ast, $k) ? $ast->{$k} : null;
        // A child in the same scope; the three children that bind a variable call expression() directly.
        $sub = fn (mixed $v, string $p): ?array => $this->expression($v, $path . '.' . $p, $scope, $depth + 1);
        $list = function (mixed $v, string $p) use ($sub, $path): array {
            if (!is_array($v)) {
                $this->add('type', $path . '.' . $p, 'must be a list');
                return [];
            }

            $out = [];

            foreach ($v as $i => $item) {
                $out[] = $sub($item, $p . "[$i]");
            }

            return $out;
        };
        $bindingName = function (mixed $v, string $p) use ($path): ?string {
            if (!is_string($v) || preg_match(self::VARIABLE_NAME, $v) !== 1) {
                $this->add('variable-name', $path . '.' . $p, 'a variable name like "x"');
                return null;
            }

            return $v;
        };

        switch ($kind) {
            case 'PathExpr':
                $absolute = $f('absolute');

                if (!is_bool($absolute)) {
                    $this->add('type', $path . '.absolute', 'must be true or false');
                }

                $steps = $f('steps');

                if (!is_array($steps)) {
                    $this->add('type', $path . '.steps', 'must be a list');
                    return null;
                }

                if ($steps === [] && $absolute !== true) {
                    $this->add('type', $path . '.steps', 'a relative path needs at least one step');
                }

                $outSteps = [];

                foreach ($steps as $i => $s) {
                    $outSteps[] = $this->step($s, $path . ".steps[$i]", $scope, $depth + 1);
                }

                return ['node' => $kind, 'pos' => $pos, 'absolute' => $absolute === true, 'steps' => $outSteps];

            case 'BinaryOp':
                $op = $f('op');

                if (!in_array($op, self::ALLOWED_OPERATORS, true)) {
                    $this->add('operator-not-allowed', $path . '.op', 'operator "' . (is_scalar($op) ? (string) $op : '') . '" is not supported by both validators');
                }

                return ['node' => $kind, 'pos' => $pos, 'op' => is_string($op) ? $op : '', 'left' => $sub($f('left'), 'left'), 'right' => $sub($f('right'), 'right')];

            case 'UnaryOp':
                $op = $f('op');

                if ($op !== '-' && $op !== '+') {
                    $this->add('operator-not-allowed', $path . '.op', 'unary operator must be "-" or "+"');
                }

                return ['node' => $kind, 'pos' => $pos, 'op' => is_string($op) ? $op : '-', 'operand' => $sub($f('operand'), 'operand')];

            case 'FunctionCall':
                $qn = $this->qname($f('name'), $path . '.name');
                $args = $list($f('args'), 'args');

                if ($qn === null) {
                    return null;
                }

                if ($qn['prefix'] === 'xs') {
                    if (!in_array($qn['local'], self::ALLOWED_CONSTRUCTORS, true)) {
                        $this->add(
                            'function-not-allowed',
                            $path . '.name',
                            'xs:' . $qn['local'] . '() is not supported; allowed are '
                                . implode(', ', array_map(static fn (string $t): string => 'xs:' . $t, self::ALLOWED_CONSTRUCTORS))
                        );
                    } elseif (count($args) !== 1) {
                        $this->add('arity', $path . '.args', 'xs:' . $qn['local'] . '() takes one argument');
                    }

                    return ['node' => $kind, 'pos' => $pos, 'name' => $qn, 'args' => $args];
                }

                // ⚠️ `fn:` refused, not stripped: VAMPIRE JS looks the name up WITH the prefix.
                $fname = $qn['prefix'] === null ? $qn['local'] : ($qn['prefix'] === 'u' ? 'u:' . $qn['local'] : null);
                $arity = $fname !== null ? (self::ALLOWED_FUNCTIONS[$fname] ?? null) : null;

                if ($arity === null) {
                    $this->add('function-not-allowed', $path . '.name', ($qn['prefix'] !== null ? $qn['prefix'] . ':' : '') . $qn['local'] . '() is not supported in a rule profile');
                } elseif (count($args) < $arity[0] || ($arity[1] !== -1 && count($args) > $arity[1])) {
                    $range = $arity[0] === $arity[1]
                        ? (string) $arity[0]
                        : $arity[0] . ($arity[1] === -1 ? ' or more' : ' to ' . $arity[1]);
                    $this->add('arity', $path . '.args', $fname . '() takes ' . $range . ' arguments, not ' . count($args));
                }

                return ['node' => $kind, 'pos' => $pos, 'name' => $qn, 'args' => $args];

            case 'VarRef':
                $v = $bindingName($f('name'), 'name');

                // ⚠️ An unbound variable throws in both engines when the rule runs - and only then.
                if ($v !== null && !in_array($v, $scope, true)) {
                    $this->add('variable-unbound', $path . '.name', '$' . $v . ' is not bound at this point');
                }

                return ['node' => $kind, 'pos' => $pos, 'name' => $v ?? ''];

            case 'Literal':
                $lk = $f('kind');
                $value = $f('value');
                $ok = is_string($value) && (
                    ($lk === 'string' && mb_strlen($value) <= self::LIMIT_TEXT)
                    || ($lk === 'integer' && preg_match('/^\d{1,30}$/', $value) === 1)
                    || ($lk === 'decimal' && preg_match('/^(\d{1,30}\.\d{0,30}|\.\d{1,30})$/', $value) === 1)
                );

                if (!$ok) {
                    $this->add('literal', $path, 'a literal is { kind: string | integer | decimal, value: "…" }');
                }

                return ['node' => $kind, 'pos' => $pos, 'kind' => $ok ? $lk : 'string', 'value' => is_string($value) ? $value : ''];

            case 'SequenceExpr':
                return ['node' => $kind, 'pos' => $pos, 'items' => $list($f('items'), 'items')];

            case 'RangeExpr':
                return ['node' => $kind, 'pos' => $pos, 'from' => $sub($f('from'), 'from'), 'to' => $sub($f('to'), 'to')];

            case 'IfExpr':
                return ['node' => $kind, 'pos' => $pos, 'cond' => $sub($f('cond'), 'cond'), 'then' => $sub($f('then'), 'then'), 'else' => $sub($f('else'), 'else')];

            case 'ForExpr':
            case 'LetExpr':
                $v = $bindingName($f('var'), 'var');
                $inner = $scope;

                if ($v !== null) {
                    $inner[] = $v;
                }

                // The bound expression first, then the body - the order of the problems, as in JS.
                $bound = $kind === 'ForExpr' ? $sub($f('in'), 'in') : $sub($f('value'), 'value');
                $return = $this->expression($f('return'), $path . '.return', $inner, $depth + 1);

                return $kind === 'ForExpr'
                    ? ['node' => $kind, 'pos' => $pos, 'var' => $v ?? '', 'in' => $bound, 'return' => $return]
                    : ['node' => $kind, 'pos' => $pos, 'var' => $v ?? '', 'value' => $bound, 'return' => $return];

            case 'QuantifiedExpr':
                $q = $f('quantifier');

                if ($q !== 'some' && $q !== 'every') {
                    $this->add('operator-not-allowed', $path . '.quantifier', 'must be "some" or "every"');
                }

                $v = $bindingName($f('var'), 'var');
                $inner = $scope;

                if ($v !== null) {
                    $inner[] = $v;
                }

                return [
                    'node' => $kind, 'pos' => $pos, 'quantifier' => is_string($q) ? $q : 'some', 'var' => $v ?? '',
                    'in' => $sub($f('in'), 'in'), 'satisfies' => $this->expression($f('satisfies'), $path . '.satisfies', $inner, $depth + 1),
                ];

            case 'CastableExpr':
            case 'CastExpr':
                $qn = $this->qname($f('type'), $path . '.type');

                if ($qn !== null && ($qn['prefix'] !== 'xs' || !in_array($qn['local'], self::ALLOWED_CAST_TYPES, true))) {
                    $this->add(
                        'type-not-allowed',
                        $path . '.type',
                        'cast target must be one of ' . implode(', ', array_map(static fn (string $t): string => 'xs:' . $t, self::ALLOWED_CAST_TYPES))
                    );
                }

                $optional = $f('optional');

                if ($optional !== null && !is_bool($optional)) {
                    $this->add('type', $path . '.optional', 'must be true or false');
                }

                return ['node' => $kind, 'pos' => $pos, 'operand' => $sub($f('operand'), 'operand'), 'type' => $qn, 'optional' => $optional === true];

            case 'ParenExpr':
                return ['node' => $kind, 'pos' => $pos, 'inner' => $sub($f('inner'), 'inner')];
        }

        // Every name in ALLOWED_NODES has its case above; PHPStan proves it, so nothing follows here.
    }

    /**
     * @param list<string> $scope
     * @return array<string, mixed>|null
     */
    private function step(mixed $s, string $path, array $scope, int $depth): ?array
    {
        if (!$s instanceof \stdClass) {
            $this->add('type', $path, 'a path step must be an object');
            return null;
        }

        $this->onlyKeys($s, self::KEYS['step'], $path);

        $f = static fn (string $k): mixed => property_exists($s, $k) ? $s->{$k} : null;
        $predicates = property_exists($s, 'predicates') ? $s->predicates : [];

        if (!is_array($predicates)) {
            $this->add('type', $path . '.predicates', 'must be a list');
            return null;
        }

        $preds = [];

        foreach ($predicates as $i => $p) {
            $preds[] = $this->expression($p, $path . ".predicates[$i]", $scope, $depth + 1);
        }

        if ($f('primary') !== null) {
            if ($f('axis') !== null || $f('test') !== null) {
                $this->add('type', $path, 'a step is either an axis step or an expression, not both');
            }

            return ['axis' => null, 'test' => null, 'predicates' => $preds, 'primary' => $this->expression($f('primary'), $path . '.primary', $scope, $depth + 1)];
        }

        $axis = $f('axis');

        if (!in_array($axis, self::ALLOWED_AXES, true)) {
            $this->add('axis-not-allowed', $path . '.axis', 'axis "' . (is_scalar($axis) ? (string) $axis : 'null') . '" is not supported by both validators');
        }

        $t = $f('test');

        if (!$t instanceof \stdClass) {
            $this->add('type', $path . '.test', 'an axis step needs a node test');
            return null;
        }

        $this->onlyKeys($t, self::KEYS['nodetest'], $path . '.test');

        $tk = property_exists($t, 'kind') ? $t->kind : null;
        $tname = property_exists($t, 'name') ? $t->name : null;
        $test = null;

        if ($tk === 'kindtest') {
            $kt = property_exists($t, 'kindtestName') ? $t->kindtestName : null;

            if (!in_array($kt, self::ALLOWED_KINDTESTS, true)) {
                $this->add('kindtest-not-allowed', $path . '.test', (is_scalar($kt) ? (string) $kt : 'null') . '() is not supported; allowed are node() and text()');
            }

            if ($tname !== null) {
                $this->add('type', $path . '.test.name', 'a kind test has no name');
            }

            $test = ['kind' => 'kindtest', 'name' => null, 'kindtestName' => is_string($kt) ? $kt : 'node'];
        } elseif ($tk === 'wildcard') {
            $name = null;

            if ($tname !== null) {
                $name = $this->qname($tname, $path . '.test.name', true);

                if ($name !== null && ($name['prefix'] === null || $name['local'] !== '*')) {
                    $this->add('type', $path . '.test.name', 'a prefixed wildcard is { prefix: "ram", local: "*" }');
                }

                if ($name !== null && $name['prefix'] !== null) {
                    $this->boundPrefix($name['prefix'], $path . '.test.name.prefix');
                }
            }

            $test = ['kind' => 'wildcard', 'name' => $name, 'kindtestName' => null];
        } elseif ($tk === 'name') {
            $name = $this->qname($tname, $path . '.test.name');

            if ($name !== null && $name['prefix'] !== null) {
                $this->boundPrefix($name['prefix'], $path . '.test.name.prefix');
            }

            $test = ['kind' => 'name', 'name' => $name, 'kindtestName' => null];
        } else {
            $this->add('type', $path . '.test.kind', 'must be "name", "wildcard" or "kindtest"');
        }

        return ['axis' => is_string($axis) ? $axis : 'child', 'test' => $test, 'predicates' => $preds, 'primary' => null];
    }

    /**
     * ⚠️ An undeclared prefix is refused here: at run time it throws only when the step runs, and a
     * rule whose context selects nothing never gets there.
     */
    private function boundPrefix(string $prefix, string $path): void
    {
        if (!array_key_exists($prefix, $this->namespaces)) {
            $this->add('prefix-unbound', $path, 'namespace prefix "' . $prefix . '" is not declared in "namespaces"');
        }
    }

    /**
     * @return array{prefix: string|null, local: string}|null
     */
    private function qname(mixed $q, string $path, bool $wildcard = false): ?array
    {
        if (!$q instanceof \stdClass) {
            $this->add('type', $path, 'a name is { prefix, local }');
            return null;
        }

        $this->onlyKeys($q, self::KEYS['qname'], $path);

        $prefix = property_exists($q, 'prefix') ? $q->prefix : null;
        $prefix = $prefix === '' ? null : $prefix;

        if ($prefix !== null && (!is_string($prefix) || preg_match(self::PREFIX_NAME, $prefix) !== 1)) {
            $this->add('type', $path . '.prefix', 'not a valid prefix');
            return null;
        }

        $local = property_exists($q, 'local') ? $q->local : null;
        $okLocal = is_string($local) && (preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,127}$/', $local) === 1 || ($wildcard && $local === '*'));

        if (!$okLocal) {
            $this->add('type', $path . '.local', 'not a valid local name');
            return null;
        }

        /** @var string $local */
        return ['prefix' => $prefix, 'local' => $local];
    }

    /** `-UBL` or `-CII` at the end of a rule set id; null when the id says neither (`UBL-TR`). */
    public static function syntaxOfRuleset(string $id): ?string
    {
        return preg_match('/-(UBL|CII)$/i', $id, $m) === 1 ? strtolower($m[1]) : null;
    }
}

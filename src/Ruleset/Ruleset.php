<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Ruleset;

/**
 * One imported rule package (Aufgabe 3.1/3.3), e.g. `EN16931-UBL@1.3.16`. Serialized to/from
 * `rulesets/<id>@<version>.json` by `RulesetJsonCodec`. `sourceHash` (sha256 of the compiled XSLT
 * this was imported from) stands in for "Herkunft und Commit" (Aufgabe 3.3's own wording) - there
 * is no git commit for `vendor-sources/` to cite (it is a local copy, not a submodule, see
 * docs/notes/decisions.md), and a hash is a strictly stronger, actually-verifiable provenance claim: it
 * changes if and only if the imported content does.
 */
final readonly class Ruleset
{
    /**
     * @param array<string, string> $namespaces prefix => URI, collected from the compiled XSLT's
     *                                           own `svrl:ns-prefix-in-attribute-values` (Aufgabe
     *                                           3.1) - includes every alias a rule context/test
     *                                           actually uses (e.g. both `ubl-invoice` and `ubl`
     *                                           for the same UBL Invoice namespace).
     * @param LetBinding[] $globalLets Document-global `<sch:let>` bindings, evaluated once against
     *                                 the document root, in declaration order, before any pattern
     *                                 runs (Aufgabe 3.4) - later bindings may reference earlier
     *                                 ones (measured in the real corpus, e.g. `$XR-CIUS-ID` uses
     *                                 `$XR-MAJOR-MINOR-VERSION`).
     * @param Pattern[] $patterns
     * @param ?string $catalogStandard Task 0.2c - the name this rule set carries in the suite's rule
     *                                 catalogue (`INDEX.json`, field `standard`), e.g.
     *                                 "PEPPOL-BIS-Billing3-UBL" for `PEPPOL-EN16931-UBL@3.0.21`.
     *                                 Written so that Rule Explorer and this library talk about the
     *                                 same rule set instead of each using its own name. `null` for a
     *                                 rule set the catalogue does not cover, e.g. a hand-written one.
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $title,
        public string $sourceFile,
        public string $sourceHash,
        public array $namespaces,
        public array $globalLets,
        public array $patterns,
        public ?string $catalogStandard = null,
        /**
         * The SPDX identifier under which THIS rule package is passed on ( `packageLicence` in
         * `vampire-php/rule-provenance.json` ): EUPL-1.2 for rule sets derived from
         * EN 16931 material, Apache-2.0 for XRechnung, NOASSERTION for Peppol. Null in a file written
         * without a provenance entry.
         *
         * ⚠️ Not the licence of this library. `LicenseRef-specialPress-Proprietary` covers `src/` only.
         */
        public ?string $license = null,
        /**
         * The sentence the user must see in every report of a check that ran this rule set, by language
         * ( `visibleNotice` in the provenance table; FeRD's condition for ZUGFeRD ), or null.
         *
         * @var array<string, string>|null
         */
        public ?array $notice = null,
    ) {
    }

    /**
     * A copy with licence and visible notice set - the import and the runtime export stamp both from the
     * provenance table, and a readonly object cannot be changed in place.
     *
     * @param array<string, string>|null $notice
     */
    public function withLicence(?string $license, ?array $notice): self
    {
        return new self(
            $this->id,
            $this->version,
            $this->title,
            $this->sourceFile,
            $this->sourceHash,
            $this->namespaces,
            $this->globalLets,
            $this->patterns,
            $this->catalogStandard,
            $license,
            $notice,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            // 2 adds the OPTIONAL catalogue metadata from Task 0.2c (`catalogStandard` here,
            // `duplicateKey`/`descriptions` per assertion). Nothing existing changed meaning, so a
            // version-1 file still loads - see fromArray().
            'schemaVersion' => 2,
            'id' => $this->id,
            'version' => $this->version,
            'title' => $this->title,
        ];

        // ⚠️ In the head, before `source`: a reader of the first lines ( and `build-release.php`, which
        // reads only the first 8 KB ) must find the licence of the package without parsing 24 MB.
        if ($this->license !== null) {
            $data['license'] = $this->license;
        }

        if ($this->notice !== null) {
            $data['notice'] = $this->notice;
        }

        $data += [
            'source' => ['file' => $this->sourceFile, 'sha256' => $this->sourceHash],
            'namespaces' => $this->namespaces,
            'globalLets' => array_map(static fn (LetBinding $l): array => $l->toArray(), $this->globalLets),
            'patterns' => array_map(static fn (Pattern $p): array => $p->toArray(), $this->patterns),
        ];

        if ($this->catalogStandard !== null) {
            $data['catalogStandard'] = $this->catalogStandard;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // ⚠️ Version 1 stays readable ON PURPOSE. It differs from 2 only by the absence of the
        // catalogue metadata, and refusing it would turn every hand-compiled rule set from before
        // Task 0.2c into an error - for a field that is optional by definition. What a version-1
        // file does NOT have is a `duplicateKey`, so it takes no part in the duplicate handling of
        // Task 5.8; that is a consequence to know about, not a defect.
        if (!in_array($data['schemaVersion'] ?? null, [1, 2], true)) {
            throw new \InvalidArgumentException(
                'unsupported ruleset schema version: ' . ($data['schemaVersion'] ?? '(missing)')
            );
        }

        /** @var array<string, string> $namespaces */
        $namespaces = $data['namespaces'];
        /** @var array<int, array<string, mixed>> $globalLetsData */
        $globalLetsData = $data['globalLets'];
        /** @var array<int, array<string, mixed>> $patternsData */
        $patternsData = $data['patterns'];
        /** @var array{file: string, sha256: string} $source */
        $source = $data['source'];

        return new self(
            (string) $data['id'],
            (string) $data['version'],
            (string) $data['title'],
            (string) $source['file'],
            (string) $source['sha256'],
            $namespaces,
            array_map(LetBinding::fromArray(...), $globalLetsData),
            array_map(Pattern::fromArray(...), $patternsData),
            isset($data['catalogStandard']) ? (string) $data['catalogStandard'] : null,
            isset($data['license']) ? (string) $data['license'] : null,
            isset($data['notice']) && is_array($data['notice'])
                ? array_map('strval', array_filter($data['notice'], 'is_string'))
                : null,
        );
    }
}

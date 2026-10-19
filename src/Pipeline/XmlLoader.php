<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Pipeline;

use EinvoiceValidator\Exception\DocumentTooLargeException;
use EinvoiceValidator\Exception\NotWellformedException;

/**
 * Task 1.2 — safe XML reading: no external entities, LIBXML_NONET, a configurable size limit,
 * meaningful errors (with line numbers) for not-wellformed input.
 *
 * Security note on external entities: since libxml2 2.9 (bundled with every currently supported
 * PHP version), `DOMDocument::loadXML()`/`load()` do NOT resolve external entities or load
 * external DTD subsets by default - that behaviour only re-appears if the caller explicitly
 * passes `LIBXML_NOENT` and/or `LIBXML_DTDLOAD`, which this class never does. `LIBXML_NONET` is
 * still set explicitly as defense in depth (blocks network-based resolution even if some future
 * change to this class ever added those flags) and because it also blocks network access for
 * `xsl:include`/schema imports handled elsewhere in the pipeline. `libxml_disable_entity_loader()`
 * is deliberately NOT called - it was deprecated in PHP 8.0 and removed in PHP 8.4, and is a no-op
 * (or a fatal call to a removed function on 8.4) given the safe-by-default behaviour above.
 */
final class XmlLoader
{
    private const DEFAULT_MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
    }

    /**
     * @throws DocumentTooLargeException
     * @throws NotWellformedException
     */
    public function loadFile(string $path): \DOMDocument
    {
        $size = @filesize($path);
        if ($size === false) {
            throw new \RuntimeException("cannot read file: $path");
        }
        if ($size > $this->maxBytes) {
            throw new DocumentTooLargeException($size, $this->maxBytes);
        }

        $xml = file_get_contents($path);
        if ($xml === false) {
            throw new \RuntimeException("cannot read file: $path");
        }

        return $this->loadString($xml);
    }

    /**
     * @throws DocumentTooLargeException
     * @throws NotWellformedException
     */
    public function loadString(string $xml): \DOMDocument
    {
        $bytes = strlen($xml);
        if ($bytes > $this->maxBytes) {
            throw new DocumentTooLargeException($bytes, $this->maxBytes);
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $dom = new \DOMDocument();
            $dom->resolveExternals = false;
            $dom->substituteEntities = false;

            $loaded = $dom->loadXML($xml, LIBXML_NONET);

            if ($loaded === false) {
                throw new NotWellformedException(libxml_get_errors());
            }

            return $dom;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }
}

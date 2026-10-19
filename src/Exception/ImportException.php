<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Exception;

/**
 * Regel A.6: a compiled Schematron-XSLT that does not match the shape `SchematronXsltImporter`
 * (Aufgabe 3.1) actually measured against all four real EN16931/XRechnung rulesets (a missing
 * `@id`/`@priority`, a pattern with no matching `xsl:apply-templates`, a rule context that is not a
 * path expression or union of path expressions) is a clear, located import error - never silently
 * skipped or guessed at.
 */
final class ImportException extends \RuntimeException
{
}

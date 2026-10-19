<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Pipeline;

use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Result\Message;
use EinvoiceValidator\Result\Stage;

/**
 * Turns libxml's own error objects (from `libxml_get_errors()`, used both for not-wellformed XML
 * and for XSD schema-validation failures) into our Message model - one shared place instead of
 * duplicating the LIBXML_ERR_* → Flag mapping in both XmlLoader and XsdStage.
 */
final class LibxmlErrorConverter
{
    /**
     * @param \LibXMLError[] $errors
     * @return Message[]
     */
    public static function toMessages(array $errors, Stage $stage, string $ruleset): array
    {
        return array_map(
            static fn (\LibXMLError $error): Message => new Message(
                stage: $stage,
                ruleId: null,
                flag: $error->level === LIBXML_ERR_WARNING ? Flag::Warning : Flag::Fatal,
                text: trim($error->message),
                location: $error->file !== '' ? $error->file : null,
                line: $error->line > 0 ? $error->line : null,
                ruleset: $ruleset,
            ),
            $errors
        );
    }
}

<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Pipeline;

use EinvoiceValidator\Result\Result;
use EinvoiceValidator\Result\Stage;
use EinvoiceValidator\Result\StageStatus;

/**
 * Task 1.4 — validates a document against its XML Schema (`DOMDocument::schemaValidate()`),
 * converting every libxml error into a Message on the shared Result.
 */
final class XsdStage
{
    public function __construct(
        private readonly string $schemaPath,
        public readonly XsdMode $mode = XsdMode::Stop,
    ) {
    }

    public function run(\DOMDocument $document, Result $result): void
    {
        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $valid = $document->schemaValidate($this->schemaPath, LIBXML_NONET);

            if ($valid) {
                $result->setStageStatus(Stage::Xsd, StageStatus::Passed);
                return;
            }

            foreach (LibxmlErrorConverter::toMessages(libxml_get_errors(), Stage::Xsd, 'xsd') as $message) {
                $result->addMessage($message);
            }
            $result->setStageStatus(Stage::Xsd, StageStatus::Failed);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
        }
    }
}

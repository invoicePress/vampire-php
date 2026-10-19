<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Pipeline;

use EinvoiceValidator\Pipeline\XmlLoader;
use EinvoiceValidator\Pipeline\XsdStage;
use EinvoiceValidator\Result\Result;
use EinvoiceValidator\Result\Stage;
use EinvoiceValidator\Result\StageStatus;
use PHPUnit\Framework\TestCase;

/**
 * Uses the real, official OASIS UBL 2.1 Invoice XSD ( the copy under rulesets/xsd/ ) and a
 * real, official XRechnung-3.0.2-testsuite instance (tests/fixtures/invoices/), not synthetic
 * fixtures - a valid sample invoice is the point of the test.
 */
final class XsdStageTest extends TestCase
{
    private function schemaPath(): string
    {
        return dirname(__DIR__, 3) . '/rulesets/xsd/ubl/maindoc/UBL-Invoice-2.1.xsd';
    }

    public function testValidInvoicePassesXsdValidation(): void
    {
        self::assertFileExists($this->schemaPath());

        $dom = (new XmlLoader())->loadFile(dirname(__DIR__, 2) . '/fixtures/invoices/valid-ubl-invoice.xml');
        $stage = new XsdStage($this->schemaPath());
        $result = new Result();

        $stage->run($dom, $result);

        self::assertSame(StageStatus::Passed, $result->stageStatus(Stage::Xsd));
        self::assertSame([], $result->messagesForStage(Stage::Xsd));
        self::assertTrue($result->isAccepted());
    }

    public function testStructurallyBrokenInvoiceFailsWithAReadableMessage(): void
    {
        $dom = (new XmlLoader())->loadString(
            '<ubl:Invoice xmlns:ubl="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            . '<cbc:UnknownElement>not part of the schema</cbc:UnknownElement>'
            . '</ubl:Invoice>'
        );
        $stage = new XsdStage($this->schemaPath());
        $result = new Result();

        $stage->run($dom, $result);

        self::assertSame(StageStatus::Failed, $result->stageStatus(Stage::Xsd));
        $messages = $result->messagesForStage(Stage::Xsd);
        self::assertNotEmpty($messages);
        self::assertNotSame('', $messages[0]->text);
        self::assertFalse($result->isAccepted());
    }

    public function testDefaultModeIsStop(): void
    {
        self::assertSame(\EinvoiceValidator\Pipeline\XsdMode::Stop, (new XsdStage($this->schemaPath()))->mode);
    }
}

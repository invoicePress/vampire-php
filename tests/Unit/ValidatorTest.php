<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use EinvoiceValidator\Pipeline\XsdStage;
use EinvoiceValidator\Result\Stage;
use EinvoiceValidator\Result\StageStatus;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end over the real official UBL 2.1 XSD and a real official XRechnung-testsuite
 * instance (see tests/Unit/Pipeline/XsdStageTest.php for the same fixtures at stage level) -
 * this is the Task 1.5 acceptance criterion itself, not a synthetic stand-in for it.
 */
final class ValidatorTest extends TestCase
{
    private function schemaPath(): string
    {
        return dirname(__DIR__, 2) . '/rulesets/xsd/ubl/maindoc/UBL-Invoice-2.1.xsd';
    }

    public function testValidInvoiceFileIsAccepted(): void
    {
        $validator = new Validator(xsdStage: new XsdStage($this->schemaPath()));

        $result = $validator->validateFile(dirname(__DIR__) . '/fixtures/invoices/valid-ubl-invoice.xml');

        self::assertTrue($result->isAccepted());
        self::assertSame(StageStatus::Passed, $result->stageStatus(Stage::Wellformed));
        self::assertSame(StageStatus::Passed, $result->stageStatus(Stage::Xsd));
    }

    public function testValidInvoiceStringIsAccepted(): void
    {
        $xml = file_get_contents(dirname(__DIR__) . '/fixtures/invoices/valid-ubl-invoice.xml');
        self::assertIsString($xml);

        $validator = new Validator(xsdStage: new XsdStage($this->schemaPath()));
        $result = $validator->validateString($xml);

        self::assertTrue($result->isAccepted());
    }

    public function testNotWellformedFileGivesAReadableMessageAndIsRejected(): void
    {
        $validator = new Validator(xsdStage: new XsdStage($this->schemaPath()));

        $result = $validator->validateString('<unclosed>');

        self::assertFalse($result->isAccepted());
        self::assertSame(StageStatus::Failed, $result->stageStatus(Stage::Wellformed));
        $messages = $result->messagesForStage(Stage::Wellformed);
        self::assertNotEmpty($messages);
        self::assertNotSame('', $messages[0]->text);
        // Not wellformed -> XSD never runs, there is no DOM to validate.
        self::assertSame(StageStatus::NotRun, $result->stageStatus(Stage::Xsd));
    }

    public function testValidateDomSkipsWellformedCheckTriviallyAsPassed(): void
    {
        $dom = new \DOMDocument();
        $dom->loadXML(file_get_contents(dirname(__DIR__) . '/fixtures/invoices/valid-ubl-invoice.xml') ?: '');

        $validator = new Validator(xsdStage: new XsdStage($this->schemaPath()));
        $result = $validator->validateDom($dom);

        self::assertSame(StageStatus::Passed, $result->stageStatus(Stage::Wellformed));
        self::assertTrue($result->isAccepted());
    }

    public function testWithoutAnXsdStageOnlyWellformedRuns(): void
    {
        $validator = new Validator();

        $result = $validator->validateString('<root/>');

        self::assertTrue($result->isAccepted());
        self::assertSame(StageStatus::NotRun, $result->stageStatus(Stage::Xsd));
    }
}

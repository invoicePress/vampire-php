<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Result;

use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Result\Message;
use EinvoiceValidator\Result\Result;
use EinvoiceValidator\Result\Stage;
use EinvoiceValidator\Result\StageStatus;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    public function testFreshResultIsAccepted(): void
    {
        self::assertTrue((new Result())->isAccepted());
    }

    public function testWarningDoesNotBlockAcceptance(): void
    {
        $result = new Result();
        $result->addMessage(new Message(Stage::Business, 'BR-1', Flag::Warning, 'text', null, null, 'EN16931'));

        self::assertTrue($result->isAccepted());
    }

    public function testFatalBlocksAcceptance(): void
    {
        $result = new Result();
        $result->addMessage(new Message(Stage::Business, 'BR-1', Flag::Fatal, 'text', null, null, 'EN16931'));

        self::assertFalse($result->isAccepted());
    }

    public function testInformationDoesNotBlockAcceptance(): void
    {
        $result = new Result();
        $result->addMessage(new Message(Stage::Syntax, null, Flag::Information, 'text', null, null, 'UBL-SR'));

        self::assertTrue($result->isAccepted());
    }

    public function testExplicitStageFailureBlocksAcceptanceEvenWithoutAFatalMessage(): void
    {
        $result = new Result();
        $result->setStageStatus(Stage::Xsd, StageStatus::Failed);

        self::assertFalse($result->isAccepted());
    }

    public function testMessagesForStageFiltersCorrectly(): void
    {
        $result = new Result();
        $xsd = new Message(Stage::Xsd, null, Flag::Fatal, 'xsd error', null, 3, 'xsd');
        $business = new Message(Stage::Business, 'BR-1', Flag::Fatal, 'business error', null, null, 'EN16931');
        $result->addMessage($xsd);
        $result->addMessage($business);

        self::assertSame([$xsd], $result->messagesForStage(Stage::Xsd));
        self::assertSame([$business], $result->messagesForStage(Stage::Business));
        self::assertSame([], $result->messagesForStage(Stage::Syntax));
    }

    public function testStageStatusDefaultsToNotRun(): void
    {
        self::assertSame(StageStatus::NotRun, (new Result())->stageStatus(Stage::Business));
    }
}

<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Task 7.4 — the CLI, exercised as a real process.
 *
 * ⚠️ Called through `proc_open`, not by including the script: the exit code IS part of the contract
 * (0 accepted, 1 rejected, 2 wrong call), and a test that only inspects printed text would let a
 * broken exit code through - which is exactly what a shell script piping invoices would trip over.
 */
final class CliTest extends TestCase
{
    /**
     * @param list<string> $args
     * @return array{code: int, out: string, err: string}
     */
    private function runCli(array $args): array
    {
        $root = dirname(__DIR__, 2);
        $command = array_merge([PHP_BINARY, $root . '/bin/einvoice-validator'], $args);

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root
        );

        self::assertIsResource($process);

        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($process), 'out' => $out, 'err' => $err];
    }

    public function testAValidInvoiceExitsWithZero(): void
    {
        $result = $this->runCli(['validate', 'tests/fixtures/invoices/xrechnung-standard/01.01a-INVOICE_ubl.xml']);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('ANGENOMMEN', $result['out']);
    }

    public function testARejectedInvoiceExitsWithOne(): void
    {
        $result = $this->runCli(['validate', 'tests/fixtures/invoices/mutated/ubl-empty-invoice-number.xml']);

        self::assertSame(1, $result['code']);
        self::assertStringContainsString('ABGELEHNT', $result['out']);
        self::assertStringContainsString('BR-02', $result['out']);
    }

    public function testAMissingFileExitsWithTwo(): void
    {
        $result = $this->runCli(['validate', 'gibt-es-nicht.xml']);

        self::assertSame(2, $result['code'], 'Ein Aufruffehler darf nicht wie eine ungültige Rechnung aussehen.');
    }

    public function testAProfileChangesTheVerdictOnTheCommandLineToo(): void
    {
        $result = $this->runCli([
            'validate',
            'tests/fixtures/invoices/mutated/ubl-empty-invoice-number.xml',
            '--profile=test-disable',
        ]);

        self::assertSame(0, $result['code']);
    }

    public function testJsonOutputIsParseable(): void
    {
        $result = $this->runCli([
            'validate',
            'tests/fixtures/invoices/mutated/ubl-empty-invoice-number.xml',
            '--format=json',
        ]);

        $data = json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);

        self::assertIsArray($data);
        self::assertFalse($data['accepted']);
    }

    public function testXmlOutputIsWellFormed(): void
    {
        $result = $this->runCli([
            'validate',
            'tests/fixtures/invoices/mutated/ubl-empty-invoice-number.xml',
            '--format=xml',
        ]);

        $dom = new \DOMDocument();

        self::assertTrue($dom->loadXML(trim($result['out'])));
        self::assertSame('report', $dom->documentElement?->localName);
    }

    public function testSelftestPasses(): void
    {
        $result = $this->runCli(['selftest']);

        self::assertSame(0, $result['code'], $result['out'] . $result['err']);
        self::assertStringContainsString('angenommen', $result['out']);
    }

    public function testRulesetListNamesEveryShippedRuleset(): void
    {
        $result = $this->runCli(['ruleset:list']);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('EN16931-UBL@1.3.16', $result['out']);
        self::assertStringContainsString('PEPPOL-EN16931-UBL@3.0.21', $result['out']);
    }

    public function testProfileShowDescribesTheProfile(): void
    {
        $result = $this->runCli(['profile:show', 'test-disable']);

        self::assertSame(0, $result['code']);
        self::assertStringContainsString('BR-02', $result['out']);
    }

    public function testHelpExitsWithTwoSoMisuseIsNoticed(): void
    {
        $result = $this->runCli([]);

        self::assertSame(2, $result['code']);
        self::assertStringContainsString('validate', $result['out']);
    }
}

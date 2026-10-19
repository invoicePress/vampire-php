<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Pipeline;

use EinvoiceValidator\Exception\DocumentTooLargeException;
use EinvoiceValidator\Exception\NotWellformedException;
use EinvoiceValidator\Pipeline\XmlLoader;
use PHPUnit\Framework\TestCase;

final class XmlLoaderTest extends TestCase
{
    public function testLoadsWellformedXml(): void
    {
        $dom = (new XmlLoader())->loadString('<root><child>value</child></root>');

        self::assertSame('root', $dom->documentElement?->localName);
    }

    public function testThrowsOnNotWellformedXmlWithLineNumber(): void
    {
        $loader = new XmlLoader();

        try {
            $loader->loadString("<root>\n<unclosed>\n</root>");
            self::fail('expected NotWellformedException');
        } catch (NotWellformedException $e) {
            self::assertNotEmpty($e->errors);
            self::assertGreaterThan(0, $e->errors[0]->line);
        }
    }

    public function testThrowsOnOversizedString(): void
    {
        $loader = new XmlLoader(maxBytes: 10);

        $this->expectException(DocumentTooLargeException::class);
        $loader->loadString('<root>this is definitely more than ten bytes</root>');
    }

    public function testThrowsOnOversizedFile(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'einv');
        self::assertIsString($path);
        file_put_contents($path, '<root>this is definitely more than ten bytes</root>');

        try {
            $loader = new XmlLoader(maxBytes: 10);
            $this->expectException(DocumentTooLargeException::class);
            $loader->loadFile($path);
        } finally {
            unlink($path);
        }
    }

    public function testExternalEntitiesAreNotExpanded(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'einv-secret');
        self::assertIsString($secret);
        file_put_contents($secret, 'THIS-MUST-NEVER-APPEAR-IN-OUTPUT');

        $uri = str_replace('\\', '/', $secret);
        $xxe = '<?xml version="1.0"?>'
            . '<!DOCTYPE root [<!ENTITY xxe SYSTEM "file:///' . $uri . '">]>'
            . '<root>&xxe;</root>';

        try {
            $loader = new XmlLoader();

            try {
                $dom = $loader->loadString($xxe);
                // Either it loads without expanding the entity (content stays empty/literal)...
                self::assertStringNotContainsString('THIS-MUST-NEVER-APPEAR-IN-OUTPUT', $dom->saveXML() ?: '');
            } catch (NotWellformedException) {
                // ...or libxml refuses the undeclared/blocked entity outright - both outcomes are safe.
                self::assertTrue(true);
            }
        } finally {
            unlink($secret);
        }
    }
}

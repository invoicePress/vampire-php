<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Report;

use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Result\Result;
use EinvoiceValidator\Result\Stage;
use EinvoiceValidator\Result\StageStatus;

/**
 * The report in the shape the KoSIT validator produces (`rep:report`, VARL 1.0.0).
 *
 * This is the format the existing validation-report explainer already reads. Matching it means the
 * explainer works with reports from this library without a line of change.
 *
 * ⚠️ `rep:engine/rep:name` says THIS library and its version, not "KoSIT Validator 1.6.2". The format
 * is borrowed, the verdict is ours; a report that claims to come from the reference implementation
 * would be a lie that nobody reading it could detect.
 *
 * ⚠️ Severity words are KoSIT's, not ours: our `fatal` is its `error`. The mapping happens here, in
 * one place, because a reader of this file compares it against KoSIT output, not against our enum.
 *
 * ⚠️ Every rule set gets its own `rep:validationStepResult`, including the ones that found nothing -
 * a step missing from the report reads as "not checked", which is a different statement from
 * "checked, nothing found".
 */
final class KositXmlReport
{
    private const REP_NS = 'http://www.xoev.de/de/validator/varl/1';
    private const S_NS = 'http://www.xoev.de/de/validator/framework/1/scenarios';

    /** How many elements of the checked document the embedded view lists at most. */
    private const MAX_DOCUMENT_ROWS = 5000;

    public function __construct(
        private readonly string $engineName = 'invoicepress/vampire-php',
        private readonly string $engineVersion = '0.1.0',
    ) {
    }

    public function render(Result $result, ?string $documentXml = null): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $report = $dom->createElementNS(self::REP_NS, 'rep:report');
        $report->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:s', self::S_NS);
        $report->setAttribute('varlVersion', '1.0.0');
        $report->setAttribute('valid', $result->isAccepted() ? 'true' : 'false');
        $dom->appendChild($report);

        /**
         * ⚠️ THE RULE PACKAGES STAND IN THE ENGINE NAME, NOT IN AN ELEMENT OF THEIR OWN. The report must say
         * "checked with vampire-php x.y, rule package …". In the VARL schema, however, `rep:engine`
         * knows only `rep:name`; an additional child would set every schema-validating reader against the
         * report - and the report is in the KoSIT format precisely so that foreign readers understand it.
         *
         * The package names do stand per check step in `s:resource/s:name` anyway; the line here is the ONE
         * place a person reads without going through the step list - and the only one `einvoice-report`
         * shows as "Engine".
         */
        $engine = $dom->createElementNS(self::REP_NS, 'rep:engine');

        $label = $this->engineName . ' ' . $this->engineVersion;
        $refs = $result->rulesets();

        if ($refs !== []) {
            $label .= ', Regelpaket ' . implode(', ', $refs);
        }

        $engine->appendChild($dom->createElementNS(self::REP_NS, 'rep:name', $label));
        $report->appendChild($engine);

        $report->appendChild($dom->createElementNS(self::REP_NS, 'rep:timestamp', date('c')));
        $report->appendChild($this->documentIdentification($dom, $result, $documentXml));

        $matched = $dom->createElementNS(self::REP_NS, 'rep:scenarioMatched');
        $report->appendChild($matched);

        $scenario = $dom->createElementNS(self::S_NS, 's:scenario');
        $scenario->appendChild($dom->createElementNS(self::S_NS, 's:name', $result->scenarioName() ?? 'kein Szenario'));
        $matched->appendChild($scenario);

        $matched->appendChild($this->xsdStep($dom, $result));

        foreach ($result->rulesets() as $index => $ref) {
            $matched->appendChild($this->schematronStep($dom, $result, $ref, $index + 1));
        }

        $report->appendChild($this->assessment($dom, $result, $documentXml));

        return (string) $dom->saveXML();
    }

    private function documentIdentification(\DOMDocument $dom, Result $result, ?string $documentXml): \DOMElement
    {
        $identification = $dom->createElementNS(self::REP_NS, 'rep:documentIdentification');

        if ($documentXml !== null) {
            $hash = $dom->createElementNS(self::REP_NS, 'rep:documentHash');
            $hash->appendChild($dom->createElementNS(self::REP_NS, 'rep:hashAlgorithm', 'SHA-256'));
            $hash->appendChild($dom->createElementNS(
                self::REP_NS,
                'rep:hashValue',
                base64_encode(hash('sha256', $documentXml, true))
            ));
            $identification->appendChild($hash);
        }

        $reference = $result->documentReference();

        if ($reference !== null) {
            $node = $dom->createElementNS(self::REP_NS, 'rep:documentReference');
            $node->appendChild($dom->createTextNode($reference));
            $identification->appendChild($node);
        }

        return $identification;
    }

    private function xsdStep(\DOMDocument $dom, Result $result): \DOMElement
    {
        $status = $result->stageStatus(Stage::Xsd);

        $step = $dom->createElementNS(self::REP_NS, 'rep:validationStepResult');
        $step->setAttribute('id', 'val-xsd');
        $step->setAttribute('valid', $status === StageStatus::Failed ? 'false' : 'true');

        $counter = 0;

        foreach ($result->messagesForStage(Stage::Xsd) as $message) {
            $step->appendChild($this->message($dom, $message, 'val-xsd.' . (++$counter)));
        }

        return $step;
    }

    private function schematronStep(\DOMDocument $dom, Result $result, string $ref, int $number): \DOMElement
    {
        $step = $dom->createElementNS(self::REP_NS, 'rep:validationStepResult');
        $step->setAttribute('id', "val-sch.$number");

        $resource = $dom->createElementNS(self::S_NS, 's:resource');
        $resource->appendChild($dom->createElementNS(self::S_NS, 's:name', $ref));
        $step->appendChild($resource);

        $counter = 0;
        $valid = true;

        foreach ($result->messages() as $message) {
            // ⚠️ `origins()`, not `ruleset`: a finding merged across rule sets (Task 5.8a) belongs to
            // EVERY step it came from. The KoSIT format's structure is one message per step, and the
            // report has to keep looking like the original's - so here the one merged finding is
            // written out under each of its steps, which is what the original does too.
            if (!in_array($ref, $message->origins(), true)) {
                continue;
            }

            if ($message->flag === Flag::Fatal) {
                $valid = false;
            }

            $step->appendChild($this->message($dom, $message, "val-sch.$number." . (++$counter)));
        }

        $step->setAttribute('valid', $valid ? 'true' : 'false');

        return $step;
    }

    private function message(\DOMDocument $dom, \EinvoiceValidator\Result\Message $message, string $id): \DOMElement
    {
        $node = $dom->createElementNS(self::REP_NS, 'rep:message');
        $node->setAttribute('id', $id);
        // ⚠️ `nativeFlag()`, not `flag`: measured against 208 real KoSIT reports (Task 4.6), the
        // original writes the RULE SET's severity here and lets `customLevel` decide the verdict
        // alone. A CVD document shows BR-CL-13 as `error` and is accepted anyway. Writing the
        // effective severity here instead looked more consistent and made this report differ from
        // the one the explainer is built for.
        $node->setAttribute('level', self::level($message->nativeFlag()));

        if ($message->location !== null) {
            $node->setAttribute('xpathLocation', $message->location);
        }

        if ($message->ruleId !== null) {
            $node->setAttribute('code', $message->ruleId);
        }

        $node->appendChild($dom->createTextNode($message->text));

        return $node;
    }

    /** KoSIT's vocabulary: our Fatal is its "error". */
    private static function level(Flag $flag): string
    {
        return match ($flag) {
            Flag::Fatal => 'error',
            Flag::Warning => 'warning',
            Flag::Information => 'information',
        };
    }

    private function assessment(\DOMDocument $dom, Result $result, ?string $documentXml): \DOMElement
    {
        $assessment = $dom->createElementNS(self::REP_NS, 'rep:assessment');
        $verdict = $dom->createElementNS(self::REP_NS, $result->isAccepted() ? 'rep:accept' : 'rep:reject');

        $explanation = $dom->createElementNS(self::REP_NS, 'rep:explanation');

        // ⚠️ The original does NOT put plain text here - it embeds a whole XHTML document, and the
        // "Inhalt des Rechnungsdokuments" table inside it is the ONLY place the field-by-field view
        // of the invoice exists. Readers built for the KoSIT report (`einvoice-report`'s ReaderXml)
        // slice this markup back out and parse it as HTML; a text-only explanation therefore yields a
        // report that renders findings and nothing else. Measured against
        // a report of the KoSIT validator for a clean UBL invoice, element for
        // element - the class names below are its, not ours.
        // ⚠️ `createElement()`, NOT `createElementNS()` with the XHTML namespace - and the `xmlns` is
        // declared by hand. Measured: creating these in the XHTML namespace inside a document whose
        // root already carries `rep:`/`s:` bindings makes libxml invent a prefix on serialisation,
        // so the markup comes out as `<default:html><default:table class="document">`. Readers parse
        // the explanation with `loadHTML()` (namespace-oblivious) and look for `table`, `tr` - and
        // find nothing at all. Cost me a full round trip through the reader to see it.
        $html = $dom->createElement('html');
        $html->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', 'http://www.w3.org/1999/xhtml');
        $body = $dom->createElement('body');

        $intro = $dom->createElement('p');
        $intro->setAttribute('class', 'important');
        // ⚠️ The KoSIT format knows only accept and reject. Without the scenario's rule sets the element
        // stays `rep:reject` ( the format allows nothing else, and "accept" would be a false claim ), but the
        // sentence says why: the document was NOT checked ( `Result::hasVerdict()` ).
        $intro->appendChild($dom->createTextNode(
            !$result->hasVerdict()
                ? 'Bewertung: Kein Urteil. Dieses Paket enthält das Regelwerk ' . implode(', ', $result->missingRulesets())
                    . ' nicht; das Dokument wurde nicht geprüft.'
                : ($result->isAccepted()
                    ? 'Bewertung: Es wird empfohlen das Dokument anzunehmen und weiter zu verarbeiten.'
                    : 'Bewertung: Der Beleg verletzt mindestens eine Regel mit sperrender Einstufung.')
        ));
        $body->appendChild($intro);

        // FeRD's visible notice, right under the assessment ( `Result::notices()` ).
        foreach ($result->notices() as $notice) {
            $p = $dom->createElement('p');
            $p->appendChild($dom->createTextNode($notice));
            $body->appendChild($p);
        }

        if ($documentXml !== null && trim($documentXml) !== '') {
            $this->appendDocumentView($dom, $body, $documentXml);
        }

        $html->appendChild($body);
        $explanation->appendChild($html);

        $verdict->appendChild($explanation);
        $assessment->appendChild($verdict);

        return $assessment;
    }

    /**
     * The flat, element-by-element view of the checked document - what the original calls
     * "Inhalt des Rechnungsdokuments".
     *
     * ⚠️ Parsed from the bytes again rather than handed in as a DOM. `render()` has always taken the
     * document as a string (it needs the bytes for the hash), and changing that signature would
     * break every caller for a few milliseconds of parsing. Same hardening as `Pipeline\XmlLoader`:
     * no network, no external entities - this is a report generator, it must not fetch anything.
     *
     * ⚠️ CAPPED. An invoice with a thousand line items has tens of thousands of elements, and the
     * report is held in memory, shipped through a request and rendered in a browser. The cap is
     * visible in the output, never silent: a truncated view says so in its own row.
     */
    private function appendDocumentView(\DOMDocument $dom, \DOMElement $body, string $documentXml): void
    {
        $source = new \DOMDocument();
        $source->preserveWhiteSpace = false;
        $previous = libxml_use_internal_errors(true);
        $loaded = $source->loadXML($documentXml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $source->documentElement === null) {
            return;
        }

        $title = $dom->createElement('p');
        $title->setAttribute('class', 'important');
        $title->appendChild($dom->createTextNode('Inhalt des Rechnungsdokuments:'));
        $body->appendChild($title);

        $table = $dom->createElement('table');
        $table->setAttribute('class', 'document');

        $position = 0;
        $truncated = $this->appendElementRows($dom, $table, $source->documentElement, 1, $position);

        if ($truncated) {
            $row = $dom->createElement('tr');
            $row->setAttribute('class', 'row');
            $cell = $dom->createElement('td');
            $cell->setAttribute('class', 'pos');
            $row->appendChild($cell);
            $note = $dom->createElement('td');
            $note->setAttribute('class', 'element level1');
            $note->setAttribute('title', '…');
            $value = $dom->createElement('div');
            $value->setAttribute('class', 'val');
            $value->appendChild($dom->createTextNode(sprintf(
                'Gekürzt: nur die ersten %d Elemente werden angezeigt.',
                self::MAX_DOCUMENT_ROWS
            )));
            $note->appendChild($value);
            $row->appendChild($note);
            $table->appendChild($row);
        }

        $body->appendChild($table);
    }

    /**
     * @param int $position running number, by reference - the original numbers rows document-wide,
     *                      not per level
     * @return bool true when the cap cut the listing short
     */
    private function appendElementRows(
        \DOMDocument $dom,
        \DOMElement $table,
        \DOMElement $element,
        int $level,
        int &$position
    ): bool {
        if ($position >= self::MAX_DOCUMENT_ROWS) {
            return true;
        }

        $position++;

        $row = $dom->createElement('tr');
        $row->setAttribute('class', 'row');
        $row->setAttribute('id', sprintf('%04d', $position));

        $pos = $dom->createElement('td');
        $pos->setAttribute('class', 'pos');
        $pos->appendChild($dom->createTextNode(sprintf('%04d', $position)));
        $row->appendChild($pos);

        $cell = $dom->createElement('td');
        $cell->setAttribute('class', 'element level' . $level);

        // ⚠️ The element NAME lives in the `title` attribute, not in the cell text - a container
        // element has no text of its own, and a reader looking at the text would show nothing for it.
        $cell->setAttribute('title', $element->localName ?? $element->nodeName);

        // Only the element's OWN text, not its descendants'. `textContent` would repeat the entire
        // subtree on every container row.
        $own = '';

        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $own .= $child->textContent;
            }
        }

        $own = trim($own);

        if ($own !== '') {
            $value = $dom->createElement('div');
            $value->setAttribute('class', 'val');
            $value->appendChild($dom->createTextNode($own));
            $cell->appendChild($value);
        }

        foreach ($element->attributes as $attribute) {
            $attr = $dom->createElement('div');
            $attr->setAttribute('class', 'attribute');
            $attr->setAttribute('title', $attribute->nodeName);
            $attr->appendChild($dom->createTextNode($attribute->value));
            $cell->appendChild($attr);
        }

        $row->appendChild($cell);
        $table->appendChild($row);

        foreach ($element->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }

            if ($this->appendElementRows($dom, $table, $child, $level + 1, $position)) {
                return true;
            }
        }

        return false;
    }
}

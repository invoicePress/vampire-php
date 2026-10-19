<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Mutation;

/**
 * Task 4.2 — applies a {@see Mutation} to a copy of a valid invoice.
 *
 * Namespaces are registered per syntax; the catalogue below is the single place where a mutation is
 * described, so adding one is a data change, not a code change.
 */
final class Mutator
{
    private const NAMESPACES = [
        'ubl' => [
            'ubl' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
            'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
            'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
        ],
        'cii' => [
            'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
            'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
            'udt' => 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100',
        ],
    ];

    /**
     * @return list<Mutation>
     */
    public static function catalogue(): array
    {
        return [
            // --- missing mandatory content ------------------------------------------------------
            // Emptying the element keeps the document XSD-valid (the types are plain strings) while
            // violating the content rule - see the Mutator docblock on why elements are not removed.
            new Mutation(
                'ubl-empty-invoice-number',
                'ubl',
                '/ubl:Invoice/cbc:ID',
                'setText',
                '',
                null,
                'BR-02',
                'Rechnungsnummer (BT-1) geleert.'
            ),
            new Mutation(
                'cii-empty-invoice-number',
                'cii',
                '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:ID',
                'setText',
                '',
                null,
                'BR-02',
                'Rechnungsnummer (BT-1) geleert.'
            ),
            new Mutation(
                'ubl-empty-seller-name',
                'ubl',
                '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName',
                'setText',
                '',
                null,
                'BR-06',
                'Verkäufername (BT-27) geleert.'
            ),
            new Mutation(
                'cii-empty-seller-name',
                'cii',
                '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:Name',
                'setText',
                '',
                null,
                'BR-06',
                'Verkäufername (BT-27) geleert.'
            ),

            // --- code lists ---------------------------------------------------------------------
            new Mutation(
                'ubl-invalid-invoice-type-code',
                'ubl',
                '/ubl:Invoice/cbc:InvoiceTypeCode',
                'setText',
                '999',
                null,
                'BR-CL-01',
                'Dokumentenart (BT-3) auf einen Wert ausserhalb UNTDID 1001 gesetzt.'
            ),
            new Mutation(
                'cii-invalid-invoice-type-code',
                'cii',
                '/rsm:CrossIndustryInvoice/rsm:ExchangedDocument/ram:TypeCode',
                'setText',
                '999',
                null,
                'BR-CL-01',
                'Dokumentenart (BT-3) auf einen Wert ausserhalb UNTDID 1001 gesetzt.'
            ),
            new Mutation(
                'ubl-invalid-currency',
                'ubl',
                '/ubl:Invoice/cbc:DocumentCurrencyCode',
                'setText',
                'XYZ',
                null,
                'BR-CL-04',
                'Rechnungswährung (BT-5) auf einen Wert ausserhalb ISO 4217 gesetzt.'
            ),
            new Mutation(
                'ubl-invalid-country',
                'ubl',
                '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cac:Country/cbc:IdentificationCode',
                'setText',
                'QQ',
                null,
                'BR-CL-14',
                'Länderkennung des Verkäufers (BT-40) auf einen Wert ausserhalb ISO 3166-1 gesetzt.'
            ),
            new Mutation(
                'cii-invalid-country',
                'cii',
                '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:PostalTradeAddress/ram:CountryID',
                'setText',
                'QQ',
                null,
                'BR-CL-14',
                'Länderkennung des Verkäufers (BT-40) auf einen Wert ausserhalb ISO 3166-1 gesetzt.'
            ),

            // --- arithmetic ---------------------------------------------------------------------
            new Mutation(
                'ubl-broken-payable-amount',
                'ubl',
                '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount',
                'setText',
                '99999.99',
                null,
                'BR-CO-16',
                'Zahlbetrag (BT-115) passt nicht mehr zu BT-112 minus BT-113 plus BT-114.'
            ),
            new Mutation(
                'ubl-broken-tax-inclusive-amount',
                'ubl',
                '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount',
                'setText',
                '12345.67',
                null,
                'BR-CO-15',
                'Bruttobetrag (BT-112) passt nicht mehr zu Nettobetrag plus Steuer.'
            ),
            new Mutation(
                'cii-broken-tax-inclusive-amount',
                'cii',
                '/rsm:CrossIndustryInvoice/rsm:SupplyChainTradeTransaction/ram:ApplicableHeaderTradeSettlement/ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount',
                'setText',
                '12345.67',
                null,
                'BR-CO-15',
                'Bruttobetrag (BT-112) passt nicht mehr zu Nettobetrag plus Steuer.'
            ),

            // --- Check digits of identifiers ----------------------------------------------------
            // One document per check-digit function that triggers it through a REAL rule. The values are
            // the same ones `ChecksumFunctionsTest` checks against: known valid identifiers with a
            // falsified check digit, from the check-digit vectors of an identifier library.
            //
            // ⚠️ The source is a **Peppol** invoice. The rules PEPPOL-COMMON-R040/R043/R049/R050 exist
            // only in the Peppol rule set; in an XRechnung scenario none of them runs, and the mutation
            // would then check nothing.
            new Mutation(
                'peppol-bad-gln-endpoint',
                'ubl',
                '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID',
                'setTextAndAttribute',
                '4006381333932',
                'schemeID',
                'PEPPOL-COMMON-R040',
                'EndpointID mit Schema 0088 (GLN) und verfälschter GS1-Prüfziffer - u:gln.',
                '0088',
                'phive-peppol-billing/base-example.xml'
            ),
            new Mutation(
                'peppol-bad-belgian-number',
                'ubl',
                '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID',
                'setTextAndAttribute',
                '0417497107',
                'schemeID',
                'PEPPOL-COMMON-R043',
                'EndpointID mit Schema 0208 (belgische Unternehmensnummer), Prüfziffer verfälscht - u:mod97-0208.',
                '0208',
                'phive-peppol-billing/base-example.xml'
            ),
            new Mutation(
                'peppol-bad-swedish-orgnr',
                'ubl',
                '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID',
                'setTextAndAttribute',
                '5560160681',
                'schemeID',
                'PEPPOL-COMMON-R049',
                'EndpointID mit Schema 0007 (schwedische Organisationsnummer), Luhn verfälscht - u:checkSEOrgnr.',
                '0007',
                'phive-peppol-billing/base-example.xml'
            ),
            new Mutation(
                'peppol-bad-australian-abn',
                'ubl',
                '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID',
                'setTextAndAttribute',
                '51824753557',
                'schemeID',
                'PEPPOL-COMMON-R050',
                'EndpointID mit Schema 0151 (ABN), Prüfziffer verfälscht - u:abn.',
                '0151',
                'phive-peppol-billing/base-example.xml'
            ),
        ];
    }

    /**
     * @throws \RuntimeException when the mutation's XPath matches nothing - a silently skipped
     *                           mutation would leave a negative test that tests nothing.
     */
    public function apply(\DOMDocument $document, Mutation $mutation): void
    {
        $xpath = new \DOMXPath($document);

        foreach (self::NAMESPACES[$mutation->syntax] as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        $nodes = $xpath->query($mutation->xpath);

        if ($nodes === false || $nodes->length === 0) {
            throw new \RuntimeException(
                sprintf('Mutation %s: XPath "%s" trifft nichts.', $mutation->id, $mutation->xpath)
            );
        }

        $node = $nodes->item(0);

        if (!$node instanceof \DOMElement) {
            throw new \RuntimeException(sprintf('Mutation %s: Treffer ist kein Element.', $mutation->id));
        }

        switch ($mutation->operation) {
            case 'setText':
                $node->textContent = (string) $mutation->value;
                break;

            case 'setAttribute':
                $node->setAttribute((string) $mutation->attribute, (string) $mutation->value);
                break;

            // Task 4.7: an identifier only reaches its check-digit rule together with its scheme id -
            // `<cbc:EndpointID schemeID="0088">` decides WHICH rule looks at the value at all.
            case 'setTextAndAttribute':
                $node->textContent = (string) $mutation->value;
                $node->setAttribute((string) $mutation->attribute, (string) $mutation->attributeValue);
                break;

            case 'remove':
                $node->parentNode?->removeChild($node);
                break;

            default:
                throw new \RuntimeException(sprintf('Mutation %s: unbekannte Operation.', $mutation->id));
        }
    }
}

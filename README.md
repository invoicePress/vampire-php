# VAMPIRE PHP

Validate e-invoices against EN 16931 in plain PHP - no Java, no network, no external service.

VAMPIRE PHP takes an XML invoice ( UBL or CII ) and returns a validation report: first well-formedness, then the XML
schema, then the business rules of the matching rule set. The rules come from the official Schematron packages; they are
executed by an XPath 2.0 interpreter of its own, not by an XSLT processor. Rule identifiers, message texts and the
accept / reject decision are those of the reference validator of KoSIT.

## What it checks

| Rule set | Syntaxes |
|---|---|
| EN 16931 core rules | UBL, CII |
| XRechnung 3.0.2, including Extension and CVD | UBL Invoice, UBL CreditNote, CII |
| Peppol BIS Billing 3.0.21 | UBL Invoice, UBL CreditNote |
| Factur-X / ZUGFeRD 2.5.2, profiles EN16931 and EXTENDED | CII |
| Factur-X / ZUGFeRD 2.1 to 2.5.2, all profiles, for the check against every edition | CII |

A document whose rule set is not in the package gets no verdict and names the missing rule set; it is never reported as
rejected by mistake.

## Requirements

PHP 8.2 or newer with `ext-dom`, `ext-libxml` and `ext-bcmath`. No other runtime dependencies.

## Installation

```
composer require invoicepress/vampire-php
```

## Usage

```php
use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Validator;

// The package directory: the scenarios decide which schema and which rule sets an invoice gets.
$package   = dirname( ( new ReflectionClass( Validator::class ) )->getFileName(), 2 );
$validator = new Validator(
    scenarioResolver: ScenarioResolver::fromJsonFile( $package . '/rulesets/scenarios.json' ),
    baseDir:          $package,
);

$result = $validator->validateFile( 'invoice.xml' );

echo $result->isAccepted() ? "accepted\n" : "rejected\n";

foreach ( $result->messages() as $message ) {
    printf( "%-8s %-14s %s\n", $message->flag->value, $message->ruleId ?? '-', $message->text );
}
```

⚠️ `baseDir` is the package directory, not your project root. The paths inside `scenarios.json` are resolved against it;
pointing it elsewhere resolves the scenario but runs no rules.

`validateString()` takes the XML as a string and `validateDom()` a `DOMDocument`.

## Together with horstoeko/zugferd

VAMPIRE PHP checks what `horstoeko/zugferd` or `horstoeko/invoicesuite` writes, in the same process and without a
detour through a file:

```php
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;

$document = ZugferdDocumentBuilder::createNew( ZugferdProfiles::PROFILE_EN16931 );
$document->setDocumentInformation( 'R-2026-001', '380', new DateTime( '2026-10-18' ), 'EUR' );
// … fill in seller, buyer, lines and totals as usual …

$result = $validator->validateDom( $document->getContentAsDomDocument() );
```

With only the document information above, the result is "rejected", and the first message is BR-06: an invoice shall
contain the seller name ( BT-27 ).

## Command line

```
bin/einvoice-validator validate invoice.xml
bin/einvoice-validator validate invoice.xml --format=json
bin/einvoice-validator ruleset:list
bin/einvoice-validator selftest
```

Exit codes: 0 accepted, 1 rejected, 2 calling error.

## Documentation

The manual for users is in `docs/manual/` in English and German. The developer documentation in `docs/developer/` is in
German:

| File | Content |
|---|---|
| `docs/developer/api.md` | the public API, its exceptions, and what counts as internal |
| `docs/developer/report-formats.md` | the report formats JSON, HTML and KoSIT XML |
| `docs/developer/profile-format.md` | rule profiles: own rules on top of the official ones |
| `docs/developer/custom-rules.md` | own rules bound to business terms |
| `docs/developer/eu-cius.md` | the national variants: what runs and what does not |
| `docs/developer/performance.md` | run time and memory for large invoices |

## Development

```
composer install
composer test
```

The test suite has 1,341 tests; 29 of them compare against an invoice corpus and recordings outside this repository and are skipped without them ( see `tests/Support/Corpus.php` ). `src/` is the runtime. The rule packages under `rulesets/` are compiled from the official
Schematron and scenario files; the compiler is not part of this repository.

## Licence

VAMPIRE PHP is free software under the GNU General Public License, version 2 or later ( `LICENSE` ).

The rule packages are not original work of this project. Each one is derived by machine from a published Schematron rule
set and carries the licence of its source in its head. `NOTICE` names origin, rights holder and licence of every rule
package, and `licences/` holds the licence texts.

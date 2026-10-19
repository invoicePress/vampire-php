# VAMPIRE PHP – Manual
*Applies to version 1.1.0. As of 18.10.2026.*

## 1 · Overview

VAMPIRE PHP is a PHP library that validates e-invoices according to EN 16931. It takes an XML invoice in UBL or CII and returns a validation report: first well-formedness, then the XML schema, then the business rules of the matching rule set. The rules come from the official Schematron packages. They run inside the PHP process itself, without Java, without a validation service and without the network.

**Who it is for:** developers who want to build EN 16931 validation into their own PHP application, also on ordinary web hosting without Java.

**What it does:**

- It checks well-formedness, XML schema and business rules in one pass and returns the verdict "accepted" or "rejected" with all messages.
- It chooses schema and rule sets itself through scenarios, based on the invoice's specification identifier.
- It ships 17 rule sets with 9,677 rules in total ( measured with `ruleset:list` ). It chooses 15 of them itself through the scenarios: EN 16931 for UBL and CII, XRechnung 3.0.2 for UBL and CII, Peppol BIS Billing 3.0.21, four Factur-X 1.09.2 profiles and the national variants for Italy, Romania, Portugal, Belgium and Serbia. The variant for Croatia and the Factur-X profile EN 16931 are included and can be added through a validation profile.
- It can check a ZUGFeRD invoice against several ZUGFeRD versions at once.
- It writes reports as text, JSON, HTML or in the XML format of the KoSIT validator.
- It can be made stricter or more lenient through validation profiles, without changing a rule set.
- It has a command line with a self-test.

**What it does not do:**

- It does not read PDF files. The XML embedded in a ZUGFeRD or Factur-X PDF must be extracted first.
- It does not check formats outside EN 16931 and its variants, such as FatturaPA or KSeF.
- It is not a user interface. For WordPress there is the plugin eInvoice Validator for VAMPIRE.

**Requirements:**

| Requirement | Value |
|---|---|
| PHP | 8.2 or newer |
| PHP extensions | `dom`, `libxml`, `bcmath` |
| Other dependencies | none |

## 2 · Installation

You receive VAMPIRE PHP as a ZIP file from specialPress.

1. Unpack the ZIP file into a directory of your project, for example `lib/vampire-php/`.
2. Include the autoloader. With Composer, add the directory as a path repository and require the package `invoicepress/vampire-php`; without Composer, include `vendor/autoload.php` from the package.
3. Run `php bin/einvoice-validator selftest`. **Expected:** every line reports "ok", and the sample invoice is "accepted".

### Updating

Replace the directory with the new version. Back up your own validation profiles in the folder `profiles/` first and put them back afterwards.

## 3 · First steps

```php
use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Validator;

$base      = __DIR__ . '/lib/vampire-php';
$resolver  = ScenarioResolver::fromJsonFile( $base . '/rulesets/scenarios.json' );
$validator = new Validator( scenarioResolver: $resolver, baseDir: $base );

$result = $validator->validateFile( 'invoice.xml' );

if ( $result->isAccepted() ) {
	echo "accepted\n";
} else {
	foreach ( $result->messages() as $message ) {
		printf( "%-12s %-14s %s\n", $message->flag->value, $message->ruleId ?? '-', $message->text );
	}
}
```

`baseDir` is the directory against which the paths in `scenarios.json` are resolved, usually the package directory. Without a `ScenarioResolver` the library only checks well-formedness.

## 4 · Working with VAMPIRE PHP

### The course of a validation

1. **Well-formedness.** If the file is not valid XML, the validation ends here with "rejected".
2. **Scenario.** The library reads the invoice's specification identifier and looks up the matching scenario in `rulesets/scenarios.json`. There are 22 scenarios. If none matches, it says so instead of guessing.
3. **XML schema.** The scenario names the schema to validate against.
4. **Business rules.** The scenario names one or more rule sets whose rules are evaluated in turn.

The verdict follows the most serious message: a message of level `fatal` makes the invoice "rejected", warnings and information messages do not.

### On the command line

```
php bin/einvoice-validator validate invoice.xml
php bin/einvoice-validator validate invoice.xml --format=json --profile=house-standard
php bin/einvoice-validator validate invoice.xml --cascade
php bin/einvoice-validator ruleset:list
php bin/einvoice-validator profile:show house-standard
php bin/einvoice-validator selftest
```

| Option | Effect |
|---|---|
| `--format=text\|json\|html\|xml` | The report format. `xml` is the format of the KoSIT validator. |
| `--profile=<id>` | A validation profile from the folder `profiles/`. |
| `--scenario=<id>` | Force a scenario instead of detecting it. |
| `--rule-report` | Also list the rules that passed, as a positive validation report. |
| `--cascade` / `--matrix` | Check a ZUGFeRD invoice against several ZUGFeRD versions, as a sequence or as a table. |

The command line returns 0 if the invoice is accepted, 1 if it is rejected, and 2 for a usage error.

### Validation profiles

A validation profile sets how strictly the library validates. It never changes a rule set but lies on top of it as a layer. Profiles are JSON files in the package's folder `profiles/`:

```json
{
	"id": "house-standard",
	"title": "House profile, 2026",
	"extends": null,
	"disable": [ "BR-DE-15" ],
	"severity": { "BR-DE-18": "warning" },
	"include": [ { "ruleset": "PEPPOL-EN16931-UBL@3.0.21", "rules": [ "PEPPOL-EN16931-R120" ] } ],
	"note": "What this profile is for."
}
```

| Field | Meaning |
|---|---|
| `id` | The unique name; it appears in the report. |
| `extends` | The `id` of another profile whose entries apply first. |
| `disable` | Rules that are not evaluated at all. |
| `severity` | Rules with a different level: `fatal`, `warning` or `information`. |
| `include` | Rules from a rule set the scenario does not check anyway. |

⚠️ A profile that switches off a mandatory rule can accept an invoice the official validator rejects. The report therefore always names the profile used.

### Reports

| Format | What it is meant for |
|---|---|
| Text | For the command line: verdict and one line per message. |
| JSON | For further processing: scenario, verdict, messages with rule, level, text and location. |
| HTML | For people: a report to display or save. |
| KoSIT XML | For tools that expect the report of the KoSIT validator. |

## 5 · Settings

The library has no settings file. You decide what it does in the call: through the scenario, the validation profile and the report format.

## 6 · Special cases

### Checking a ZUGFeRD PDF

Extract the embedded XML from the PDF, for example the file `factur-x.xml`, and check that. The library itself does not open PDFs.

### An invoice matches no scenario

The invoice's specification identifier names no known format. Check the identifier in field BT-24, or force a scenario with `--scenario` if you know what the invoice is built to.

### Large invoices

The validation runs in the memory of the PHP process. For invoices with very many lines, raise `memory_limit` and the time limit of your script.

## 7 · Working together

- **eInvoice Validator for VAMPIRE** brings the library into the WordPress admin as a plugin with a drop area and a report.
- **VAMPIRE JS** checks the same rules in the browser.

## 8 · Troubleshooting

| What you see | Cause | What to do |
|---|---|---|
| The self-test reports a missing extension | `dom`, `libxml` or `bcmath` is missing in your PHP. | Have your host enable the extension. |
| "No scenario" | The invoice carries no known specification identifier. | See section 6. |
| Memory runs out | The invoice is very large. | Raise `memory_limit`. |
| Return value 2 | The command-line call is wrong, for example a missing file. | Check path and options. |

## 9 · Privacy and security

- The library calls no service and loads nothing from the network. The invoice does not leave your server.
- It writes no files unless you have it write a report.
- XML is loaded without external entities.

## 10 · Licence

VAMPIRE PHP is a commercial product under its own specialPress licence ( file `LICENSE` ). The rule sets come from public sources; their origin is stated in the file `NOTICE`. The library does not belong in any plugin distributed through wordpress.org.

## 11 · Versions

| Version | What changed |
|---|---|
| 1.1.0 | The first version released as a package of its own. It contains the positive validation report, the check against several ZUGFeRD versions and the document view in the report. Until now the library was only bundled with the plugin eInvoice Validator for VAMPIRE. |

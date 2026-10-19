# VAMPIRE PHP – Handbuch
*Gilt für Fassung 1.1.0. Stand 18.10.2026.*

## 1 · Überblick

VAMPIRE PHP ist eine PHP-Bibliothek, die E-Rechnungen nach EN 16931 prüft. Sie nimmt eine XML-Rechnung in UBL oder CII entgegen und liefert einen Prüfbericht: zuerst Wohlgeformtheit, dann das XML-Schema, dann die Geschäftsregeln des passenden Regelwerks. Die Regeln stammen aus den offiziellen Schematron-Paketen. Ausgeführt werden sie im PHP-Prozess selbst, ohne Java, ohne Prüfdienst und ohne Netzwerk.

**Für wen sie gedacht ist:** für Entwickler, die eine Prüfung nach EN 16931 in eine eigene PHP-Anwendung einbauen wollen, auch auf einem gewöhnlichen Webhosting ohne Java.

**Was sie kann:**

- Sie prüft Wohlgeformtheit, XML-Schema und Geschäftsregeln in einem Durchgang und gibt das Urteil „angenommen“ oder „abgelehnt“ mit allen Meldungen zurück.
- Sie wählt Schema und Regelwerke über Szenarien selbst aus, anhand der Spezifikationskennung der Rechnung.
- Sie bringt 17 Regelwerke mit zusammen 9 677 Regeln mit ( gemessen mit `ruleset:list` ). 15 davon wählt sie über die Szenarien selbst aus: EN 16931 für UBL und CII, XRechnung 3.0.2 für UBL und CII, Peppol BIS Billing 3.0.21, vier Profile von Factur-X 1.09.2 und die Länderausprägungen für Italien, Rumänien, Portugal, Belgien und Serbien. Die Ausprägung für Kroatien und das Factur-X-Profil EN 16931 liegen bei und lassen sich über ein Prüfprofil zuschalten.
- Sie prüft eine ZUGFeRD-Rechnung auf Wunsch gegen mehrere ZUGFeRD-Fassungen zugleich.
- Sie schreibt Berichte als Text, JSON, HTML oder im XML-Format des KoSIT-Validators.
- Sie lässt sich über Prüfprofile strenger oder milder einstellen, ohne ein Regelwerk zu ändern.
- Sie hat eine Kommandozeile mit Selbsttest.

**Was sie nicht kann:**

- Sie liest keine PDF-Datei. Aus einer ZUGFeRD- oder Factur-X-PDF muss die eingebettete XML vorher herausgelöst werden.
- Sie prüft keine Formate ausserhalb von EN 16931 und ihren Ausprägungen, etwa FatturaPA oder KSeF.
- Sie ist keine Oberfläche. Für WordPress gibt es dafür das Plugin eInvoice Validator for VAMPIRE.

**Voraussetzungen:**

| Voraussetzung | Angabe |
|---|---|
| PHP | 8.2 oder neuer |
| PHP-Erweiterungen | `dom`, `libxml`, `bcmath` |
| Weitere Abhängigkeiten | keine |

## 2 · Installieren

VAMPIRE PHP erhalten Sie als ZIP-Datei von specialPress.

1. Entpacken Sie die ZIP-Datei in ein Verzeichnis Ihres Projekts, etwa `lib/vampire-php/`.
2. Binden Sie den Autoloader ein. Mit Composer tragen Sie das Verzeichnis als Pfad-Repository ein und verlangen das Paket `invoicepress/vampire-php`; ohne Composer binden Sie `vendor/autoload.php` aus dem Paket ein.
3. Führen Sie `php bin/einvoice-validator selftest` aus. **Erwartet:** Jede Zeile meldet „ok“, und der Beispielbeleg ist „angenommen“.

### Aktualisieren

Ersetzen Sie das Verzeichnis durch die neue Fassung. Eigene Prüfprofile im Ordner `profiles/` sichern Sie vorher und legen sie danach wieder hinein.

## 3 · Erste Schritte

```php
use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Validator;

$base      = __DIR__ . '/lib/vampire-php';
$resolver  = ScenarioResolver::fromJsonFile( $base . '/rulesets/scenarios.json' );
$validator = new Validator( scenarioResolver: $resolver, baseDir: $base );

$result = $validator->validateFile( 'rechnung.xml' );

if ( $result->isAccepted() ) {
	echo "angenommen\n";
} else {
	foreach ( $result->messages() as $message ) {
		printf( "%-12s %-14s %s\n", $message->flag->value, $message->ruleId ?? '-', $message->text );
	}
}
```

`baseDir` ist das Verzeichnis, gegen das die Pfade aus `scenarios.json` aufgelöst werden, in der Regel das Paketverzeichnis. Ohne `ScenarioResolver` prüft die Bibliothek nur die Wohlgeformtheit.

## 4 · Arbeiten mit VAMPIRE PHP

### Der Ablauf einer Prüfung

1. **Wohlgeformtheit.** Ist die Datei kein gültiges XML, endet die Prüfung hier mit „abgelehnt“.
2. **Szenario.** Die Bibliothek liest die Spezifikationskennung der Rechnung und sucht in `rulesets/scenarios.json` das passende Szenario. Es gibt 22 Szenarien. Passt keines, meldet sie das, statt zu raten.
3. **XML-Schema.** Das Szenario nennt das Schema, gegen das geprüft wird.
4. **Geschäftsregeln.** Das Szenario nennt ein oder mehrere Regelwerke, deren Regeln nacheinander ausgewertet werden.

Das Urteil folgt der schwersten Meldung: Eine Meldung der Stufe `fatal` macht die Rechnung „abgelehnt“, Warnungen und Hinweise nicht.

### Auf der Kommandozeile

```
php bin/einvoice-validator validate rechnung.xml
php bin/einvoice-validator validate rechnung.xml --format=json --profile=haus-standard
php bin/einvoice-validator validate rechnung.xml --cascade
php bin/einvoice-validator ruleset:list
php bin/einvoice-validator profile:show haus-standard
php bin/einvoice-validator selftest
```

| Schalter | Wirkung |
|---|---|
| `--format=text\|json\|html\|xml` | Das Format des Berichts. `xml` ist das Format des KoSIT-Validators. |
| `--profile=<id>` | Ein Prüfprofil aus dem Ordner `profiles/`. |
| `--scenario=<id>` | Ein Szenario erzwingen statt es zu erkennen. |
| `--rule-report` | Auch die bestandenen Regeln aufführen, als positiver Prüfbericht. |
| `--cascade` / `--matrix` | Eine ZUGFeRD-Rechnung gegen mehrere ZUGFeRD-Fassungen prüfen, als Folge oder als Tabelle. |

Die Kommandozeile gibt 0 zurück, wenn die Rechnung angenommen ist, 1, wenn sie abgelehnt ist, und 2 bei einem Aufruffehler.

### Prüfprofile

Ein Prüfprofil legt fest, wie streng geprüft wird. Es ändert nie ein Regelwerk, sondern liegt als Schicht darüber. Profile sind JSON-Dateien im Ordner `profiles/` des Pakets:

```json
{
	"id": "haus-standard",
	"title": "Hausprofil, Stand 2026",
	"extends": null,
	"disable": [ "BR-DE-15" ],
	"severity": { "BR-DE-18": "warning" },
	"include": [ { "ruleset": "PEPPOL-EN16931-UBL@3.0.21", "rules": [ "PEPPOL-EN16931-R120" ] } ],
	"note": "Wozu dieses Profil da ist."
}
```

| Feld | Bedeutung |
|---|---|
| `id` | Der eindeutige Name; er erscheint im Bericht. |
| `extends` | Die `id` eines anderen Profils, dessen Einträge zuerst gelten. |
| `disable` | Regeln, die gar nicht ausgewertet werden. |
| `severity` | Regeln mit einer anderen Stufe: `fatal`, `warning` oder `information`. |
| `include` | Regeln aus einem Regelwerk, das das Szenario nicht ohnehin prüft. |

⚠️ Ein Profil, das eine Pflichtregel abschaltet, kann eine Rechnung annehmen, die der amtliche Validator ablehnt. Der Bericht nennt deshalb immer das verwendete Profil.

### Berichte

| Format | Wofür es gedacht ist |
|---|---|
| Text | Für die Kommandozeile: Urteil und eine Zeile je Meldung. |
| JSON | Für die Weiterverarbeitung: Szenario, Urteil, Meldungen mit Regel, Stufe, Text und Fundstelle. |
| HTML | Für Menschen: ein Bericht zum Anzeigen oder Speichern. |
| KoSIT-XML | Für Werkzeuge, die den Bericht des KoSIT-Validators erwarten. |

## 5 · Einstellungen

Die Bibliothek hat keine Einstellungsdatei. Was sie tut, legen Sie im Aufruf fest: über das Szenario, das Prüfprofil und das Format des Berichts.

## 6 · Besondere Fälle

### Eine ZUGFeRD-PDF prüfen

Lösen Sie die eingebettete XML aus der PDF, etwa die Datei `factur-x.xml`, und prüfen Sie diese. Die Bibliothek selbst öffnet keine PDF.

### Eine Rechnung passt zu keinem Szenario

Die Spezifikationskennung der Rechnung nennt kein bekanntes Format. Prüfen Sie die Kennung im Feld BT-24, oder erzwingen Sie mit `--scenario` ein Szenario, wenn Sie wissen, wonach die Rechnung gebaut ist.

### Grosse Rechnungen

Die Prüfung läuft im Speicher des PHP-Prozesses. Bei Rechnungen mit sehr vielen Positionen erhöhen Sie `memory_limit` und die Laufzeitgrenze Ihres Skripts.

## 7 · Zusammenspiel

- **eInvoice Validator for VAMPIRE** bringt die Bibliothek als WordPress-Plugin mit Ablagefläche und Bericht in die Verwaltung.
- **VAMPIRE JS** prüft dieselben Regeln im Browser.

## 8 · Wenn etwas nicht geht

| Was Sie sehen | Woran es liegt | Was Sie tun |
|---|---|---|
| Der Selbsttest meldet eine fehlende Erweiterung | `dom`, `libxml` oder `bcmath` fehlt in Ihrem PHP. | Lassen Sie die Erweiterung von Ihrem Hoster einschalten. |
| „Kein Szenario“ | Die Rechnung trägt keine bekannte Spezifikationskennung. | Siehe Abschnitt 6. |
| Der Speicher reicht nicht | Die Rechnung ist sehr gross. | Erhöhen Sie `memory_limit`. |
| Rückgabewert 2 | Der Aufruf der Kommandozeile ist fehlerhaft, etwa eine fehlende Datei. | Prüfen Sie Pfad und Schalter. |

## 9 · Datenschutz und Sicherheit

- Die Bibliothek ruft keinen Dienst auf und lädt nichts aus dem Netz. Die Rechnung verlässt Ihren Server nicht.
- Sie schreibt keine Dateien, ausser Sie lassen einen Bericht schreiben.
- XML wird ohne externe Entitäten geladen.

## 10 · Lizenz

VAMPIRE PHP ist ein Kaufprodukt unter einer eigenen Lizenz von specialPress ( Datei `LICENSE` ). Die Regelwerke stammen aus öffentlichen Quellen; ihre Herkunft steht in der Datei `NOTICE`. Die Bibliothek gehört in kein Plugin, das über wordpress.org verteilt wird.

## 11 · Fassungen

| Fassung | Was sich geändert hat |
|---|---|
| 1.1.0 | Die erste Fassung, die als eigenes Paket erscheint. Sie enthält den positiven Prüfbericht, die Prüfung gegen mehrere ZUGFeRD-Fassungen und die Dokumentansicht im Bericht. Bisher lag die Bibliothek nur im Plugin eInvoice Validator for VAMPIRE bei. |

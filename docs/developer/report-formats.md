# Berichtsformate (Aufgaben 7.1 bis 7.3)

Drei Ausgaben aus demselben `Result`. Keine ist die „richtige": JSON ist für Programme, HTML für
Menschen, das KoSIT-XML für Werkzeuge, die schon Prüfberichte lesen.

## JSON (`Report\JsonReport`)

```json
{
  "schemaVersion": "1.1",
  "accepted": false,
  "scenario": "EN16931 XRechnung (UBL Invoice)",
  "rulesets": ["EN16931-UBL@1.3.16", "XRechnung-UBL@3.0.2"],
  "document": "rechnung.xml",
  "stages":  { "wellformed": "passed", "xsd": "passed", "syntax": "passed", "business": "failed" },
  "counts":  { "fatal": 2, "warning": 0, "information": 1 },
  "findings": [
    {
      "stage": "business",
      "rule": "BR-02",
      "rulesets": ["EN16931-UBL@1.3.16", "CEN-EN16931-UBL@3.0.21"],
      "severity": "fatal",
      "ruleSeverity": "fatal",
      "text": "[BR-02]-An Invoice shall have an Invoice number (BT-1).",
      "location": "/ubl:Invoice",
      "line": 12,
      "fields": ["BT-1"]
    }
  ]
}
```

| Feld | Bedeutung |
|---|---|
| `schemaVersion` | erhöht sich, wenn sich die Bedeutung eines Feldes ändert |
| `accepted` | Gesamturteil; wahr genau dann, wenn kein Befund sperrt. Seit 09.10.2026 `null`, wenn das Paket ein Regelwerk des Szenarios nicht enthält ( siehe `missingRulesets` ); das ist kein „abgelehnt“, denn das Dokument wurde nicht geprüft |
| `stages` | `notrun`, `passed`, `failed`, `skipped` je Stufe |
| `counts` | Befunde je Einstufung |
| `findings[].severity` | `fatal`, `warning`, `information` — die **wirksame** Einstufung, sie entscheidet über das Urteil |
| `findings[].ruleSeverity` | was das **Regelwerk selbst** sagt, bevor ein Szenario oder Profil umgestuft hat |
| `findings[].rulesets` | **alle** Regelwerke, aus denen dieser eine Befund stammt (Aufgabe 5.8a) |
| `findings[].fields` | BT-/BG-Bezüge, die der Regeltext nennt (kann leer sein) |
| `findings[].origin` | seit 08.10.2026: `official` (ein Regelwerk), `profile` (ein Regelprofil des Rule Studio) oder `validator` (die Prüfstrecke selbst: Wohlgeformtheit, Schema, Szenario, Ablehnungen des Bedienerprofils) |
| `findings[].profile` | seit 08.10.2026: `name@version` des Regelprofils bei `origin: profile`, sonst `null` |
| `findings[].severityBy` | seit 08.10.2026: `name@version` des Regelprofils, das einen amtlichen Befund umgestuft hat, sonst `null` |
| `profiles` | seit 08.10.2026: je Regelprofil, ob es angewendet wurde, welche Fassung es getroffen hat und welche amtlichen Regeln es abgeschaltet, ersetzt oder umgestuft hat, mit der Zahl der unterdrückten Befunde. Ohne Profile eine leere Liste |
| `officialAccepted` | seit 08.10.2026: das Urteil der amtlichen Regelwerke allein, ohne jede Änderung durch ein Regelprofil; `null`, wenn kein Regelprofil angewendet wurde |
| `missingRulesets` | seit 09.10.2026: die Regelwerke des aufgelösten Szenarios, die dieses Paket nicht enthält, etwa nach einem Export mit `--without=peppol`. Nicht leer heisst: kein Urteil, kein Schema, keine Regeln. Immer vorhanden, sonst eine leere Liste. VAMPIRE JS schreibt dasselbe Feld |
| `notices` | seit 09.10.2026: Sätze, die jede Anzeige dieses Berichts sichtbar zeigen muss. Heute nur der Hinweis von FeRD bei einer Prüfung mit einem Factur-X-Regelwerk; FeRD macht ihn zur Bedingung der Lizenz. Immer vorhanden, sonst eine leere Liste |

Die Regelprofile und ihre Felder beschreibt `docs/developer/profile-format.md`. Die fünf Felder
sind innerhalb von `schemaVersion` 1.1 hinzugekommen, wie es die Stabilitätszusage unten erlaubt.

⚠️ **`ruleset` heißt seit `schemaVersion` 1.1 `rulesets` und ist eine Liste.** Dieselbe Regel steckt in
bis zu sechs Regelwerken — `UBL-CR-400` zum Beispiel, und zwischen `EN16931-UBL@1.3.16` und Peppols
eigener Fassung sind es gemessen 975 von 979 Regeln. Sechs Befunde zu melden, wo es ein Problem gibt,
wäre schlicht falsch gezählt. Das alte Einzelfeld wurde **entfernt**, nicht danebengestellt: ein Leser,
der die Version ignoriert und weiter `ruleset` liest, sähe sonst stillschweigend nur eine Herkunft und
erführe von den anderen nie.

⚠️ **Die Befundliste ist flach**, nicht nach Stufe verschachtelt. Wer gruppieren will, gruppiert
ohnehin nach etwas anderem — Einstufung, Regelwerk, Geschäftsbegriff —, und ein Baum zwingt jeden
Abnehmer, ihn erst wieder flach zu machen.

**Stabilitätszusage:** Felder werden innerhalb einer `schemaVersion` nicht entfernt und nicht
umgedeutet. Neue Felder können dazukommen; ein Leser muss unbekannte Felder ignorieren.

## Positiver Prüfbericht (`ruleReport`, seit 09.10.2026)

Mit eingeschaltetem Schalter ( PHP `new Validator( ruleReport: true )`, Kommandozeile `--rule-report`,
VAMPIRE JS `new Validator( { ruleReport: true } )` und im Zusatz `validate( xml, { ruleReport: true } )` )
trägt der JSON-Bericht am Ende das Feld `ruleReport`. Ohne Schalter fehlt das Feld ganz; der Bericht ist dann
Byte für Byte derselbe wie vorher. Die Messung zu KoSIT und Mustang und
die Paritätszahlen stehen in `positiver-pruefbericht.md`. VAMPIRE JS schreibt dieselbe Form
( `RuleReport` in VAMPIRE JS ).

Das Beispiel ist der Bericht zu `XRechnung/01.01a-INVOICE_ubl.xml` aus dem Korpus, gekürzt auf je einen
Eintrag in `rulesets` und `rules`; Prüfsummen sind in der Mitte gekürzt.

```json
"ruleReport": {
  "formatVersion": 1,
  "validatedAt": "2026-10-09T15:23:15Z",
  "engine": { "name": "VAMPIRE PHP", "version": "1.0.0" },
  "document": { "name": "01.01a-INVOICE_ubl.xml", "sha256": "6a41d0d8…442f1667", "bytes": 6887, "syntax": "UBL",
                "specificationId": "urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0" },
  "scenario": { "id": "en16931-xrechnung-ubl-invoice", "name": "EN16931 XRechnung (UBL Invoice)" },
  "steps": [
    { "step": "wellformed", "result": "passed", "reason": null },
    { "step": "scenario", "result": "passed", "reason": null },
    { "step": "schema", "result": "passed", "reason": null },
    { "step": "rules", "result": "passed", "reason": null }
  ],
  "rulesets": [
    { "ref": "EN16931-UBL@1.3.16", "id": "EN16931-UBL", "version": "1.3.16", "title": "EN16931  model bound to UBL",
      "license": "EUPL-1.2", "sourceSha256": "39f9d282…7595fcbd", "ran": true, "reason": null, "rules": 979 }
  ],
  "counts": { "passed": 897, "failed": 1, "not-applicable": 157, "not-checked": 0 },
  "rules": [
    { "ruleset": "EN16931-UBL@1.3.16", "rule": "BR-01", "key": "BR-01", "result": "passed",
      "severity": "fatal", "ruleSeverity": "fatal", "text": "[BR-01]-An Invoice shall have a Specification identifier (BT-24).",
      "checked": 1, "failed": 0, "reason": null }
  ]
}
```

| Feld | Bedeutung |
|---|---|
| `formatVersion` | erhöht sich, wenn sich die Bedeutung eines Feldes dieses Abschnitts ändert |
| `validatedAt` | Zeitpunkt der Prüfung, ISO 8601 in UTC mit `Z` |
| `engine` | `VAMPIRE PHP` oder `VAMPIRE JS` mit Fassung; im Zusatz `vampire/` dessen Fassung |
| `document.sha256`, `document.bytes` | SHA-256 und Grösse der geprüften Bytes. `null` bei `validateDom()`, weil ein DOM keine eigenen Bytes hat. Im Zusatz `vampire/` wird der übergebene Text als UTF-8 gerechnet; das ergibt die Prüfsumme der Datei, wenn sie UTF-8 ist und samt BOM gelesen wurde |
| `document.name` | nur der Dateiname, nie ein Pfad; `null` bei `validateString()` |
| `document.syntax`, `document.specificationId` | `UBL` oder `CII` nach dem Namensraum der Wurzel, und der Wert von BT-24, so wie der Beleg ihn nennt |
| `scenario` | Kennung und Name des angewandten Szenarios, beides `null` ohne Szenario |
| `steps` | die Kaskade in der Reihenfolge des Laufs: `wellformed`, `scenario`, `schema`, `rules`, je mit `passed`, `failed` oder `not-checked` und dem Grund |
| `rulesets` | jedes Regelwerk des Laufs in Laufreihenfolge, auch eines, das fehlt ( `ran: false`, `reason: not-included`, `rules: null` ) oder das nicht lief ( `ran: false`, `reason: stopped` ) |
| `counts` | Zahl der Regelzeilen je Ergebnis |
| `rules[].result` | `passed`, `failed`, `not-applicable` oder `not-checked`; Anzeigetexte „erfüllt“, „verletzt“, „nicht anwendbar“, „nicht geprüft“ |
| `rules[].key` | die Regelkennung, oder bei einer Bedingung ohne Kennung ihre Stelle im Regelwerk als `#<Muster>.<Regel>.<Bedingung>`, gezählt ab 0. Die Factur-X-Regelwerke haben solche Bedingungen, EXTENDED allein 521 |
| `rules[].severity`, `rules[].ruleSeverity` | die wirksame Schwere nach Szenario, Bedienerprofil und Regelprofil, und die Schwere des Regelwerks selbst; bei einer Kennung auf mehreren Bedingungen die höchste |
| `rules[].text` | der Regeltext ohne Werte des Belegs: berechnete Teile stehen als „…“ |
| `rules[].checked`, `rules[].failed` | an wie vielen Knoten die Regel ausgewertet wurde und wie oft sie dabei anschlug |
| `rules[].reason` | nur bei `not-checked`: `stopped`, `not-included`, `disabled` oder `error` |

⚠️ **Eine Zeile je Regel und Regelwerk, nicht je Fundstelle und nicht je Bedingung.** Trägt ein Regelwerk eine
Kennung auf mehreren Bedingungen ( bei CIUS-RS 46 Kennungen auf 67 Bedingungen ), ist das eine Zeile:
`failed`, wenn eine davon anschlug, `passed`, wenn eine ausgewertet wurde und keine anschlug. Dieselbe
Kennung in zwei Regelwerken bleibt zwei Zeilen, denn beide Regelwerke wurden angewandt.

⚠️ **Die Regelzeilen beschreiben die amtlichen Regelwerke, so wie sie ausgewertet wurden.** Hat ein Regelprofil
eine amtliche Regel abgeschaltet, wird sie trotzdem ausgewertet ( siehe `profiles` ) und steht hier mit ihrem
echten Ergebnis; die Befundliste enthält sie nicht. Die eigenen Regeln eines Regelprofils stehen als Regelwerk
`profile:<name>@<fassung>` darunter.

⚠️ **Der Schalter ändert keinen Befund und kein Urteil.** Er zählt nur mit. Getestet in PHP
( `RuleReportTest` ) und in JS ( `test/rule-report.test.mjs` ): der Bericht mit Schalter ist ohne das Feld
`ruleReport` gleich dem Bericht ohne Schalter.

## Ergebnis der Mehrfachprüfung (`Cascade\CascadeResult`, seit 09.10.2026)

`Cascade::validateFile()` und `validateString()` in VAMPIRE PHP und `Cascade.validate()` in VAMPIRE JS liefern
dasselbe Feld. Es umschliesst die JSON-Berichte der einzelnen Fassungen und ersetzt sie nicht.

| Feld | Inhalt |
|---|---|
| `cascadeVersion` | `1`, die Fassung der Kaskadentabelle und dieses Feldes |
| `mode` | `cascade`, wenn die Prüfung bei der ersten annehmenden Fassung anhält, `matrix`, wenn alle Fassungen liefen |
| `family` | `{ id, title, order }` der Formatfamilie, etwa `zugferd` mit `newest-first` oder `xrechnung-3.0` mit `validity`; `null`, wenn es für das Format keine Fassungen gibt |
| `issueDate` | das Rechnungsdatum BT-2 als `JJJJ-MM-TT`, gelesen wie bei der Szenarienwahl, oder `null` |
| `chosen` | `{ version, scenarioId, reasonCode, reason, validVersion }`: die gewählte Fassung, der Grund als Kennung und als Satz in der Sprache der Prüfung, und bei XRechnung die Fassung, die das Datum bestimmt hat |
| `accepted` | das Urteil der gewählten Fassung, `null` ohne Urteil |
| `steps` | eine Zeile je Fassung, neueste zuerst: `version`, `ran`, `scenario`, `scenarioId`, `accepted`, `verdict` ( `accepted`, `warnings`, `rejected`, `no-verdict`, `not-run` ), `schemaFailed`, `counts`, `rules` ( `fatal` und `warning` als sortierte Schlüssel ), `differs` ( `fired` und `notFired` gegenüber den anderen Fassungen mit Urteil ), `durationMs` und `report` |
| `report` | der JSON-Bericht der gewählten Fassung |

Die Gründe sind `first-accepting`, `none-accepting`, `validity`, `validity-not-carried`, `outside-validity`,
`no-date`, `no-family`, `no-carried` und `not-wellformed`.

⚠️ **Ein Schlüssel in `rules` ist die Regelkennung, und nur ohne Kennung etwas anderes.** Ein Schemabefund heisst
immer `XSD`, weil die Zahl der Schemameldungen vom Prüfwerkzeug abhängt. Eine Regel ohne Kennung, wie es sie in den
Fassungen 2.1 bis 2.3.3 oft gibt, heisst nach ihrem Text in Anführungszeichen, gekürzt auf 100 Zeichen.

⚠️ **Verglichen werden PHP und JS ohne `durationMs` und ohne die eingebetteten Berichte.** Die Berichte vergleichen die
bestehenden Paritätsmessungen; ihre Fundstellen sind bewusst verschieden geschrieben.

## HTML (`Report\HtmlReport`)

Eine in sich geschlossene Seite, gruppiert nach Regelwerk und darin nach Einstufung, sperrende
Befunde zuerst. Das Stylesheet steht inline.

⚠️ **Keine externen Verweise, kein Skript.** Ein Bericht wird verschickt und gedruckt; einer, der
einen Webserver braucht, um richtig auszusehen, ist außerhalb dieses Rechners wertlos. Alle Werte aus
Beleg und Regeltext laufen durch `htmlspecialchars()` — Regeltexte enthalten Anführungszeichen und
spitze Klammern, Belegdaten sind Fremdeingabe.

Seit dem 09.10.2026 trägt die Seite mit eingeschaltetem positiven Prüfbericht am Ende den Abschnitt
„Geprüfte Regeln“ ( P2.5 Teil B ): den Satz „technischer Nachweis, kein Testat“, Prüfsumme, Zeitpunkt,
Engine, die Zählung je Ergebnis, die Prüfschritte, die Regelwerke und eine Tabellenzeile je Regel. Ohne
Schalter ist die Seite Byte für Byte die alte ( `RuleReportTest` ). Die Ansichten in Plugin und Apps zeichnen
denselben Abschnitt dreisprachig mit `RuleReportSection`; Einzelheiten in
`positiver-pruefbericht.md`, Teil 3.

## KoSIT-XML (`Report\KositXmlReport`)

Die Form, die der KoSIT-Validator ausgibt (`rep:report`, VARL 1.0.0) — und die der vorhandene
Validierungsbericht-Erklärer bereits liest.

```xml
<rep:report varlVersion="1.0.0" valid="false">
  <rep:engine><rep:name>invoicepress/vampire-php 0.1.0</rep:name></rep:engine>
  <rep:timestamp>2026-09-21T…</rep:timestamp>
  <rep:documentIdentification>
    <rep:documentHash><rep:hashAlgorithm>SHA-256</rep:hashAlgorithm><rep:hashValue>…</rep:hashValue></rep:documentHash>
    <rep:documentReference>rechnung.xml</rep:documentReference>
  </rep:documentIdentification>
  <rep:scenarioMatched>
    <s:scenario><s:name>EN16931 XRechnung (UBL Invoice)</s:name></s:scenario>
    <rep:validationStepResult id="val-xsd" valid="true"/>
    <rep:validationStepResult id="val-sch.1" valid="false">
      <s:resource><s:name>EN16931-UBL@1.3.16</s:name></s:resource>
      <rep:message id="val-sch.1.1" level="error" xpathLocation="/ubl:Invoice" code="BR-02">…</rep:message>
    </rep:validationStepResult>
  </rep:scenarioMatched>
  <rep:assessment><rep:reject><rep:explanation>…</rep:explanation></rep:reject></rep:assessment>
</rep:report>
```

⚠️ **`rep:engine/rep:name` nennt diese Bibliothek, nicht „KoSIT Validator 1.6.2".** Das Format ist
geborgt, das Urteil ist unseres; ein Bericht, der sich als Ausgabe der Referenzimplementierung
ausgibt, wäre eine Behauptung, die niemand beim Lesen überprüfen kann.

⚠️ **`level` benutzt KoSITs Wortschatz:** unser `fatal` heißt dort `error`. Die Zuordnung steht an
genau einer Stelle im Code, weil sie beim Lesen gegen echte KoSIT-Berichte verglichen wird.

⚠️ **`level` ist die Einstufung des Regelwerks, nicht die wirksame.** Das sieht im Bericht nach einem
Widerspruch aus — ein CVD-Beleg zeigt `BR-CL-13` als `error` und wird trotzdem angenommen — und ist
genau das, was der Referenzvalidator tut: die `customLevel`-Angabe eines Szenarios entscheidet **nur**
über das Urteil, die Meldung behält ihre eigene Einstufung. Gemessen an 208 echten KoSIT-Berichten
(Aufgabe 4.6). Die wirksame Einstufung steht im JSON-Bericht als `severity`.

⚠️ **Jedes gelaufene Regelwerk bekommt einen eigenen `validationStepResult`, auch ein befundfreies.**
Ein fehlender Schritt liest sich als „nicht geprüft", und das ist eine andere Aussage als „geprüft,
nichts gefunden".

⚠️ **Hier bleibt es bei einer Meldung je Prüfschritt** — anders als im JSON-Bericht. Ein Befund, der im
JSON zu **einem** Eintrag mit mehreren Herkünften zusammengefasst wird (Aufgabe 5.8a), erscheint im
KoSIT-XML in **jedem** seiner Schritte. Das ist kein Widerspruch, sondern der Sinn des Formats: seine
Struktur ist „ein Schritt, seine Meldungen", und der vorhandene Erklärer rechnet genau damit. Wer
wissen will, ob zwei Meldungen dieselbe Regel sind, liest den JSON-Bericht.

## Was in keinem Bericht steht

Der Belegtext selbst. Der Bericht nennt Fundstellen als XPath, nicht die Werte, die dort stehen —
außer dort, wo ein Regeltext sie ausdrücklich einsetzt. Ein Prüfbericht wandert weiter als die
Rechnung, aus der er stammt.

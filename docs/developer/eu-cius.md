# EU-CIUS: Länder-Ausprägungen von EN 16931 (Aufgabe 8.2)

Stand: 21.09.2026 · Quelle: phive-rules · Regelpakete unter `rulesets/`, Szenarien aus
`vendor-sources/eu-cius/scenarios.xml`

## Was jetzt da ist

| Regelwerk | Version | Regeln (Assertions) | Szenario |
|---|---|---:|---|
| `CIUS-RO-UBL` | 1.0.9 | 1098 | CIUS-RO eFactura (UBL Invoice) |
| `CIUS-PT-UBL` | 2.1.1 | 1155 | CIUS-PT (UBL Invoice) |
| `UBLBE-UBL` | 1.31 | 1050 | UBL.BE (UBL Invoice) |
| `CIUS-RS-UBL` | 1.0.0 | 67 | CIUS-RS srbdt (UBL Invoice) |
| `AGID-EN16931-UBL` | 3.0.2 | 1064 | Peppol Italia AGID (UBL Invoice) |
| `CIUS-HR-UBL` | 1.0.3 | 73 | **keines** — siehe unten |
| `UBL-TR` | 1.2.1 | 474 | **keines** — siehe unten |

Alle sieben ließen sich **ohne eine einzige nicht unterstützte XPath-Konstruktion** importieren. Vier
Funktionen fehlten und wurden ergänzt: `fn:data`, `fn:index-of`, `u:ctrlOIB` und `u:check3010`.

⚠️ **Die Reihenfolge der Szenarien ist nicht beliebig.** Die italienische AGID-Kennung **beginnt** mit
der Peppol-Billing-Kennung, und das Peppol-Szenario prüft mit `starts-with()`. Stünde Peppol vorn,
liefe eine italienische Rechnung nie gegen die AGID-Regeln — ohne dass irgendetwas auffiele. Die
EU-CIUS-Datei wird deshalb **vor** der Peppol-Konfiguration eingelesen
(`devkit/Cli/import-scenarios.php`).

⚠️ **Jede `match`-Bedingung ist an den Testbelegen gemessen**, nicht aus der Spezifikation geraten.
Zwei Messungen haben dabei die Bedingung selbst verändert:

- **Belgien schreibt die Kennung in zwei Schreibweisen:** 328 Belege mit `#conformant#urn:UBL.BE:…`,
  26 mit `#Conformant#` — großes C. Ein `starts-with()` auf die vollständige Kennung hätte 26 echte
  Belege stillschweigend durchfallen lassen. Die Bedingung prüft deshalb nur auf `urn:UBL.BE:`.
- **Rumänien führt zwei Fassungen** (`:CIUS-RO:1.0.0` und `:CIUS-RO:1.0.1`, je 22 Belege), Portugal
  hängt an seine Kennung einen Punkt (`:CIUS-PT:1.0.0.`). Beide Bedingungen enden deshalb vor der
  Versionsnummer.

## Was gemessen wurde

93 Belege aus den phive-Modulen, gefiltert auf Rechnungen der jeweiligen CIUS (die Module liefern
auch Bestellungen, Lieferavise und Antwortdokumente mit — andere Dokumentarten, kein Rechnungsfall):

| Gruppe | Belege | angenommen | abgelehnt | Urteil = KoSIT |
|---|---:|---:|---:|---|
| `phive-cius-rs` | 4 | **4** | 0 | ✔ 4/4 |
| `phive-agid-it` | 33 | **23** | 10 | ✔ 33/33 |
| `phive-ublbe` | 36 | 3 | 33 | ✔ 36/36 |
| `phive-cius-ro` | 11 | 0 | 11 | ✔ 11/11 |
| `phive-cius-pt` | 9 | 0 | 9 | ✔ 9/9 |

**Alle 93 werden dem richtigen Szenario zugeordnet** (`EuCiusScenarioTest`) — **und für alle 93 urteilt
der KoSIT-Referenzvalidator genauso wie wir.**

## Der Vergleichslauf deckt diese 93 mit ab

⚠️ **Das war zunächst anders gedacht, und der Gedanke war falsch.** Ein erster Stand dieser Datei
sagte, für die abgelehnten Belege lasse sich keine Erwartung schreiben, weil „wir das richtige Urteil
nicht kennen". Das ist genau die Frage, die der Vergleichslauf aus Aufgabe 4.4/4.6 beantwortet: Beleg
durch beide Programme schicken und die Ergebnisse vergleichen. **Ob angenommen oder abgelehnt, ist
dabei gleichgültig — gleich sein müssen sie.**

Dafür wurde aus `vendor-sources/eu-cius/` ein echtes KoSIT-Repository gebaut (`resources/{ro,pt,be,rs,it}`,
die UBL-2.1-Schemata, der EN-16931-Kern **in unserer Fassung 1.3.16** und die Report-Stylesheets), damit
beide Seiten dasselbe Regelmaterial fahren und jede Differenz eine echte wäre.

⚠️ **Die EU-CIUS-Belege laufen dabei gegen diese Konfiguration allein.** Gäbe man dem Validator
gleichzeitig die Peppol-Konfiguration mit, träfen auf eine italienische Rechnung **zwei** Szenerien zu
— und der KoSIT-Validator löst das nicht als „erste gewinnt" auf, sondern meldet
`noScenarioMatched` und lehnt ab, ohne einen Hinweis im Log. Die Differenz wäre dann ein Artefakt des
Aufrufs, nicht des Programms.

**Ergebnis: 312 Belege verglichen, 0 Abweichungen beim Urteil, 0 beim Szenario.** Die 63 Ablehnungen
sind also keine Unsicherheit, sondern ein bestätigtes Ergebnis: diese Belege verletzen die Regeln
wirklich, und die Referenzimplementierung sagt dasselbe.

Eine Erwartungsdatei bekommen trotzdem nur die angenommenen — für die abgelehnten wäre sie eine
schwächere Wiederholung dessen, was der goldene Bericht ohnehin festnagelt (Szenario, Regelmenge **und**
Urteil).

## Warum so viele abgelehnt werden

Die Gründe, je gemessen:

**Rumänien (11 von 11):** `BR-RO-001` und `BR-RO-010` — rumänische Regeln aus **1.0.9**, während die
Belege sich selbst als CIUS-RO **1.0.0/1.0.1** ausweisen. phive paart in seinen eigenen Testläufen
jede Regelversion mit den Belegen derselben Version; wir fahren die neueste Regelfassung gegen alle.

**Portugal (9 von 9) und Belgien (33 von 36):** `BR-CL-10`, `BR-CL-11`, `BR-CL-25`, `BR-CL-22` … —
das sind **Codelisten-Regeln des EN-16931-Kerns**, nicht Landesregeln. Die Belege stammen aus der
Zeit vor EN16931 1.3.16, gegen die wir sie prüfen; seither sind Codelisten gewachsen und Regeln
verschärft worden.

**Italien (10 von 33):** ausschließlich `PEPPOL-EN16931-CL008`. Gemessen, nicht vermutet: diese zehn
Belege tragen `<cbc:EndpointID schemeID="0205">`, und die EAS-Codeliste im Regelwerk
`PEPPOL-EN16931-UBL.xslt` (Peppol 3.0.21) enthält `0205` **nicht** — nachgelesen in der
`$eaid`-Variablen der Datei selbst. Die übrigen 23 laufen durch, einschließlich der AGID-Regeln.

⚠️ **Die Lehre ist dieselbe wie in Aufgabe 0.2b, eine Ebene tiefer:** Testbelege gehören zu einer
Regelversion. Dort hat es gereicht, je Modul **eine** Version zu wählen; hier müsste man je Modul
**Kern- und Landesregelwerk gemeinsam** auf den Stand der Belege ziehen, wenn man sie als „müssen
angenommen werden" führen will.

Für den Nachweis, dass diese Bibliothek richtig prüft, ist das aber **nicht** nötig: der
Vergleichslauf zeigt bei jedem einzelnen dieser Belege dasselbe Urteil und dieselbe Regelmenge wie der
Referenzvalidator. Die Versionspaarung würde die Belege von „gemeinsam abgelehnt" zu „gemeinsam
angenommen" bringen — beides ist gleich viel wert als Nachweis.

## Was dieser Vergleich aufgedeckt hat

⚠️ **`fn:matches()` war falsch implementiert — anchored statt unanchored.** Die rumänische Regel
`BR-RO-010` lautet `matches(normalize-space(cbc:ID), '([0-9])')`: „die Rechnungsnummer muss eine
Ziffer **enthalten**". Unsere Umsetzung verankerte jedes Muster (`^(?:…)$`) und verlangte damit eine
Rechnungsnummer, die aus **genau einer Ziffer besteht**. Die Regel feuerte auf allen elf rumänischen
Belegen; der Referenzvalidator meldete sie auf keinem einzigen.

Der Grund für den Irrtum steht auch im alten Kommentar: XSDs `xs:pattern`-**Facette** ist implizit
verankert — `fn:matches()` ist es nicht. XPath 2.0 F&O 7.6.2 sagt es mit eigenen Beispielen:
`fn:matches("abracadabra", "bra")` ist **wahr**.

⚠️ **Warum es über 200 Vergleichsbelege lang unsichtbar blieb:** fast jedes Muster im gesamten
Regelbestand ist mit `^…$` geschrieben, und ein zweites Mal zu verankern ändert daran nichts. Die
rumänische CIUS ist das erste Regelwerk hier mit einem unverankerten Muster. Ohne diesen
Vergleichslauf wäre der Fehler nicht aufgefallen — und er betrifft jede Regel dieser Bauart, in jedem
Land.

## Was bewusst kein Szenario hat

- **HR eRacun** (`CIUS-HR-UBL@1.0.3`): Das Modul bringt **null** Testdateien mit (gemessen). Ohne
  einen echten Beleg lässt sich die Kennung nicht messen — und geraten wird sie nicht. Das Regelwerk
  ist importiert und per Prüfprofil zuschaltbar; seine beiden eigenen Funktionen `u:ctrlOIB` und
  `u:check3010` sind umgesetzt und getestet.
- **UBL-TR** (`UBL-TR@1.2.1`): trägt als `CustomizationID` nur `TR1.2` bzw. `TR1.2.1` und ist **kein**
  EN-16931-Format. Es bräuchte ein eigenes XSD; das gehört in eine eigene Aufgabe.
  ⚠️ In `docs/coverage.md` steht dieses Regelwerk mit **0 Regeln** — nicht, weil es leer wäre (474
  Assertions), sondern weil **keine einzige davon eine `id` trägt**. Die Quelle vergibt keine
  Regel-IDs; der Suite-Regelkatalog hat dafür synthetische erfunden, wir führen sie wie überall mit
  `id: null`. Die Abdeckungsmessung zählt IDs, also zählt sie hier nichts.
- **EHF (Norwegen):** 121 XSLT über viele Dokumentarten und mehrere Namenskonventionen. Schon der
  Regelkatalog der Suite hat das Modul aus genau diesem Grund zurückgestellt.
- **Frankreich (Flux 2, CTC):** die Regeldateien liegen in vier Fassungen nebeneinander
  (0.1 bis 1.3.1) und betreffen teils Dokumentarten außerhalb der Rechnung. Eine saubere
  Versionswahl steht noch aus.

## Ein Fehler, den dieser Import aufgedeckt hat

⚠️ **PCRE kann `{n,m}` nur bis 65535.** CIUS-PT 2.1.1 enthält das Muster `^(.{1,6826666})$` — knapp
sieben Millionen Zeichen. `preg_match()` scheiterte daran mit „number too big in {} quantifier",
gab `false` zurück, und `fn:matches()` meldete daraufhin für **jedes** Dokument „passt nicht". Eine
Regel, die „der Wert entspricht diesem Muster" behauptet, feuert dann auf einwandfreien Rechnungen,
und im Bericht steht nichts von einem kaputten Regulärausdruck.

Behoben in `FunctionLibrary::relaxHugeQuantifiers()`: die obere Grenze wandert aus dem Muster heraus
und wird als Längenprüfung angewendet — was ein solches Muster ohnehin bedeutet. Mit Test.

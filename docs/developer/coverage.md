# Abdeckungsübersicht (Aufgabe 3.5)

Kreuzprobe je Regelwerk: die eigene `SchematronXsltImporter`-Extraktion gegen eine UNABHÄNGIGE Extraktion derselben XSLT-Dateien mit einem eigenen Werkzeug - Übereinstimmung beider ist ein echter Beleg für Vollständigkeit, keine Selbstauskunft.

## EN16931-UBL@1.3.16

- Eigene Extraktion: 979 Assertions, 979 distinkte Regel-IDs
- Kreuzprobe (en16931-ubl.json): 979 Assertions, 979 distinkte Regel-IDs
- **✅ Multimengen der Regel-IDs identisch**

## EN16931-CII@1.3.16

- Eigene Extraktion: 806 Assertions, 806 distinkte Regel-IDs
- Kreuzprobe (en16931-cii.json): 806 Assertions, 806 distinkte Regel-IDs
- **✅ Multimengen der Regel-IDs identisch**

## XRechnung-UBL@3.0.2

- Eigene Extraktion: 76 Assertions, 76 distinkte Regel-IDs
- Kreuzprobe (xrechnung-ubl.json): 76 Assertions, 76 distinkte Regel-IDs
- **✅ Multimengen der Regel-IDs identisch**

## XRechnung-CII@3.0.2

- Eigene Extraktion: 71 Assertions, 71 distinkte Regel-IDs
- Kreuzprobe (xrechnung-cii.json): 71 Assertions, 71 distinkte Regel-IDs
- **✅ Multimengen der Regel-IDs identisch**

**Ergebnis: alle vier Regelwerke — jede Regel der Kreuzprobe ist importiert, keine zusätzliche erfunden.**

## Testabdeckung (Aufgabe 4.5)

Gemessen über 230 Testdateien: offizieller XRechnung-Testbestand, die Peppol- und ZUGFeRD-Beispiele, der phive-rules-Testbestand aus Aufgabe 0.2b und die erzeugten Negativfälle aus Aufgabe 4.2. Gezählt wird, welche Regel-IDs beim Validieren mindestens einmal ausgelöst haben.

⚠️ Die 92 Belege aus 0.2b haben die Quote **kaum** bewegt, und das ist kein Mangel des Imports: es sind durchweg **gültige** Belege, und ein gültiger Beleg löst fast keine Regel aus. Ihr Wert liegt woanders — sie fangen Rückschritte, wenn eine Änderung anfängt, korrekte Rechnungen abzulehnen. Die Quote hebt nur ein größerer Negativbestand.

| Regelwerk | Regeln | mindestens einmal ausgelöst | Anteil |
|---|---:|---:|---:|
| `CEN-EN16931-UBL@3.0.21` | 979 | 0 | 0.0 % |
| `CIUS-HR-UBL@1.0.4` | 62 | 0 | 0.0 % |
| `CIUS-RO-UBL@1.0.9` | 1098 | 11 | 1.0 % |
| `EN16931-CII@1.3.16` | 806 | 13 | 1.6 % |
| `EN16931-UBL@1.3.16` | 979 | 17 | 1.7 % |
| `FACTURX-BASIC-CII@1.09.2` | 385 | 0 | 0.0 % |
| `FACTURX-BASICWL-CII@1.09.2` | 278 | 0 | 0.0 % |
| `FACTURX-EN16931-CII@1.09.2` | 433 | 0 | 0.0 % |
| `FACTURX-EXTENDED-CII@1.09.2` | 943 | 1 | 0.1 % |
| `FACTURX-MINIMUM-CII@1.09.2` | 55 | 0 | 0.0 % |
| `PEPPOL-EN16931-UBL@3.0.21` | 165 | 4 | 2.4 % |
| `XRechnung-CII@3.0.2` | 71 | 3 | 4.2 % |
| `XRechnung-UBL@3.0.2` | 76 | 3 | 3.9 % |

⚠️ Eine Regel, die nie ausgelöst hat, ist nicht zwangsläufig ungetestet — die meisten Regeln treffen auf diese Belege schlicht nicht zu. Das Akzeptanzkriterium der Phase 4 (jede Regel der XRechnung-Konfiguration wird von mindestens einem Testfall ausgelöst **und** von mindestens einem nicht) ist damit **nicht** erfüllt; dafür braucht es einen deutlich größeren Negativbestand als die heutigen 16 Mutationen. Die Zahlen oben sagen, wie weit es bis dahin ist.

Erzeugt von `devkit/Cli/coverage-tests.php`; `CoverageDocTest` rechnet sie nach.

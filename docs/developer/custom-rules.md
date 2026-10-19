# Eigene Regeln (Aufgaben 5.5 und 5.5a)

Eigene Regeln werden als JSON geschrieben, mit `devkit/Cli/compile-custom-rules.php` in ein normales
Regelpaket übersetzt und über ein Prüfprofil zugeschaltet. Danach laufen sie in derselben Engine wie
die amtlichen Regeln — es gibt keinen zweiten Ausführungsweg.

## Schreiben

```json
{
  "id": "haus-regeln",
  "version": "1.0",
  "syntax": "ubl",
  "title": "Hausregeln, Stand 2026",
  "rules": [
    {
      "id": "HAUS-01",
      "flag": "warning",
      "contextBt": "BT-1",
      "test": "string-length(normalize-space(.)) >= 12",
      "message": "Die Rechnungsnummer soll mindestens zwölf Zeichen haben.",
      "fields": ["BT-1"]
    }
  ]
}
```

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `id`, `version` | ja | ergeben den Paketnamen `custom-<id>@<version>` |
| `syntax` | ja | `ubl` oder `cii` — entscheidet, wie `contextBt` aufgelöst wird |
| `rules[].id` | ja | erscheint im Bericht |
| `rules[].contextBt` | entweder … | BT-/BG-Verweis, wird über die Bindungstabelle zum Pfad |
| `rules[].context` | … oder | Pfad direkt, wenn kein BT passt |
| `rules[].test` | ja | XPath; wahr heißt „Regel erfüllt" (bei `kind: "report"` umgekehrt) |
| `rules[].message` | ja | Klartext, wird mit `[REGEL-ID]` vorangestellt ausgegeben |
| `rules[].flag` | nein | `fatal` (Vorgabe), `warning`, `information` |
| `rules[].fields` | nein | betroffene BT/BG, erscheinen am Befund |

## Übersetzen

```
php devkit/Cli/build-field-bindings.php --apply     # einmalig: Bindungstabelle erzeugen
php devkit/Cli/compile-custom-rules.php haus-regeln.json --apply
```

Das Ergebnis ist `custom-haus-regeln@1.0.json` — dasselbe Format wie die importierten amtlichen
Pakete.

**Wohin die Datei gehört.** Nicht nach `rulesets/`: dort liegen die ausgelieferten amtlichen Pakete,
und ein eigenes dazwischen würde bei jedem Update der Bibliothek überschrieben oder mitgezählt. Eigene
Regelpakete liegen in einem eigenen Verzeichnis, das dem `Validator` mitgegeben wird:

```php
$validator = new Validator(
    scenarioResolver: $resolver,
    baseDir:          __DIR__,
    profile:          $profile,
    rulesetDirs:      [ __DIR__ . '/haus-regeln' ],   // wird vor rulesets/ durchsucht
);
```

Das mitgelieferte Beispiel liegt aus genau diesem Grund unter
`tests/fixtures/rulesets/custom-haus-regeln@1.0.json`.

⚠️ **Übersetzt wird im devkit, nicht zur Laufzeit.** `src/` darf `devkit/` nicht laden, und der
XPath-Parser liegt im devkit. Eigene Regeln gehen deshalb denselben Weg wie die amtlichen: als Text
geschrieben, zu einem AST übersetzt, als JSON ausgeliefert.

## Zuschalten

```json
{
  "id": "haus-profil",
  "include": [ { "ruleset": "custom-haus-regeln@1.0", "rules": ["HAUS-01"] } ]
}
```

## Herkunft im Bericht

Jeder Befund trägt sein Regelwerk. Bei eigenen Regeln beginnt es mit `custom-`, sodass im Bericht
immer erkennbar bleibt, was aus der Norm stammt und was aus dem eigenen Haus.

## Die Bindungstabelle

`rulesets/field-bindings.json` bildet 311 BT/BG-Verweise auf UBL- und CII-Pfade ab, erzeugt aus dem
Feldkatalog der eInvoice-Suite.

⚠️ **Nicht jeder BT existiert in beiden Syntaxen.** Wo ein UBL-Gegenstück fehlt, steht in der Quelle
Prosa statt eines Pfades; solche Einträge werden beim Bau verworfen, nicht durchgereicht. Eine Regel,
die einen in der gewählten Syntax unbekannten BT als Kontext nennt, wird beim Übersetzen mit Angabe
des BT abgelehnt — sie würde sonst auf nichts passen und nie auffallen.

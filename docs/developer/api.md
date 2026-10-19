# Öffentliche API (Aufgabe 7.5)

Alles unter `src/` mit `public`-Sichtbarkeit, das hier aufgeführt ist, gilt als öffentlich. Alles
andere — insbesondere `src/Xpath/**`, `src/Engine/**` und jede Klasse unter `devkit/` — ist Interna
und darf sich jederzeit ändern.

## Einstiegspunkt

```php
use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Validator;

$validator = new Validator(
    scenarioResolver: ScenarioResolver::fromJsonFile(__DIR__ . '/rulesets/scenarios.json'),
    baseDir:          __DIR__,
);

$result = $validator->validateFile('rechnung.xml');
```

### `Validator::__construct()`

Alle Parameter sind benannt und haben Vorgaben:

| Parameter | Zweck |
|---|---|
| `xmlLoader` | Ladegrenzen und Sicherheitseinstellungen (`XmlLoader`) |
| `xsdStage` | feste XSD-Prüfung, wenn kein Szenario entscheidet |
| `scenarioResolver` | wählt XSD und Regelwerke anhand des Belegs |
| `baseDir` | Wurzel, gegen die `rulesets/…`-Pfade aufgelöst werden |
| `profile` | Prüfprofil (Phase 5) |
| `forceScenarioId` | erzwingt ein Szenario, schlägt die automatische Wahl |
| `rulesetDirs` | zusätzliche Verzeichnisse für eigene Regelpakete, werden **vor** `rulesets/` durchsucht |

⚠️ **`rulesetDirs` ist der Platz für eigene Regelpakete** — nicht das ausgelieferte `rulesets/`, das
bei einem Update dieser Bibliothek überschrieben wird. Findet sich ein Regelpaket nirgends, gibt es
eine Ausnahme, die jeden durchsuchten Pfad nennt; einen stillen Rückfall gibt es nicht.

⚠️ **Ohne `scenarioResolver` laufen nur Wohlgeformtheit und, falls `xsdStage` gesetzt ist, XSD.**
Es gibt keinen stillen Rückfall auf „irgendein Regelwerk": welche Regeln gelten, entscheidet das
Szenario, und wenn keines da ist, wird eben nicht fachlich geprüft.

### `validateFile()`, `validateString()`, `validateDom()`

Liefern jeweils ein `Result`. Ein nicht wohlgeformter Beleg wirft nicht, sondern ergibt ein `Result`
mit fehlgeschlagener Wohlgeformtheitsstufe und einer Meldung mit Zeilennummer.

## `Result`

| Methode | Rückgabe |
|---|---|
| `isAccepted()` | `bool` — wahr, wenn kein Befund sperrt und keine Stufe fehlschlug |
| `messages()` | `Message[]` in Auffindungsreihenfolge |
| `messagesForStage(Stage)` | dieselben, auf eine Stufe gefiltert |
| `stageStatus(Stage)` | `StageStatus` |
| `scenarioName()` | Name des aufgelösten Szenarios oder `null` |
| `rulesets()` | gelaufene Regelwerke in Reihenfolge |
| `documentReference()` | Pfad/Name des Belegs, falls bekannt |

`Message` hat öffentliche, unveränderliche Eigenschaften: `stage`, `ruleId`, `flag`, `text`,
`location`, `line`, `ruleset`, `bt`, `rulesets` — und zwei Methoden, `origins()` und `withOrigin()`.

⚠️ **`ruleset` ist die *erste* Herkunft, `origins()` sind *alle*.** Ein Befund kann aus mehreren
Regelwerken zugleich stammen, weil dieselbe Regel in mehreren steht (Aufgabe 5.8a). Wer `ruleset`
liest, bekommt weiterhin eine sinnvolle Antwort; wer vollständig sein muss, nimmt `origins()`.

⚠️ **`flag` ist die wirksame Einstufung, `nativeFlag()` die des Regelwerks.** Stuft ein Szenario oder
ein Profil eine Regel herab, gehen die beiden auseinander: `flag` entscheidet über das Urteil,
`nativeFlag()` sagt, was das Regelwerk selbst vorsieht. Der KoSIT-förmige Bericht schreibt
`nativeFlag()`, weil das Original es so macht (Aufgabe 4.6).

⚠️ **`Message` hat keine Getter.** Wer `$message->text()` schreibt, bekommt einen Fehler — die
Eigenschaften sind `public readonly`.

## Berichte

```php
use EinvoiceValidator\Report\{JsonReport, HtmlReport, KositXmlReport};

echo (new JsonReport())->toJson($result);
echo (new HtmlReport())->render($result);
echo (new KositXmlReport())->render($result, file_get_contents('rechnung.xml'));
```

Formate und Stabilitätszusagen: `docs/developer/report-formats.md`.

## Prüfprofile

```php
use EinvoiceValidator\Profile\ProfileLoader;

$profile = (new ProfileLoader(__DIR__ . '/profiles'))->load('haus-profil');
```

Format: `docs/developer/profile-format.md`. Eigene Regeln: `docs/developer/custom-rules.md`.

## Regelkatalog

```php
use EinvoiceValidator\Ruleset\RuleCatalog;

$catalog = RuleCatalog::fromFiles(glob(__DIR__ . '/rulesets/*@*.json'));
$catalog->byId('BR-02');              // Liste, nicht ein Eintrag
$catalog->byField('BT-1');
$catalog->byDuplicateKey($key);       // dieselbe Regel, wie auch immer sie heißt
$catalog->rulesetsOf('UBL-CR-400');   // in welchen Regelwerken sie vorkommt
```

⚠️ **`byId()` liefert eine Liste.** Dieselbe Regel-ID gibt es in mehreren Regelwerken — BR-02 fünfmal.

⚠️ **`byId()` findet nur diese eine Schreibweise.** Dieselbe Regel heißt in UBL.BE `ubl-CR-400` statt
`UBL-CR-400`; zusammen gehören beide erst über den `duplicateKey`. Für eine Anzeige „Regel einmal,
Herkünfte darunter" ist `byDuplicateKey()` bzw. `rulesetsOf()` das richtige Werkzeug (Aufgabe 5.8d).
`rulesetsOf()` fällt auf die ID zurück, wenn eine Regel keinen Schlüssel hat.

## Kommandozeile

```
vampire-php validate <datei> [--format=text|json|html|xml] [--profile=<id>] [--scenario=<id>]
vampire-php profile:show <id>
vampire-php ruleset:list
vampire-php export:schematron <profil-id> <verzeichnis>
vampire-php selftest
```

Rückgabewerte: **0** angenommen, **1** abgelehnt, **2** Aufruffehler. Das ist der maschinenlesbare
Teil — ein Skript muss „ungültige Rechnung" von „falsch aufgerufen" unterscheiden können, ohne Text
zu lesen.

## Ausnahmen

| Klasse | Wann |
|---|---|
| `NotWellformedException` | XML nicht wohlgeformt (wird intern zu einem `Result`) |
| `DocumentTooLargeException` | Beleg über der eingestellten Grenze |
| `UnsupportedConstructException` | unbekanntes XPath-Konstrukt in einem Regelwerk |
| `ImportException` | fehlerhaftes Regelpaket, Profil oder Szenario |
| `RuntimeException` | unbekanntes vorgegebenes Szenario, unpassende Syntax beim Zuschalten |

⚠️ Keine dieser Ausnahmen bedeutet „Rechnung ungültig". Ein ungültiger Beleg ist immer ein `Result`,
nie eine Ausnahme.

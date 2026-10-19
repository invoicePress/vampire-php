# Testvektoren — dasselbe prüfen, in zwei Sprachen

`tests/vectors/*.json` sind sprachneutrale Prüffälle für die XPath-Auswertung. Die PHP-Seite führt sie
über `tests/Unit/Xpath/VectorTest.php` aus; die JavaScript-Portierung führt
**dieselben Dateien** aus. Was beide bestehen, ist Parität; alles andere ist eine Behauptung.

**Stand 25.09.2026: 94 Vektoren, 523 Zusicherungen, PHP grün.** Die JS-Seite gibt es noch nicht.

| Datei | Vektoren | woraus abgeleitet |
|---|---:|---|
| `xpath-core.json` | 35 | `tests/Unit/Xpath/InterpreterTest.php` |
| `xpath-functions.json` | 41 | `tests/Unit/Xpath/Runtime/FunctionLibraryTest.php` |
| `xpath-comparisons.json` | 18 | `tests/Unit/Xpath/Runtime/ComparisonsTest.php` |

---

## Drei Regeln, die den Wert der Vektoren ausmachen

⚠️ **Abgeleitet, nicht danebengeschrieben.** Jeder Vektor stammt aus einer vorhandenen Zusicherung.
Frei erfundene Vektoren liessen beide Seiten über etwas übereinstimmen, das keiner der ursprünglichen
Tests je behauptet hat — und das hiesse dann Parität.

⚠️ **Die Erwartungen sind nicht aus einem Lauf aufgezeichnet.** Sie sind die Werte, die die
ursprünglichen Zusicherungen nennen. Eine erzeugte Vektordatei wäre per Konstruktion grün und bewiese
über keine der beiden Umsetzungen etwas.

⚠️ **Ausdrücke statt interner Aufrufe.** `ComparisonsTest` prüft `Comparisons::general()` mit
`Sequence`- und `AtomicValue`-Objekten; die JS-Seite wird diese Klassen nicht haben. Die Vektoren
stellen dieselbe Frage als XPath-Ausdruck ( `(1, 2) = (2, 3)`, `cac:V > xs:decimal(9)` ) — das ist die
Ebene, auf der beide Umsetzungen dasselbe können müssen.

---

## Das Format

```json
{
  "schemaVersion": 1,
  "id": "xpath-core",
  "title": "…",
  "source": "tests/Unit/Xpath/InterpreterTest.php",
  "namespaces": { "cac": "urn:cac", "cbc": "urn:cbc" },
  "vectors": [
    {
      "id": "predicate-positional",
      "xml": "<cac:Root …>…</cac:Root>",
      "contextPath": "cac:B/cac:C",
      "expr": "cac:Item[2]",
      "expect": { "count": 1, "text": "b" },
      "note": "⚠️ …"
    }
  ]
}
```

| Feld | |
|---|---|
| `namespaces` | gilt für die ganze Datei; der Interpreter wird damit gebaut |
| `xml` | das Dokument. Der Kontextknoten ist das Dokumentelement … |
| `contextPath` | … ausser hier steht ein Pfad; dann ist es dessen **erster** Treffer |
| `expr` | der auszuwertende Ausdruck |
| `expect` | **alle** angegebenen Prüfungen gelten, nicht nur eine |
| `note` | warum der Fall existiert — oft ein echter Fehler, der ihn erzwungen hat |

### `expect`

| Schlüssel | bedeutet |
|---|---|
| `count` | Länge der Ergebnissequenz |
| `empty` | Sequenz ist leer |
| `ebv` | effektiver boolescher Wert der **Sequenz** |
| `string` | `asString()` des ersten Elements |
| `boolean` | `asBoolean()` des ersten Elements — ⚠️ **nicht** dasselbe wie `ebv` |
| `text` | Textinhalt des ersten Elements, das ein **Knoten** sein muss |
| `strings` | `asString()` jedes Elements, in Reihenfolge |
| `error` | die Auswertung muss scheitern |

⚠️ **`boolean` und `ebv` sind mit Absicht getrennt.** `'false' cast as xs:boolean` ist `false`
( lexikalische Regel ), während der effektive boolesche Wert derselben einelementigen Sequenz `true`
wäre, weil eine nichtleere Zeichenkette wahr ist. Genau diese Verwechslung liess eine Regel auf einen
Knoten passen, dessen Text buchstäblich „false" lautete.

⚠️ **`contextPath` wird mit demselben Interpreter ausgewertet, der geprüft wird.** Das ist zirkulär und
bewusst so: eine zweite Auswahlmechanik im Format müsste die JS-Seite ebenfalls bauen. Die benutzten
Selektoren sind einfache Kindschritte, und ein fehlschlagender `contextPath` fällt sofort auf, weil
die Zusicherung darunter ins Leere greift.

---

## Der AST liegt daneben — und das ist der Punkt

`tests/vectors/ast/<id>.ast.json` trägt zu jedem Vektor seinen **kompilierten Ausdrucksbaum**,
erzeugt von `devkit/Cli/vector-ast.php`.

⚠️ **DIE JAVASCRIPT-PORTIERUNG BEKOMMT KEINEN PARSER, UND SIE BRAUCHT AUCH KEINEN.** `Parser` liegt in
`devkit/`, ausserhalb der ausgelieferten Laufzeit: die Regelpakete unter `rulesets/` tragen bereits
kompilierte ASTs, und die Laufzeit wertet **Bäume** aus, keinen Text. Eine Portierung, die mit dem
Parser anfinge, baute genau den Teil nach, der absichtlich nicht ausgeliefert wird. Ohne den Baum
neben dem Vektor könnte die JS-Seite keinen einzigen davon ausführen.

```
php devkit/Cli/vector-ast.php            prüft, schreibt nichts ( Rückgabewert 1 bei Abweichung )
php devkit/Cli/vector-ast.php --apply    schreibt
```

⚠️ **Der Baum ist abgeleitet, die Erwartungen sind es nicht.** Das Werkzeug erzeugt nur die
Übersetzung eines Ausdrucks in einen Baum; `expect` bleibt handgeschrieben.

⚠️ **Und er wird in eine eigene Datei geschrieben, nicht in die Vektoren.** Ein Werkzeug, das die
handgepflegten Dateien neu formatiert, begräbt die nächste echte Änderung im Rauschen.

`VectorTest::testStoredAstMatchesTheParser()` hält beide Seiten zusammen: der gespeicherte Baum muss
der sein, den der Parser liefert — verglichen als **Objekte**, nicht als JSON, denn zwei
Serialisierungen desselben Baums sind derselbe Baum.

⚠️ **Kein einziger der 94 Ausdrücke scheitert im Parser** — auch `unknownprefix:Foo` und
`no-such-function('x')` nicht. Beide sind Fehlerfälle, aber der Fehler entsteht erst im
Interpreter. Das Format kennt trotzdem einen `parseError`-Eintrag: die JS-Seite darf an einer
anderen Stelle scheitern, und dann muss sichtbar sein, an welcher.

---

## Was noch fehlt

Die Vektoren decken **Kern, Funktionsbibliothek und Vergleiche** ab — nicht die Dezimalarithmetik
( `DecimalTest`, eigene Semantik ohne XPath-Oberfläche ), nicht die Prüfsummenfunktionen
( `ChecksumFunctionsTest`, 30 Fälle über einen Datenprovider ) und nicht die Schematron-Ausführung.
Alle drei sind Kandidaten für eigene Dateien, sobald die JS-Seite so weit ist, dass sie mehr als den
Ausdruckskern braucht.

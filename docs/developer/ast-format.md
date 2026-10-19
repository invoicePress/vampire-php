# AST-Format (Aufgabe 2.1)

Version: `1`. Jeder AST-Knoten ist ein PHP-Objekt aus `EinvoiceValidator\Xpath\Ast\` (readonly),
JSON-serialisierbar (für Caching/Debugging, Aufgabe 2.5) über ein `"node"`-Diskriminatorfeld.

## Warum ein eigenes AST statt direkt auf DOMXPath abzubilden

`DOMXPath::evaluate()` versteht nur XPath 1.0. Unsere Regeln brauchen 2.0-Konstrukte
(`for`/`if`/`some`/`every`, `castable as`, `eq`/`ne`/..., `xs:decimal(...)`-Konstruktoren, eigene
`u:*`-Funktionen). `Interpreter` (Aufgabe 2.4) wertet den AST selbst aus und delegiert NUR reine,
XPath-1.0-kompatible Pfadschritte an `DOMXPath` (Optimierung, kein Korrektheits-Fallback).

## Knotentypen

Jeder Knoten trägt `pos` (0-basierter Zeichenoffset im Quellausdruck, für Fehlermeldungen mit
Fundstelle, Regel A.6).

| `node` | Felder | Bedeutung |
|---|---|---|
| `PathExpr` | `steps: Step[]`, `absolute: bool` | Eine Pfad-Schrittfolge, z. B. `cac:Foo/cbc:Bar` |
| `Step` | `axis: ?string`, `test: ?NodeTest`, `predicates: Expr[]`, `primary: ?Expr` | Ein einzelner Schritt - entweder ein Achsenschritt (`axis`+`test` gesetzt, `primary` null) oder ein beliebiger `PrimaryExpr` als Schritt (`primary` gesetzt, `axis`/`test` null) - **an JEDER Position im Pfad möglich, nicht nur der ersten** (echtes Beispiel aus dem Korpus: `cac:TaxScheme/normalize-space(upper-case(cbc:ID))='VAT'` hat einen Funktionsaufruf-Schritt an zweiter Stelle) |
| `NodeTest` | `kind: 'name'\|'wildcard'\|'kindtest'`, `name: ?QName`, `kindtestName: ?string` | `cac:Foo`, `*`, `node()` usw. |
| `BinaryOp` | `op: string`, `left: Expr`, `right: Expr` | `=`, `eq`, `and`, `or`, `+`, `\|`, `to`, `union`, `intersect`, `except`, `is` |
| `UnaryOp` | `op: '-'\|'+'`, `operand: Expr` | Vorzeichen |
| `FunctionCall` | `name: QName`, `args: Expr[]` | `normalize-space(...)`, `u:mod11(...)` |
| `VarRef` | `name: string` | `$x` |
| `Literal` | `kind: 'string'\|'integer'\|'decimal'`, `value: string` | Immer als String gespeichert - `Decimal`/Ganzzahl-Konvertierung erst zur Laufzeit (Rundungsfehler vermeiden) |
| `SequenceExpr` | `items: Expr[]` | `(a, b, c)` |
| `RangeExpr` | `from: Expr`, `to: Expr` | `1 to 5` |
| `IfExpr` | `cond: Expr`, `then: Expr`, `else: Expr` | `if (...) then ... else ...` |
| `ForExpr` | `var: string`, `in: Expr`, `return: Expr` | `for $x in ... return ...` (nur eine Bindung - keine Regel im Inventar bindet mehrere) |
| `QuantifiedExpr` | `quantifier: 'some'\|'every'`, `var: string`, `in: Expr`, `satisfies: Expr` | |
| `LetExpr` | `var: string`, `value: Expr`, `return: Expr` | |
| `CastableExpr` | `operand: Expr`, `type: QName`, `optional: bool` | `... castable as xs:integer` |
| `CastExpr` | `operand: Expr`, `type: QName`, `optional: bool` | `... cast as xs:string` |
| `ParenExpr` | `inner: Expr` | Nur zur Vorrang-Erhaltung beim Pretty-Print, Interpreter wertet `inner` direkt aus |

`QName` ist kein eigener Knotentyp, sondern ein einfaches `{prefix: ?string, local: string}`-Array.

## Nicht abgebildet (siehe `docs/notes/xpath-whitelist.md`)

`instance of`, `treat as` - keine Knotentypen dafür, ein Parser-Treffer wirft
`UnsupportedConstructException`.

## JSON-Serialisierung (Aufgabe 2.5, Ausdruckscache)

```json
{ "astVersion": 1, "root": { "node": "BinaryOp", "op": "=", "pos": 0, "left": {...}, "right": {...} } }
```

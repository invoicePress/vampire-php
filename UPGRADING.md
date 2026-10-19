# Umstieg

Hier steht, was sich zwischen Fassungen an der öffentlichen API ändert und was dafür zu tun ist. Was
öffentlich ist, sagt `docs/api.md`.

## Noch vor 1.0.0

Die API ist **noch nicht eingefroren**. Bis zur 1.0.0 kann sich jede Signatur ändern; jede Änderung
steht ab jetzt hier.

### Unveröffentlicht

- **`Result` bekam `scenarioName()`, `rulesets()` und `documentReference()`.** Rein additiv, nichts
  umbenannt. Die drei Angaben brauchen die Berichtsformate: ohne sie lässt sich „angenommen" nicht von
  „unter dem falschen Szenario angenommen" unterscheiden, und ein befundfreies Regelwerk verschwände
  aus dem Bericht.
- **`Validator::__construct()` bekam `profile` und `forceScenarioId`.** Beide benannt und mit Vorgabe
  `null`; bestehende Aufrufe bleiben gültig.
- **`Engine::__construct()` bekam `codeDbDir`, `Engine::run()` ein drittes Argument.** Beides mit
  Vorgabe. `Engine` ist nicht Teil der öffentlichen API — hier nur genannt, weil es in `src/` liegt.
- **`ScenarioResolver::resolve()` bekam ein zweites Argument.** Mit Vorgabe `null`.
- **`Validator::__construct()` bekam `rulesetDirs`** (Aufgabe 4x.3). Liste zusätzlicher Verzeichnisse,
  in denen ein Regelpaket `<ref>.json` gesucht wird, **vor** dem paketeigenen `rulesets/`. Benannt, mit
  Vorgabe `[]` — bestehende Aufrufe bleiben unverändert gültig. Gedacht für eigene Regelpakete, die
  nicht im ausgelieferten `rulesets/` liegen sollen, weil sie dort bei jedem Update im Weg stehen.
  ⚠️ Wird ein Regelpaket nirgends gefunden, gibt es eine Ausnahme, die **alle** durchsuchten Pfade
  nennt — kein stiller Rückfall auf `rulesets/`.
- **`Message` bekam `rulesets` und die Methoden `origins()` / `withOrigin()`** (Aufgabe 5.8a). `ruleset`
  bleibt, was es war: die **erste** Herkunft. Wer alle braucht, nimmt `origins()`.
- **Der JSON-Bericht steht auf `schemaVersion` 1.1.** ⚠️ **Eine Änderung, die Leser betrifft:** In
  `findings[]` heißt `ruleset` jetzt `rulesets` und ist eine **Liste**. Das Einzelfeld wurde entfernt,
  nicht danebengestellt — sonst läse ein alter Abnehmer weiter genau eine Herkunft und erführe von den
  anderen nie. Begründung und Zahlen: `docs/report-formats.md`.
- **Das Regelpaket-Format steht auf `schemaVersion` 2** (Aufgabe 0.2c): optional `catalogStandard` je
  Paket sowie `duplicateKey` und `descriptions` je Assertion. **Version 1 bleibt lesbar**; sie nimmt
  nur an der Dublettenbehandlung aus Aufgabe 5.8 nicht teil.
- **`Result` bekam `replaceMessage()`.** Interna der Pipeline; genannt, weil `Result` öffentlich ist.
- **`Message` bekam `ruleFlag` und `nativeFlag()`** (Aufgabe 4.6). `flag` bleibt die wirksame
  Einstufung. ⚠️ **Sichtbare Änderung im KoSIT-XML-Bericht:** dessen `level` ist jetzt die Einstufung
  des Regelwerks statt der wirksamen — so macht es der Referenzvalidator, gemessen an 208 seiner
  Berichte. Der JSON-Bericht führt beide (`severity`, `ruleSeverity`).

## Womit zu rechnen ist

- `Message` bleibt `readonly` mit öffentlichen Eigenschaften. Getter kommen nicht.
- Die JSON-Berichtsfelder bleiben innerhalb einer `schemaVersion` stabil; neue Felder können
  dazukommen, deshalb müssen Leser unbekannte Felder ignorieren.
- `src/Xpath/**` und `src/Engine/**` sind Interna und ändern sich ohne Ankündigung.

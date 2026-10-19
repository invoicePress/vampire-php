# Mitarbeit

## Regeln, die nicht verhandelbar sind

1. **Kein Java**, weder zur Laufzeit noch im Build oder in den Tests. Ein vorhandener
   KoSIT-Dienst darf nur für den optionalen Vergleichslauf angesprochen werden, nie aus `composer test`.
2. **Kein Netzwerkzugriff zur Laufzeit.** Regelpakete und Schemas liegen lokal, libxml läuft mit
   `LIBXML_NONET` und ohne externe Entitäten.
3. **Beträge niemals als `float`.** Nur Dezimal-Strings über BCMath — ein Rundungsfehler in einer
   Summenregel ist ein falscher Prüfbescheid.
4. **Nichts still überspringen.** Ein unbekanntes XPath-Konstrukt, ein unbekanntes Szenario oder eine
   fehlende Regel führen zu einem klaren Fehler mit Fundstelle, nicht zu einem stillen `true`.
5. **Keine Regel passend machen, damit ein Test grün wird.** Lieber den Test als offen markieren und
   die Frage in `docs/open-questions.md` eintragen.
6. **Kein fremder Code.** Andere Implementierungen dürfen nachgeschlagen, nicht übernommen werden.

## Sprache

Code und Code-Kommentare auf Englisch. Dokumentation und eigene Meldungstexte auf Deutsch.
Regel-Meldungen bleiben im Originalwortlaut des Regelwerks — sie sollen mit dem Referenzvalidator
vergleichbar bleiben.

## Ablauf

Arbeit auf Feature-Branches `phase-N/<kurzname>`, ein Pull Request je Aufgabe, Merge per Squash.
`main` ist geschützt, kein Force-Push.

**Definition of Done je Aufgabe:** Tests grün · PHPStan Level 8 ohne neue Fehler · Codestil geprüft ·
Eintrag in `CHANGELOG.md` · betroffene Dokumentation aktualisiert.

```
composer install
composer test
vendor/bin/phpstan analyse --level=8
vendor/bin/phpcs --standard=PSR12 src devkit
```

## Entscheidungen und offene Fragen

Eine Annahme, die man treffen musste, gehört mit Datum und Begründung nach `docs/decisions.md`.
Eine Frage zur Regelsemantik, die man nicht aus der Quelle beantworten kann, gehört mit Zitat der
Regel nach `docs/open-questions.md`. Beides ist ausdrücklich erwünscht: ein dokumentierter Irrweg
spart der nächsten Person denselben Umweg.

## Testdaten

Testrechnungen stammen aus den offiziellen Testbeständen (XRechnung-Testsuite, phive-rules). Ihre
Herkunft steht in `NOTICE` und `docs/sources.md`. **Keine echten Kundenbelege ins Repository** — auch
nicht anonymisiert, wenn die Anonymisierung nicht nachweisbar vollständig ist.

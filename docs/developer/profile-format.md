# Prüfprofil-Format (Aufgabe 5.1)

⚠️ **Nicht zu verwechseln mit den Regelprofilen des Rule Studio** (
`RuleProfile`, `RuleProfileLoader`, Format weiter unten ). Das Prüfprofil
hier richtet sich an den Betreiber und schaltet eine Regel wirklich ab, sodass sie nicht ausgewertet
wird. Ein Regelprofil richtet sich an den Kunden, bringt eigene Regeln mit, und eine Regel, die es
abschaltet, wird trotzdem ausgewertet und im Bericht gezählt, damit das amtliche Urteil daneben stehen
bleibt. Beide können gleichzeitig wirken.

Ein Prüfprofil beschreibt, **wie streng** geprüft wird — nicht, was geprüft wird. Es schaltet Regeln
ab, stuft sie um oder nimmt Regeln aus anderen Regelwerken hinzu. Das Profil ändert nie ein
Regelwerk; es liegt als Schicht darüber.

## Datei

JSON, eine Datei je Profil:

```json
{
  "id": "haus-standard",
  "title": "Hausprofil, Stand 2026",
  "extends": null,
  "disable": ["BR-DE-15"],
  "severity": { "BR-DE-18": "warning", "BR-CL-21": "information" },
  "include": [
    { "ruleset": "PEPPOL-EN16931-UBL@3.0.21", "rules": ["PEPPOL-EN16931-R120"] }
  ],
  "note": "Wozu dieses Profil da ist."
}
```

| Feld | Pflicht | Bedeutung |
|---|---|---|
| `id` | ja | eindeutiger Name, erscheint im Bericht |
| `title` | nein | Klartext für die Oberfläche |
| `extends` | nein | `id` eines anderen Profils; dessen Einträge gelten zuerst |
| `disable` | nein | Regel-IDs, die **gar nicht erst ausgewertet** werden |
| `severity` | nein | Regel-ID → `fatal` \| `warning` \| `information` |
| `include` | nein | Regeln aus einem Regelwerk, das das Szenario nicht ohnehin fährt |
| `note` | nein | Freitext |

## Was gilt in welcher Reihenfolge

1. Das Szenario bestimmt Regelwerke und seine eigenen `customLevel`-Übersteuerungen (KoSIT).
2. Darüber legt sich das Profil: erst `extends`, dann die eigenen Einträge.
3. `disable` schlägt `severity` — eine abgeschaltete Regel wird nicht ausgewertet, also kann sie auch
   nicht umgestuft werden.

⚠️ **`disable` heißt wirklich „nicht auswerten", nicht „Ergebnis verwerfen".** Das ist der Unterschied
zwischen einem schnelleren Lauf und einem Bericht, der etwas verschweigt: eine abgeschaltete Regel
taucht nirgends auf, auch nicht als unterdrückte Meldung. Wer eine Meldung sehen, aber nicht darüber
stolpern will, stuft sie auf `information` herab.

⚠️ **Ein Profil kann eine Prüfung nur lockern oder verschärfen, nie eine Regel erfinden.** Eigene
Regeln sind Aufgabe 5.5 und gehen einen anderen Weg (Regelmodell), damit im Bericht immer erkennbar
bleibt, woher eine Meldung stammt.

## `include` und die Syntaxfrage

Eine zugeschaltete Regel muss zur Syntax des Belegs passen. `PEPPOL-EN16931-R120` ist eine UBL-Regel;
auf eine CII-Rechnung angewendet, liefe sie ins Leere und würde falsche Sicherheit erzeugen. Deshalb
prüft der Lader beim Zuschalten, ob das genannte Regelwerk zur Syntax des aufgelösten Szenarios passt,
und lehnt es sonst mit einer klaren Meldung ab.

## Dieselbe Regel in mehreren Regelwerken (Aufgabe 5.8)

Eine Regel steht oft in mehreren Regelwerken — teils unter genau derselben ID, teils anders
geschrieben (`UBL-CR-400` gegenüber `ubl-CR-400` in UBL.BE). Woran man sie wiedererkennt, ist der
`duplicateKey`: ein Hash über den normalisierten Kontext und Testausdruck, übernommen aus dem
Suite-Regelkatalog (Aufgabe 0.2c).

Daraus folgen drei Dinge, die ein Profil betreffen:

1. **`disable` und `severity` meinen die Regel, nicht eine Schreibweise.** Wer `BR-02` abschaltet,
   schaltet sie in **jedem** gerade laufenden Regelwerk ab. ⚠️ Vorher wirkte so ein Eintrag nur im
   erstgenannten Paket — die Regel lief anderswo weiter, und das Profil sah aus, als täte es nichts.
   Eine ausdrückliche Einstufung für eine bestimmte Schreibweise behält Vorrang.
2. **`include` einer Regel, die bereits läuft, wird abgelehnt** statt ein zweites Mal ausgeführt. Der
   Bericht bekommt dafür einen Hinweis (`information`, Regelwerk `profile`), der die Regel und das
   Regelwerk nennt, das sie schon abdeckt. Bleibt von einem `include` danach nichts übrig, wird das
   Regelwerk gar nicht erst zugeschaltet und erscheint auch nicht als Prüfschritt — es hat nichts
   geprüft.
3. **Im Bericht ist es ein Befund mit mehreren Herkünften**, nicht mehrere Befunde
   (`docs/developer/report-formats.md`).

Ein Regelpaket ohne `duplicateKey` (`schemaVersion` 1, oder eine selbst geschriebene Regel) nimmt an
alldem nicht teil: es wird dann nach ID gearbeitet, wie vorher.

## Kennzeichnung im Bericht

Jede Meldung trägt, aus welchem Regelwerk sie stammt. Kommt ihre Einstufung aus einem Profil statt aus
dem Regelwerk, wird das im Bericht ausgewiesen — ein Prüfbericht, dessen Strenge jemand verändert hat,
muss das sagen.

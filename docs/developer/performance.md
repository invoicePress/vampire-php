# Laufzeit bei großen Rechnungen (Aufgabe 8.6)

Gemessen am 08.10.2026 mit `devkit/Cli/benchmark.php`, PHP 8.3.30, Median aus 3 Läufen je Größe.

Grundlage ist die offizielle XRechnung-Testrechnung `01.01a-INVOICE_ubl.xml`. Ihre Rechnungspositionen werden auf die gewünschte Zahl gebracht (vervielfacht oder entfernt) und alle Summen aus dem gezählt, was danach wirklich dasteht, damit der Beleg **gültig bleibt**.

| Positionen | Größe | Zeit (Median) | schnellster/langsamster Lauf | je Position | Urteil |
|---:|---:|---:|---:|---:|---|
| 1 | 5.9 KB | 191.7 ms | 186.3 / 224.4 ms | 191.66 ms | angenommen |
| 10 | 18.2 KB | 428.2 ms | 426.9 / 431.3 ms | 42.82 ms | angenommen |
| 50 | 76.5 KB | 1574.8 ms | 1533.6 / 1622.6 ms | 31.50 ms | angenommen |
| 200 | 295.4 KB | 6170.0 ms | 6105.7 / 6239.8 ms | 30.85 ms | angenommen |
| 500 | 733.1 KB | 17507.0 ms | 16965.7 / 17532.3 ms | 35.01 ms | angenommen |
| 1000 | 1462.5 KB | 39279.7 ms | 38414.2 / 40157.1 ms | 39.28 ms | angenommen |

**Form der Kurve.** Bei einer Position kostet die Prüfung 192 ms, und davon ist fast alles Fixaufwand — Regelpakete laden, Szenario auflösen, XSD. Die Zeit je Position wird deshalb zunächst **billiger**; am günstigsten ist sie bei 200 Positionen mit 30.85 ms. Danach steigt sie wieder, auf 39.28 ms bei 1000 Positionen. Insgesamt kosten 1000-mal so viele Positionen das 205-fache an Zeit — weniger als linear.

**Spitzenspeicher** beim größten Beleg (1000 Positionen, 1462.5 KB): 174.0 MB.

⚠️ **Was das praktisch heißt.** Zehn Positionen sind in 0.4 Sekunden geprüft, fünfzig in 1.6, zweihundert in 6. Ein Beleg mit tausend Positionen braucht rund 39 Sekunden und 174 MB Speicher — mehr, als ein `memory_limit` von 128 MB hergibt. Wer solche Belege im Web-Request prüfen will, braucht einen Hintergrundlauf, kein schnelleres Hosting.

⚠️ **Absolut sagen diese Zahlen nichts** — sie stammen von einem Rechner, einer PHP-Version, 3 Läufen je Größe. Die Spalte „schnellster/langsamster Lauf" zeigt, wie weit dieselbe Messung auseinandergeht, wenn der Rechner nebenbei etwas anderes tut: hier bis zum 1.2-fachen. Wofür die Zahlen da sind, ist die **Form** der Kurve. Wer vergleichen will, misst neu — auf einem ruhigen Rechner.

⚠️ **Der erzeugte Beleg muss gültig bleiben.** Ein Beleg, der schon am XSD scheitert, beendet die Kette vor der Schematron-Stufe — gemessen würde dann der Fehlerpfad, und die Zahl sähe hervorragend aus. Das Skript prüft deshalb `isAccepted()` und schreibt bei einer Ablehnung nichts.

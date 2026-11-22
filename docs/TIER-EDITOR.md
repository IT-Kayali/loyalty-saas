# Mitgliedsstufen bearbeiten

Auf der Seite eines Punkteprogramms öffnet **Bearbeiten** neben jeder Mitgliedsstufe einen getrennten Editor. Änderbar sind Name, Lifetime-Punkteschwelle und Vorteil. Die Stufe bleibt beim selben Programm und Unternehmen. Keine neue API-/MCP-Funktion und keine Änderung der Bonuszeiten oder Löschfunktion.

## Wirkung

Die Stufe wird wie bisher aus den aktuellen Lifetime-Punkten der Karte berechnet. Wenn eine Schwelle geändert wird, kann ein bestehendes Mitglied beim nächsten Seitenabruf eine andere Stufe sehen. Es werden keine Punkte gutgeschrieben oder abgezogen, keine Transaktionen erzeugt und keine Nachrichten versendet. Der Vorteil ist Beschreibungstext, keine automatisch berechnete Rabatt- oder Geldleistung. Bestehende Offlinekopien und installierte Wallet-Pässe werden nicht automatisch aktualisiert.

Unterschiedliche Schwellen ergeben eine eindeutige Staffelung. Gleiche Schwellen bleiben wie bisher erlaubt; für deren Reihenfolge gibt es keine neue Eindeutigkeitsregel. Das ist keine umfassende Reward-/Limitkonfiguration, kein Club und keine automatische Leistungsgewährung.

## Prüfungen und Konflikte

Eigentümer, Punkteprogramm, CSRF und Planfeature `tiers` sind erforderlich. Lesen bleibt ohne bezahlten Schreibplan möglich. Name: 1–40 Zeichen. Vorteil: optional, höchstens 120 Zeichen. Beide UTF-8, ohne Steuerzeichen. Schwelle: kanonische ganze Zahl von 0 bis 1000000, keine negativen Werte, Dezimalzahlen, Exponenten, führenden Nullen oder stilles Abschneiden/Klemmen. Diese Feldprüfung gilt auch beim Anlegen einer Stufe.

Der Editor überträgt einen Prüfstand aus Stufen-/Programm-ID, Name, Schwelle und Vorteil. Unter `BEGIN IMMEDIATE` werden Eigentümer und Plan erneut geprüft. Ein älterer Tab oder ein paralleler Schreiber kann einen geänderten Stand nicht überschreiben. Werte und ID-Audit `tier.edit` werden gemeinsam gespeichert oder zurückgenommen; Anlegen schreibt entsprechend atomar `tier.create`. Audits enthalten keine Namen oder Vorteiltexte. GET ist `private, no-store` und schreibt nichts.

Der Prüfstand ist keine fortlaufende Versionsnummer: eine zwischenzeitliche Änderung mit exakter Rückkehr zum selben Inhalt ist nicht erkennbar. Kein dauerhafter Wiederholungsbeleg oder Datenbank-Administratorschutz. Die bisherige Tier-Löschung ist getrennt und wurde hier nicht umgebaut.

## Tests

`python3 tests/run-tier-edit.py`: 40 HTTP-/Domänen- und 12 Chromium-Prüfungen. Feldgrenzen/Unicode/Arrays, Anlegen ohne Klemmen, Eigentümer/CSRF/Plan, unveränderte Punkte/Journal/Programme/Prämien/Mails, dynamische Stufenberechnung nach Schwellenänderung, alte Tabs, atomare Audit-Rollbacks und zwei echte konkurrierende PHP-Schreiber mit genau einem Gewinner. Desktop/Mobil einschließlich langer Texte und Programmliste visuell geprüft. Screenshots unter `/tmp/loyalty-tier-edit/`, nur Wegwerfdaten.

Etappenregression: alle 40 weiteren Nicht-WordPress-`run-*.py`-Suiten grün. WordPress-Echtumgebung nicht erneut geprüft.

Nachtrag: Die zuvor direkte Löschfunktion wurde separat durch Prüf-/Bestätigungsseiten mit Konfliktschutz und atomarem Audit ersetzt, siehe [PROGRAM-RULE-DELETE.md](PROGRAM-RULE-DELETE.md).

# Bonuszeiten bearbeiten

Eigentümer eines Punkteprogramms können vorhandene Bonuszeiten über **Bearbeiten** neben dem Zeitfenster ändern: Wochentag, Beginn, Ende und Multiplikator. Das Zeitfenster bleibt beim bisherigen Programm. Ein eigener Editor, kein Umbau der Bonus-Auswertungslogik.

## Wirkung und Zeitzone

Die bestehende Funktion `points_multiplier()` nutzt die **PHP-Serverzeitzone**, nicht die eingestellte Unternehmenszeitzone. Programmseite und Editor zeigen den tatsächlich konfigurierten Zeitzonennamen. Im lokalen Test ist dies UTC. Eine andere Serverkonfiguration kann andere Stunden aktivieren; diese Etappe verschiebt keine Regeln und stellt die Auswertung nicht still um. Eine spätere Serverzeitzonenänderung bleibt eine Betriebsänderung mit Wirkung auf alle Bonuszeiten.

Beginn einschließlich, Ende ausschließlich, nur ganze Stunden eines Wochentags. 0–24 ist ein ganzer Tag; 24 ist nur als Ende erlaubt. Für einen Zeitraum über Mitternacht zwei Regeln anlegen. Bei Überschneidungen gilt wie bisher der höchste Multiplikator, nicht das Produkt. Es gibt keine neue Überschneidungssperre.

Änderungen gelten bei künftigen Punktevergaben, soweit deren Buchungspfad den Bonusmultiplikator verwendet. Bestehende Punkte, Lifetime-Werte und Journalbuchungen werden nicht neu berechnet. Keine Nachricht, kein Wallet-Push, keine automatische Korrekturbuchung. Keine Datums-/Feiertagsregeln, Minuten, Standortregeln oder neue API-/MCP-Funktion. Die bisherige Löschung bleibt getrennt unverändert.

## Validierung und Konfliktschutz

Gemeinsame Prüfung für Anlegen und Bearbeiten: Wochentag 0–6, Beginn 0–23, Ende 1–24, Ende nach Beginn, Multiplikator 2–10. Nur kanonische ganze Zahlen, keine Arrays, Exponenten, Dezimalzahlen, negativen Werte oder führenden Nullen. Keine still geklemmten Eingaben mehr.

Eigentümer, Punkteprogramm, CSRF und Planfeature `bonus_hours` erforderlich. Lesen bleibt ohne bezahlten Schreibplan möglich. Der Prüfstand bindet Regel-ID, Programm und vier Werte. Unter SQLite-Schreiblock werden Eigentümer/Plan und aktueller Stand erneut geprüft. Alte Tabs und zwei parallele Schreiber überschreiben nicht blind den neueren Stand. Speichern und ID-Audit `bonus.edit` sind atomar, Anlegen entsprechend `bonus.create`. GET ist `private, no-store`, ohne Schreiben/Audit.

Der Vergleich ist keine fortlaufende Versionsnummer. Zwischenzeitliche exakte Wiederherstellung des alten Inhalts ist nicht erkennbar. Kein dauerhafter Wiederholungsbeleg und kein Schutz vor administrativen Datenbankeingriffen.

## Tests

`python3 tests/run-bonus-edit.py`: 40 HTTP-/Domänen- und 12 Chromium-Prüfungen. Eingabegrenzen/Arrays/Anlage, Eigentümer/CSRF/Plan, Lesezugriff, unveränderte Punkte/Journal/Programme/Prämien/Mails, alte Tabs, Audit-Rollback und zwei parallele PHP-Schreiber. Bestehende Multiplikatorlogik geprüft: höchster überlappender Wert, anderer Wochentag ausgeschlossen und Ende exklusiv. Desktop/Mobil samt Programmliste visuell geprüft, Screenshots unter `/tmp/loyalty-bonus-edit/`. Nur Wegwerfdaten, keine externen Dienste oder Geräteabnahme.

Etappenregression: alle 41 weiteren Nicht-WordPress-`run-*.py`-Suiten grün, einschließlich Stufen-Editor, Programme und Buchungswege. WordPress-Echtumgebung nicht erneut geprüft.

Nachtrag: Die zuvor direkte Löschfunktion wurde separat durch Prüf-/Bestätigungsseiten mit Konfliktschutz und atomarem Audit ersetzt, siehe [PROGRAM-RULE-DELETE.md](PROGRAM-RULE-DELETE.md).

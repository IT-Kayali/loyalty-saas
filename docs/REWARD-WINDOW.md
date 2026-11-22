# Prämien-Zeitfenster sicher speichern

Stand: 22.11.2026. Ergänzung des bestehenden einzelnen Prämien-Zeitfensters, keine neue Zeitplanung. Siehe [REWARD-CODES.md](REWARD-CODES.md) und [TIMEZONE.md](TIMEZONE.md).

## Bedienung und Wirkung

Punkteprogramm → **Zeitfenster für Prämien**. Jede Zeile nennt nun ihre aktuelle Eingabezeitzone. Die bestehenden lokalen Datumsfelder bleiben erhalten; gespeichert wird UTC. Beide Felder leer entfernt das Fenster. Nur Start oder nur Ende ist weiterhin möglich, bei beiden Angaben muss Ende nach Start liegen.

Das Fenster wird wie bisher bei neuen Codes, direkten Einlösungen und der vorhandenen Merchant-API geprüft. Bereits ausgestellte Codes behalten Name-/Kostensnapshot und gespeicherten Ablauf, werden beim tatsächlichen Einlösen aber zusätzlich gegen das aktuelle Prämienfenster geprüft. Ein neues Fenster kann daher alte, noch nicht abgelaufene Codes zeitweise oder dauerhaft unbenutzbar machen. Verlängern eines Fensters verlängert nicht den gespeicherten Ablauf alter Codes.

Speichern verändert keine Namen, Preise, Programmzuordnung, Mitgliederdaten, Salden, Lifetime-Punkte, Journalbuchungen, Code-Snapshots oder Codezustände. Keine Nachricht, kein Wallet-Push und kein Anbieteraufruf. Das Laden der Programmseite schreibt keinen Audit.

## Konfliktschutz

Das Formular übermittelt `window_revision`. Der Inhaltsprüfstand bindet Prämien-ID, Programm, aktuelles UTC-Fenster und effektive Unternehmenszeitzone. Vor dem Schreiben werden Eigentümer, Punkteprogramm, Planfeature `programs`, aktuelles Fenster und Zeitzone unter SQLite-Schreiblock erneut geprüft. Eine alte Seite kann weder ein inzwischen geändertes Fenster überschreiben noch eingegebene Ortszeiten stillschweigend in einer anderen Unternehmenszeitzone umdeuten.

Bei Konflikt wird nichts gespeichert. Die Programmseite zeigt eine Fehlermeldung und lädt aktuelle Felder, Zeitzone und Prüfstand. Vor erneutem Speichern diese neuen Angaben prüfen. Alte Formulare ohne Prüfstand werden abgewiesen statt blind übernommen. Fehlende Datumsfelder sind kein impliziter Löschbefehl, beide Felder müssen ausdrücklich übermittelt werden.

Name-/Punktekosten-Editor ist unabhängig: Er schreibt keine Zeitfelder, das Zeitfensterformular schreibt weder Namen noch Kosten. Eine separate Namens-/Kostenänderung wird durch Fenster-Speichern nicht zurückgesetzt.

Der Prüfstand ist keine fortlaufende Versionsnummer. Eine zwischenzeitliche exakte Wiederherstellung desselben Fensters und derselben effektiven Zeitzone ist nicht erkennbar. Es gibt kein dauerhaftes Wiederholungsregister für unverändert erneut gespeicherte Inhalte.

## Validierung und Audit

CSRF und Eigentümerzugang bleiben Pflicht. IDs und Felder müssen einzelne Werte sein; unbekannte/fremde und Nicht-Punkteziele sind nicht verfügbar. Lesen des Programms bleibt ohne aktiven Schreibplan möglich. Die bestehende Datumsprüfung und UTC-Umrechnung werden weiter benutzt. Kein Serientermin, keine neue Parser-/Sommerzeitlogik. Bei mehrdeutigen oder nicht existierenden Ortszeiten an Sommerzeitwechseln gilt weiterhin PHP-DateTime-Verhalten; keine neue Auswahl des ersten/zweiten Vorkommens. Solche Eingaben vermeiden und vor produktiver Terminierung prüfen.

Update und ID-Audit `reward.window` sind jetzt eine Transaktion. Auditfehler lassen auch das Fenster unverändert. Der neue Audit enthält Prämien-/Programm-ID, keine Zeitwerte oder Kundentexte. Vorhandene ältere Auditeinträge werden nicht umgeschrieben. Zwei abweichende parallele Änderungen desselben Ausgangsstands können nicht beide diesen Stand überschreiben.

## Tests und Grenzen

`python3 tests/run-reward-window.py`: 27 HTTP-/Domänen- und 12 Chromiumprüfungen. CSRF/Eigentümer/Mitglied, fehlende oder als Array übermittelte Felder, alter Prüfstand, ungültiger Tag/Reihenfolge, UTC sowie bestehende Berliner Winter-/Sommerumrechnung, Zeitzonenwechsel nach Öffnen, unabhängige Name-/Kostenänderung, Audit-Rollback, Plan, zwei echte parallele PHP-Schreiber, ausdrückliches Entfernen und unveränderte fachliche Daten. Browser prüft Speichern, Fenster-/Zeitzonenkonflikte, aktuelle Zeitzone pro Zeile, Entfernen und 390px-Mobilansicht. Screenshot-Formularausschnitte für Desktop/Mobil unter `/tmp/loyalty-reward-window/`.

Die bestehende Code-/Einlösesuite `tests/run-claims.py` nutzt nun die frisch gerenderten Prüfstände und prüft weiterhin echte lokale Code-Erzeugung/Einlösung sowie Zeitgrenzen über die vorhandenen Pfade. Nur Wegwerfdaten, keine tatsächlichen Kunden- oder Anbieteraktionen. Kein Gerätetest und keine Zusage einer vollständigen Kalender-/Planungsfunktion. Etappenregression: alle 46 weiteren Nicht-WordPress-`run-*.py`-Suiten grün, einschließlich Prämieneditor und bestehender Einlöse-/Zeitfensterpfade. PHP-Lint und `git diff --check` sauber. WordPress-Echtumgebung nicht erneut geprüft.

# Punkteprämien bearbeiten

Stand: 22.11.2026. Bestehende Prämien eines eigenen Punkteprogramms lassen sich über Programm → Prämie → **Bearbeiten** öffnen (`/reward/ID/edit`). Name und Punktekosten werden getrennt vom vorhandenen Zeitfenster bearbeitet.

## Wirkung

- Neuer Name und neue Kosten gelten für künftig direkt bestätigte Einlösungen und neu erstellte Einlösecodes.
- Bereits ausgestellte Einlösecodes behalten ihren gespeicherten Namen und ihre gespeicherten Kosten. Sie werden nicht neu bepreist. Vorhandene aktuelle Prämien-Zeitfenster werden beim Einlösen solcher Codes weiter geprüft.
- Keine Punkte werden beim Bearbeiten vergeben, abgezogen oder reserviert. Bestehende Salden, Lifetime-Punkte, Journalbuchungen, Prämiencodes und deren Zustände bleiben unverändert. Erst eine echte Einlösung bucht nach dem jeweiligen Einlösepfad.
- Programmzuordnung und Zeitfenster werden hier nicht geändert. Keine Mails, Wallet-Pushes, Updates an bereits installierten Pässen oder sonstige Anbieteranfragen durch Speichern.

## Eingaben und Schutz

Name: 1 bis 120 Unicode-Zeichen nach Rand-Leerraumkürzung, ohne Steuerzeichen. Punktekosten: ganze Zahl 1 bis 100000, keine Arrays, Exponenten, Dezimalwerte, Vorzeichen oder führenden Nullen. Dieselbe Prüfung gilt jetzt für die bisherige Prämienanlage. Fehler werden zurückgewiesen statt still gekürzt oder auf einen Mindestwert gesetzt.

Nur der Eigentümer des Punkteprogramms darf lesen und schreiben. Fremde, unbekannte und Nicht-Punkteziele sind nicht verfügbar. Feste Ziel-ID aus der URL, andere übermittelte IDs werden nicht übernommen. CSRF und bestehendes Planfeature `programs`; Lesen bleibt ohne aktiven Schreibplan möglich.

Prüfstand bindet Prämien-ID, Programm, Name und Punktekosten. Ein abweichender Stand nach Öffnen des Editors wird abgewiesen. Die aktuelle Fassung wird anschließend neu geladen. Zeitfenster werden bewusst nicht mitgeschrieben und sind nicht Teil dieses Inhaltsprüfstands. Kein fortlaufendes Änderungsjournal: eine zwischenzeitliche exakte Wiederherstellung desselben Inhalts ist nicht erkennbar.

SQLite-Schreibtransaktion prüft Eigentümer, Plan und Prüfstand nochmals. Speichern und ID-Audit `reward.edit` bzw. `reward.create` erfolgen atomar. Zwei konkurrierende Bearbeitungen desselben alten Stands haben höchstens einen Gewinner. Audit enthält Prämien-/Programm-ID, keine Namen oder Kundendaten. Prüfseite `private, no-store`, HTML-Ausgabe maskiert. Lange Prämiennamen umbrechen auch in Programmliste und Zeitfensterformular.

## Tests

`python3 tests/run-reward-edit.py`: 39 HTTP-/Domänen- und 14 Chromiumprüfungen. Rechte, CSRF, Eingabegrenzen/Unicode/Arrays, feste IDs, unveränderte Zeitfenster, alte Tabs, Planlesezugriff, Audit-Rollback, zwei echte PHP-Schreiber und unveränderte Mitglieds-/Saldo-/Journal-/Code-/Mail-/Gerätedaten beim Bearbeiten. Ein vor Bearbeitung erzeugter Code löst danach zum alten Namen/Preis ein, ein neuer Code übernimmt den aktuellen Namen. Getrennte strikte Anlageprüfung. Browser prüft Desktop/Mobil, lange Namen in der Programmliste, Speichern und Zwei-Tab-Konflikt. Screenshots unter `/tmp/loyalty-reward-editor/`.

Nur isolierte Wegwerfdaten. Kein echter Provider-/Geräte-/Lasttest, keine Änderung realer Kundenpunkte. Etappenregression: alle 45 weiteren Nicht-WordPress-`run-*.py`-Suiten grün. PHP-Lint und `git diff --check` sauber. WordPress-Echtumgebung nicht erneut geprüft.

Der getrennte Fensterpfad besitzt ebenfalls Inhalts-/Zeitzonenprüfung und atomaren Audit, ohne Name/Kosten mitzuschreiben: [REWARD-WINDOW.md](REWARD-WINDOW.md).

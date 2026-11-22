# Stufen und Bonuszeiten bewusst löschen

Die früheren direkten Kreuz-Buttons sind durch **Löschen prüfen** ersetzt. Eine getrennte private Bestätigungsseite zeigt genau die aktuelle Stufe oder Bonuszeit. Öffnen und Abbrechen verändern nichts. Die endgültige Löschung braucht das Bestätigungsfeld; bei Stufen zusätzlich den exakten aktuellen Stufennamen.

## Wirkung

Eine gelöschte Stufe steht nicht mehr zur dynamischen Lifetime-Berechnung bereit. Bestehende Mitglieder können beim nächsten Abruf eine andere oder keine Stufe sehen. Der Vorteil ist Beschreibungstext und wird durch diese Funktion weder automatisch gewährt noch zurückgenommen.

Eine gelöschte Bonusregel beeinflusst künftige Vergaben, soweit der Buchungspfad Bonuszeiten nutzt. Der höchste verbleibende überlappende Multiplikator gilt weiter. Die PHP-Serverzeitzone wird angezeigt; keine neue Unternehmenszeitzonen-Auswertung.

Keine bestehenden Punkte, Lifetime-Werte, Prämien oder Journalbuchungen werden neu berechnet oder gelöscht. Kein Versand, Wallet-Push oder Rückruf von Offlinekopien. Bereits laufende Buchungen werden nicht rückwirkend korrigiert. Keine Archiv-/Wiederherstellungsfunktion.

## Schutz

- Festes Ziel aus der URL, nur Eigentümer eines Punkteprogramms. Übermittelte andere Programm-/Regel-IDs ändern das Ziel nicht.
- CSRF, explizite Bestätigung und Planfeature `tiers` bzw. `bonus_hours`; Lesen bleibt ohne bezahlten Schreibplan möglich.
- Prüfstand bindet Regel-ID/Programm und aktuelle Werte. Ein älterer Stand nach Bearbeitung wird abgelehnt und die aktuelle Regel mit leerer Bestätigung erneut gezeigt.
- SQLite-Schreiblock mit frischer Eigentümer-/Plan-/Inhaltsprüfung. Löschen und ID-Audit `tier.delete` oder `bonus.delete` werden zusammen gespeichert oder zurückgenommen. Zwei gleichzeitige Löschungen erzeugen höchstens einen Erfolg und einen Audit-Eintrag. Konkurrierendes Bearbeiten und Löschen kann nicht denselben alten Stand zweimal als gültig verwenden.
- Alte direkte POST-Endpunkte `/tier/delete` und `/bonus/delete` löschen nicht mehr. Sie verlangen, das Programm neu zu öffnen und die Prüfseite zu benutzen. Gelöschte Prüf-/Editorziele sind nicht mehr verfügbar.
- `private, no-store` auf Prüfseiten, kein Audit beim bloßen Lesen. Audits enthalten Regel-/Programm-ID, keine Namen oder Vorteiltexte.

Der Prüfstand ist keine fortlaufende Versionsnummer: Eine zwischenzeitliche exakte Wiederherstellung desselben Inhalts ist nicht erkennbar. Kein dauerhaftes Wiederholungsregister und kein Schutz vor beliebigen administrativen Datenbankeingriffen.

## Tests

`python3 tests/run-rule-delete.py`: 46 HTTP-/Domänen- und 28 Chromium-Prüfungen. Reine Vorschau, Abbrechen, Name/Checkbox/Revision, CSRF/Plan/Eigentümer, alte direkte Formulare, feste Ziele, Bearbeitung nach Vorschau, Audit-Rollback, wiederholte und parallele Löschungen, echtes Bearbeiten-/Löschen-Prozessrennen und unveränderte Punkte/Journal/Prämien/Mails. Browser prüft getrennte Stufen-/Bonusprüfseiten auf Desktop/Mobil, neue Bestätigung nach Konflikt und erfolgreiche Löschung. Screenshots unter `/tmp/loyalty-rule-delete/` visuell geprüft. Nur lokale Wegwerfdaten.

Etappenregression auf dem aktuellen Wallet-Datenschutzstand: alle 44 weiteren Nicht-WordPress-`run-*.py`-Suiten grün, einschließlich Standort-Nähe und separater Kundenwahl/Widerruf. PHP-Lint und `git diff --check` sauber. WordPress-Echtumgebung nicht erneut geprüft. Keine externe Zustell-/Geräteabnahme.

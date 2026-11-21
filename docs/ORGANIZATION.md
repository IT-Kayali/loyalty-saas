# Netzwerke, Standorte und Teamrechte

## Bedienung

Im Betreiber-Menü führt **Organisation** zu `/organization`. Dort lassen sich Netzwerke anlegen, eigene Unternehmen zuordnen sowie Standorte anlegen und deaktivieren. **Team & Rechte verwalten** öffnet `/business/{id}/team`.

Netzwerke sind in dieser Etappe eine Gruppierung der Unternehmen desselben Betreibers. Sie teilen keine Mitglieder, Programme, Punkte oder Guthaben. Es gibt noch keine Clubs oder Kooperation zwischen fremden Betreibern.

Standorte gehören zu genau einem Unternehmen. Alle Standorte dieses Unternehmens sehen dieselben Mitglieder und Programme. Die Standortzuordnung am Teamzugang dient der Buchungszuordnung, nicht einer separaten Datensicht. Ein Teamzugang ohne Standort ist unternehmensweit und erzeugt Buchungen ohne Standortkennung.

## Rechte

| Profil | Vergeben | Einlösen | Eigene Aktionen stornieren | Pässe verkaufen |
|---|---|---|---|---|
| Viewer | Nein | Nein | Nein | Nein |
| Kasse | Ja | Ja | Nein | Ja |
| Schichtleitung | Ja | Ja | Ja | Ja |

Ein Profilwechsel setzt im Browser die Häkchen neu. Danach lassen sie sich einzeln ändern. Die gespeicherten Häkchen sind verbindlich, der Rollenname ist keine zusätzliche Freigabe. Bei deaktiviertem JavaScript die Häkchen selbst prüfen.

- `award`: Stempel und Punkte vergeben.
- `redeem`: Prämien, einfache Gutscheine/Mehrfachkarten, Code-Gutscheine und verkaufte Passbesuche einlösen.
- `reverse`: nur eigene Staff-Buchungen/Besuche stornieren. Alte Buchungen ohne Staffkennung lassen sich nur vom Betreiber stornieren.
- `sell_pass`: extern bezahlte Passverkäufe dokumentieren. Keine Zahlung wird durch die App ausgeführt.

Keine Teamrolle erhält Betreiber-, Abrechnungs- oder Plattformrechte. Bestehende Teamzugänge migrieren auf Schichtleitung, um die bisher verfügbaren Aktionen nicht still zu entziehen. Neue Zugänge starten als Kasse. Vor Produktivbetrieb bestehende Rechte bewusst prüfen und möglichst einschränken.

Die Kontrollen laufen serverseitig. Alte geöffnete Formulare oder direkte POST-Aufrufe umgehen sie nicht. Einzelne Aktionsknöpfe können trotz fehlender Rechte sichtbar bleiben; der Server weist diese Aktion zurück. Verkäufe, Passnutzung und Code-Gutscheine prüfen Rechte unter ihrer Schreibtransaktion erneut. Staff-Kartenbuchungen und Undo lesen die Berechtigung ebenfalls innerhalb der Schreibtransaktion frisch.

## Zugänge und Nachverfolgung

Neue PINs und PIN-Änderungen benötigen 6 bis 12 Ziffern. Bestehende Demo-PINs bleiben für lokale Tests unverändert. Ein PIN-Wechsel macht alle bisherigen Staff-Sitzungen ungültig. Fehlerhafte PIN-Eingaben speichern auch keine teilweise geänderten Rechte.

Rechteänderungen, deaktivierte Zugänge und deaktivierte zugewiesene Standorte greifen bei der nächsten Anfrage. Staff-/Standortkennungen werden für neue Kartenbuchungen, Code-Gutschein-Einlösungen und Passverkaufs-/Besuchs-/Stornoeinträge gespeichert. Historische Einträge bleiben ohne erfundene Zuordnung. Betreiberaktionen erhalten keine Staffkennung, auch wenn im selben Browser zusätzlich ein Staff-Login existiert.

SQLite-Trigger verhindern Netzwerkzuordnungen zu einem anderen Betreiber und Standortzuordnungen zu einem anderen Unternehmen. Netzwerkbesitzer und Standortunternehmen sind nicht nachträglich umhängbar. Netzwerke/Standorte sind in die zentrale Planprüfung eingebunden. Der optionale Feature-Schlüssel ist `locations`, Teamverwaltung nutzt `staff`. Ohne diesen zusätzlichen Feature-Schlüssel bleiben vorhandene eigene Pläne kompatibel.

## Geprüft

```sh
php tests/billing.php
PHP_BIN=php python tests/run-organization.py
PHP_BIN=php python tests/run-policy.py
PHP_BIN=php python tests/run-programs.py
PHP_BIN=php python tests/run-mail.py
PHP_BIN=php python tests/run-browser.py
PHP_BIN=php python tests/run-backup.py
```

Die neue Suite verwendet Wegwerfinstallationen und prüft Rollen/Overrides, fremde Netzwerk-/Standort-/Staff-IDs, SQL-Integrität, Staff-/Standortzuordnung, eigene gegenüber fremden Stornos, sofortigen Rechteentzug, PIN-Wechsel mit Sitzungswiderruf, deaktivierte Standorte, parallele Betreiber-/Staff-Anmeldung und Mobilansichten.

## Weiter offen

Keine unternehmensübergreifenden Salden oder Clubs, keine delegierten Managerkonten, keine Standort-Datensicht oder Standortberichte, keine zeitgesteuerten Rollen, keine Staff-Einladungen/OTP/MFA und keine vollständige Audit-/Compliance-Abnahme. Eigene Netzwerknamen sind jetzt separat editierbar (siehe unten). Standortnamen/-adressen bleiben nicht editierbar; Netzwerke/Standorte nicht löschbar. Keine vollständige Funktionsgleichheit mit dem Referenzprodukt.

## Eigenen Netzwerknamen ändern

Organisation zeigt alle eigenen Netzwerke mit aktueller Zahl zugeordneter eigener Unternehmen und „Name bearbeiten“. Auch noch nicht zugeordnete Netzwerke sind erreichbar. Der getrennte Editor `/network/ID/edit` ändert ausschließlich den Gruppierungsnamen: 1 bis 80 UTF-8-Zeichen nach äußeren Leerzeichen, keine Steuerzeichen oder Formulararrays. HTML bleibt Text und wird bei Ausgabe escaped. Lange Namen ohne Leerzeichen umbrechen in Liste und Editor.

Netzwerk-ID, Eigentümer und Unternehmenszuordnungen bleiben gleich. Keine Verschiebung von Mitgliedern, Programmen, Karten, Salden, Standorten oder Teamrechten. Aktuelle Organisationslisten und Netzwerkauswahlen zeigen den neuen Namen beim nächsten Abruf. Keine Umbenennung alter Exporte oder Dokumente und kein Wallet-Push, Mailversand oder externer Aufruf.

Nur der Eigentümer, CSRF und Planfeature `locations`. Lesen bleibt bei fehlender Planberechtigung möglich. Ziel ist die URL, fremde zusätzliche Formular-IDs können es nicht ändern. Name und ID-Audit `network.rename` speichern atomar unter `BEGIN IMMEDIATE`. Eigentümer und Plan werden darin erneut geprüft. Ein alter Namensprüfstand überschreibt keine neuere Umbenennung. Bei Konflikt lädt die Seite den aktuellen Namen mit Hinweis, verworfene Eingaben werden nicht als Entwurf behalten.

Das ist ein Zustandsvergleich, keine Namenshistorie: Wird zwischenzeitlich exakt der alte Name wiederhergestellt, erkennt der Prüfsummenvergleich diese Zwischenänderung nicht. Gleichzeitige Unternehmenszuordnung ist kein Namenskonflikt und wird vom Editor nicht überschrieben. Keine Änderung von Netzwerkbesitzer, keine Fremdbetreiber-/Clubfreigabe oder geteilten Salden. Anlage und deren bisherige Begrenzungen bleiben unverändert; keine rückwirkende Bereinigung alter Namen und keine neue Eindeutigkeitsregel.

`python3 tests/run-network-edit.py`: 28 HTTP-/Domänenprüfungen für Name/Unicode/Arrays/UTF-8, exakte Grenzen, Mandant/Ziel/CSRF/Plan, ausgeschlossene Seiteneffekte, Audit-Rollback, Escaping und zwei echte parallele Speicherprozesse. 14 Chromium-Prüfungen für Desktop/Mobil, echte Zwei-Tab-Konflikte, aktuellen Namen und Maximalnamen ohne Überlauf. Screenshots unter `/tmp/loyalty-network-edit/` visuell geprüft. Wegwerfdaten, keine echten Konten oder externen Dienste geändert.

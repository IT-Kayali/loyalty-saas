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

Keine unternehmensübergreifenden Salden oder Clubs, keine delegierten Managerkonten, keine Standort-Datensicht oder Standortberichte, keine zeitgesteuerten Rollen, keine Staff-Einladungen/OTP/MFA und keine vollständige Audit-/Compliance-Abnahme. Eigene Netzwerknamen sind jetzt separat editierbar (siehe unten). Standortnamen/-adressen sind ebenfalls getrennt editierbar (siehe unten); Unzugeordnete Netzwerke sind nach Bestätigung löschbar (siehe unten), Standorte bleiben nicht löschbar. Keine vollständige Funktionsgleichheit mit dem Referenzprodukt.

## Eigenen Netzwerknamen ändern

Organisation zeigt alle eigenen Netzwerke mit aktueller Zahl zugeordneter eigener Unternehmen und „Name bearbeiten“. Auch noch nicht zugeordnete Netzwerke sind erreichbar. Der getrennte Editor `/network/ID/edit` ändert ausschließlich den Gruppierungsnamen: 1 bis 80 UTF-8-Zeichen nach äußeren Leerzeichen, keine Steuerzeichen oder Formulararrays. HTML bleibt Text und wird bei Ausgabe escaped. Lange Namen ohne Leerzeichen umbrechen in Liste und Editor.

Netzwerk-ID, Eigentümer und Unternehmenszuordnungen bleiben gleich. Keine Verschiebung von Mitgliedern, Programmen, Karten, Salden, Standorten oder Teamrechten. Aktuelle Organisationslisten und Netzwerkauswahlen zeigen den neuen Namen beim nächsten Abruf. Keine Umbenennung alter Exporte oder Dokumente und kein Wallet-Push, Mailversand oder externer Aufruf.

Nur der Eigentümer, CSRF und Planfeature `locations`. Lesen bleibt bei fehlender Planberechtigung möglich. Ziel ist die URL, fremde zusätzliche Formular-IDs können es nicht ändern. Name und ID-Audit `network.rename` speichern atomar unter `BEGIN IMMEDIATE`. Eigentümer und Plan werden darin erneut geprüft. Ein alter Namensprüfstand überschreibt keine neuere Umbenennung. Bei Konflikt lädt die Seite den aktuellen Namen mit Hinweis, verworfene Eingaben werden nicht als Entwurf behalten.

Das ist ein Zustandsvergleich, keine Namenshistorie: Wird zwischenzeitlich exakt der alte Name wiederhergestellt, erkennt der Prüfsummenvergleich diese Zwischenänderung nicht. Gleichzeitige Unternehmenszuordnung ist kein Namenskonflikt und wird vom Editor nicht überschrieben. Keine Änderung von Netzwerkbesitzer, keine Fremdbetreiber-/Clubfreigabe oder geteilten Salden. Anlage und deren bisherige Begrenzungen bleiben unverändert; keine rückwirkende Bereinigung alter Namen und keine neue Eindeutigkeitsregel.

`python3 tests/run-network-edit.py`: 28 HTTP-/Domänenprüfungen für Name/Unicode/Arrays/UTF-8, exakte Grenzen, Mandant/Ziel/CSRF/Plan, ausgeschlossene Seiteneffekte, Audit-Rollback, Escaping und zwei echte parallele Speicherprozesse. 14 Chromium-Prüfungen für Desktop/Mobil, echte Zwei-Tab-Konflikte, aktuellen Namen und Maximalnamen ohne Überlauf. Screenshots unter `/tmp/loyalty-network-edit/` visuell geprüft. Wegwerfdaten, keine echten Konten oder externen Dienste geändert.

## Standortname und beschreibende Adresse bearbeiten

Der Standort in Organisation verlinkt „Name / Adresse bearbeiten“. `/location/ID/edit` ändert ausschließlich Name (1 bis 80 UTF-8-Zeichen) und optionale Adresse (0 bis 200), nach äußeren Leerzeichen. Keine Steuerzeichen/Arrays, HTML als Text escaped. Die Adresse ist ein beschreibendes Textfeld, keine verifizierte Anschrift, Geokodierung, Kartenposition oder Rechnungsadresse. Kein automatischer Versand, externe Adressprüfung oder Wallet-Update.

Zugehöriges Unternehmen, Standort-ID, Aktivierung, Teamzuordnung und Buchungskennungen bleiben unverändert. Aktuelle Organisationslisten und Team-Standortauswahlen lesen die neuen Texte beim nächsten Abruf. Historische Einträge behalten ihre Kennungen, aber Ansichten mit aktuellen Standortnamen können damit den neuen Namen anzeigen. Kein historischer Namens-/Adresssnapshot oder Umbenennen bereits exportierter Dokumente. Standortdatensicht und Salden bleiben wie bisher unternehmensweit.

Nur Eigentümer, CSRF und Planfeature `locations`. Fremde/zusätzliche Formular-IDs ändern das URL-Ziel nicht. Name und Adresse mit ID-Audit `location.edit` atomar unter Schreiblock, Eigentümer/Plan darin erneut geprüft. Der Vergleich umfasst Name, Adresse und feste Kennungen. Ein alter Textstand überschreibt keine neueren Texte. Konflikt lädt den aktuellen Stand mit Hinweis, keine Entwurfserhaltung oder Zusammenführung. Keine vollständige Historie: zwischenzeitliche exakte Wiederherstellung ist im Vergleich nicht erkennbar.

Aktivierung bleibt bewusst außerhalb des Textvergleichs und wird nie geschrieben. Eine parallel erfolgte Deaktivierung wird deshalb nicht rückgängig gemacht, während ein gültiger Texteditor speichern kann. Auch inaktive Standorte sind bearbeitbar, bleiben inaktiv und im Editor so beschriftet. Die Aktivierung ist im folgenden Abschnitt separat abgesichert; Anlage und SQL-Zuordnungsschutz wurden nicht umgebaut. Keine Standortlöschung oder neue Eindeutigkeitsregel, keine rückwirkende Bereinigung älterer Adressen.

`python3 tests/run-location-edit.py`: 32 HTTP-/Domänen- und 16 Chromium-Prüfungen, darunter Feldgrenzen/UTF-8/Arrays, Mandanten/Ziel/CSRF/Plan, alte Tabs, zwei parallele Speicherprozesse, unveränderte Salden/Team/Mails, Audit-Rollback, aktuelle Auswahlnamen, Deaktivierung neben Textänderung, Desktop/Mobil und lange Namen/Adressen ohne Überlauf. Screenshots unter `/tmp/loyalty-location-edit/` visuell geprüft. Nur Wegwerfdaten, keine externen Dienste oder echten Konten geändert.

## Unzugeordnetes Netzwerk entfernen

„Löschen prüfen“ in der eigenen Netzwerkliste öffnet eine getrennte private/no-store Prüfseite mit aktuellem Namen und Zahl der Zuordnungen. Reines Öffnen löscht und protokolliert nichts. Abbrechen kehrt zur Organisation zurück. Erst der exakte aktuelle Name im Bestätigungsfeld plus „Unzugeordnetes Netzwerk endgültig löschen“ entfernt die Gruppierung. Keine Wiederherstellung, kein Versand oder Rückruf.

Ein Netzwerk mit irgendeinem verknüpften Unternehmen bleibt gesperrt, auch bei direktem POST oder einer Zuordnung nach Öffnen der Prüfseite. Keine automatische Abkopplung oder Kaskade. Unternehmens-, Standort-, Mitglieder-, Team-, Karten-/Saldo- und Buchungsdaten bleiben bestehen. Bestehende Zuordnungen bei Bedarf zuerst separat und bewusst in Organisation ändern. Der Editor nimmt diese Entscheidung nicht vor.

Eigentümer, CSRF, Planfeature `locations`, exakter Name und Namensprüfstand werden kontrolliert, zuletzt unter `BEGIN IMMEDIATE`. Löschung und ID-Audit `network.delete` sind atomar. Neue Namen oder Zuordnungen blockieren einen alten Bestätigungsstand. Ein gleichzeitig gestartetes Zuordnen/Löschen kann nur eine sichere Reihenfolge gewinnen: Zuordnung zuerst sperrt Löschen, Löschen zuerst lässt keine neue Zuordnung auf die fehlende ID zu. Nach Löschung liefern alte Prüf-/Editorlinks 404. Kein neuer API-/MCP-Pfad.

Keine historische Namenskopie: zwischenzeitliche exakte Wiederherstellung desselben Namens ist im Vergleich nicht erkennbar. Keine Soft-Delete-/Archivfunktion, kein Löschbeleg im Nutzerthread, kein Schutz vor beliebigen administrativen Datenbankeingriffen. Konto-/Unternehmenslöschung und deren bestehende Regeln bleiben getrennt. Standortlöschung bleibt offen.

`python3 tests/run-network-delete.py`: 21 HTTP-/Domänenprüfungen, darunter reine Vorschau, Name/Revision, Plan/Eigentum/CSRF, feste Ziele, Zuordnung nach Vorschau, Audit-Rollback, unveränderte Unternehmens-/Saldo-/Maildaten und echtes Zuordnungs-/Lösch-Prozessrennen ohne verwaiste Zuordnung. 12 Chromium-Prüfungen für Desktop/Mobil, Abbrechen, Sperre, falsche und erfolgreiche Bestätigung. Screenshots unter `/tmp/loyalty-network-delete/` visuell geprüft. Nur lokale Wegwerfdaten.

## Expliziter Standortstatus statt blindem Umschalten

Organisation zeigt jetzt „Standort deaktivieren“ oder „Standort aktivieren“. Das Formular trägt den gewünschten Zielstatus und einen Prüfstand von Standort-ID, Unternehmens-ID und aktuellem Aktivstatus. Kein blindes `1-active` mehr. Ein unmittelbar doppelt gesendetes oder altes Formular kippt den Status nicht zurück, sondern wird mit Hinweis auf den geänderten Stand abgelehnt. Alte Formulare ohne die neuen Angaben ändern nichts, Organisation neu laden.

Nur Eigentümer mit CSRF und Planfeature `locations`. Die Funktion prüft Eigentümer/Plan/Ausgangsstatus unter `BEGIN IMMEDIATE`; Aktivstatus und ID-/Zielstatus-Audit `location.state` werden gemeinsam gespeichert oder zurückgenommen. Keine Zuordnung, Texte, Salden, Teamrechte oder Nachrichten ändern sich. Standortdeaktivierung wirkt wie bisher bei der nächsten Team-Anfrage und bei der frischen Rechteprüfung unter der Buchungstransaktion. Bereits abgeschlossene Buchungen werden nicht zurückgenommen. Reaktivierung stellt nur den Standortstatus her, aktiviert keinen separat deaktivierten Teamzugang.

Textänderungen sind unabhängig vom Statusvergleich und werden nie überschrieben. Ebenso schreibt der Texteditor keinen Aktivstatus. Der Vergleich ist keine fortlaufende Versionsnummer: Nach zwischenzeitlichem Deaktivieren und exaktem Wiederaktivieren ist derselbe Ausgangsstatus wieder vorhanden. Kein dauerhaftes Wiederholungsregister, keine vollständige Historie und kein Zeitplan. Unterbrechungsfreier Rückruf bereits laufender externer Vorgänge ist nicht zugesagt.

`python3 tests/run-location-state.py`: 19 HTTP-/Domänen- und 10 Browserprüfungen für explizite Ziele, fehlende/ungültige/altes Formularfelder, Rechte/CSRF/Plan, unveränderte Salden/Mails/Zuordnungen, Audit-Rollback, zwei parallele Deaktivierungen und echte Zwei-Tab-Konflikte Desktop/Mobil. Bestehende Organisationssuite prüft weiterhin Sitzungsentzug durch inaktiven Standort. Screenshots unter `/tmp/loyalty-location-state/` visuell geprüft. Nur lokale Wegwerfdaten.

## Wallet-Nähe (vorbereitet, nicht gerätegeprüft)

Getrennter Standorteditor mit ausdrücklicher Auswahl und bis zu zehn Geschäftskoordinaten je Unternehmen. Apple `locations`, Google-Objekt `merchantLocations`, keine Kundenortung durch die App. Signierung/Issuer und echte Geräteprüfung bleiben nötig: [WALLET-NEARBY.md](WALLET-NEARBY.md). Tests: `python3 tests/run-wallet-nearby.py`.

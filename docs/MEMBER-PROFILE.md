# Eigener Name in der Wallet

In der angemeldeten Wallet führt "Meine Namen" zu `/member/profile`. Dort steht für jeden eigenen Mitgliedseintrag ein getrenntes Formular mit Unternehmen und Mitgliedscode. Ein Name wird nicht still über alle Unternehmen vereinheitlicht. Falls historische Doppeleinträge existieren, lassen sie sich am Mitgliedscode unterscheiden.

- Namen ändern und "Name speichern". 1 bis 80 Zeichen, Rand-Leerzeichen werden entfernt. Steuerzeichen innerhalb des Namens und ungültiges UTF-8 werden abgelehnt. Ausgabe als Text maskiert, kein HTML.
- Die Mitglieds-ID muss zur angemeldeten Wallet-E-Mail gehören. Fremde oder unbekannte IDs geben 404. E-Mail, Passwort, Punkte, Karten, Einwilligung und Geburtstag werden durch zusätzliche POST-Felder nicht geändert.
- Anmeldung und CSRF erforderlich. 20 POST-Versuche pro Wallet-Konto in 15 Minuten, auch über mehrere Browser hinweg. Die eigene Namenspflege bleibt bei inaktivem Betreiber-Abo nutzbar.
- Name und Historie: Es wird nur `members.name` geändert, keine Buchung. Ansichten, die ältere Buchungen mit dem aktuellen Mitgliedsnamen verknüpfen, zeigen anschließend den neuen Namen. Das ist keine Umschreibung der Buchungsdaten und kein eigener Name je Transaktion.

## Grenzen

Kein E-Mail-Wechsel, keine Adresse/Telefonnummer, kein allgemeiner Profileditor. Passwortänderung ist separat unter [MEMBER-PASSWORD.md](MEMBER-PASSWORD.md), Geburtstag/Einwilligung bleiben bei den bisherigen Einstellungen. Keine neue Mail, kein Profil-WebHook, kein eigenes Profiländerungsprotokoll und kein automatischer Push bereits installierter Apple-/Google-Pässe. Gespeicherte Offline-Kopien werden nicht nachträglich geändert; nach erneutem Online-Speichern enthalten sie den aktuellen Stand.

Die Konto-Grenze bleibt die vorhandene Shared-Wallet-Zuordnung nach E-Mail, keine neue Identitätsprüfung. Es wird weder der Name fremder Mitglieder geändert noch ein fremdes Betreiberprofil bearbeitet. Es gibt keine echte Geräte-/Wallet-Pass-Abnahme.

## Tests

`python3 tests/run-member-profile.py`: Wegwerf-Datenbank mit mehreren eigenen Mitgliedseinträgen, fremde/unbekannte ID, CSRF, Leer-/Lang-/Steuerzeichen-Namen, getrennte Änderungen, HTML-Maskierung, unveränderte E-Mail/Hash/Sitzung/Einwilligung/Karten/Buchungen, inaktives Betreiber-Abo und Rate-Limit. Chromium Desktop/Mobil einschließlich Speichern und aktueller Wallet-Begrüßung.

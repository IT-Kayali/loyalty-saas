# Unternehmensname ändern

Auf der Unternehmensseite führt "Unternehmensname" zu `/business/ID/settings`. Der angemeldete Eigentümer kann ausschließlich den Anzeigenamen dieses Unternehmens ändern. Kein rechtlicher Firmennamen-/Rechnungsempfängerwechsel bei Stripe, PayPal oder anderen Anbietern.

## Wirkung

- 1 bis 100 UTF-8-Zeichen nach Entfernen äußerer Leerzeichen, ohne Steuerzeichen. HTML-Zeichen werden als Text gespeichert und bei Ausgabe escaped.
- Die Änderung schreibt `businesses.name`; ein Datenbanktrigger erhöht außerdem die technische `wallet_push_notice_revision`, damit alte Zustimmungen für optionale Wallet-Mitteilungen nicht unter neuer Absenderidentität weitergelten (WALLET-PUSH.md). Kunden müssen diese bei Bedarf neu bestätigen, auch nach Rückkehr zum alten Namen. Kein Versand oder Geräteupdate. Eigentümer, Verknüpfungen, Programme, Punkte-/Stempel-/Gutscheinstände, Schlüssel, Netzwerk, Standorte, Zeitzone, Farben und Designertexte bleiben unverändert.
- Neue Serverabrufe lesen den neuen Namen, wo der Unternehmensname ausgegeben wird: Dashboard, Unternehmensansicht und Wallet. Eigene Kopfzeilen oder statische Texte in Kartenlayouts behalten ihren Inhalt. Es werden keine alten Kampagnen, Belege oder bereits erzeugten Dokumente umbenannt.
- Bereits installierte Apple-/Google-Wallet-Pässe, Offlinekopien, Exporte und Ausdrucke erhalten keinen automatischen Namens-Push. Bei später neu erzeugten Pässen hängt die Anzeige weiterhin von den bestehenden Layout-/Providerregeln ab. Keine echte Geräteprüfung.
- Links, Beitrittscode, Mitgliedscodes und QR-Ziele ändern sich nicht. Es gibt keine URL-Weiterleitung oder Slug-Änderung und keinen externen Aufruf beim Speichern.

## Rechte und Konflikte

Nur der Eigentümer des über die URL bestimmten Unternehmens, CSRF und die vorhandene Planprüfung. Fremde IDs und weitere Felder im Formular werden ignoriert. Lesen bleibt bei gesperrtem Plan möglich, Schreiben nicht. Audit `business.rename` enthält ausschließlich die Business-ID.

Ein SHA-256-Vergleich des bisherigen Namens wird in einer `BEGIN IMMEDIATE`-Transaktion erneut geprüft. Ein inzwischen geänderter Name wird nicht überschrieben. Bei Konflikt lädt der Editor den aktuellen Namen mit Hinweis. Das ist keine Namenshistorie: nach exakter Wiederherstellung des alten Namens ist die Zwischenänderung nicht erkennbar. Andere zwischenzeitliche Unternehmensänderungen, zum Beispiel Designertexte, werden weder überschrieben noch als Namenskonflikt behandelt.

## Grenzen

Keine vollständigen Unternehmenseinstellungen, Rechts-/Steuerdaten, Betreiberübertragung, Löschung, Domainverwaltung, Währungseinstellung, Adressverwaltung oder neues Branding. Netzwerk/Standorte und Design bleiben über ihre vorhandenen Seiten erreichbar. Namensänderungen senden keine Nachrichten und lösen keine neuen Wallet-Updates aus.

## Tests

`python3 tests/run-business-settings.py`: Wegwerfkonten, Name/Zeichenlimit/Steuerzeichen, CSRF, Eigentümer und fixiertes Ziel, Feldvergleich ohne Nebenänderungen, Online-Anzeigen, Konflikt, Escaping, Audit, Planrechte und parallele Designeränderung. Chromium Desktop/Mobil, tatsächliche Zwei-Tab-Konflikte und Prüfung, dass eigener Designertext bestehen bleibt. Screenshots unter `/tmp/loyalty-business-settings/` visuell geprüft. Keine echten Kundenkonten oder Wallet-Geräte geändert.

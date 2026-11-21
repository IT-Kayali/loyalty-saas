# WordPress-Händlerdashboard und optionale WooCommerce-Punkte

Das Plugin funktioniert ohne WooCommerce. Im WordPress-Admin gibt es einen eigenen Menüpunkt **Loyalty**. Kunden und Karten werden live aus genau einem Unternehmen der Loyalty-Plattform gelesen, nicht als zweite Kundendatenbank in WordPress geführt.

## Installation

1. PHP 8.1+ für die Loyalty-Plattform, PHP 8.0+ und WordPress 6.0+ für das Plugin bereitstellen. Die tatsächlich geprüfte lokale WordPress-Version ist im Testbericht unten genannt. Ältere unterstützte WordPress-Versionen sind nicht einzeln abgenommen.
2. Den gesamten Ordner `integrations/woocommerce` nach `wp-content/plugins/loyalty-saas-woocommerce` kopieren. Beide PHP-Dateien gehören zusammen. Bestehende Installationen vollständig ersetzen, nicht nur die Hauptdatei.
3. In WordPress unter **Plugins** aktivieren.
4. Als WordPress-Administrator unter **Einstellungen → Loyalty Verbindung** die öffentlich erreichbare HTTPS-Basisadresse der Loyalty-Plattform und den API-Schlüssel des richtigen Unternehmens speichern. Nicht `/api/v1` anhängen. Private Netzwerkadressen, HTTP, Zugangsdaten in der URL, Query-/Fragmentteile und andere Ports als 443 werden abgelehnt. IPv4-Auflösung ist erforderlich.
5. Der Verbindungstest zeigt den Unternehmensnamen. Vor Kundenaktionen prüfen, dass es das richtige Unternehmen ist. Das Dashboard über **Loyalty** öffnen.

Die Plattform muss bereits installiert sein. Das Plugin legt weder ein SaaS-Betreiberkonto an noch verbindet es verschiedene WordPress-Verkäufer automatisch mit verschiedenen Mandanten. Eine WordPress-Site hat eine gemeinsame Verbindung zu einem Unternehmen. Nicht als Multi-Vendor-Marktplatz-Isolation verwenden.

## Bedienung und Rollen

- **Administrator (`manage_options`)**: Verbindung und Schlüssel verwalten, Dashboard bedienen.
- **Shop-Manager oder andere Rolle mit `manage_woocommerce`**: Kunden und Karten bedienen, keine Verbindungseinstellungen. WooCommerce ist dafür optional; ohne diese Rolle bedient der Administrator das Dashboard.
- Andere WordPress-Rollen bekommen keinen Zugriff. Jede Anzeige und jeder POST prüft die Rechte erneut. Formulare prüfen zusätzlich einen WordPress-Nonce. Ein Nonce allein ersetzt keine Berechtigung.
- Mitglieder nach Name, E-Mail oder Kundencode suchen, jeweils 50 Datensätze mit Cursor-Paginierung.
- Aktuelle Mitglieder-/Kartenzahlen, Punktesalden und offene Stempelbelohnungen sehen. Das sind keine Umsatz- oder Zeitraumstatistiken, inaktive Karten zählen mit.
- Mitglieder ohne Marketingeinwilligung und ohne E-Mail-Versand anlegen. Karten werden für aktive Programme angelegt. Pass-/Gutscheinkarten sind die bestehenden einfachen Karten, kein kostenpflichtiger Passverkauf.
- Namen ändern. E-Mail, Passwörter und Einwilligungen werden nicht verändert. Für Identitäts-/Datenschutzvorgänge die Loyalty-App verwenden.
- Punkte auf eine konkrete aktive Punktekarte buchen. Aktive Bonuszeiten gelten. Einen Stempel vergeben, mit Rollover-Belohnung. Punkteprämien und offene Stempelbelohnungen ausdrücklich bestätigt einlösen.
- Gutscheincodes, verkaufte Pässe, Rückbuchungen, Kundendatenexport und Löschung bleiben in der Loyalty-App.

**Grenze dieser Etappe:** Manuelle Merchant-API-Aktionen aktualisieren Salden und Transaktionsjournal atomar. Sie lösen derzeit keine zusätzliche Achievement-Auswertung, ausgehenden Webhooks, E-Mail-Automation oder proaktiven Apple-/Google-Wallet-Push aus. Die Webansicht liest den aktuellen Saldo. Diese Folgewirkungen sind keine zugesagte Funktion des WordPress-Dashboards.

## Schlüssel und Daten

Der gespeicherte Schlüssel wird nicht in Formulare oder JavaScript ausgegeben. Das Passwortfeld bleibt leer: leer speichern behält den vorhandenen Schlüssel, die Löschoption entfernt ihn ausdrücklich. Eine andere Basisadresse verlangt einen neuen Schlüssel oder ausdrückliches Löschen. Requests laufen serverseitig über WordPress Safe HTTP mit TLS-Prüfung, Größenlimit und ohne Redirects. Die Plattform nutzt einen unternehmensweiten API-Schlüssel, keine eingeschränkten Pro-Benutzer-Tokens. Deshalb nur vertrauenswürdigen WordPress-Betreibern und Shop-Managern Zugriff geben. Der Schlüssel liegt in der WordPress-Options-Datenbank, nicht verschlüsselt; Datenbank und Backups schützen. Rotation erfolgt in der Loyalty-App.

Unbestätigte Änderungen speichern vorübergehend Ziel, Vorgangskennung und Eingabefelder in einer nicht automatisch geladenen WordPress-Option pro Benutzer. Das kann Namen und E-Mail enthalten. Nach bestätigtem Erfolg oder eindeutigem API-Fehler wird diese Option gelöscht. Bei unklarem Ergebnis bleibt sie absichtlich ohne automatische Frist bestehen, bis derselbe Vorgang abgeglichen ist. Keine allgemeine Kundendatenkopie, aber personenbezogene Vorgangsdaten. Dies bei Datenschutz, Aufbewahrung und Backups berücksichtigen. Deaktivierung entfernt keine Einstellungen oder unbestätigten Vorgänge.

## Verbindungsabbruch und Wiederholschutz

Jede Änderung hat eine zufällige Vorgangskennung. Die Plattform speichert Ergebnis und Datenfingerabdruck zusammen mit der Buchung in einer SQLite-Schreibtransaktion. Derselbe Vorgang liefert dasselbe Ergebnis; eine andere Eingabe mit derselben Kennung wird abgelehnt. Eine fehlende Antwort ist kein sicherer Fehlschlag.

Bei **Ungeklärter Vorgang** keine neue Buchung beginnen. **Denselben Vorgang sicher erneut prüfen** benutzen. Das gilt auch, wenn die erste Antwort erst nach erfolgreicher Buchung verlorenging. Neue Änderungen dieses WordPress-Benutzers bleiben bis zum Abgleich gesperrt. Ein anderer Benutzer hat eigene Vorgänge; das ist keine globale Kassensperre. Verschiedene neue Vorgangskennungen sind verschiedene Buchungen.

Vor Rotation, URL-Wechsel oder Benutzerentfernung offene Vorgänge klären. Eine andere Verbindung wird beim Retry abgelehnt. Bei bereits geänderter Verbindung muss der Administrator den ursprünglichen Unternehmensstand abgleichen und die ursprüngliche Verbindung für den Retry wiederherstellen. Unbestätigte Daten nicht einfach löschen und den Betrag nochmals buchen.

Die Plattform behält Wiederholungsbelege ohne automatische Ablaufzeit. Bei Kundenlöschung werden referenzierte Belege zu anonymen Sperrbelegen: keine Wiederanlage durch alten Create-Retry und keine gespeicherten Namen/E-Mails im Antwortbeleg. Übrig bleiben zufällige Vorgangskennung, Unternehmensbezug und Zeitpunkt. Datenbank und WordPress-Backups haben eigene Aufbewahrungsregeln.

## Optional: WooCommerce

Mit WooCommerce bleiben die bestehenden Hooks für **Processing** und **Completed** aktiv. Die Plattform findet Mitglieder per E-Mail oder legt sie an. Punkte aus `Punkte je Cent Umsatz` werden mit dem Bestellbeleg `wc-order-ID` dedupliziert. Bestellmeta `_loyalty_saas_credited` und Bestellnotizen bleiben erhalten.

Die Referenz setzt eine eindeutige WooCommerce-Bestellnummer pro verbundenem Unternehmen voraus. Nicht mehrere unabhängige Shops mit überlappenden Bestellnummern an denselben Schlüssel hängen. Keine automatische Refund-Rückbuchung, kein WordPress-Nutzerimport und kein Multisite-/Multi-Vendor-Routing.

## Tests und Grenzen

- `PHP_BIN=/pfad/php python tests/run-merchant.py`: isolierte Domänenprüfungen und echter PHP-HTTP-Server, inklusive Tenantgrenzen, Cursor, Wildcards, Planrechten, Parallelverarbeitung und Replay.
- Für eine echte lokale WordPress-Testinstallation zwei offizielle Archive herunterladen: WordPress und SQLite Database Integration. Nur für Tests, nicht als Produktions-Datenbankempfehlung.
- `PHP_BIN=/pfad/php TEST_OUTPUT=/tmp/wordpress-images python tests/run-wordpress.py --wordpress-zip /pfad/wordpress.zip --sqlite-zip /pfad/sqlite-database-integration.zip`
- Der Runner erstellt ausschließlich eine neue temporäre Installation. Ports 8099/8100 müssen frei sein. Chromium/Playwright müssen installiert sein. Er blockiert E-Mail und externe WordPress-Requests. Ein nur dort geladenes MU-Testplugin leitet die feste Testadresse intern zur lokalen Loyalty-Instanz um. Es darf nie auf eine echte Site kopiert werden.
- Lokal geprüft: WordPress 7.1.5 mit SQLite Database Integration 1.8.0, ohne WooCommerce aktiviert. Echte WordPress-Rollen, Nonces, Adminseiten, Formulare und mobile Ansichten. Antwortverlust nach erfolgreicher Buchung und Retry ohne Doppelbuchung geprüft.
- WooCommerce-Bestellobjekt nur im Regressionstest nachgebildet. Keine echte WooCommerce-Installation, kein öffentliches HTTPS-Ende-zu-Ende, keine echten Kundendaten oder Live-Bestellung getestet. WordPress 6.x, MySQL, Multisite, Hosting-Proxies und andere Sicherheitsplugins sind gesondert abzunehmen.

Grundlagen: https://developer.wordpress.org/reference/functions/wp_safe_remote_request/ · https://developer.wordpress.org/reference/functions/check_admin_referer/ · https://developer.wordpress.org/reference/functions/current_user_can/

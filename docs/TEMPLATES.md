# Programm-Vorlagen nach Branche

Beim Anlegen eines Programms (Seite des Unternehmens, Bereich „Aus Vorlage anlegen“) gibt es fertige Programme für typische Branchen. Ein Klick auf „Übernehmen“ legt ein normales Programm an. Danach lässt sich alles ändern: Texte, Prämien, Stufen, Bonus-Zeiten und Design.

Die Vorlagen orientieren sich an den Beispielen der Referenz (https://rewardloyalty.co/use-cases): Café und Restaurant mit Prämienleiter, Salon mit 6er-Stempelkarte und Vorauskarte, Food-Truck mit einer Stempelkarte. Alle Beträge und Ziele sind Vorschläge, keine Preisvorgabe.

## Enthaltene Vorlagen

| Branche | Vorlage | Typ | Inhalt |
|---|---|---|---|
| Restaurant | Stammgast-Karte | Stempel | 10 Stempel, Dessert oder Vorspeise |
| Restaurant | Genuss-Punkte | Punkte | 5 Prämien (60 bis 450 Punkte), Silber und Gold, doppelte Punkte Di bis Do 14 bis 17 Uhr |
| Café | Kaffeekarte | Stempel | 10 Stempel, 1 Getränk gratis |
| Café | 10er-Getränkekarte | Mehrfachkarte | 10 Besuche im Voraus |
| Bäckerei | Frühstücksstempel | Stempel | 8 Stempel |
| Pizzeria | Pizza-Karte | Stempel | 10 Stempel |
| Eisdiele | Eiskarte | Stempel | 8 Stempel |
| Bar | Stammgast-Punkte | Punkte | 3 Prämien, Happy Hour Fr 17 bis 19 Uhr doppelt |
| Imbisswagen | Imbiss-Stempelkarte | Stempel | 8 Stempel, Hauptgericht gratis |
| Grillhähnchen | Hähnchen-Karte | Stempel | 10 Stempel, halbes Hähnchen gratis |
| Grillhähnchen | Hähnchen-Punkte | Punkte | 4 Prämien, Mittagszeit Fr doppelt |
| Döner | Döner-Karte | Stempel | 10 Stempel |
| Friseur | Stammkunden-Karte | Stempel | 6 Stempel, Waschen und Föhnen |
| Friseur | 5er-Haarschnittkarte | Mehrfachkarte | 5 Besuche im Voraus |
| Barbershop | Barber-Karte | Stempel | 8 Stempel |
| Nagelstudio | Nagelkarte | Stempel | 6 Stempel |
| Kosmetik | 3er-Behandlungskarte | Mehrfachkarte | 3 Besuche im Voraus |
| Autowäsche | 5er-Waschkarte | Mehrfachkarte | 5 Besuche im Voraus |
| Studio und Kurse | 10er-Karte | Mehrfachkarte | 10 Besuche |
| Boutique | Stil-Punkte | Punkte | 3 Prämien, 2 Stufen |
| Blumenladen | Blüten-Punkte | Punkte | 3 Prämien |

Jede Vorlage hat einen Hinweis für das Personal, wann ein Stempel oder Punkte gebucht werden (zum Beispiel „ein Stempel je Hähnchen“).

## Technik

- Definition: `app/inc/templates.php` (`program_templates()`). Neue Vorlage = ein Eintrag im Array.
- Anlegen: `template_apply()` in einer Transaktion (Programm, Prämien, Stufen, Bonus-Zeiten), Route `POST /program/template` mit CSRF, Besitzprüfung und Planlimit für Programme.
- Design: Farben und Stempel-Icon je Vorlage. Neue Icons (🍗 🥙 🍞 🍦 🧼 💅 🍝 🍺) stehen auch im Programmdesign zur Wahl (`stamp_icons()` in `app/bootstrap.php`).
- Der Name lässt sich optional beim Anlegen überschreiben (Feld `name`).

## Grenzen

- Preise und Prämien sind Beispiele und müssen zum Betrieb passen. Es gibt keine Marktdaten dahinter.
- Vorlagen sind nur Deutsch. Nach dem Anlegen sind die Texte normale Programmdaten.
- Mehrfachkarten tragen nur die Besuchszahl. Preis und Gültigkeit stellt der Betrieb im Programm selbst ein.
- Kein Vorlagen-Editor und kein Teilen eigener Vorlagen. Kein MCP-Werkzeug dafür.
- Bonus-Zeiten richten sich nach der Serverzeit.

## Test

`python tests/run-templates.py` legt jede Vorlage per HTTP an und prüft Typ, Ziel, Prämien, Stufen, Bonus-Zeiten und Design, dazu Fehlerfälle (unbekannte Vorlage, ohne CSRF, ohne Login) und Browser-Läufe mit Screenshots (Desktop und Mobil).

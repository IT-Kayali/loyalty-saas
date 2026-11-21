# Karten-Designer: gestaltete Webkarte mit Vorder- und Rückseite

Unter Karten-Design (Unternehmen oder Programm) gibt es den Abschnitt "Gestaltete Karte (Vorder- und Rückseite)". Er gilt für die **Webkarte**: Wallet-Ansicht des Kunden und die öffentliche Karte `/c/CODE`.

## Was einstellbar ist
Je Seite (Vorderseite, Rückseite):
- Hintergrundbild mit Farb-Overlay: Overlay-Farbe frei wählbar (oder wie Kartenfarbe), Deckkraft 0 bis 100. Vorderseite nutzt das Bild und die Deckkraft aus "Hintergrund" im Designer, die Rückseite hat ein eigenes Bild (PNG/JPEG/WebP, wie alle Uploads neu als PNG gespeichert, max. 2 MB).
- Fünf Elemente, jeweils ein- und ausblendbar und positionierbar (Links/Oben in Prozent der Karte, in der Vorschau auch per Ziehen):
  Überschrift (eigener Text, leer = Programm- bzw. Firmenname), Text, Prämie (Prämientext bzw. Punkte, Nutzungen oder Besuche je nach Programmtyp), Stempel-Kreise (Größe 4 bis 16) und QR-Code (Größe 16 bis 48).
- Der QR-Code enthält den persönlichen Link des Mitglieds (`/c/CODE`), genau wie der QR unter der Karte, und ist für den Scanner gedacht.
- Die Schrift skaliert mit der Kartenbreite (Container-Einheiten), das Format ist fest 1,586:1 wie eine Bankkarte. Mobil und Desktop zeigen dasselbe Layout.
- Die Vorschau im Designer rendert mit derselben Funktion wie die echte Karte, mit Beispieldaten.

## Einschalten
Der Haken "Gestaltete Karte verwenden" ist standardmäßig aus. Dann bleibt die bisherige Karte unverändert. Ein Programmdesign hat Vorrang vor dem Unternehmensdesign.

## Technik
`app/inc/cardlayout.php` (`design_layout`, `layout_apply_post`, `card_faces_html`, `layout_form_html`), Speicherung im JSON `card_design.layout`, Vorschau und Umschalter in `public/assets/app.js`. Alle Werte werden serverseitig begrenzt, Texte maskiert. Tests: `python tests/run-cardlayout.py`.

## Grenzen
- Gilt nur für die Webkarte. **Apple-Wallet- und Google-Wallet-Pässe haben feste Layouts** und übernehmen nur Farben, Logo und Stempel-Streifen wie bisher, nicht die freie Positionierung oder die Rückseite.
- Positionen sind Prozentwerte der Kartenfläche mit oberer linker Ecke, keine Ebenen und keine Drehung. Der Designer warnt bei Überschneidungen oder Elementen außerhalb der Kartenfläche. Stempel und QR werden nicht automatisch verschoben; solche Konflikte müssen im Designer korrigiert werden.
- Eine Karte pro Programm, die Stempel-Kreise erscheinen nur bei Stempelprogrammen. Der Kreis-Stil (Bild, Emoji) kommt weiter aus dem bisherigen Designer.
- Kein Schriftartenwechsel, keine Schriftgröße je Element, keine Bilder als freie Elemente.
- Nicht mit echten Geräten oder QR-Scannern getestet, nur Browser-Tests (Desktop und Mobil, inklusive Ziehen mit der Maus). Touch-Ziehen ist nicht gesondert getestet.
- Die Wallet-Pässe von Apple und Google zeigen den QR-Code in ihrem eigenen Format.

## Schutz vor QR-Überdeckung (Webkarte)
- Neue Standard-Rückseite: Überschrift oben links, Hinweis darunter, QR weiter unten rechts (Links 64, Oben 42, Größe 30). Bereits gespeicherte Positionen werden nicht still umgeschrieben.
- Im Browser begrenzen Textfelder ihre Fläche vor dem QR, dem nächsten Textfeld und dem Kartenrand. Lange Texte umbrechen; reicht der Platz nicht, werden ganze Zeilen gekürzt. Bei völlig unbrauchbaren Positionen kann ein Textfeld ganz verschwinden. Dann im Designer mehr Platz schaffen.
- Die Warnung berücksichtigt die ursprünglichen Elementflächen vor der Textkürzung, erscheint live nach Eingabe, Ziehen und Seitenwechsel und verschwindet nach Korrektur. Sie blockiert das Speichern nicht. Nur die sichtbare Kartenseite wird geprüft.
- Der Schutz läuft auch für bereits gespeicherte Designs in Wallet und öffentlicher Karte. Er benötigt JavaScript wie die Live-Vorschau. QR-Inhalt und gewählte QR-Größe bleiben dabei unverändert.
- Automatisiert geprüft bei 1280, 390 und 320 Pixeln Browserbreite, einschließlich alter überlappender Positionen mit langen Texten. Kein Test auf echten Geräten oder mit physischem Scanner.

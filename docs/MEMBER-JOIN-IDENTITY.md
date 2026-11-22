# Öffentlicher Beitritt und bestehende Wallet-Identitäten

Stand: 22.11.2026. Dies ist eine eng begrenzte Schutzmaßnahme für den öffentlichen Beitritt, keine vollständige Sicherheitsfreigabe der gemeinsamen Wallet-Identität.

## Verhalten

- Eine bisher unbekannte, nicht leere E-Mail kann eine neue Mitgliedschaft mit eigener Wallet anlegen.
- Sobald bereits irgendeine Mitgliedschaft dieselbe normalisierte E-Mail verwendet, legt der öffentliche Beitritt keine zusätzliche Mitgliedschaft an und meldet den Besucher nicht in der Wallet an. Groß-/Kleinschreibung und äußere Leerzeichen werden berücksichtigt.
- Ein Beitritt ohne E-Mail führt weiterhin nur zur einzelnen neu angelegten Kundenkarte, nicht zu einer E-Mail-Wallet.
- Die bestehende CSRF- und Ratenbegrenzung bleibt unverändert.

Die Existenzprüfung ist Bestandteil derselben SQL-Anweisung wie das Einfügen. Es gibt keine neue Tabelle, keinen Hintergrundauftrag, keinen Provider-Aufruf und keine Bereitstellungsänderung.

## Bewusste vorläufige Einschränkung

Auch berechtigte Bestandskunden können über den anonymen Beitritt vorläufig keine weitere Mitgliedschaft unter derselben E-Mail verknüpfen. Ein frei eingegebener Name, eine E-Mail und ein neu gewähltes Passwort sind kein Nachweis für eine bereits bestehende Wallet-Identität. Eine spätere Verknüpfung braucht einen gesondert geprüften Identitätsnachweis.

Der Hinweis auf Wallet-Login oder Passwort-Hilfe ist keine automatische Verknüpfung und kein Versprechen, dass dadurch eine neue Mitgliedschaft entsteht. Für die Passwort-Hilfe muss der E-Mail-Versand korrekt eingerichtet sein.

## Nachweis und Grenzen

`PHP_BIN=php python3 tests/run-member-join-identity.py` verwendet nur künstliche Daten in einer Wegwerfinstallation. Der gleiche eng begrenzte Beitrittsfall scheitert auf dem vorherigen Stand an der ersten Schutzbehauptung und besteht nach der Korrektur. Geprüft werden insbesondere:

- keine Offenlegung des bestehenden künstlichen Mitgliedsnamens;
- keine zusätzliche Mitgliedszeile und keine Änderung bestehender Mitgliedschaften oder Karten;
- keine neu erzeugte Mitgliedssitzung nach dem abgelehnten Beitritt;
- unveränderte normale Beitritte mit neuer oder ohne E-Mail;
- dieselbe Ablehnung im Browser bei 1280, 390 und 320 Pixeln.

Diese Änderung widerruft keine bereits bestehenden Sitzungen und bereinigt keine früher angelegten Mitgliedszeilen. Andere Wege zur gemeinsamen Wallet-Identität sind damit nicht vollständig bewertet oder freigegeben. Eine vollständige Absicherung aller Identitätswege ist ein gesonderter offener Punkt. Bis dahin nicht als Freigabe für echte Kundendaten behandeln.

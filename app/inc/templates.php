<?php
declare(strict_types=1);
/**
 * Fertige Programm-Vorlagen nach Branche. Beträge sind Vorschläge zum Anpassen, keine Preisvorgabe.
 * Eine Vorlage legt ein normales Programm an (Stempel, Punkte oder Mehrfachkarte) samt Prämien,
 * Stufen, Bonus-Zeiten und Kartendesign. Danach ist alles wie ein selbst angelegtes Programm editierbar.
 */
const TEMPLATE_GROUPS = ['gastro' => 'Restaurant & Café', 'street' => 'Imbiss & Street Food', 'beauty' => 'Friseur & Beauty', 'service' => 'Dienstleistung & Sport', 'retail' => 'Handel'];

function program_templates(): array
{
    static $t = null;
    if ($t !== null) return $t;
    $s = fn(string $group, string $label, string $name, string $desc, int $n, string $reward, string $icon, array $d, string $staff): array =>
        ['group' => $group, 'label' => $label, 'type' => 'stamps', 'name' => $name, 'description' => $desc, 'stamps' => $n, 'reward' => $reward, 'design' => ['stamp_icon' => $icon] + $d, 'staff' => $staff];
    $p = fn(string $group, string $label, string $name, string $desc, array $rewards, array $tiers, array $bonus, string $icon, array $d, string $staff): array =>
        ['group' => $group, 'label' => $label, 'type' => 'points', 'name' => $name, 'description' => $desc, 'rewards' => $rewards, 'tiers' => $tiers, 'bonus' => $bonus, 'design' => ['stamp_icon' => $icon] + $d, 'staff' => $staff];
    $m = fn(string $group, string $label, string $name, string $desc, int $visits, string $icon, array $d, string $staff): array =>
        ['group' => $group, 'label' => $label, 'type' => 'pass', 'name' => $name, 'description' => $desc, 'visits' => $visits, 'design' => ['stamp_icon' => $icon] + $d, 'staff' => $staff];
    $warm = ['bg_color' => '#2B1D16', 'text_color' => '#FBF3E8', 'accent_color' => '#D9822B'];
    $green = ['bg_color' => '#14291F', 'text_color' => '#F2F7F1', 'accent_color' => '#4FA56B'];
    $red = ['bg_color' => '#2A1414', 'text_color' => '#FBF1EE', 'accent_color' => '#E4572E'];
    $ink = ['bg_color' => '#1C1A17', 'text_color' => '#FAF7F2', 'accent_color' => '#C8A24A'];
    $rose = ['bg_color' => '#2A1A22', 'text_color' => '#FBF2F6', 'accent_color' => '#E0699A'];
    $blue = ['bg_color' => '#14212E', 'text_color' => '#F1F6FB', 'accent_color' => '#3F8FD2'];
    $t = [
        'restaurant-stempel' => $s('gastro', 'Restaurant: Stammgast-Karte', 'Stammgast-Karte', 'Zehn Besuche, dann geht das Dessert oder die Vorspeise aufs Haus.', 10, 'Dessert oder Vorspeise gratis', '🍝', $warm, 'Ein Stempel pro Besuch und Tisch ab 15 € Verzehr, am Ende der Rechnung.'),
        'restaurant-punkte' => $p('gastro', 'Restaurant: Genuss-Punkte', 'Genuss-Punkte', 'Ein Punkt je 1 € Umsatz. Löse Dessert, Aperitif oder ein Menü für zwei ein.',
            [['Dessert nach Wahl', 60], ['Aperitif', 80], ['Vorspeise', 120], ['Hauptgericht bis 18 €', 250], ['Menü für zwei', 450]],
            [['Silber', 300, 'Aperitif zum Geburtstag'], ['Gold', 1000, 'Bevorzugte Tischreservierung']], [[2, 14, 17, 2], [3, 14, 17, 2], [4, 14, 17, 2]], '🍝', $warm,
            'Beim Bezahlen den Rechnungsbetrag in vollen Euro als Punkte buchen. Ohne Getränke-Pauschalen und Gutscheinkäufe. Ruhige Zeiten Di bis Do 14 bis 17 Uhr zählen doppelt.'),
        'cafe-stempel' => $s('gastro', 'Café: Kaffeekarte', 'Kaffeekarte', 'Zehn Getränke sammeln, das nächste schenken wir dir.', 10, '1 Getränk gratis', '☕', $warm, 'Ein Stempel je Getränk ab 3 € Bestellwert, höchstens ein Stempel pro Besuch.'),
        'cafe-10er' => $m('gastro', 'Café: 10er-Getränkekarte', '10er-Getränkekarte', 'Zehn Getränke im Voraus bezahlen und mit einer Karte abholen.', 10, '☕', $warm, 'Beim Verkauf den bezahlten Betrag erfassen, pro Getränk einen Besuch abbuchen. Preis selbst festlegen, zum Beispiel neun statt zehn Getränke.'),
        'baeckerei-stempel' => $s('gastro', 'Bäckerei: Frühstücksstempel', 'Frühstücksstempel', 'Acht Stempel sammeln, dann gibt es ein Frühstücksset gratis.', 8, 'Frühstücksset gratis', '🍞', $warm, 'Ein Stempel pro Einkauf ab 5 €.'),
        'pizzeria-stempel' => $s('gastro', 'Pizzeria: Pizza-Karte', 'Pizza-Karte', 'Zehn Pizzen bestellen, die nächste ist gratis.', 10, '1 Pizza gratis (bis 12 €)', '🍕', $red, 'Ein Stempel pro Pizza vor Ort, zur Abholung oder Lieferung. Gratis-Pizza zählt nicht.'),
        'eisdiele-stempel' => $s('gastro', 'Eisdiele: Eiskarte', 'Eiskarte', 'Acht Becher oder Waffeln sammeln, dann gibt es eine Kugel extra.', 8, 'Eine Kugel Eis gratis', '🍦', $rose, 'Ein Stempel je Becher oder Waffel mit mindestens zwei Kugeln.'),
        'bar-punkte' => $p('gastro', 'Bar: Stammgast-Punkte', 'Stammgast-Punkte', 'Ein Punkt je 1 € auf deine Rechnung. Sammle für Drinks und Snacks.',
            [['Softdrink oder Bier 0,3', 50], ['Cocktail nach Wahl', 120], ['Snack-Platte', 200]], [['Stammgast', 300, 'Ein Drink zum Geburtstag']], [[5, 17, 19, 2]], '🍺', $ink,
            'Rechnungsbetrag in vollen Euro als Punkte buchen. Happy Hour am Freitag 17 bis 19 Uhr zählt doppelt.'),
        'imbisswagen-stempel' => $s('street', 'Imbisswagen: Stempelkarte', 'Imbiss-Stempelkarte', 'Acht Portionen essen, die neunte ist gratis. Karte im Handy, kein Papier.', 8, 'Hauptgericht gratis (bis 8 €)', '🥙', $red, 'Ein Stempel je bezahlter Hauptportion ab 5 €. QR-Code am Ausgabefenster aufkleben, Kunden scannen zum Beitritt.'),
        'grillhaehnchen-stempel' => $s('street', 'Grillhähnchen: Hähnchen-Karte', 'Hähnchen-Karte', 'Zehn Mal Grillhähnchen, das nächste halbe Hähnchen schenken wir dir.', 10, 'Halbes Hähnchen gratis', '🍗', $red, 'Ein Stempel je halbem oder ganzem Hähnchen. Menüs mit Beilage zählen als ein Stempel.'),
        'grillhaehnchen-punkte' => $p('street', 'Grillhähnchen: Hähnchen-Punkte', 'Hähnchen-Punkte', 'Ein Punkt je 1 € Umsatz. Tausche Punkte gegen Beilagen, Getränke und Hähnchen.',
            [['Portion Pommes', 20], ['Getränk 0,5', 25], ['Halbes Hähnchen', 90], ['Ganzes Hähnchen', 150]], [], [[5, 11, 14, 2]], '🍗', $red,
            'Umsatz in vollen Euro als Punkte buchen. Mittagszeit am Freitag 11 bis 14 Uhr zählt doppelt.'),
        'doener-stempel' => $s('street', 'Döner und Kebab: Stempelkarte', 'Döner-Karte', 'Zehn Mal Hunger gestillt, der nächste Döner geht auf uns.', 10, 'Döner gratis', '🥙', $green, 'Ein Stempel je Döner oder Dürüm. Mindestens 5 €, Gratis-Döner zählt nicht.'),
        'friseur-stempel' => $s('beauty', 'Friseur: Stammkunden-Karte', 'Stammkunden-Karte', 'Sechs Besuche sammeln, dann gibt es Waschen und Föhnen gratis.', 6, 'Waschen und Föhnen gratis', '✂️', $rose, 'Ein Stempel pro bezahlter Behandlung ab 25 €, nach der Zahlung.'),
        'friseur-5er' => $m('beauty', 'Friseur: 5er-Haarschnittkarte', '5er-Haarschnittkarte', 'Fünf Haarschnitte im Voraus bezahlen und einen sparen.', 5, '✂️', $rose, 'Beim Verkauf bezahlten Betrag erfassen (zum Beispiel vier Schnitte bezahlen), je Termin einen Besuch abbuchen.'),
        'barber-stempel' => $s('beauty', 'Barbershop: Barber-Karte', 'Barber-Karte', 'Acht Mal frisch, dann gibt es die Bartpflege gratis.', 8, 'Bartpflege gratis', '✂️', $ink, 'Ein Stempel je Haarschnitt oder Bartservice, nach der Zahlung.'),
        'nagelstudio-stempel' => $s('beauty', 'Nagelstudio: Nagelkarte', 'Nagelkarte', 'Sechs Termine, dann gibt es Nail-Art als Zugabe.', 6, 'Nail-Art Zugabe (bis 15 €)', '💅', $rose, 'Ein Stempel je abgeschlossener Behandlung. Terminabsagen zählen nicht.'),
        'kosmetik-3er' => $m('beauty', 'Kosmetik und Spa: 3er-Behandlungskarte', '3er-Behandlungskarte', 'Drei Behandlungen im Voraus buchen und mit einer Karte nutzen.', 3, '🌿', $green, 'Beim Verkauf Betrag erfassen. Je Termin einen Besuch abbuchen.'),
        'autowaesche-5er' => $m('service', 'Autowäsche: 5er-Waschkarte', '5er-Waschkarte', 'Fünf Wäschen im Voraus bezahlen, jede Wäsche wird an der Anlage abgebucht.', 5, '🧼', $blue, 'Je Wäsche einen Besuch abbuchen. Programm und Preis selbst festlegen.'),
        'fitness-10er' => $m('service', 'Studio und Kurse: 10er-Karte', '10er-Karte', 'Zehn Kursbesuche oder Trainings, flexibel einlösbar.', 10, '🏆', $blue, 'Am Empfang je Besuch einen Besuch abbuchen. Gültigkeit und Preis selbst regeln.'),
        'boutique-punkte' => $p('retail', 'Boutique: Stil-Punkte', 'Stil-Punkte', 'Ein Punkt je 1 € Einkauf. Mit Silber und Gold gibt es mehr Vorteile.',
            [['5 € Gutschein', 100], ['15 € Gutschein', 280], ['Styling-Beratung', 150]], [['Silber', 300, 'Vorab-Einladung zu Sale-Tagen'], ['Gold', 1000, 'Kostenlose Änderungen und Einkaufsvorsprung']], [], '💎', $ink,
            'Einkaufswert in vollen Euro als Punkte buchen, ohne Gutscheinkäufe und Retouren.'),
        'blumen-punkte' => $p('retail', 'Blumenladen: Blüten-Punkte', 'Blüten-Punkte', 'Sammle ein Punkt je 1 € und hol dir kleine Extras für Zuhause.',
            [['Einzelne Rose', 30], ['Kleiner Strauß', 80], ['Pflanze bis 15 €', 140]], [], [], '🌿', $green,
            'Einkaufswert in vollen Euro als Punkte buchen, Trauerfloristik und Bestellungen für Firmen ausnehmen.'),
    ];
    return $t;
}

function template_summary(array $t): string
{
    return match ($t['type']) {
        'stamps' => $t['stamps'] . ' Stempel · ' . $t['reward'],
        'points' => count($t['rewards']) . ' Prämien ab ' . min(array_column($t['rewards'], 1)) . ' Punkten' . ($t['tiers'] ? ' · ' . count($t['tiers']) . ' Stufen' : '') . ($t['bonus'] ? ' · Bonus-Zeit' : ''),
        default => $t['visits'] . ' Besuche im Voraus',
    };
}

/** Legt das Programm samt Zubehör in einer Transaktion an. Liefert die Programm-ID. */
function template_apply(array $business, string $key, string $name = ''): int
{
    $t = program_templates()[$key] ?? null;
    if (!$t) throw new InvalidArgumentException('Unbekannte Vorlage.');
    $name = trim($name) !== '' ? trim($name) : $t['name'];
    if (mb_strlen($name) > 80) throw new InvalidArgumentException('Name höchstens 80 Zeichen.');
    $d = array_intersect_key($t['design'], design_defaults());
    if (!in_array($d['stamp_icon'] ?? '', stamp_icons(), true)) $d['stamp_icon'] = '';
    $db = db();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $db->prepare('INSERT INTO programs (business_id,type,name,description,stamps_required,reward_text,pass_visits,card_design) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$business['id'], $t['type'], $name, $t['description'], (int)($t['stamps'] ?? 6), (string)($t['reward'] ?? ''), (int)($t['visits'] ?? 10), json_encode($d, JSON_UNESCAPED_UNICODE)]);
        $id = (int)$db->lastInsertId();
        foreach ($t['rewards'] ?? [] as [$rn, $cost]) $db->prepare('INSERT INTO rewards (program_id,name,points_cost) VALUES (?,?,?)')->execute([$id, $rn, $cost]);
        foreach ($t['tiers'] ?? [] as [$tn, $min, $benefit]) $db->prepare('INSERT INTO tiers (program_id,name,min_points,benefit) VALUES (?,?,?,?)')->execute([$id, $tn, $min, $benefit]);
        foreach ($t['bonus'] ?? [] as [$wd, $from, $to, $mult]) $db->prepare('INSERT INTO bonus_hours (program_id,weekday,start_hour,end_hour,multiplier) VALUES (?,?,?,?,?)')->execute([$id, $wd, $from, $to, $mult]);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        try { $db->exec('ROLLBACK'); } catch (Throwable) {}
        throw $e;
    }
    return $id;
}

function route_program_template(): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], (int)($_POST['business_id'] ?? 0));
    if (!$b) { flash(t('Ungültige Eingabe.'), 'err'); redirect('/dashboard'); }
    try {
        if ($e = plan_policy((int)$u['id'], '', 'programs')) throw new InvalidArgumentException($e);
        $id = template_apply($b, (string)($_POST['template'] ?? ''), (string)($_POST['name'] ?? ''));
    } catch (InvalidArgumentException $e) {
        flash($e->getMessage(), 'err'); redirect('/business/' . (int)$b['id']);
    }
    audit_log((int)$u['id'], 'program.template', ['template' => (string)$_POST['template'], 'program_id' => $id]);
    flash('Programm aus Vorlage angelegt. Prüfe Prämien, Texte und Design und passe alles an.');
    redirect('/program/' . $id);
}

function templates_panel_html(int $businessId): string
{
    $h = '<details class="panel"><summary>' . h(t('Aus Vorlage anlegen')) . '</summary><p class="muted">Fertige Programme für typische Branchen mit Prämien, Stufen und Design. Beträge sind Vorschläge. Nach dem Anlegen kannst du alles ändern.</p>';
    foreach (TEMPLATE_GROUPS as $g => $glabel) {
        $h .= '<h3>' . h($glabel) . '</h3><div class="template-list">';
        foreach (program_templates() as $key => $tpl) {
            if ($tpl['group'] !== $g) continue;
            $h .= '<form method="post" action="/program/template" class="template-card">' . csrf_field() . '<input type="hidden" name="business_id" value="' . $businessId . '"><input type="hidden" name="template" value="' . h($key) . '">'
                . '<strong>' . h($tpl['label']) . '</strong><span class="muted">' . h(template_summary($tpl)) . '</span><span class="muted">' . h($tpl['staff']) . '</span><button class="btn ghost">Übernehmen</button></form>';
        }
        $h .= '</div>';
    }
    return $h . '</details>';
}

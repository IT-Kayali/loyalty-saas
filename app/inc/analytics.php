<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ */
/* Etappe 7: Analytics-Dashboard                                       */
/* Doku: "Analytics" - Kennzahlen, Trends, Programm-Performance        */
/* ------------------------------------------------------------------ */

function route_analytics(int $businessId): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], $businessId);
    if (!$b) {
        not_found();
    }
    header('Cache-Control: private, no-store');
    $db = db();

    /* KPI-Karten */
    $kpi = function (string $sql, array $params = []) use ($db) {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    };
    $members = $kpi('SELECT COUNT(*) FROM members WHERE business_id = ?', [$businessId]);
    $activeMembers = $kpi("SELECT COUNT(DISTINCT c.member_id) FROM transactions t
        JOIN cards c ON t.card_id = c.id JOIN programs p ON c.program_id = p.id
        WHERE p.business_id = ? AND t.created_at >= datetime('now', '-30 days')", [$businessId]);
    $stamps = $kpi("SELECT COUNT(*) FROM transactions t JOIN cards c ON t.card_id = c.id
        JOIN programs p ON c.program_id = p.id WHERE p.business_id = ? AND t.kind = 'stamp_add' AND t.reversed_at IS NULL", [$businessId]);
    $pointsGiven = $kpi("SELECT COALESCE(SUM(t.amount),0) FROM transactions t JOIN cards c ON t.card_id = c.id
        JOIN programs p ON c.program_id = p.id WHERE p.business_id = ? AND t.kind = 'points_add' AND t.reversed_at IS NULL", [$businessId]);
    $redeems = $kpi("SELECT COUNT(*) FROM transactions t JOIN cards c ON t.card_id = c.id
        JOIN programs p ON c.program_id = p.id WHERE p.business_id = ?
        AND t.kind IN ('reward_redeem','points_redeem','voucher_use','voucher_code_use') AND t.reversed_at IS NULL", [$businessId]);
    $undos = $kpi("SELECT COUNT(*) FROM transactions t JOIN cards c ON t.card_id = c.id
        JOIN programs p ON c.program_id = p.id WHERE p.business_id = ? AND t.reversed_at IS NOT NULL", [$businessId]);
    $campaignSent = $kpi('SELECT COUNT(*) FROM campaign_sends cs JOIN campaigns c2 ON cs.campaign_id = c2.id WHERE c2.business_id = ?', [$businessId]);
    $achUnlocked = $kpi('SELECT COUNT(*) FROM member_achievements ma JOIN achievements a ON ma.achievement_id = a.id WHERE a.business_id = ?', [$businessId]);

    $kpis = [
        t('Mitglieder') => $members,
        t('Aktiv (30 Tage)') => $activeMembers,
        t('Stempel vergeben') => $stamps,
        t('Punkte vergeben') => $pointsGiven,
        t('Einlösungen') => $redeems,
        t('Stornierungen') => $undos,
        t('Kampagnen-Empfänger') => $campaignSent,
        t('Meilensteine erreicht') => $achUnlocked,
    ];
    $kpiHtml = '';
    foreach ($kpis as $label => $value) {
        $kpiHtml .= '<div class="panel" data-analytics-kpi="' . h($label) . '" style="text-align:center;padding:14px">
          <div style="font-size:26px;font-weight:700">' . $value . '</div>
          <div class="muted" style="font-size:13px">' . h($label) . '</div></div>';
    }

    /* Aktivitaet der letzten 30 Tage (SVG-Balkendiagramm) */
    $days = [];
    for ($i = 29; $i >= 0; $i--) {
        $days[date('Y-m-d', strtotime("-$i days"))] = 0;
    }
    $stmt = $db->prepare("SELECT substr(t.created_at, 1, 10) AS day, COUNT(*) AS n FROM transactions t
        JOIN cards c ON t.card_id = c.id JOIN programs p ON c.program_id = p.id
        WHERE p.business_id = ? AND t.created_at >= datetime('now', '-30 days') AND t.reversed_at IS NULL
        GROUP BY day");
    $stmt->execute([$businessId]);
    foreach ($stmt->fetchAll() as $r) {
        if (isset($days[$r['day']])) {
            $days[$r['day']] = (int)$r['n'];
        }
    }
    $max = max(1, max($days));
    $bars = '';
    $x = 0;
    $w = 18;
    $gap = 4;
    $height = 120;
    foreach ($days as $day => $n) {
        $h = (int)round($n / $max * $height);
        $bars .= '<rect x="' . $x . '" y="' . ($height - $h) . '" width="' . $w . '" height="' . max(1, $h) . '" rx="3"'
            . ' fill="' . h($b['brand_color'] ?: '#E4572E') . '"><title>' . $day . ': ' . $n . ' Aktionen</title></rect>';
        $x += $w + $gap;
    }
    $chart = '<svg viewBox="0 0 ' . $x . ' ' . $height . '" style="width:100%;height:auto" role="img" aria-label="Aktivitaet letzte 30 Tage">' . $bars . '</svg>'
        . '<p class="muted" style="font-size:12px">Aktionen pro Tag, letzte 30 Tage (' . array_sum($days) . ' gesamt)</p>';

    /* Programm-Performance */
    $prog = $db->prepare("SELECT p.id, p.name, p.type,
        (SELECT COUNT(*) FROM cards c WHERE c.program_id = p.id) AS cards,
        (SELECT COUNT(*) FROM transactions t JOIN cards c ON t.card_id = c.id WHERE c.program_id = p.id AND t.reversed_at IS NULL) AS actions,
        (SELECT COUNT(*) FROM transactions t JOIN cards c ON t.card_id = c.id WHERE c.program_id = p.id
            AND t.kind IN ('reward_redeem','points_redeem','voucher_use','voucher_code_use') AND t.reversed_at IS NULL) AS redeems
        FROM programs p WHERE p.business_id = ? ORDER BY p.id");
    $prog->execute([$businessId]);
    $rows = '';
    foreach ($prog->fetchAll() as $p) {
        $rows .= '<tr><td data-label="' . h(t('Programm')) . '">' . ($p['type']==='points'?'<a href="/program/'.(int)$p['id'].'/analytics">'.h($p['name']).'</a>':h($p['name'])) . '</td><td data-label="' . h(t('Typ')) . '" class="muted">' . h(type_label($p['type'])) . '</td>
          <td data-label="' . h(t('Karten')) . '">' . (int)$p['cards'] . '</td><td data-label="' . h(t('Aktionen')) . '">' . (int)$p['actions'] . '</td><td data-label="' . h(t('Einlösungen')) . '">' . (int)$p['redeems'] . '</td></tr>';
    }
    $progTable = $rows
        ? '<table class="table analytics-programs"><thead><tr><th>' . t('Programm') . '</th><th>' . t('Typ') . '</th><th>' . t('Karten') . '</th><th>' . t('Aktionen') . '</th><th>' . t('Einlösungen') . '</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        : '<p class="muted">' . t('Noch keine Programme.') . '</p>';

    /* Top-Mitglieder nach Lifetime-Punkten */
    $top = $db->prepare("SELECT m.id, m.name, m.code, SUM(c.points_lifetime) AS lifetime
        FROM cards c JOIN programs p ON c.program_id = p.id JOIN members m ON c.member_id = m.id
        WHERE p.business_id = ? GROUP BY m.id ORDER BY lifetime DESC LIMIT 5");
    $top->execute([$businessId]);
    $topList = '';
    foreach ($top->fetchAll() as $t) {
        $topList .= '<li><a href="/member/' . (int)$t['id'] . '">' . h($t['name']) . '</a> <span class="muted">(' . h($t['code']) . ')</span> - ' . (int)$t['lifetime'] . ' ' . t('Punkte') . '</li>';
    }
    $topList = $topList ? '<ol>' . $topList . '</ol>' : '<p class="muted">' . t('Noch keine Punkte vergeben.') . '</p>';

    page('Analytics: ' . $b['name'], '
      <p><a href="/business/' . $businessId . '" class="muted">← ' . h($b['name']) . '</a></p>
      <h1>Analytics</h1><p><a class="btn ghost" href="/business/' . $businessId . '/monthly-summary">Monatsübersicht (UTC)</a></p>
      <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr))">' . $kpiHtml . '</div>
      <p class="muted">Einlösungen zählen Stempelprämien, Punkteprämien, einfache Gutscheinkarten und Code-Gutscheine ohne stornierte Transaktionen. Keine Anzahl verkaufter Prepaid-Pässe oder Geldsumme. Einlösungszahl und Programm-Performance gelten für alle gespeicherten Daten, das Aktivitätsdiagramm nur für die letzten 30 UTC-Tage.</p>
      <h2>' . t('Aktivität') . '</h2>
      <div class="panel">' . $chart . '</div>
      <h2>' . t('Programm-Performance') . '</h2>
      ' . $progTable . '
      <h2>' . t('Top-Mitglieder (Lifetime-Punkte)') . '</h2>
      ' . $topList . '
    ', $u, $b['brand_color']);
    exit;
}

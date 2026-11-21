<?php
declare(strict_types=1);

/* ==================================================================
 * QR-Studio: druckbare QR-Codes mit Firmenlogo in der Mitte
 * (Beitritts-Link, Staff-Login, beliebige Mitglieder-Karte)
 * ================================================================== */

function route_qr_studio(int $id): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], $id);
    if (!$b) {
        not_found();
    }
    $d = business_design($b);
    $logo = ($d['qr_logo'] && $d['logo'] !== '') ? upload_url($d['logo']) : '';
    $base = base_url();

    $codes = [
        ['label' => t('Kunden-Beitritt'), 'hint' => t('Aufsteller an der Theke: Kunden scannen und werden Mitglied.'), 'url' => $base . '/join/' . $b['join_code']],
        ['label' => t('Staff-Login'), 'hint' => t('Für das Team: direkter Weg zum Theken-Login.'), 'url' => $base . '/staff/login?b=' . $id],
    ];
    $members = db()->prepare('SELECT name, code FROM members WHERE business_id = ? ORDER BY name LIMIT 10');
    $members->execute([$id]);
    foreach ($members->fetchAll() as $m) {
        $codes[] = ['label' => t('Kundenkarte:') . ' ' . $m['name'], 'hint' => '/c/' . $m['code'], 'url' => $base . '/c/' . $m['code']];
    }

    $items = '';
    foreach ($codes as $i => $c) {
        $items .= '<div class="qr-item panel">
          <h3>' . h($c['label']) . '</h3>
          <div class="qr-box" data-qr="' . h($c['url']) . '" data-qr-logo="' . h($logo) . '" data-qr-size="220" data-qr-brand="' . h($d['accent_color']) . '"></div>
          <p class="muted">' . h($c['hint']) . '</p>
          <p class="btn-row">
            <button class="btn ghost small qr-download" data-name="' . h(preg_replace('/[^A-Za-z0-9-]+/', '-', $c['label'])) . '">' . t('PNG herunterladen') . '</button>
            <button class="btn ghost small qr-print">' . t('Drucken') . '</button>
          </p>
        </div>';
    }

    page(t('QR-Studio'), '
      <p><a href="/business/' . $id . '" class="muted">← ' . h($b['name']) . '</a></p>
      <h1>' . t('QR-Studio') . '</h1>
      <p class="muted">' . t('Druckbare QR-Codes mit deinem Logo in der Mitte. Logo und Farben stammen aus dem') . ' <a href="/business/' . $id . '/design">' . t('Karten-Design') . '</a>.</p>
      <div class="qr-grid">' . $items . '</div>', $u, $b['brand_color']);
    exit;
}

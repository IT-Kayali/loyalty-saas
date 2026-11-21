<?php
declare(strict_types=1);
/**
 * Gestaltete Webkarte mit Vorder- und Rueckseite (Karten-Designer).
 * Je Seite: Hintergrundbild mit Farb-Overlay und frei positionierbare Elemente (Ueberschrift, Text, Praemie, Stempel-Kreise, QR-Code).
 * Gespeichert im card_design-JSON unter "layout". Gilt nur fuer die Webkarte (Wallet-Ansicht und /c/CODE),
 * nicht fuer Apple-/Google-Wallet-Paesse (feste Layouts).
 */
const CL_SIDES = ['front' => 'Vorderseite', 'back' => 'Rückseite'];
const CL_ELS = ['title' => 'Überschrift', 'text' => 'Text', 'reward' => 'Prämie', 'stamps' => 'Stempel-Kreise', 'qr' => 'QR-Code'];

function layout_defaults(): array
{
    $el = fn(int $show, int $x, int $y, int $size = 0, string $text = '') => ['show' => $show, 'x' => $x, 'y' => $y, 'size' => $size, 'text' => $text];
    return [
        'on' => 0,
        'front' => ['image' => '', 'overlay_color' => '', 'overlay_opacity' => 60, 'els' => [
            'title' => $el(1, 6, 8), 'text' => $el(1, 6, 24), 'reward' => $el(1, 6, 82), 'stamps' => $el(1, 36, 58, 9), 'qr' => $el(0, 70, 8, 22)]],
        'back' => ['image' => '', 'overlay_color' => '', 'overlay_opacity' => 75, 'els' => [
            'title' => $el(1, 6, 8), 'text' => $el(1, 6, 26, 0, 'Zeige diesen Code an der Kasse.'), 'reward' => $el(0, 6, 82), 'stamps' => $el(0, 6, 58, 8), 'qr' => $el(1, 64, 42, 30)]],
    ];
}

/** Normalisiertes Layout. Vorderseite nutzt Bild und Deckkraft des bestehenden Designs, wenn nichts Eigenes gesetzt ist. */
function design_layout(array $d): array
{
    $def = layout_defaults();
    $raw = is_array($d['layout'] ?? null) ? $d['layout'] : [];
    $out = ['on' => !empty($raw['on']) ? 1 : 0];
    foreach (CL_SIDES as $s => $_) {
        $rs = is_array($raw[$s] ?? null) ? $raw[$s] : [];
        $side = $def[$s];
        $col = (string)($rs['overlay_color'] ?? '');
        $side['overlay_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $col) ? $col : '';
        if ($s === 'front') {
            $side['image'] = (string)($d['background_image'] ?? '');
            $side['overlay_opacity'] = max(0, min(100, (int)($d['overlay_opacity'] ?? 60)));
        } else {
            $side['image'] = basename((string)($rs['image'] ?? ''));
            $side['overlay_opacity'] = max(0, min(100, (int)($rs['overlay_opacity'] ?? $side['overlay_opacity'])));
        }
        foreach (CL_ELS as $e => $_) {
            $re = is_array($rs['els'][$e] ?? null) ? $rs['els'][$e] : [];
            $x = $side['els'][$e];
            if ($re) {
                $x['show'] = !empty($re['show']) ? 1 : 0;
                $x['x'] = max(0, min(95, (int)($re['x'] ?? $x['x'])));
                $x['y'] = max(0, min(95, (int)($re['y'] ?? $x['y'])));
                $x['text'] = mb_substr((string)($re['text'] ?? $x['text']), 0, 140);
                $x['size'] = (int)($re['size'] ?? $x['size']);
            }
            if ($e === 'stamps') $x['size'] = max(4, min(16, $x['size'] ?: 9));
            elseif ($e === 'qr') $x['size'] = max(16, min(48, $x['size'] ?: 28));
            else $x['size'] = 0;
            $side['els'][$e] = $x;
        }
        $out[$s] = $side;
    }
    return $out;
}

/** POST-Felder des Designers in $d['layout'] uebernehmen. Wirft InvalidArgumentException bei Upload-Fehlern. */
function layout_apply_post(array &$d, int $businessId): void
{
    $cur = design_layout($d);
    $new = ['on' => isset($_POST['lay_on']) ? 1 : 0];
    foreach (CL_SIDES as $s => $_) {
        $side = $cur[$s];
        if (isset($_POST["lay_{$s}_overlay_same"])) $side['overlay_color'] = '';
        else {
            $c = (string)($_POST["lay_{$s}_overlay_color"] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $c)) $side['overlay_color'] = $c;
        }
        if ($s === 'back') {
            if (isset($_POST['lay_back_overlay_opacity'])) $side['overlay_opacity'] = max(0, min(100, (int)$_POST['lay_back_overlay_opacity']));
            if (isset($_POST['lay_back_image_remove'])) $side['image'] = '';
            if (isset($_FILES['lay_back_image']) && ($_FILES['lay_back_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $side['image'] = store_design_image($_FILES['lay_back_image'], $businessId, 'cardback');
            }
        }
        foreach (CL_ELS as $e => $_) {
            $x = $side['els'][$e];
            $x['show'] = isset($_POST["lay_{$s}_{$e}_show"]) ? 1 : 0;
            foreach (['x', 'y', 'size'] as $k) {
                if (isset($_POST["lay_{$s}_{$e}_{$k}"])) $x[$k] = (int)$_POST["lay_{$s}_{$e}_{$k}"];
            }
            if (in_array($e, ['title', 'text'], true)) $x['text'] = mb_substr(trim((string)($_POST["lay_{$s}_{$e}_text"] ?? '')), 0, $e === 'title' ? 60 : 140);
            $side['els'][$e] = $x;
        }
        $new[$s] = $side;
    }
    $d['layout'] = $new;
    $d['layout'] = design_layout($d);
}

function cl_face_style(array $d, array $lay, string $side): string
{
    $s = $lay[$side];
    $style = '--card-bg:' . $d['bg_color'] . ';--card-text:' . $d['text_color'] . ';--brand:' . $d['accent_color'] . ';';
    if ($s['image'] !== '') {
        $rgb = sscanf($s['overlay_color'] !== '' ? $s['overlay_color'] : $d['bg_color'], '#%02x%02x%02x');
        $ov = 'rgba(' . implode(',', $rgb) . ',' . ($s['overlay_opacity'] / 100) . ')';
        $style .= 'background-image:linear-gradient(' . $ov . ',' . $ov . '),url(' . upload_url($s['image']) . ');background-size:cover;background-position:center;';
    }
    return $style;
}

/**
 * Beide Kartenseiten. $ctx: program_name, business_name, type, stamps, required, reward_text, pending, points, voucher_value, uses_left, visits_left, qr_url.
 * $preview = true setzt data-Attribute fuer Live-Vorschau und Ziehen im Designer.
 */
function card_faces_html(array $d, array $ctx, bool $preview = false): string
{
    $lay = design_layout($d);
    $html = '<div class="cl-wrap" data-side="front"' . ($preview ? ' id="layoutPreview"' : '') . '><div class="cl-tabs" role="tablist"><button type="button" class="cl-tab active" data-side="front">Vorderseite</button><button type="button" class="cl-tab" data-side="back">Rückseite</button></div>';
    foreach (CL_SIDES as $s => $label) {
        $html .= '<div class="cl-face cl-face-' . $s . ' designed-card" data-side="' . $s . '" style="' . h(cl_face_style($d, $lay, $s)) . '" aria-label="' . h($label) . '"' . ($preview ? ' data-image="' . h(upload_url($lay[$s]['image'])) . '"' : '') . '>';
        foreach ($lay[$s]['els'] as $e => $x) {
            if (!$x['show'] && !$preview) continue;
            $inner = cl_element_inner($e, $x, $d, $ctx, $s);
            if ($inner === '' && !$preview) continue;
            $st = 'left:' . $x['x'] . '%;top:' . $x['y'] . '%;';
            if ($e === 'qr') $st .= 'width:' . $x['size'] . 'cqw;';
            if ($e === 'stamps') $st .= '--sz:' . $x['size'] . 'cqw;';
            if ($e !== 'qr') $st .= 'max-width:calc(100% - ' . $x['x'] . '% - 3%);';
            $html .= '<div class="cl-el cl-' . $e . '" data-el="' . $e . '" data-side="' . $s . '" style="' . $st . ($x['show'] ? '' : 'display:none;') . '">' . $inner . '</div>';
        }
        $html .= '</div>';
    }
    return $html . ($preview ? '<p class="cl-warning" role="status" aria-live="polite" hidden></p>' : '') . '</div>';
}

function cl_element_inner(string $e, array $x, array $d, array $ctx, string $side): string
{
    switch ($e) {
        case 'title':
            return h($x['text'] !== '' ? $x['text'] : ($side === 'front' ? (string)$ctx['program_name'] : (string)$ctx['business_name']));
        case 'text':
            return h($x['text']);
        case 'reward':
            return match ($ctx['type']) {
                'stamps' => ((int)($ctx['pending'] ?? 0) > 0 ? '★ ' . (int)$ctx['pending'] . 'x ' : '') . h((string)$ctx['reward_text']),
                'points' => (int)$ctx['points'] . ' ' . h(t('Punkte')),
                'voucher' => h((string)$ctx['voucher_value']) . ' · ' . (int)$ctx['uses_left'] . ' ' . h(t('Nutzungen übrig')),
                default => (int)$ctx['visits_left'] . ' ' . h(t('Besuche übrig')),
            };
        case 'stamps':
            if ($ctx['type'] !== 'stamps') return '';
            $n = min(50, max(1, (int)$ctx['required']));
            $dots = '';
            for ($i = 1; $i <= $n; $i++) $dots .= stamp_html($d, $i <= (int)$ctx['stamps']);
            return '<div class="stamp-row">' . $dots . '</div>';
        case 'qr':
            $logo = ($d['qr_logo'] && $d['logo'] !== '') ? upload_url($d['logo']) : '';
            return '<div class="qr-box cl-qrbox" data-qr="' . h((string)$ctx['qr_url']) . '" data-qr-logo="' . h($logo) . '" data-qr-size="180"></div>';
    }
    return '';
}

/** Formularteil im Designer (innerhalb des Designer-Formulars). */
function layout_form_html(array $d): string
{
    $lay = design_layout($d);
    $h = '<h2>Gestaltete Karte (Vorder- und Rückseite)</h2><p class="muted">Positioniere Überschrift, Text, Prämie, Stempel-Kreise und QR-Code auf der Webkarte. In der Vorschau lassen sich die Elemente auch ziehen. Gilt nur für die Webkarte, nicht für Apple- und Google-Wallet-Pässe.</p>
      <label class="check"><input type="checkbox" name="lay_on" value="1" ' . ($lay['on'] ? 'checked' : '') . ' style="width:auto"> Gestaltete Karte verwenden</label>';
    foreach (CL_SIDES as $s => $label) {
        $side = $lay[$s];
        $h .= '<fieldset class="cl-set"><legend>' . h($label) . '</legend>';
        if ($s === 'back') {
            $h .= '<label>Hintergrundbild<input type="file" name="lay_back_image" accept="image/png,image/jpeg,image/webp"></label>'
                . ($side['image'] !== '' ? '<label class="check"><input type="checkbox" name="lay_back_image_remove" value="1" style="width:auto"> Bild entfernen</label>' : '')
                . '<label>Overlay-Deckkraft <input type="range" name="lay_back_overlay_opacity" min="0" max="100" value="' . (int)$side['overlay_opacity'] . '"></label>';
        } else {
            $h .= '<p class="muted">Hintergrundbild und Deckkraft stellst du oben unter "Hintergrund" ein.</p>';
        }
        $same = $side['overlay_color'] === '';
        $h .= '<label>Overlay-Farbe <input type="color" name="lay_' . $s . '_overlay_color" value="' . h($same ? $d['bg_color'] : $side['overlay_color']) . '"></label>'
            . '<label class="check"><input type="checkbox" name="lay_' . $s . '_overlay_same" value="1" ' . ($same ? 'checked' : '') . ' style="width:auto"> wie Kartenfarbe</label>';
        foreach (CL_ELS as $e => $elLabel) {
            $x = $side['els'][$e];
            $n = 'lay_' . $s . '_' . $e . '_';
            $h .= '<div class="cl-row" data-side="' . $s . '" data-el="' . $e . '"><label class="check"><input type="checkbox" name="' . $n . 'show" value="1" ' . ($x['show'] ? 'checked' : '') . ' style="width:auto"> <strong>' . h($elLabel) . '</strong></label>';
            if ($e === 'title' || $e === 'text') {
                $h .= '<label>Inhalt <input name="' . $n . 'text" maxlength="' . ($e === 'title' ? 60 : 140) . '" value="' . h($x['text']) . '" placeholder="' . ($e === 'title' ? ($s === 'front' ? 'leer = Programmname' : 'leer = Firmenname') : '') . '"></label>';
            }
            $h .= '<label>Links <input type="range" name="' . $n . 'x" min="0" max="95" value="' . (int)$x['x'] . '"></label><label>Oben <input type="range" name="' . $n . 'y" min="0" max="95" value="' . (int)$x['y'] . '"></label>';
            if ($e === 'stamps') $h .= '<label>Größe <input type="range" name="' . $n . 'size" min="4" max="16" value="' . (int)$x['size'] . '"></label>';
            if ($e === 'qr') $h .= '<label>Größe <input type="range" name="' . $n . 'size" min="16" max="48" value="' . (int)$x['size'] . '"></label>';
            $h .= '</div>';
        }
        $h .= '</fieldset>';
    }
    return $h;
}

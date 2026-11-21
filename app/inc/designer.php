<?php
declare(strict_types=1);

/* ==================================================================
 * Karten-Designer: Farben, Logo, Texte, sichtbare Felder
 * Design gilt für: öffentliche Kundenkarte, Wallet-Pässe, QR-Studio.
 * ================================================================== */

function route_design(int $id, string $method, int $programId=0): never
{
    $u = require_login();
    $b = own_business((int)$u['id'], $id);
    if (!$b) {
        not_found();
    }

    $program=$programId ? own_program((int)$u['id'],$programId) : null;
    if($programId && (!$program || (int)$program['business_id']!==$id))not_found();
    $designPath=$program ? '/program/'.$programId.'/design' : '/business/'.$id.'/design';
    if ($method === 'POST') {
        $d = $program ? program_design($program,$b) : business_design($b);
        foreach (['bg_color', 'text_color', 'accent_color'] as $c) {
            $val = (string)($_POST[$c] ?? '');
            if (preg_match('/^#[0-9a-fA-F]{6}$/', $val)) {
                $d[$c] = $val;
            }
        }
        $d['header'] = mb_substr(trim((string)($_POST['header'] ?? '')), 0, 60);
        $d['footer'] = mb_substr(trim((string)($_POST['footer'] ?? '')), 0, 120);
        $d['show_points'] = isset($_POST['show_points']) ? 1 : 0;
        $d['show_tier'] = isset($_POST['show_tier']) ? 1 : 0;
        $d['qr_logo'] = isset($_POST['qr_logo']) ? 1 : 0;

        $d['overlay_opacity']=max(0,min(100,(int)($_POST['overlay_opacity'] ?? 75)));
        $icon=(string)($_POST['stamp_icon'] ?? '');
        $d['stamp_icon']=in_array($icon,stamp_icons(),true)?$icon:'';
        foreach (['logo', 'stamp_image','background_image'] as $kind) {
            if (isset($_POST[$kind . '_remove'])) $d[$kind] = '';
            if (isset($_FILES[$kind]) && ($_FILES[$kind]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $d[$kind] = store_design_image($_FILES[$kind], $id, $kind);
                } catch (InvalidArgumentException $e) {
                    flash(t($e->getMessage()), 'err');
                    redirect($designPath);
                }
            }
        }

        $table=$program?'programs':'businesses';$target=$program?$programId:$id;
        db()->prepare('UPDATE '.$table.' SET card_design=? WHERE id=?')->execute([json_encode($d,JSON_UNESCAPED_UNICODE),$target]);
        flash(t('Karten-Design gespeichert.'));
        redirect($designPath);
    }

    $d = $program ? program_design($program,$b) : business_design($b);
    $logoUrl = $d['logo'] !== '' ? upload_url($d['logo']) : '';

    page(t('Karten-Design'), '
      <p><a href="/business/' . $id . '" class="muted">← ' . h($b['name']) . '</a></p>
      <h1>' . t('Karten-Design') . ($program ? ': '.h($program['name']) : '') . '</h1>
      <p class="muted">' . ($program ? t('Dieses Design gilt für das Programm auf der Webkarte. Der Wallet-Sammelpass nutzt das erste aktive Programm. QR-Studio bleibt beim Unternehmensdesign.') : t('Dieses Design gilt für die Kundenkarte, die Wallet-Pässe (Apple/Google) und die QR-Codes im QR-Studio.')) . '</p>

      <p class="notice">' . ($program ? t('Eigenes Design nur für dieses Programm. Bestehende Stände bleiben erhalten.') : t('Standard für dieses Unternehmen. Ein eigenes Programmdesign hat Vorrang.')) . '</p>
      <div class="designer-grid">
      <form method="post" enctype="multipart/form-data" class="form">' . csrf_field() . '
        <h2>' . t('Farben') . '</h2>
        <label>' . t('Karten-Hintergrund') . ' <input type="color" name="bg_color" value="' . h($d['bg_color']) . '"></label>
        <label>' . t('Textfarbe') . ' <input type="color" name="text_color" value="' . h($d['text_color']) . '"></label>
        <label>' . t('Akzentfarbe') . ' <input type="color" name="accent_color" value="' . h($d['accent_color']) . '"></label>

        <h2>' . t('Logo') . '</h2>
        ' . ($logoUrl !== ''
            ? '<p><img src="' . h($logoUrl) . '" style="max-height:60px;border-radius:8px;background:#fff;padding:6px"></p>
               <label class="check"><input type="checkbox" name="logo_remove" value="1" style="width:auto"> ' . t('Logo entfernen') . '</label>'
            : '<p class="muted">' . t('Noch kein Logo.') . '</p>') . '
        <label>' . t('Neues Logo (PNG, JPEG, WebP, max 2 MB)') . ' <input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>

        <h2>' . t('Eigenes Stempel-Bild') . '</h2>
        <p class="muted">' . t('PNG, JPEG oder WebP bis 2 MB. Transparente PNGs eignen sich besonders gut. Bilder werden sicher neu als PNG gespeichert.') . '</p>
        ' . ($d['stamp_image'] !== '' ? '<img class="stamp-upload-preview" src="' . h(upload_url($d['stamp_image'])) . '" alt="Stempel"><label class="check"><input type="checkbox" name="stamp_image_remove" value="1"> ' . t('Stempel-Bild entfernen') . '</label>' : '') . '
        <label>' . t('Stempel-Bild hochladen') . '<input type="file" name="stamp_image" accept="image/png,image/jpeg,image/webp"></label>

        <h2>' . t('Hintergrund & Stempel-Icon') . '</h2>
        <label>' . t('Hintergrundbild') . '<input type="file" name="background_image" accept="image/png,image/jpeg,image/webp"></label>
        ' . ($d['background_image']!==''?'<label class="check"><input type="checkbox" name="background_image_remove"> Hintergrund entfernen</label>':'') . '
        <label>' . t('Farbüberlagerung') . ' <input type="range" name="overlay_opacity" min="0" max="100" value="'.(int)$d['overlay_opacity'].'"></label>
        <label>' . t('Stempel-Icon (Bild hat Vorrang)') . '<select name="stamp_icon">'.implode('',array_map(fn($v)=>'<option value="'.h($v).'"'.($d['stamp_icon']===$v?' selected':'').'>'.h($v ?: 'Kreis').'</option>',stamp_icons())).'</select></label>

        <h2>' . t('Texte & Felder') . '</h2>
        <label>' . t('Kopfzeile (leer = Firmenname)') . ' <input name="header" maxlength="60" value="' . h($d['header']) . '"></label>
        <label>' . t('Fußzeile (z.B. Adresse oder Webseite)') . ' <input name="footer" maxlength="120" value="' . h($d['footer']) . '"></label>
        <label class="check"><input type="checkbox" name="show_points" value="1" ' . ($d['show_points'] ? 'checked' : '') . ' style="width:auto"> ' . t('Punktestand auf der Karte zeigen') . '</label>
        <label class="check"><input type="checkbox" name="show_tier" value="1" ' . ($d['show_tier'] ? 'checked' : '') . ' style="width:auto"> ' . t('Mitgliedsstufe zeigen') . '</label>
        <label class="check"><input type="checkbox" name="qr_logo" value="1" ' . ($d['qr_logo'] ? 'checked' : '') . ' style="width:auto"> ' . t('Logo in der Mitte des QR-Codes') . '</label>

        <button class="btn">' . t('Speichern') . '</button>
      </form>

      <div>
        <h2>' . t('Vorschau') . '</h2>
        <div class="card-preview" id="cardPreview"
             data-background="'.h(upload_url($d['background_image'])).'" data-opacity="'.(int)$d['overlay_opacity'].'" data-icon="'.h($d['stamp_icon']).'" data-bg="' . h($d['bg_color']) . '" data-text="' . h($d['text_color']) . '" data-accent="' . h($d['accent_color']) . '"
             data-stamp="' . h(upload_url($d['stamp_image'])) . '" data-logo="' . h($logoUrl) . '" data-header="' . h($d['header'] !== '' ? $d['header'] : $b['name']) . '"
             data-footer="' . h($d['footer']) . '">
          <div class="cp-inner">
            <img class="cp-logo" alt="">
            <div class="cp-header"></div>
            <div class="cp-name">Aylin Demir</div>
            <div class="cp-stamps"></div>
            <div class="cp-qr"></div>
            <div class="cp-footer"></div>
          </div>
        </div>
        <p class="muted">' . t('Die Vorschau aktualisiert sich direkt. Speichern übernimmt das Design für alle Karten.') . '</p>
      </div>
      </div>', $u, $b['brand_color']);
    exit;
}

function route_program_design(int $id,string $method): never
{
    $u=require_login();$p=own_program((int)$u['id'],$id);if(!$p)not_found();
    route_design((int)$p['business_id'],$method,$id);
}

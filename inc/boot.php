<?php
/* ==================================================================
   One include for every page.

   Thirty pages each repeated four or five require_once lines, in
   slightly different orders, and three files defined their own copy of
   the same ico() helper. A page now starts with:

       require_once __DIR__.'/inc/boot.php';

   and has config, database, reference data, auth, layout and the shared
   helpers already loaded.
   ================================================================== */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/refdata.php';
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/whatsapp.php';
require_once __DIR__ . '/auto.php';   /* used by 11 of the 22 pages */

/* ico() lives in its own file so the public pages can use it too. */
require_once __DIR__ . '/icons.php';

/* Every POST handler began with the same three lines. */
function posted(): bool {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/* A checked POST: verifies the token and returns the action name, or
   null when this is a plain page load. */
function post_action(string $key = 'do'): ?string {
    if (!posted()) return null;
    csrf_check();
    return (string)($_POST[$key] ?? '');
}

/* Trimmed POST field — used in almost every form handler. */
function pf(string $name, string $default = ''): string {
    return trim((string)($_POST[$name] ?? $default));
}

/* Integer POST field. Named pint() because pi() is a PHP built-in. */
function pint(string $name, int $default = 0): int {
    return isset($_POST[$name]) ? (int)$_POST[$name] : $default;
}

/* Integer GET field — used by every page that takes ?id= or ?rx=. */
function gi(string $name, int $default = 0): int {
    return isset($_GET[$name]) ? (int)$_GET[$name] : $default;
}

/* Redirect with a flash message, which pages were doing by hand. */
function done(string $msg, string $to, string $kind = 'ok'): never {
    $_SESSION[$kind] = $msg;
    redirect($to);
}

/* ------------------------------------------------------------------
   Small view helpers. 63 copies of the same field+label markup and 40
   hand-written cards were the bulk of the repetition in the pages.
   ------------------------------------------------------------------ */

/* A labelled input. field('phone','Phone',$v,['required'=>true]) */
function field(string $name, string $label, string $value = '', array $o = []): string {
    $type  = $o['type']  ?? 'text';
    $ph    = $o['ph']    ?? '';
    $extra = ($o['required'] ?? false) ? ' required' : '';
    if (!empty($o['list']))  $extra .= ' list="'.e($o['list']).'"';
    if (!empty($o['class'])) $extra .= ' class="'.e($o['class']).'"';
    if (!empty($o['style'])) $extra .= ' style="'.e($o['style']).'"';
    return '<div class="field"><label>'.e($label).'</label>'
         . '<input type="'.e($type).'" name="'.e($name).'" value="'.e($value).'"'
         . ($ph !== '' ? ' placeholder="'.e($ph).'"' : '') . $extra . '></div>';
}

/* A labelled select from a picklist or a plain array. */
function field_select(string $name, string $label, array $opts, string $sel = ''): string {
    $h = '<div class="field"><label>'.e($label).'</label><select name="'.e($name).'">';
    foreach ($opts as $o) {
        $h .= '<option'.($o === $sel ? ' selected' : '').'>'.e((string)$o).'</option>';
    }
    return $h.'</select></div>';
}

/* A status pill, so the colour logic is in one place. */
function pill(string $text, string $tone = 'gray'): string {
    return '<span class="pill p-'.e($tone).'">'.e($text).'</span>';
}

/* A scrollable table wrapper — wide tables were being clipped on phones
   whenever someone forgot the .tw div. */
function table_open(array $heads, string $cls = ''): string {
    $h = '<div class="tw"><table'.($cls ? ' class="'.e($cls).'"' : '').'><thead><tr>';
    foreach ($heads as $x) $h .= '<th>'.e((string)$x).'</th>';
    return $h.'</tr></thead><tbody>';
}
function table_close(): string { return '</tbody></table></div>'; }

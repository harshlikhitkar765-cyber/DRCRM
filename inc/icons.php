<?php
/* Shared inline-SVG helper.

   Kept in its own file rather than boot.php because the public pages
   (login, the phone pad) need it too, and they must not pull in
   layout.php or the logged-in scaffolding that boot.php loads. */
declare(strict_types=1);

if (!function_exists('ico')) {
    function ico(string $path, int $size = 0): string {
        $s = $size ? ' width="'.$size.'" height="'.$size.'"' : '';
        return '<svg viewBox="0 0 24 24"'.$s.' fill="none" stroke="currentColor" '
             . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
             . 'aria-hidden="true" focusable="false">'.$path.'</svg>';
    }
}

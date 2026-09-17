<?php
/* Hand-drawn SVG sparkline - no chart library, renders in the sandboxed preview. */
declare(strict_types=1);

function trend_svg(array $points, string $label, string $unit, string $colour='#0e7c86', int $w=300, int $h=90): string {
    $pts = array_values(array_filter($points, fn($p) => is_numeric($p['v'])));
    if (count($pts) < 2) {
        return '<div style="font-size:11.5px;color:#93a4ad;padding:14px 0">'
             . e($label).' — not enough readings yet</div>';
    }
    $vals = array_map(fn($p) => (float)$p['v'], $pts);
    $min = min($vals); $max = max($vals);
    if ($max - $min < 0.001) { $max = $min + 1; }
    $pad = ($max - $min) * 0.15; $min -= $pad; $max += $pad;
    $n = count($pts); $L = 34; $R = 6; $T = 6; $B = 16;
    $iw = $w - $L - $R; $ih = $h - $T - $B;
    $x = fn(int $i) => $L + ($n === 1 ? $iw/2 : $iw * $i / ($n - 1));
    $y = fn(float $v) => $T + $ih - (($v - $min) / ($max - $min)) * $ih;

    $d = ''; $dots = '';
    foreach ($pts as $i => $p) {
        $d .= ($i ? ' L' : 'M') . round($x($i),1) . ' ' . round($y((float)$p['v']),1);
        $dots .= '<circle cx="'.round($x($i),1).'" cy="'.round($y((float)$p['v']),1).'" r="2.6" fill="'.$colour.'"/>';
    }
    $area = $d . ' L'.round($x($n-1),1).' '.($T+$ih).' L'.round($x(0),1).' '.($T+$ih).' Z';
    $last  = (float)end($pts)['v'];
    $first = (float)$pts[0]['v'];
    $delta = $last - $first;
    $arrow = $delta > 0 ? '▲' : ($delta < 0 ? '▼' : '■');
    $dcol  = $delta > 0 ? '#c0392b' : ($delta < 0 ? '#1e8e5a' : '#7a8b95');
    $id = 'g'.substr(md5($label.$colour), 0, 6);

    $s  = '<div style="margin-bottom:4px;display:flex;align-items:baseline;gap:7px">';
    $s .= '<b style="font-size:12px">'.e($label).'</b>';
    $s .= '<span style="font-size:15px;font-weight:800;color:'.$colour.'">'.e(rtrim(rtrim(number_format($last,1,'.',''),'0'),'.')).'</span>';
    $s .= '<span style="font-size:10.5px;color:#7a8b95">'.e($unit).'</span>';
    $s .= '<span style="margin-left:auto;font-size:11px;font-weight:700;color:'.$dcol.'">'.$arrow.' '
        . e(rtrim(rtrim(number_format(abs($delta),1,'.',''),'0'),'.')).'</span></div>';
    $s .= '<svg width="100%" viewBox="0 0 '.$w.' '.$h.'" style="display:block">';
    $s .= '<defs><linearGradient id="'.$id.'" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0" stop-color="'.$colour.'" stop-opacity=".22"/>'
        . '<stop offset="1" stop-color="'.$colour.'" stop-opacity="0"/></linearGradient></defs>';
    for ($g = 0; $g <= 2; $g++) {
        $gy = $T + $ih * $g / 2;
        $s .= '<line x1="'.$L.'" y1="'.$gy.'" x2="'.($w-$R).'" y2="'.$gy.'" stroke="#e8eef0" stroke-width="1"/>';
        $gv = $max - ($max - $min) * $g / 2;
        $s .= '<text x="'.($L-5).'" y="'.($gy+3.5).'" text-anchor="end" font-size="8.5" fill="#93a4ad">'
            . e(rtrim(rtrim(number_format($gv,1,'.',''),'0'),'.')).'</text>';
    }
    $s .= '<path d="'.$area.'" fill="url(#'.$id.')"/>';
    $s .= '<path d="'.$d.'" fill="none" stroke="'.$colour.'" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
    $s .= $dots;
    $s .= '<text x="'.$L.'" y="'.($h-4).'" font-size="8.5" fill="#93a4ad">'.e($pts[0]['d']).'</text>';
    $s .= '<text x="'.($w-$R).'" y="'.($h-4).'" text-anchor="end" font-size="8.5" fill="#93a4ad">'.e(end($pts)['d']).'</text>';
    return $s.'</svg>';
}

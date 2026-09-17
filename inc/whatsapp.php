<?php
/* ==========================================================
   WhatsApp engine — builds a branded, custom prescription
   message from an editable template and delivers it.
   ========================================================== */
declare(strict_types=1);
require_once __DIR__ . '/refdata.php';
require_once __DIR__ . '/config.php';

/* ---------- Default templates (editable later in the UI) ----------
   Placeholders:
     {{clinic}} {{doctor}} {{qual}} {{phone}} {{address}} {{hours}}
     {{patient}} {{diagnosis}} {{medicines}} {{labs}}
     {{advice}} {{followup}} {{vitals}} {{date}}
-------------------------------------------------------------------*/
function default_templates(): array {
    return [
'English' => <<<TPL
🏥 *{{clinic}}*
_Visit Summary — {{date}}_
{{doctor}} · {{qual}}
━━━━━━━━━━━━━━━━━━━━

Hello {{patient}}, thank you for visiting us today.

*Condition / Diagnosis*
{{diagnosis}}

*Vitals Recorded*
{{vitals}}

*Your Medicines*
{{medicines}}

*Tests Advised*
{{labs}}

*Doctor's Advice*
{{advice}}

*Next Check-up*
{{followup}}

Reply *1* to confirm your appointment
Reply *2* to reschedule
Reply *HELP* to reach the clinic

━━━━━━━━━━━━━━━━━━━━
*{{clinic}}*
👨‍⚕️ {{doctor}}
🕖 Clinic OPD: {{hours}}
📞 {{phone}}
📍 {{address}}
_Save this number for follow-ups._
TPL,

'Hindi' => <<<TPL
🏥 *{{clinic}}*
_विज़िट सारांश — {{date}}_
{{doctor}} · {{qual}}
━━━━━━━━━━━━━━━━━━━━

नमस्ते {{patient}}, आज क्लिनिक आने के लिए धन्यवाद।

*रोग / निदान*
{{diagnosis}}

*जाँच के आँकड़े*
{{vitals}}

*आपकी दवाइयाँ*
{{medicines}}

*सुझाई गई जाँचें*
{{labs}}

*डॉक्टर की सलाह*
{{advice}}

*अगली जाँच*
{{followup}}

अपॉइंटमेंट पक्का करने के लिए *1* भेजें
बदलने के लिए *2* भेजें
सहायता के लिए *HELP* भेजें

━━━━━━━━━━━━━━━━━━━━
*{{clinic}}*
👨‍⚕️ {{doctor}}
🕖 क्लिनिक ओपीडी: {{hours}}
📞 {{phone}}
📍 पता: {{address}}
_आगे संपर्क के लिए यह नंबर सेव कर लें।_
TPL,

'Marathi' => <<<TPL
🏥 *{{clinic}}*
_भेट सारांश — {{date}}_
{{doctor}} · {{qual}}
━━━━━━━━━━━━━━━━━━━━

नमस्कार {{patient}}, आज क्लिनिकला भेट दिल्याबद्दल धन्यवाद.

*आजार / निदान*
{{diagnosis}}

*तपासणीचे आकडे*
{{vitals}}

*तुमची औषधे*
{{medicines}}

*सुचवलेल्या चाचण्या*
{{labs}}

*डॉक्टरांचा सल्ला*
{{advice}}

*पुढील तपासणी*
{{followup}}

अपॉइंटमेंट निश्चित करण्यासाठी *1* पाठवा
बदलण्यासाठी *2* पाठवा

━━━━━━━━━━━━━━━━━━━━
*{{clinic}}*
👨‍⚕️ {{doctor}}
🕖 क्लिनिक ओपीडी: {{hours}}
📞 {{phone}}
📍 पत्ता: {{address}}
_पुढील संपर्कासाठी हा नंबर सेव्ह करा._
TPL,
    ];
}

/* ---------- Formatting helpers ---------- */

function fmt_date(?string $d): string {
    if (!$d) return '';
    $ts = strtotime($d);
    return $ts ? date('j M Y', $ts) : $d;
}

/** Render the medicine list exactly as it will appear on WhatsApp. */
function format_meds(array $meds, string $lang = 'English'): string {
    $none = ['English'=>'(no medicines prescribed)','Hindi'=>'(कोई दवा नहीं)','Marathi'=>'(औषध नाही)'][$lang] ?? '(none)';
    $out = []; $i = 1;
    foreach ($meds as $m) {
        $name = trim((string)($m['name'] ?? ''));
        if ($name === '') continue;
        $line2 = array_filter([
            trim((string)($m['dose'] ?? '')) . ' ' . trim((string)($m['unit'] ?? '')),
            trim((string)($m['when'] ?? '')),
            trim((string)($m['freq'] ?? '')),
        ], fn($x) => trim((string)$x) !== '');
        $dur   = trim((string)($m['duration'] ?? ''));
        $notes = trim((string)($m['notes'] ?? ''));
        $row = $i++ . '. ' . $name;
        if ($line2) $row .= "\n    " . implode(' · ', array_map('trim', $line2)) . ($dur ? " ({$dur})" : '');
        elseif ($dur) $row .= "\n    ({$dur})";
        if ($notes !== '') $row .= "\n    _" . $notes . "_";
        $out[] = $row;
    }
    return $out ? implode("\n", $out) : $none;
}

function format_vitals(array $v): string {
    $map = [
        'temp' => ['Temp', '°F'], 'bp' => ['BP', ' mmHg'], 'pulse' => ['Pulse', '/min'],
        'spo2' => ['SpO2', '%'],  'weight' => ['Wt', ' kg'], 'sugar' => ['Sugar', ' mg/dL'],
    ];
    $parts = [];
    foreach ($map as $k => [$label, $unit]) {
        $val = trim((string)($v[$k] ?? ''));
        if ($val !== '') $parts[] = "$label $val$unit";
    }
    return $parts ? implode(' · ', $parts) : '—';
}

/**
 * Fill a template with the prescription data.
 * Any section whose value is empty is removed together with its
 * heading, so the patient never sees a stray "—".
 */
function render_template(string $tpl, array $d): string {
    $lang = $d['lang'] ?? 'English';
    $none = ['English'=>'To be scheduled','Hindi'=>'निर्धारित होनी है','Marathi'=>'ठरवायची आहे'][$lang] ?? '';

    $vals = [
        '{{clinic}}'    => clinic('name'),
        '{{doctor}}'    => clinic('doctor'),
        '{{qual}}'      => clinic('qual'),
        '{{phone}}'     => clinic('phone'),
        '{{address}}'   => clinic('addr'),
        '{{hours}}'     => clinic('hours'),
        '{{date}}'      => fmt_date(date('Y-m-d')),
        '{{patient}}'   => $d['patient'] ?? '',
        '{{diagnosis}}' => trim((string)($d['diagnosis'] ?? '')),
        '{{medicines}}' => $d['medicines'] ?? '',
        '{{labs}}'      => trim((string)($d['labs'] ?? '')),
        '{{advice}}'    => trim((string)($d['advice'] ?? '')),
        '{{vitals}}'    => trim((string)($d['vitals'] ?? '')),
        '{{followup}}'  => $d['followup'] ? fmt_date($d['followup']) : $none,
    ];

    $out = strtr($tpl, $vals);

    /* Drop "*Heading*\n(empty)" blocks entirely */
    $out = preg_replace('/\n\*[^*\n]+\*\n(?:—|-)?\n/u', "\n", $out) ?? $out;
    $out = preg_replace('/\n\*[^*\n]+\*\n\s*(?=\n)/u', "\n", $out) ?? $out;
    $out = preg_replace("/\n{3,}/u", "\n\n", $out) ?? $out;

    return trim($out);
}

/** Digits-only phone in international form for wa.me / Cloud API. */
function wa_number(string $phone): string {
    $n = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($n) === 10) $n = '91' . $n;              // bare Indian mobile
    if (str_starts_with($n, '0')) $n = '91' . ltrim($n, '0');
    return $n;
}

function wa_link(string $phone, string $body): string {
    return 'https://wa.me/' . wa_number($phone) . '?text=' . rawurlencode($body);
}

/**
 * Deliver the message.
 * Returns ['ok'=>bool,'driver'=>string,'link'=>?string,'status'=>string,'response'=>string]
 */
function wa_send(string $phone, string $body): array {
    if (WA['driver'] === 'cloud' && WA['phone_id'] && WA['token']) {
        return wa_send_cloud($phone, $body);
    }
    return [
        'ok'       => true,
        'driver'   => 'link',
        'link'     => wa_link($phone, $body),
        'status'   => 'Ready to send',
        'response' => 'Click-to-chat link generated (wa.me).',
    ];
}

function wa_send_cloud(string $phone, string $body): array {
    $url = 'https://graph.facebook.com/' . WA['api_version'] . '/' . WA['phone_id'] . '/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => wa_number($phone),
        'type'              => 'text',
        'text'              => ['preview_url' => false, 'body' => $body],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . WA['token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $ok = $code >= 200 && $code < 300;
    return [
        'ok'       => $ok,
        'driver'   => 'cloud',
        'link'     => null,
        'status'   => $ok ? 'Sent' : 'Failed',
        'response' => $err ?: (string)$res,
    ];
}

/* ---------------------------------------------------------------
   Sending a HANDWRITTEN prescription (an image, not text).

   Important limitation: a wa.me click-to-chat link can carry TEXT ONLY.
   It cannot attach an image. So in 'link' mode we send the caption plus a
   secure link to the image, and the doctor may also attach the PNG manually.
   In 'cloud' mode we send a real WhatsApp image message with a caption.
   --------------------------------------------------------------- */

function rx_image_url(int $rxId): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme.'://'.$host.$dir.'/rximg.php?rx='.$rxId.'&t='.rx_token($rxId);
}

function wa_send_image(string $phone, string $caption, int $rxId, string $absPath): array {
    if (WA['driver'] === 'cloud' && WA['phone_id'] && WA['token']) {
        return wa_send_image_cloud($phone, $caption, $absPath);
    }
    $body = $caption."\n\n".rx_image_url($rxId);
    return [
        'ok'       => true,
        'driver'   => 'link',
        'link'     => wa_link($phone, $body),
        'status'   => 'Ready to send',
        'response' => 'Click-to-chat link with image URL (wa.me cannot attach files).',
    ];
}

/* Cloud API needs the media uploaded first, then referenced by id. */
function wa_upload_media(string $absPath): array {
    $url = 'https://graph.facebook.com/'.WA['api_version'].'/'.WA['phone_id'].'/media';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.WA['token']],
        CURLOPT_POSTFIELDS     => [
            'messaging_product' => 'whatsapp',
            'type'              => wa_mime($absPath),
            'file'              => new CURLFile($absPath, wa_mime($absPath), basename($absPath)),
        ],
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    $j = json_decode((string)$raw, true);
    return ['id' => $j['id'] ?? null, 'raw' => $err ?: (string)$raw];
}

function wa_send_image_cloud(string $phone, string $caption, string $absPath): array {
    $up = wa_upload_media($absPath);
    if (empty($up['id'])) {
        return ['ok'=>false,'driver'=>'cloud','link'=>null,'status'=>'Failed',
                'response'=>'Media upload failed: '.$up['raw']];
    }
    $url = 'https://graph.facebook.com/'.WA['api_version'].'/'.WA['phone_id'].'/messages';
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => wa_number($phone),
        'type'              => 'image',
        'image'             => ['id' => $up['id'], 'caption' => mb_substr($caption, 0, 1024)],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer '.WA['token'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $ok = !$err && $code >= 200 && $code < 300;
    return ['ok'=>$ok,'driver'=>'cloud','link'=>null,
            'status'=>$ok?'Sent':'Failed','response'=>$err ?: (string)$raw];
}

/* Short caption that rides along with the handwritten image. */
function ink_caption(array $pt, string $diagnosis, string $followup): string {
    $l = [];
    $l[] = '🏥 *'.clinic('name').'*';
    $l[] = '_Prescription — '.fmt_date(date('Y-m-d')).'_';
    $l[] = clinic('doctor').' · '.clinic('qual');
    $l[] = '';
    $l[] = 'Namaste '.$pt['name'].', your prescription from today\'s visit is attached.';
    if (trim($diagnosis) !== '') { $l[] = ''; $l[] = '*Condition*'; $l[] = $diagnosis; }
    if (trim($followup) !== '')  { $l[] = ''; $l[] = '*Next Check-up*'; $l[] = fmt_date($followup); }
    $l[] = '';
    $l[] = 'Please follow the doses as written. Call us if anything is unclear.';
    $l[] = '';
    $l[] = '📞 '.clinic('phone');
    $l[] = '🕖 '.clinic('hours');
    return implode("\n", $l);
}

/* Correct MIME for uploaded prescription media (handwritten PNG or scanned JPG). */
function wa_mime(string $path): string {
    $e = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return ($e === 'jpg' || $e === 'jpeg') ? 'image/jpeg' : 'image/png';
}

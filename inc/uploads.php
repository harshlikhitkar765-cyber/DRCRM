<?php
/* Safe storage for browser-captured JPEG/PNG images. */
declare(strict_types=1);

const UPLOAD_MAX_IMAGE_BYTES = 8_000_000; /* decoded bytes: enough for a 1600px clinic photo */

/**
 * Decode only a real JPEG or PNG data URL. The declared MIME, actual image
 * signature and decoded size must all agree before anything reaches disk.
 *
 * @return array{bytes:string, extension:string, mime:string}
 */
function decode_image_data_url(string $data, int $maxBytes = UPLOAD_MAX_IMAGE_BYTES): array {
    if (strlen($data) > (int)ceil($maxBytes * 1.38) + 256) {
        throw new InvalidArgumentException('The image is too large. Use a photo under 8 MB.');
    }
    if (!preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=\s]+)$~D', $data, $m)) {
        throw new InvalidArgumentException('Choose a PNG or JPEG image.');
    }

    $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
    if ($bytes === false || strlen($bytes) < 100) {
        throw new InvalidArgumentException('The image could not be read.');
    }
    if (strlen($bytes) > $maxBytes) {
        throw new InvalidArgumentException('The image is too large. Use a photo under 8 MB.');
    }

    $info = @getimagesizefromstring($bytes);
    $actual = (int)($info[2] ?? 0);
    $actualMime = (string)($info['mime'] ?? '');
    $expectedMime = $m[1] === 'png' ? 'image/png' : 'image/jpeg';
    if (!in_array($actual, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $actualMime !== $expectedMime) {
        throw new InvalidArgumentException('The image data does not match its file type.');
    }

    return [
        'bytes'     => $bytes,
        'extension' => $actual === IMAGETYPE_PNG ? 'png' : 'jpg',
        'mime'      => $actualMime,
    ];
}

/** Store a decoded image with a collision-resistant, server-owned name. */
function store_image_data_url(string $data, string $directory, string $prefix): string {
    $image = decode_image_data_url($data);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('The image storage folder could not be created.');
    }
    $file = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $image['extension'];
    $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $file;
    if (file_put_contents($path, $image['bytes'], LOCK_EX) === false) {
        throw new RuntimeException('The image could not be saved.');
    }
    @chmod($path, 0640);
    return $file;
}

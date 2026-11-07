<?php
// Web Push for BAC — no Composer needed. Uses PHP's openssl + curl.
// Implements VAPID (RFC 8292) and aes128gcm payload encryption (RFC 8291).
declare(strict_types=1);

function b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

// Raw 65-byte P-256 public key -> PEM, so openssl can use it.
function p256_pub_pem(string $raw): string {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
function p256_new(): array {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k) throw new RuntimeException('openssl EC keys unavailable');
    $d = openssl_pkey_get_details($k);
    $raw = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
    return [$k, $raw];
}

// VAPID keys are made once and kept in data/vapid.json.
function vapid_keys(): array {
    if (is_file(VAPID_FILE)) {
        $v = json_decode((string)file_get_contents(VAPID_FILE), true);
        if (!empty($v['private_pem']) && !empty($v['public'])) return $v;
    }
    [$k, $raw] = p256_new();
    openssl_pkey_export($k, $pem);
    $v = ['private_pem' => $pem, 'public' => b64u_enc($raw)];
    file_put_contents(VAPID_FILE, json_encode($v), LOCK_EX);
    @chmod(VAPID_FILE, 0600);
    return $v;
}

// openssl gives an ASN.1 DER signature; JWT ES256 needs raw r||s (64 bytes).
function der_to_raw(string $der): string {
    $p = 2;
    if (ord($der[1]) & 0x80) $p += ord($der[1]) & 0x7f;
    $p++; $len = ord($der[$p]); $p++; $r = substr($der, $p, $len); $p += $len;
    $p++; $len = ord($der[$p]); $p++; $s = substr($der, $p, $len);
    return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}
function vapid_header(string $endpoint, array $v): string {
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $h = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $p = b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => VAPID_SUBJECT]));
    openssl_sign("$h.$p", $der, $v['private_pem'], OPENSSL_ALGO_SHA256);
    return 'vapid t=' . "$h.$p." . b64u_enc(der_to_raw($der)) . ', k=' . $v['public'];
}

// Encrypt one message for one subscription (RFC 8291, single record).
function push_encrypt(string $payload, string $uaPublicB64, string $authB64): string {
    $uaPublic = b64u_dec($uaPublicB64);
    $authSecret = b64u_dec($authB64);
    if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) throw new InvalidArgumentException('bad subscription keys');
    [$asKey, $asPublic] = p256_new();
    $ecdh = openssl_pkey_derive(openssl_pkey_get_public(p256_pub_pem($uaPublic)), $asKey, 32);
    if ($ecdh === false) throw new RuntimeException('ECDH failed');
    $ikm   = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $salt  = random_bytes(16);
    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $ct . $tag;
}

// Send to many subscriptions at once. Returns [sent, endpoints that are gone].
function push_send_many(array $subs, array $message): array {
    if (!$subs || !function_exists('curl_multi_init')) return [0, []];
    $v = vapid_keys();
    $payload = json_encode($message, JSON_UNESCAPED_UNICODE);
    $mh = curl_multi_init(); $handles = [];
    foreach ($subs as $s) {
        try { $body = push_encrypt($payload, $s['p256dh'], $s['auth']); } catch (Throwable $e) { continue; }
        $ch = curl_init($s['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . vapid_header($s['endpoint'], $v),
                'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream',
                'TTL: 86400', 'Urgency: high',
            ],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = [$ch, $s['endpoint']];
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
    $sent = 0; $gone = [];
    foreach ($handles as [$ch, $ep]) {
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($code >= 200 && $code < 300) $sent++;
        elseif ($code === 404 || $code === 410) $gone[] = $ep;
        curl_multi_remove_handle($mh, $ch); curl_close($ch);
    }
    curl_multi_close($mh);
    return [$sent, $gone];
}

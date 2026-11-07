<?php
// Text messages via ClickSend (https://developers.clicksend.com/docs/rest/v3/).
declare(strict_types=1);

function sms_ready(): bool { return APP_URL !== '' && CLICKSEND_USERNAME !== '' && CLICKSEND_API_KEY !== '' && function_exists('curl_init'); }

// UK numbers: 07… → +447…  Also accepts +44… or other +country numbers.
function norm_phone(string $p): ?string {
    $p = preg_replace('/[\s\-\(\)\.]/', '', $p);
    if (preg_match('/^07\d{9}$/', $p)) return '+44' . substr($p, 1);
    if (preg_match('/^447\d{9}$/', $p)) return '+' . $p;
    if (preg_match('/^00(\d{8,15})$/', $p, $m)) return '+' . $m[1];
    if (preg_match('/^\+\d{8,15}$/', $p)) return $p;
    return null;
}
function mask_phone(string $p): string { return str_repeat('•', max(0, strlen($p) - 3)) . substr($p, -3); }

function json_file(string $f): array { if (!is_file($f)) return []; $j = json_decode((string)file_get_contents($f), true); return is_array($j) ? $j : []; }
function json_save(string $f, array $d): void { file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT), LOCK_EX); @chmod($f, 0600); }

function sms_send(string $to, string $body): bool {
    if (!sms_ready()) return false;
    $msg = ['source' => 'bac', 'body' => $body, 'to' => $to];
    if (SMS_FROM !== '') $msg['from'] = SMS_FROM;
    $ch = curl_init('https://rest.clicksend.com/v3/sms/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(CLICKSEND_USERNAME . ':' . CLICKSEND_API_KEY)],
        CURLOPT_POSTFIELDS => json_encode(['messages' => [$msg]]),
    ]);
    $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $j = json_decode((string)$res, true);
    $ok = $code === 200 && (($j['data']['messages'][0]['status'] ?? '') === 'SUCCESS');
    $log = json_file(SMS_LOG);
    if ($ok) $log['last'] = time();
    $log['sent'][] = time();
    $log['sent'] = array_values(array_filter($log['sent'], fn($t) => $t > time() - 86400));
    json_save(SMS_LOG, $log);
    return $ok;
}

// No more than 20 texts a day in total, whatever happens (protects your credit).
function sms_daily_ok(): bool { return count(json_file(SMS_LOG)['sent'] ?? []) < 20; }

// ClickSend credit expires after 12 months with no texts or top-ups.
// Once every ~11 months, text the admin so the credit stays active (about 4p).
function sms_keepalive(): void {
    if (!sms_ready()) return;
    $log = json_file(SMS_LOG);
    if (empty($log['last'])) { $log['last'] = time(); json_save(SMS_LOG, $log); return; }   // clock starts when set up
    if (time() - (int)$log['last'] < 330 * 86400) return;
    $phone = json_file(PHONES_FILE)[ADMIN] ?? null;
    if (!$phone) return;
    $log['last'] = time(); json_save(SMS_LOG, $log);    // claim first so two visitors can't both send
    sms_send($phone, BAND_NAME . ' calendar: yearly check-in to keep your text credit active. No action needed.');
}

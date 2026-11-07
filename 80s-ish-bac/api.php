<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/push.php';
require __DIR__ . '/sms.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

// Behind Caddy the request comes from the proxy, so use the visitor's address it passes on.
// Only trusted when the direct caller is on a private (Docker) network.
function clientIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
    $fwd = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($fwd !== '' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        $first = trim(explode(',', $fwd)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
    }
    return $ip;
}

function member(string $name): ?array {
    foreach (MEMBERS as $m) if ($m['name'] === $name) return $m;
    return null;
}
function viewer(string $name): ?array {
    foreach (VIEWERS as $v) if ($v['name'] === $name) return $v;
    return null;
}
// ---- members' own PINs (hashed). No entry = still on the default PIN from config.php ----
function pins(): array {
    if (!is_file(PINS_FILE)) return [];
    $j = json_decode((string)file_get_contents(PINS_FILE), true);
    return is_array($j) ? $j : [];
}
function pinVersion(string $name): string {
    $h = pins()[$name] ?? null;
    return $h ? substr(hash('sha256', $h), 0, 12) : 'default';
}
// Token = name.signature. 'full' tokens work everywhere; 'chg' tokens can only set a new PIN.
// The current PIN is baked into the signature, so changing a PIN signs out every old session.
function sign(string $name, string $scope = 'full'): string {
    return $name . '.' . hash_hmac('sha256', $name . '|' . $scope . '|' . pinVersion($name), SECRET);
}
function tokenName(string $t): string { return explode('.', $t, 2)[0]; }
function auth(array $in): string {
    $t = (string)($in['token'] ?? '');
    $name = tokenName($t);
    if (!member($name) || !hash_equals(sign($name), $t)) fail('Please log in again', 401);
    return $name;
}
// Members OR hidden viewers — for reading only.
function authRead(): string {
    $t = (string)($_SERVER['HTTP_X_TOKEN'] ?? '');
    $name = tokenName($t);
    if (!(member($name) || viewer($name)) || !hash_equals(sign($name), $t)) fail('Please log in again', 401);
    return $name;
}
function pinProblem(string $name, string $new): ?string {
    if (!preg_match('/^\d{4,8}$/', $new)) return 'PIN must be 4 to 8 numbers';
    if (hash_equals(member($name)['pin'], $new)) return 'Choose a different PIN from the one you were given';
    if (preg_match('/^(\d)\1+$/', $new) || strpos('0123456789', $new) !== false) return 'Too easy to guess — try another';
    return null;
}
function savePin(string $name, string $new): void {
    ensureDir();
    $fh = fopen(PINS_FILE, 'c+'); flock($fh, LOCK_EX);
    $all = json_decode(stream_get_contents($fh) ?: '', true) ?: [];
    $all[$name] = password_hash($new, PASSWORD_DEFAULT);
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($all, JSON_PRETTY_PRINT)); fflush($fh); flock($fh, LOCK_UN); fclose($fh);
    @chmod(PINS_FILE, 0600);
}
function phoneOf(string $name): ?string { return json_file(PHONES_FILE)[$name] ?? null; }
function savePhone(string $name, string $phone): void { ensureDir(); $all = json_file(PHONES_FILE); $all[$name] = $phone; json_save(PHONES_FILE, $all); }
function loginReply(string $name): array {
    return ['name' => $name, 'token' => sign($name), 'admin' => $name === ADMIN, 'viewer' => false, 'needPhone' => phoneOf($name) === null];
}

function complete(array $avail, string $date): bool {
    foreach (MEMBERS as $m) if (($avail[$date][$m['name']] ?? '') !== 'y') return false;
    return true;
}
function prettyDate(string $d): string { return date('D j M', strtotime($d)); }

// ---- notification subscriptions ----
function readSubs(): array {
    if (!is_file(SUBS_FILE)) return [];
    $j = json_decode((string)file_get_contents(SUBS_FILE), true);
    return is_array($j) ? $j : [];
}
function writeSubs(array $s): void { ensureDir(); file_put_contents(SUBS_FILE, json_encode($s, JSON_PRETTY_PRINT), LOCK_EX); }
function notify(array $names, array $msg): array {
    $subs = readSubs(); $list = [];
    foreach ($names as $n) foreach ($subs[$n] ?? [] as $s) $list[] = $s;
    if (!$list) return [0, 0];
    try { [$sent, $gone] = push_send_many($list, $msg); } catch (Throwable $e) { return [0, count($list)]; }
    if ($gone) {   // phone unsubscribed or app removed: forget it
        foreach ($subs as $n => $arr) $subs[$n] = array_values(array_filter($arr, fn($s) => !in_array($s['endpoint'], $gone, true)));
        writeSubs($subs);
    }
    return [$sent, count($list)];
}
function requireAdmin(string $name): void { if ($name !== ADMIN) fail('Admin only', 403); }
function validDate($d): bool { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1; }
function clean($s, int $max): string { return mb_substr(trim((string)$s), 0, $max); }

function emptyData(): array { return ['avail' => [], 'events' => []]; }

function ensureDir(): void {
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) fail('Cannot create data folder', 500);
}

function readData(): array {
    if (!is_file(DATA_FILE)) return emptyData();
    $j = json_decode((string)file_get_contents(DATA_FILE), true);
    return is_array($j) ? $j + emptyData() : emptyData();
}

// Locked read-modify-write so two people saving at once can't clobber each other.
function mutate(callable $fn): array {
    ensureDir();
    $fh = fopen(DATA_FILE, 'c+');
    if (!$fh) fail('Cannot open data file', 500);
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $data = json_decode($raw ?: '', true);
    $data = is_array($data) ? $data + emptyData() : emptyData();
    $data = $fn($data);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $data;
}

function publicState(array $d): array {
    return [
        'band'    => BAND_NAME,
        'admin'   => ADMIN,
        'members' => array_map(fn($m) => ['name' => $m['name'], 'color' => $m['color']], MEMBERS),
        'avail'   => (object)$d['avail'],
        'events'  => array_values($d['events']),
    ];
}

// --- simple login throttle: 6 bad PINs per IP per 10 minutes ---
function throttled(string $ip, bool $record = false): bool {
    ensureDir();
    $t = is_file(THROTTLE_FILE) ? (json_decode((string)file_get_contents(THROTTLE_FILE), true) ?: []) : [];
    $now = time();
    $t[$ip] = array_values(array_filter($t[$ip] ?? [], fn($ts) => $ts > $now - 600));
    if ($record) { $t[$ip][] = $now; file_put_contents(THROTTLE_FILE, json_encode($t), LOCK_EX); }
    return count($t[$ip]) >= 6;
}

$action = $_GET['action'] ?? 'state';
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];

switch ($action) {
    case 'members': // public: just enough for the login screen
        out(['band' => BAND_NAME,
             'members' => array_map(fn($m) => ['name' => $m['name'], 'color' => $m['color']], MEMBERS)]);

    case 'public': // "Just looking": gigs only — no availability, rehearsals, other entries or notes
        $gigs = array_values(array_filter(readData()['events'], fn($e) => $e['type'] === 'gig'));
        $gigs = array_map(fn($e) => ['id' => $e['id'], 'date' => $e['date'], 'type' => 'gig',
            'title' => $e['title'], 'time' => $e['time'], 'place' => $e['place']], $gigs);
        out(['band' => BAND_NAME, 'admin' => '', 'members' => [], 'avail' => new stdClass, 'events' => $gigs]);

    case 'state':
        authRead();
        sms_keepalive();
        out(publicState(readData()));

    case 'login':
        $ip = clientIp();
        if (throttled($ip)) fail('Too many attempts — try again in 10 minutes', 429);
        $name = (string)($in['name'] ?? '');
        $pin  = (string)($in['pin'] ?? '');
        if ($name === '') { // no name tapped: try hidden viewers
            foreach (VIEWERS as $v) {
                if ($pin !== '' && hash_equals($v['pin'], $pin)) {
                    out(['name' => $v['name'], 'token' => sign($v['name']), 'admin' => false, 'viewer' => true]);
                }
            }
            throttled($ip, true);
            fail('Tap your name first', 401);
        }
        $m = member($name);
        if (!$m) { throttled($ip, true); fail('Wrong PIN', 401); }
        $own = pins()[$name] ?? null;
        if ($own) {
            if (!password_verify($pin, $own)) { throttled($ip, true); fail('Wrong PIN', 401); }
            out(loginReply($name));
        }
        if (!hash_equals($m['pin'], $pin)) { throttled($ip, true); fail('Wrong PIN', 401); }
        // first time in: default PIN only lets them choose a new one
        out(['name' => $name, 'token' => sign($name, 'chg'), 'mustChange' => true, 'admin' => false, 'viewer' => false]);

    case 'change_pin':
        $t = (string)($in['token'] ?? ''); $name = tokenName($t);
        if (!member($name) || !(hash_equals(sign($name, 'chg'), $t) || hash_equals(sign($name), $t))) fail('Please log in again', 401);
        $new = (string)($in['pin'] ?? '');
        if ($e = pinProblem($name, $new)) fail($e);
        $phoneIn = trim((string)($in['phone'] ?? ''));
        if ($phoneIn !== '' || phoneOf($name) === null) {
            $ph = norm_phone($phoneIn);
            if (!$ph) fail('Add your mobile number (e.g. 07700 900123) so you can reset your PIN if you forget it');
            savePhone($name, $ph);
        }
        savePin($name, $new);
        out(loginReply($name));

    case 'set_phone':
        $name = auth($in);
        $ph = norm_phone((string)($in['phone'] ?? ''));
        if (!$ph) fail("That doesn't look like a mobile number");
        savePhone($name, $ph);
        out(loginReply($name) + ['phone' => mask_phone($ph)]);

    case 'forgot_pin':
        if (!sms_ready()) fail("PIN reset by text isn't switched on yet");
        $ip = clientIp();
        if (throttled($ip)) fail('Too many attempts — try again in 10 minutes', 429);
        throttled($ip, true);                                     // every request counts towards the limit
        $name = (string)($in['name'] ?? ''); $ph = norm_phone((string)($in['phone'] ?? ''));
        $sameReply = ['ok' => true];                              // same answer either way, so numbers can't be guessed
        if (!member($name) || !$ph || phoneOf($name) !== $ph || !sms_daily_ok()) out($sameReply);
        $resets = json_file(RESETS_FILE);
        $resets = array_filter($resets, fn($r) => $r['exp'] > time() - 86400);
        $recent = count(array_filter($resets, fn($r) => $r['name'] === $name && $r['made'] > time() - 3600));
        if ($recent >= 3) out($sameReply);                        // max 3 texts per person per hour
        $tok = b64u_enc(random_bytes(24));
        $resets[hash('sha256', $tok)] = ['name' => $name, 'made' => time(), 'exp' => time() + 900, 'used' => false];
        ensureDir(); json_save(RESETS_FILE, $resets);
        sms_send($ph, BAND_NAME . ': tap to choose a new PIN (works once, for 15 minutes): ' . APP_URL . '?reset=' . $tok);
        out($sameReply);

    case 'reset_pin':
        $tok = (string)($in['reset'] ?? ''); $key = hash('sha256', $tok);
        $resets = json_file(RESETS_FILE); $r = $resets[$key] ?? null;
        if (!$r || $r['used'] || $r['exp'] < time()) fail('This reset link has expired. Ask for a new one from "Forgot PIN?"', 410);
        $name = $r['name'];
        if ($e = pinProblem($name, (string)($in['pin'] ?? ''))) fail($e);
        $resets[$key]['used'] = true; json_save(RESETS_FILE, $resets);
        savePin($name, (string)$in['pin']);
        out(loginReply($name));

    case 'push_key':
        try { out(['key' => vapid_keys()['public']]); } catch (Throwable $e) { fail('Notifications are not available on this server', 500); }

    case 'push_sub':
        $name = auth($in);
        $s = $in['sub'] ?? [];
        $ep = (string)($s['endpoint'] ?? ''); $p = (string)($s['keys']['p256dh'] ?? ''); $a = (string)($s['keys']['auth'] ?? '');
        if (!preg_match('#^https://#', $ep) || strlen($ep) > 1000 || strlen(b64u_dec($p)) !== 65 || strlen(b64u_dec($a)) !== 16) fail('Bad subscription');
        $subs = readSubs();
        $subs[$name] = array_values(array_filter($subs[$name] ?? [], fn($x) => $x['endpoint'] !== $ep));
        $subs[$name][] = ['endpoint' => $ep, 'p256dh' => $p, 'auth' => $a, 'added' => date('c')];
        $subs[$name] = array_slice($subs[$name], -5);   // max 5 devices each
        writeSubs($subs);
        out(['ok' => true]);

    case 'push_test':
        $name = auth($in);
        [$sent, $tried] = notify([$name], ['title' => BAND_NAME, 'body' => 'Notifications are working 🎸', 'tag' => 'test']);
        out(['sent' => $sent, 'devices' => $tried]);

    case 'avail':
        $name = auth($in);
        $date = $in['date'] ?? null;
        $status = $in['status'] ?? null; // 'y', 'n' or null (clear)
        if (!validDate($date)) fail('Bad date');
        if (!in_array($status, ['y', 'n', null], true)) fail('Bad status');
        $became = false;
        $d = mutate(function ($d) use ($name, $date, $status, &$became) {
            $before = complete($d['avail'], $date);
            if ($status === null) {
                unset($d['avail'][$date][$name]);
                if (empty($d['avail'][$date])) unset($d['avail'][$date]);
            } else {
                $d['avail'][$date][$name] = $status;
            }
            // alert once per date, even if someone untaps and taps again
            $became = !$before && complete($d['avail'], $date) && empty($d['notified'][$date]);
            if ($became) $d['notified'][$date] = date('c');
            return $d;
        });
        if ($became) {
            notify(array_column(MEMBERS, 'name'), ['title' => BAND_NAME . ' — everyone\'s free!',
                'body' => prettyDate($date) . ': all five of you are available.', 'tag' => 'free-' . $date]);
        }
        out(publicState($d));

    case 'event_add':
        $name = auth($in); requireAdmin($name);
        $e = $in['event'] ?? [];
        if (!validDate($e['date'] ?? null)) fail('Bad date');
        $type = in_array($e['type'] ?? '', ['rehearsal', 'gig', 'other'], true) ? $e['type'] : 'other';
        $ev = [
            'id'    => bin2hex(random_bytes(6)),
            'date'  => $e['date'],
            'type'  => $type,
            'title' => clean($e['title'] ?? '', 80),
            'time'  => clean($e['time'] ?? '', 20),
            'place' => clean($e['place'] ?? '', 80),
            'notes' => clean($e['notes'] ?? '', 400),
        ];
        $d = mutate(function ($d) use ($ev) { $d['events'][] = $ev; return $d; });
        out(publicState($d));

    case 'event_del':
        $name = auth($in); requireAdmin($name);
        $id = (string)($in['id'] ?? '');
        $d = mutate(function ($d) use ($id) {
            $d['events'] = array_values(array_filter($d['events'], fn($e) => $e['id'] !== $id));
            return $d;
        });
        out(publicState($d));

    default:
        fail('Unknown action', 404);
}

<?php
// ===================== CONFIGURAZIONE =====================
const USER = 'admin';
// Password in chiaro (cambiala!) oppure un hash generato con:
// php -r 'echo password_hash("latuapassword", PASSWORD_DEFAULT);'
const PASS = 'domus2026';

// gen 1 = Shelly Gen1 (es. Shelly 1, 2.5, RGBW2) | gen 2 = Shelly Plus / Pro
// type: relay = accendi/spegni | rgb = luce colorata
const MEROSS_KEY = 'LA_TUA_CHIAVE_MEROSS';   // chiave dell'account Meross (non la password)
const CIABATTA_IP = '192.168.1.61';       // IP della ciabatta

// Tuya / Smart Life (via cloud): dati del progetto su iot.tuya.com. Europa = tuyaeu, USA = tuyaus, India = tuyain, Cina = tuyacn
const TUYA = ['id' => 'ACCESS_ID', 'secret' => 'ACCESS_SECRET', 'host' => 'https://openapi.tuyaeu.com'];

// Codice per inserire l'allarme e per il tasto "Esco" (cambialo!). Puoi usare anche un hash: php -r 'echo password_hash("1234", PASSWORD_DEFAULT);'
const ALARM_CODE = '1234';

const DEVICES = [
    // Esempio: copia questa riga per ogni dispositivo che vuoi aggiungere
    'salotto' => ['name' => 'Luce salotto', 'ip' => '192.168.1.60', 'gen' => 2, 'type' => 'relay', 'light' => true, 'room' => 'Cucina e sala'],
    // Ciabatta Meross della scrivania di Simo: stesso IP e chiave, cambia solo il canale (0 = generale, 1-3 = prese, 4 = USB: da verificare, vedi ?api=channels)
    'ciab0'   => ['name' => 'Scrivania generale', 'brand' => 'meross', 'ip' => CIABATTA_IP, 'key' => MEROSS_KEY, 'channel' => 0, 'type' => 'relay', 'room' => 'Camera Simo'],
    'ciab1'   => ['name' => 'Presa 1',           'brand' => 'meross', 'ip' => CIABATTA_IP, 'key' => MEROSS_KEY, 'channel' => 1, 'type' => 'relay', 'room' => 'Camera Simo'],
    'ciab2'   => ['name' => 'Presa 2',           'brand' => 'meross', 'ip' => CIABATTA_IP, 'key' => MEROSS_KEY, 'channel' => 2, 'type' => 'relay', 'room' => 'Camera Simo'],
    'ciab3'   => ['name' => 'Presa 3',           'brand' => 'meross', 'ip' => CIABATTA_IP, 'key' => MEROSS_KEY, 'channel' => 3, 'type' => 'relay', 'room' => 'Camera Simo'],
    'ciab4'   => ['name' => 'USB',               'brand' => 'meross', 'ip' => CIABATTA_IP, 'key' => MEROSS_KEY, 'channel' => 4, 'type' => 'relay', 'room' => 'Camera Simo'],
    // Tapparelle: Shelly Plus 2PM in modalità 'Cover'. Cancella le righe delle stanze senza tapparella.
    'tap_cucina' => ['name' => 'Tapparella', 'ip' => '192.168.1.71', 'gen' => 2, 'type' => 'cover', 'room' => 'Cucina e sala'],
    'tap_letto' => ['name' => 'Tapparella', 'ip' => '192.168.1.72', 'gen' => 2, 'type' => 'cover', 'room' => 'Camera da letto'],
    'tap_gaia' => ['name' => 'Tapparella', 'ip' => '192.168.1.73', 'gen' => 2, 'type' => 'cover', 'room' => 'Camera Gaia'],
    'tap_simo' => ['name' => 'Tapparella', 'ip' => '192.168.1.74', 'gen' => 2, 'type' => 'cover', 'room' => 'Camera Simo'],
    'tap_bagno' => ['name' => 'Tapparella', 'ip' => '192.168.1.75', 'gen' => 2, 'type' => 'cover', 'room' => 'Bagno'],
    // Allarme Dahua (telecamere/NVR con 'Disinserimento'). Usa un utente dedicato, non admin.
    'allarme' => ['name' => 'Allarme Dahua', 'brand' => 'dahua', 'ip' => '192.168.1.80', 'user' => 'domus', 'pass' => 'PASSWORD_DAHUA', 'type' => 'alarm', 'room' => 'Sicurezza'],
    // Lampadina RGB Smart Life (Tuya): device_id lo trovi su iot.tuya.com > Devices
    'comodino' => ['name' => 'Comodino', 'brand' => 'tuya', 'device_id' => 'ID_DISPOSITIVO', 'type' => 'rgb', 'room' => 'Camera Simo'],
    // TV smart (solo accendi/spegni) tramite presa smart Shelly. Se mi dici marca e modello la comando direttamente.
    'tv' => ['name' => 'TV', 'ip' => '192.168.1.62', 'gen' => 2, 'type' => 'relay', 'room' => 'Cucina e sala'],
];
// ==========================================================

session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_start();
$csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(16));

const USERS_FILE = __DIR__ . '/data/users.json';
function users(): array {
    if (!is_file(USERS_FILE)) {
        @mkdir(dirname(USERS_FILE), 0750, true);
        file_put_contents(dirname(USERS_FILE) . '/.htaccess', "Require all denied\n");
        $h = str_starts_with(PASS, '$2y$') ? PASS : password_hash(PASS, PASSWORD_DEFAULT);
        file_put_contents(USERS_FILE, json_encode([strtolower(USER) => ['hash' => $h, 'role' => 'admin']]), LOCK_EX);
    }
    return json_decode(file_get_contents(USERS_FILE), true) ?: [];
}
function save_users(array $u): void { file_put_contents(USERS_FILE, json_encode($u, JSON_PRETTY_PRINT), LOCK_EX); }

function meross(array $d, string $ns, string $method, array $payload = []): ?array {
    $id = md5(random_bytes(8)); $t = time();
    $body = json_encode(['header' => ['messageId' => $id, 'namespace' => $ns, 'method' => $method, 'payloadVersion' => 1,
        'from' => "http://{$d['ip']}/config", 'timestamp' => $t, 'timestampMs' => 0, 'sign' => md5($id . ($d['key'] ?? '') . $t)],
        'payload' => (object)$payload]);
    $ch = curl_init("http://{$d['ip']}/config");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $r = curl_exec($ch); curl_close($ch);
    $j = $r === false ? null : json_decode($r, true);
    $p = is_array($j) ? ($j['payload'] ?? []) : null;
    return isset($p['error']) ? null : $p;   // errore (es. chiave sbagliata) = come non raggiungibile
}
function meross_status(array $d): array {
    $p = meross($d, 'Appliance.System.All', 'GET');
    if ($p === null) return ['online' => false];
    $dg = $p['all']['digest'] ?? [];
    $tx = $dg['togglex'] ?? [];
    if (isset($tx['channel'])) $tx = [$tx];
    foreach ($tx as $t) if (($t['channel'] ?? 0) === ($d['channel'] ?? 0)) return ['online' => true, 'on' => !empty($t['onoff'])];
    return ['online' => true, 'on' => !empty($dg['toggle']['onoff'])];
}
function meross_set(array $d, bool $on): void {
    meross($d, 'Appliance.Control.ToggleX', 'SET', ['togglex' => ['channel' => $d['channel'] ?? 0, 'onoff' => $on ? 1 : 0]]);
}

function cover_status(array $d): array {
    $s = shelly($d, '/rpc/Cover.GetStatus?id=0');
    return $s === null ? ['online' => false] : ['online' => true, 'state' => $s['state'] ?? '', 'pos' => $s['current_pos'] ?? null];
}
function cover_cmd(array $d, string $c, int $pos): void {
    if ($c === 'pos') shelly($d, '/rpc/Cover.GoToPosition?id=0&pos=' . max(0, min(100, $pos)));
    elseif (in_array($c, ['open', 'close', 'stop'], true)) shelly($d, '/rpc/Cover.' . ucfirst($c) . '?id=0');
}

function dahua(array $d, string $q): ?string {
    $ch = curl_init("http://{$d['ip']}/cgi-bin/configManager.cgi?$q");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPAUTH => CURLAUTH_DIGEST, CURLOPT_USERPWD => $d['user'] . ':' . $d['pass']]);
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ($r === false || $code !== 200) ? null : $r;
}
function alarm_status(array $d): array {
    $r = dahua($d, 'action=getConfig&name=DisableLinkage');
    return $r === null ? ['online' => false] : ['online' => true, 'armed' => stripos($r, 'Enable=false') !== false];
}
function alarm_set(array $d, bool $arm): void {   // Enable=true significa "disinserimento attivo"
    dahua($d, 'action=setConfig&DisableLinkage%5B0%5D.Enable=' . ($arm ? 'false' : 'true'));
}

function tuya_req(string $method, string $path, string $body = '', ?string $token = null): ?array {
    $t = (string)(int)(microtime(true) * 1000);
    $sts = $method . "\n" . hash('sha256', $body) . "\n\n" . $path;
    $sign = strtoupper(hash_hmac('sha256', TUYA['id'] . ($token ?? '') . $t . $sts, TUYA['secret']));
    $h = ['client_id: ' . TUYA['id'], 'sign: ' . $sign, 't: ' . $t, 'sign_method: HMAC-SHA256', 'Content-Type: application/json'];
    if ($token) $h[] = 'access_token: ' . $token;
    $ch = curl_init(TUYA['host'] . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => $h, CURLOPT_CUSTOMREQUEST => $method]);
    if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $r = curl_exec($ch); curl_close($ch);
    $j = $r === false ? null : json_decode($r, true);
    return is_array($j) && !empty($j['success']) ? $j : null;
}
function tuya_token(): ?string {
    $f = __DIR__ . '/data/tuya_token.json';
    $c = is_file($f) ? json_decode(file_get_contents($f), true) : null;
    if ($c && $c['exp'] > time() + 60) return $c['token'];
    $j = tuya_req('GET', '/v1.0/token?grant_type=1');
    if (!$j) return null;
    @file_put_contents($f, json_encode(['token' => $j['result']['access_token'], 'exp' => time() + (int)$j['result']['expire_time']]), LOCK_EX);
    return $j['result']['access_token'];
}
function hsv2hex(float $h, float $s, float $v): string {
    $c = $v * $s; $x = $c * (1 - abs(fmod($h / 60, 2) - 1)); $m = $v - $c;
    [$r, $g, $b] = match ((int)floor($h / 60) % 6) { 0 => [$c, $x, 0], 1 => [$x, $c, 0], 2 => [0, $c, $x], 3 => [0, $x, $c], 4 => [$x, 0, $c], default => [$c, 0, $x] };
    return sprintf('#%02x%02x%02x', round(($r + $m) * 255), round(($g + $m) * 255), round(($b + $m) * 255));
}
function hex2hsv(string $hex): array {
    [$r, $g, $b] = array_map(fn($x) => $x / 255, sscanf($hex, '#%02x%02x%02x'));
    $mx = max($r, $g, $b); $dl = $mx - min($r, $g, $b);
    $h = $dl == 0 ? 0 : ($mx == $r ? 60 * fmod(($g - $b) / $dl, 6) : ($mx == $g ? 60 * (($b - $r) / $dl + 2) : 60 * (($r - $g) / $dl + 4)));
    return [(int)round($h < 0 ? $h + 360 : $h), $mx == 0 ? 0 : $dl / $mx, $mx];
}
function tuya_status(array $d): array {
    $tok = tuya_token(); if (!$tok) return ['online' => false];
    $j = tuya_req('GET', "/v1.0/devices/{$d['device_id']}/status", '', $tok);
    if (!$j) return ['online' => false];
    $m = array_column($j['result'], 'value', 'code');
    $c = $m['colour_data_v2'] ?? null;
    if (is_string($c)) $c = json_decode($c, true);
    $rgb = is_array($c) ? hsv2hex($c['h'] ?? 0, ($c['s'] ?? 0) / 1000, ($c['v'] ?? 1000) / 1000) : '#ffffff';
    return ['online' => true, 'on' => !empty($m['switch_led']), 'rgb' => $rgb];
}
function tuya_set(array $d, bool $on, ?string $hex): void {
    $tok = tuya_token(); if (!$tok) return;
    $cmds = [['code' => 'switch_led', 'value' => $on]];
    if ($on && $hex && preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
        [$h, $s, $v] = hex2hsv($hex);
        $cmds[] = ['code' => 'work_mode', 'value' => 'colour'];
        $cmds[] = ['code' => 'colour_data_v2', 'value' => ['h' => $h, 's' => (int)round($s * 1000), 'v' => max(10, (int)round($v * 1000))]];
    }
    tuya_req('POST', "/v1.0/devices/{$d['device_id']}/commands", json_encode(['commands' => $cmds]), $tok);
}

function rete(): array {   // letto da data/rete.xml, scritto dalla scansione nmap (cron sul Raspberry)
    $f = __DIR__ . '/data/rete.xml';
    $x = is_file($f) ? @simplexml_load_file($f) : false;
    if (!$x) return ['updated' => null, 'hosts' => []];
    $known = [];
    foreach (DEVICES as $d) if (!empty($d['ip'])) $known[$d['ip']][] = $d['room'] ?? $d['name'];
    $out = [];
    foreach ($x->host as $h) {
        if ((string)$h->status['state'] !== 'up') continue;
        $ip = $mac = $vendor = '';
        foreach ($h->address as $a) {
            if ((string)$a['addrtype'] === 'ipv4') $ip = (string)$a['addr'];
            if ((string)$a['addrtype'] === 'mac') { $mac = (string)$a['addr']; $vendor = (string)$a['vendor']; }
        }
        $out[] = ['ip' => $ip, 'mac' => $mac, 'vendor' => $vendor, 'name' => (string)$h->hostnames->hostname['name'],
                  'known' => isset($known[$ip]) ? implode(', ', array_unique($known[$ip])) : ''];
    }
    usort($out, fn($a, $b) => ip2long($a['ip']) <=> ip2long($b['ip']));
    return ['updated' => filemtime($f), 'hosts' => $out];
}

function code_locked(): bool {
    $f = __DIR__ . '/data/alarm_fail.json';
    $st = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    return ($st['until'] ?? 0) > time();
}
function code_ok(string $c): bool {
    $f = __DIR__ . '/data/alarm_fail.json';
    $ok = str_starts_with(ALARM_CODE, '$2y$') ? password_verify($c, ALARM_CODE) : hash_equals(ALARM_CODE, $c);
    if ($ok) { @unlink($f); return true; }
    $st = is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : [];
    $n = ($st['n'] ?? 0) + 1;   // dopo 5 errori: blocco di 5 minuti
    @file_put_contents($f, json_encode($n >= 5 ? ['n' => 0, 'until' => time() + 300] : ['n' => $n]), LOCK_EX);
    return false;
}

function shelly(array $d, string $path): ?array {
    $ch = curl_init("http://{$d['ip']}$path");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r === false ? null : (json_decode($r, true) ?? []);
}

function status(array $d): array {
    if (($d['brand'] ?? '') === 'tuya') return tuya_status($d);
    if ($d['type'] === 'cover') return cover_status($d);
    if ($d['type'] === 'alarm') return alarm_status($d);
    if (($d['brand'] ?? '') === 'meross') return meross_status($d);
    if ($d['type'] === 'rgb') {
        if ($d['gen'] === 1) {
            $s = shelly($d, '/color/0');
            return $s === null ? ['online' => false] : ['online' => true, 'on' => !empty($s['ison']),
                'rgb' => sprintf('#%02x%02x%02x', $s['red'] ?? 255, $s['green'] ?? 255, $s['blue'] ?? 255)];
        }
        $s = shelly($d, '/rpc/RGB.GetStatus?id=0');
        $c = $s['rgb'] ?? [255, 255, 255];
        return $s === null ? ['online' => false] : ['online' => true, 'on' => !empty($s['output']),
            'rgb' => sprintf('#%02x%02x%02x', ...$c)];
    }
    $s = $d['gen'] === 1 ? shelly($d, '/relay/0') : shelly($d, '/rpc/Switch.GetStatus?id=0');
    return $s === null ? ['online' => false]
        : ['online' => true, 'on' => !empty($d['gen'] === 1 ? $s['ison'] ?? false : $s['output'] ?? false)];
}

function act(array $d, bool $on, ?string $hex): void {
    if (($d['brand'] ?? '') === 'tuya') { tuya_set($d, $on, $hex); return; }
    if (($d['brand'] ?? '') === 'meross') { meross_set($d, $on); return; }
    if ($d['type'] === 'rgb' && $hex && preg_match('/^#[0-9a-f]{6}$/i', $hex)) {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $d['gen'] === 1
            ? shelly($d, "/color/0?turn=" . ($on ? 'on' : 'off') . "&red=$r&green=$g&blue=$b&gain=100")
            : shelly($d, "/rpc/RGB.Set?id=0&on=" . ($on ? 'true' : 'false') . "&rgb=[$r,$g,$b]");
        return;
    }
    $ep = $d['type'] === 'rgb' ? ($d['gen'] === 1 ? '/color/0?turn=' : '/rpc/RGB.Set?id=0&on=') : ($d['gen'] === 1 ? '/relay/0?turn=' : '/rpc/Switch.Set?id=0&on=');
    $v = $d['gen'] === 1 ? ($on ? 'on' : 'off') : ($on ? 'true' : 'false');
    shelly($d, $ep . $v);
}

function icon(string $n): string {
    $p = ['dash' => '<rect x="3" y="3" width="7" height="9" rx="2"/><rect x="14" y="3" width="7" height="5" rx="2"/><rect x="14" y="12" width="7" height="9" rx="2"/><rect x="3" y="16" width="7" height="5" rx="2"/>',
          'wifi' => '<path d="M2 9a15 15 0 0 1 20 0"/><path d="M5.5 12.5a10 10 0 0 1 13 0"/><path d="M9 16a5 5 0 0 1 6 0"/><circle cx="12" cy="19.5" r="1" fill="currentColor"/>',
          'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.2a6.5 6.5 0 0 1 3.5 5.8"/>',
          'out' => '<path d="M10 4H5a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h5"/><path d="M15 8l4 4-4 4M19 12H9"/>'];
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p[$n] . '</svg>';
}
function nav(string $active, bool $admin): string {
    $items = [['', 'dash', 'Dashboard'], ['wifi', 'wifi', 'Wi‑Fi']];
    if ($admin) $items[] = ['utenti', 'users', 'Utenti'];
    $h = '<header class="top"><span class="brand">Domus Home</span><nav>';
    foreach ($items as [$k, $i, $l])
        $h .= '<a href="' . ($k ? "?page=$k" : './') . '"' . ($active === $k ? ' class="act" aria-current="page"' : '') . '>' . icon($i) . "<span>$l</span></a>";
    return $h . '<a href="?logout=1">' . icon('out') . '<span>Esci</span></a></nav></header>';
}
function wifi(array $d): array {
    if (($d['brand'] ?? '') === 'meross') {
        $p = meross($d, 'Appliance.System.Runtime', 'GET');
        return $p === null ? ['online' => false] : ['online' => true, 'ssid' => 'Meross', 'rssi' => null, 'signal' => $p['runtime']['signal'] ?? null, 'ip' => $d['ip']];
    }
    $s = $d['gen'] === 1 ? shelly($d, '/status') : shelly($d, '/rpc/Wifi.GetStatus');
    if ($s === null) return ['online' => false];
    $w = $d['gen'] === 1 ? ($s['wifi_sta'] ?? []) : $s;
    return ['online' => true, 'ssid' => $w['ssid'] ?? '-', 'rssi' => $w['rssi'] ?? null, 'ip' => $w['ip'] ?? $w['sta_ip'] ?? $d['ip']];
}

// --- Logout / login
if (isset($_GET['logout'])) { session_destroy(); header('Location: ./'); exit; }
$error = '';
if (($_POST['login'] ?? null) !== null) {
    usleep(500000);
    $name = strtolower(trim($_POST['user'] ?? '')); $u = users();
    if (isset($u[$name]) && password_verify($_POST['pass'] ?? '', $u[$name]['hash'])) {
        session_regenerate_id(true);
        $_SESSION['auth'] = true; $_SESSION['user'] = $name; $_SESSION['role'] = $u[$name]['role'];
        header('Location: ./'); exit;
    }
    $error = 'Utente o password non corretti.';
}
$auth = !empty($_SESSION['auth']);
$admin = $auth && ($_SESSION['role'] ?? '') === 'admin';
$page = $_GET['page'] ?? '';
$msg = ''; $bad = false;
if ($admin && $page === 'utenti' && $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, $_POST['csrf'] ?? '')) {
    $u = users(); $op = $_POST['op'] ?? ''; $name = strtolower(trim($_POST['name'] ?? '')); $pw = $_POST['pass'] ?? '';
    if ($op === 'add') {
        if (!preg_match('/^[a-z0-9_.-]{3,32}$/', $name)) { $bad = true; $msg = 'Nome utente: da 3 a 32 caratteri, solo lettere, numeri, punto e trattino.'; }
        elseif (isset($u[$name])) { $bad = true; $msg = 'Questo utente esiste già.'; }
        elseif (strlen($pw) < 8) { $bad = true; $msg = 'La password deve avere almeno 8 caratteri.'; }
        else { $u[$name] = ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'role' => ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'utente']; $msg = "Utente $name aggiunto."; }
    } elseif ($op === 'pass' && isset($u[$name])) {
        if (strlen($pw) < 8) { $bad = true; $msg = 'La password deve avere almeno 8 caratteri.'; }
        else { $u[$name]['hash'] = password_hash($pw, PASSWORD_DEFAULT); $msg = "Password di $name aggiornata."; }
    } elseif ($op === 'del' && isset($u[$name])) {
        if ($name === $_SESSION['user']) { $bad = true; $msg = 'Non puoi eliminare il tuo utente.'; }
        else { unset($u[$name]); $msg = "Utente $name eliminato."; }
    }
    if (!$bad) save_users($u);
}

// --- API (solo autenticata)
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    if (!$auth) { http_response_code(401); echo '{"error":"auth"}'; exit; }
    if ($_GET['api'] === 'status') {
        echo json_encode(array_map('status', DEVICES)); exit;
    }
    if ($_GET['api'] === 'channels' && $admin) {   // diagnostica: elenca i canali della ciabatta
        $d = DEVICES[$_GET['id'] ?? 'ciab0'] ?? null; $p = $d ? meross($d, 'Appliance.System.All', 'GET') : null;
        echo json_encode($p['all']['digest']['togglex'] ?? $p['all']['digest'] ?? null, JSON_PRETTY_PRINT); exit;
    }
    if ($_GET['api'] === 'rete') { echo json_encode(rete()); exit; }
    if ($_GET['api'] === 'wifi') { echo json_encode(array_map('wifi', array_filter(DEVICES, fn($x) => $x['type'] !== 'alarm' && ($x['brand'] ?? '') !== 'tuya'))); exit; }
    if ($_GET['api'] === 'set' && hash_equals($csrf, $_SERVER['HTTP_X_CSRF'] ?? '')) {
        $in = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!empty($in['esco']) || ($in['alarm'] ?? '') === 'arm') {
            if (code_locked()) { echo json_encode(['error' => 'Troppi tentativi. Riprova tra 5 minuti.']); exit; }
            if (!code_ok((string)($in['code'] ?? ''))) { echo json_encode(['error' => 'Codice errato.']); exit; }
        }
        if (!empty($in['esco'])) {   // Esco: spegne tutte le luci e inserisce l'allarme
            foreach (DEVICES as $d) {
                if ($d['type'] === 'rgb' || !empty($d['light'])) act($d, false, null);
                elseif ($d['type'] === 'alarm') alarm_set($d, true);
            }
            echo json_encode(['ok' => true]); exit;
        }
        if (isset($in['alarm'])) {
            $d = DEVICES[$in['id'] ?? ''] ?? null;
            if ($d && $d['type'] === 'alarm') alarm_set($d, $in['alarm'] === 'arm');
            echo json_encode(['ok' => true]); exit;
        }
        if (isset($in['cover'])) {
            $ids = ($in['id'] ?? '') === 'tapparelle' ? array_keys(array_filter(DEVICES, fn($x) => $x['type'] === 'cover')) : [$in['id'] ?? ''];
            foreach ($ids as $id) if ((DEVICES[$id]['type'] ?? '') === 'cover') cover_cmd(DEVICES[$id], (string)$in['cover'], (int)($in['pos'] ?? 0));
            echo json_encode(['ok' => true]); exit;
        }
        $targets = ($in['id'] ?? '') === 'tutto' ? array_keys(DEVICES) : [$in['id'] ?? ''];
        foreach ($targets as $id) if (isset(DEVICES[$id]) && !in_array(DEVICES[$id]['type'], ['cover', 'alarm'], true)) act(DEVICES[$id], (bool)($in['on'] ?? false), $in['rgb'] ?? null);
        echo json_encode(['ok' => true]); exit;
    }
    http_response_code(400); echo '{"error":"bad"}'; exit;
}
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Domus Home</title>
<style>
:root{--bg:#eef1f4;--card:#fff;--ink:#16202a;--mute:#6b7886;--line:#dde3e9;--on:#0f766e;--onbg:#e3f4f1;--bad:#b42318}
@media(prefers-color-scheme:dark){:root{--bg:#10161c;--card:#18212a;--ink:#e8edf2;--mute:#8d9aa7;--line:#27323d;--on:#2dd4bf;--onbg:#12332f;--bad:#f97066}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:720px;margin:0 auto;padding:28px 18px 60px}
header{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:22px}
h1{font-size:26px;margin:0;letter-spacing:-.02em}
a{color:var(--mute);font-size:14px}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin-bottom:12px;display:flex;align-items:center;gap:16px;transition:background .2s,border-color .2s}
.card.on{background:var(--onbg);border-color:var(--on)}
.card h2{font-size:17px;margin:0}
.card small{color:var(--mute)}
.grow{flex:1}
.sw{width:54px;height:30px;border-radius:15px;background:var(--line);border:0;position:relative;cursor:pointer;flex:none}
.sw::after{content:"";position:absolute;top:3px;left:3px;width:24px;height:24px;border-radius:50%;background:#fff;transition:transform .2s}
.on .sw{background:var(--on)}.on .sw::after{transform:translateX(24px)}
.sw:focus-visible,input:focus-visible,button:focus-visible{outline:3px solid var(--on);outline-offset:2px}
input[type=color]{width:44px;height:44px;border:0;background:none;padding:0;cursor:pointer}
.all{display:flex;gap:10px;margin-bottom:18px}
.all button,.login button{flex:1;padding:12px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-weight:600;cursor:pointer}
.all button:first-child,.login button{background:var(--on);border-color:var(--on);color:#fff}
.hero{flex-direction:column;align-items:stretch;gap:20px;padding:24px;background:radial-gradient(120% 140% at 0% 0%,color-mix(in srgb,var(--glow) 30%,var(--card)),var(--card) 70%);border-color:color-mix(in srgb,var(--glow) 55%,var(--line))}
.hero:not(.on){background:var(--card);border-color:var(--line)}
.row{display:flex;align-items:center;gap:16px}
.lamp{width:58px;height:58px;border-radius:18px;background:var(--glow);box-shadow:0 0 34px color-mix(in srgb,var(--glow) 65%,transparent);flex:none;transition:background .2s}
.hero:not(.on) .lamp{background:var(--line);box-shadow:none}
.hero.on .sw{background:var(--glow)}
.hero h2{font-size:20px}
.dots{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.dot{width:38px;height:38px;border-radius:50%;border:2px solid var(--card);box-shadow:0 0 0 1px var(--line);cursor:pointer;padding:0}
.grid,.rg{display:grid;gap:12px}
.grid .card,.grid .all{margin:0}
@media(max-width:819px){
.all{position:fixed;left:0;right:0;bottom:0;z-index:5;padding:12px 18px calc(12px + env(safe-area-inset-bottom,0px));background:color-mix(in srgb,var(--bg) 88%,transparent);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px);border-top:1px solid var(--line)}
.all{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.all button{padding:12px}
body{padding-bottom:150px}
}
@media(min-width:820px){
main{max-width:1040px;padding-top:48px}
h1{font-size:32px}
.rg{grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.grid{gap:24px}
.all{grid-column:1/-1;max-width:760px}
.hero{grid-column:span 2}
.dots{gap:14px}
}
@media(hover:hover){.dot:hover{transform:scale(1.1)}.all button:hover{filter:brightness(1.06)}}
.f{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.f input,.f select{padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--bg);color:var(--ink);font:inherit;min-width:0;flex:1}
.f button{padding:10px 14px;border-radius:10px;border:1px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-weight:600;cursor:pointer}
.f .danger{color:var(--bad)}
.f.add{flex-direction:column;align-items:stretch;margin-top:22px}
.f.add button{background:var(--on);border-color:var(--on);color:var(--bg)}
.ucard{flex-wrap:wrap}
.okmsg{color:var(--on)}
[hidden]{display:none!important}
.top{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:24px;padding:8px 8px 8px 20px;background:var(--card);border:1px solid var(--line);border-radius:18px;position:sticky;top:10px;z-index:6}
.brand{font-weight:700;font-size:18px;letter-spacing:-.02em}
.top nav{display:flex;gap:4px}
.top a{display:flex;align-items:center;gap:8px;padding:9px 14px;border-radius:12px;color:var(--mute);text-decoration:none;font-size:15px;font-weight:600}
.top a:hover{color:var(--ink);background:var(--bg)}
.top a.act{background:var(--onbg);color:var(--on)}
@media(max-width:559px){.top a span{display:none}.top a{padding:10px}.top{padding-left:16px}.brand{font-size:16px}}
.bars{display:flex;align-items:flex-end;gap:3px;height:22px}
.bars i{width:5px;background:var(--line);border-radius:2px}
.bars i:nth-child(1){height:6px}.bars i:nth-child(2){height:11px}.bars i:nth-child(3){height:16px}.bars i:nth-child(4){height:22px}
.bars i.f{background:var(--on)}
.rn{margin:6px 0 10px;font-size:18px;letter-spacing:-.01em}
.cvb{display:flex;gap:6px}
.cvb button{width:44px;height:44px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--ink);font-size:16px;cursor:pointer}
.cvb button:hover{background:var(--bg)}
.cvb .tb{width:auto;padding:0 14px;font:inherit;font-weight:600;font-size:15px}
.cov input[type=range]{width:100%;margin-top:10px;accent-color:var(--on)}
.hr{display:flex;justify-content:space-between;flex-wrap:wrap;gap:4px 12px;padding:10px 0;border-bottom:1px solid var(--line)}
dialog{border:1px solid var(--line);border-radius:18px;background:var(--card);color:var(--ink);padding:24px;width:calc(100% - 32px);max-width:340px}
dialog::backdrop{background:rgba(0,0,0,.55)}
dialog h2{font-size:18px;margin:0 0 14px}
dialog input{width:100%;padding:14px;font:inherit;font-size:24px;text-align:center;letter-spacing:.4em;border:1px solid var(--line);border-radius:12px;background:var(--bg);color:var(--ink)}
dialog .err{min-height:1.5em;margin:8px 0 0;font-size:14px}
.dlg-b{display:flex;gap:10px;margin-top:12px}
.dlg-b button{flex:1;padding:12px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-weight:600;cursor:pointer}
.dlg-b .pri{background:var(--on);border-color:var(--on);color:var(--bg)}
.off{opacity:.55}.err{color:var(--bad)}
.login{max-width:340px;margin:12vh auto;padding:0 18px}
.login input[type=text],.login input[type=password]{width:100%;padding:12px;margin:6px 0 14px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font:inherit}
</style>
</head>
<body>
<?php if (!$auth): ?>
<form class="login" method="post">
  <h1>Domus Home</h1>
  <p style="color:var(--mute)">Accedi per controllare la tua casa.</p>
  <label>Utente<input type="text" name="user" autocomplete="username" required autofocus></label>
  <label>Password<input type="password" name="pass" autocomplete="current-password" required></label>
  <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <button name="login" value="1">Accedi</button>
</form>
<?php elseif ($page === 'wifi'): ?>
<main>
  <?= nav('wifi', $admin) ?>
  <div class="grid">
  <?php foreach (array_filter(DEVICES, fn($x) => $x['type'] !== 'alarm' && ($x['brand'] ?? '') !== 'tuya') as $id => $d): ?>
  <div class="card off" id="w-<?= $id ?>">
    <div class="grow"><h2><?= htmlspecialchars($d['name']) ?></h2><small>Controllo…</small></div>
    <span class="bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
  </div>
  <?php endforeach; ?>
  </div>
  <h2 class="rn" style="margin-top:28px">Dispositivi collegati <span id="rn-n"></span></h2>
  <div class="card" id="rete" style="flex-direction:column;align-items:stretch;gap:0"><small>Caricamento…</small></div>
</main>
<script>
const WIDS = <?= json_encode(array_keys(array_filter(DEVICES, fn($x) => $x['type'] !== 'alarm' && ($x['brand'] ?? '') !== 'tuya'))) ?>;
async function wf(){
  try{
    const r = await fetch('?api=wifi'); if(r.status===401) return location.reload();
    const s = await r.json();
    for(const id of WIDS){
      const d = s[id], c = document.getElementById('w-'+id), t = c.querySelector('small');
      c.classList.toggle('on', !!d.online); c.classList.toggle('off', !d.online);
      if(!d.online){ t.textContent = 'Non raggiungibile'; continue; }
      const lv = d.signal!=null ? (d.signal>75?4:d.signal>50?3:d.signal>25?2:1) : d.rssi==null ? 0 : d.rssi>-55 ? 4 : d.rssi>-67 ? 3 : d.rssi>-78 ? 2 : 1;
      c.querySelectorAll('.bars i').forEach((b,i)=>b.classList.toggle('f', i<lv));
      t.textContent = d.ssid + ', ' + (d.signal!=null ? 'segnale ' + d.signal + '%' : (d.rssi ?? '?') + ' dBm') + ', IP ' + d.ip;
    }
  }catch(e){}
}
const esc = t => String(t).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]);
async function rete(){
  try{
    const s = await (await fetch('?api=rete')).json(), el = document.getElementById('rete');
    if(!s.updated){ el.innerHTML = '<small>Nessun dato: attiva la scansione di rete sul Raspberry (nmap + cron).</small>'; return; }
    document.getElementById('rn-n').textContent = '(' + s.hosts.length + ')';
    el.innerHTML = s.hosts.map(h => '<div class="hr"><b>' + esc(h.known || h.name || h.vendor || 'Dispositivo') + '</b><small>' + esc(h.ip) + ', ' + esc(h.vendor || h.mac) + '</small></div>').join('')
      + '<small style="margin-top:10px">Aggiornato alle ' + new Date(s.updated*1000).toLocaleTimeString('it-IT',{hour:'2-digit',minute:'2-digit'}) + '</small>';
  }catch(e){}
}
wf(); rete(); setInterval(wf, 10000); setInterval(rete, 60000);
</script>
<?php elseif ($page === 'utenti' && $admin): ?>
<main>
  <?= nav('utenti', true) ?>
  <?php if ($msg): ?><p class="<?= $bad ? 'err' : 'okmsg' ?>"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
  <?php foreach (users() as $n => $info): $en = htmlspecialchars($n); ?>
  <div class="card ucard">
    <div class="grow"><h2><?= $en ?></h2><small><?= $info['role'] === 'admin' ? 'Amministratore' : 'Utente' ?></small></div>
    <form class="f" method="post" action="?page=utenti">
      <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="name" value="<?= $en ?>">
      <input type="password" name="pass" placeholder="Nuova password" autocomplete="new-password" required>
      <button name="op" value="pass">Cambia</button>
      <?php if ($n !== $_SESSION['user']): ?><button name="op" value="del" class="danger" formnovalidate onclick="return confirm('Eliminare questo utente?')">Elimina</button><?php endif; ?>
    </form>
  </div>
  <?php endforeach; ?>
  <form class="card f add" method="post" action="?page=utenti">
    <h2>Aggiungi utente</h2>
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="op" value="add">
    <input name="name" placeholder="Nome utente" autocomplete="off" required>
    <input type="password" name="pass" placeholder="Password (minimo 8 caratteri)" autocomplete="new-password" required>
    <select name="role" aria-label="Ruolo"><option value="utente">Utente</option><option value="admin">Amministratore</option></select>
    <button>Aggiungi utente</button>
  </form>
</main>
<?php else: ?>
<main>
  <?= nav('', $admin) ?>
  <div class="grid">
  <div class="all">
    <button onclick="set('tutto',true)">Accendi tutto</button>
    <button onclick="set('tutto',false)">Spegni tutto</button>
    <?php $alarmId = array_key_first(array_filter(DEVICES, fn($x) => $x['type'] === 'alarm')); if ($alarmId): ?>
    <button id="alb" data-id="<?= $alarmId ?>">Allarme</button>
    <button onclick="esco()">Esco</button>
    <?php endif; ?>
  </div>
  <?php if (array_filter(DEVICES, fn($x) => $x['type'] === 'cover')): ?>
  <div class="card"><div class="grow"><h2>Tutte le tapparelle</h2></div>
    <div class="cvb"><button onclick="cov('tapparelle','open')" aria-label="Alza tutte">▲</button><button onclick="cov('tapparelle','close')" aria-label="Abbassa tutte">▼</button></div></div>
  <?php endif; ?>
  <?php $rooms = []; foreach (DEVICES as $id => $d) $rooms[$d['room'] ?? 'Altro'][$id] = $d;
  foreach ($rooms as $rn => $list): ?>
  <section><h2 class="rn"><?= htmlspecialchars($rn) ?></h2><div class="rg">
  <?php foreach ($list as $id => $d): $n = htmlspecialchars($d['name']); if ($d['type'] === 'rgb'): ?>
  <div class="card hero off" id="c-<?= $id ?>" style="--glow:#2dd4bf">
    <div class="row">
      <div class="lamp"></div>
      <div class="grow"><h2><?= $n ?></h2><small id="s-<?= $id ?>">Controllo…</small></div>
      <button class="sw" id="b-<?= $id ?>" aria-label="Accendi o spegni <?= $n ?>"></button>
    </div>
    <div class="dots">
      <?php foreach (['#2dd4bf'=>'Verde acqua','#ffffff'=>'Bianco','#ffb347'=>'Bianco caldo','#60a5fa'=>'Azzurro','#a78bfa'=>'Viola','#f472b6'=>'Rosa'] as $hx => $lbl): ?>
      <button class="dot" data-c="<?= $hx ?>" style="background:<?= $hx ?>" aria-label="<?= $lbl ?>"></button>
      <?php endforeach; ?>
      <input type="color" id="col-<?= $id ?>" value="#2dd4bf" aria-label="Colore personalizzato">
    </div>
  </div>
  <?php elseif ($d['type'] === 'alarm'): ?>
  <div class="card off" id="c-<?= $id ?>">
    <div class="grow"><h2><?= $n ?></h2><small id="s-<?= $id ?>">Controllo…</small></div>
    <div class="cvb"><button class="tb" data-al="arm">Inserisci</button><button class="tb" data-al="disarm">Disinserisci</button></div>
  </div>
  <?php elseif ($d['type'] === 'cover'): ?>
  <div class="card off cov" id="c-<?= $id ?>">
    <div class="grow"><h2><?= $n ?></h2><small id="s-<?= $id ?>">Controllo…</small>
      <input type="range" min="0" max="100" value="0" id="p-<?= $id ?>" aria-label="Posizione <?= $n ?>"></div>
    <div class="cvb"><button data-a="open" aria-label="Alza">▲</button><button data-a="stop" aria-label="Ferma">■</button><button data-a="close" aria-label="Abbassa">▼</button></div>
  </div>
  <?php else: ?>
  <div class="card off" id="c-<?= $id ?>">
    <div class="grow"><h2><?= $n ?></h2><small id="s-<?= $id ?>">Controllo…</small></div>
    <button class="sw" id="b-<?= $id ?>" aria-label="Accendi o spegni <?= $n ?>"></button>
  </div>
  <?php endif; endforeach; ?>
  </div></section>
  <?php endforeach; ?>
  </div>
</main>
<dialog id="codedlg">
  <form method="dialog" onsubmit="return false">
    <h2>Codice</h2>
    <input type="password" inputmode="numeric" autocomplete="off" aria-label="Codice" required>
    <p class="err" role="alert"></p>
    <div class="dlg-b"><button type="button" data-x>Annulla</button><button class="pri">Conferma</button></div>
  </form>
</dialog>
<script>
const CSRF = <?= json_encode($csrf) ?>, IDS = <?= json_encode(array_keys(DEVICES)) ?>;
const state = {};
async function call(body){
  const r = await fetch('?api=set',{method:'POST',headers:{'X-CSRF':CSRF},body:JSON.stringify(body)});
  const j = await r.json().catch(() => ({}));
  refresh();
  return j;
}
function askCode(title, err){
  return new Promise(res => {
    const dg = document.getElementById('codedlg'), f = dg.querySelector('form'), i = dg.querySelector('input');
    dg.querySelector('h2').textContent = title; dg.querySelector('.err').textContent = err || ''; i.value = '';
    f.onsubmit = e => { e.preventDefault(); const v = i.value; dg.close(); res(v); };
    dg.querySelector('[data-x]').onclick = () => { dg.close(); res(null); };
    dg.oncancel = () => res(null);
    dg.showModal(); i.focus();
  });
}
async function withCode(title, body){
  let err = '';
  for(;;){
    const code = await askCode(title, err); if(code === null) return;
    const j = await call({...body, code});
    if(!j.error) return j;
    err = j.error;
  }
}
function alarmAct(id, a){
  if(a === 'arm') return withCode('Codice per inserire l’allarme', {id, alarm:'arm'});
  if(confirm('Disinserire l’allarme?')) return call({id, alarm:'disarm'});
}
function esco(){ withCode('Codice per uscire: inserisce l’allarme e spegne le luci', {esco:true}); }
function set(id,on,rgb){ call({id,on,rgb}); }
function cov(id,cover,pos){ call({id,cover,pos}); }
async function refresh(){
  try{
    const r = await fetch('?api=status'); if(r.status===401) return location.reload();
    const s = await r.json();
    for(const id of IDS){
      const d = s[id], c = document.getElementById('c-'+id);
      state[id] = d;
      c.classList.toggle('on', !!d.on);
      c.classList.toggle('off', !d.online);
      let txt = !d.online ? 'Non raggiungibile' : d.on ? 'Acceso' : 'Spento';
      if(d.online && d.state!==undefined){
        txt = ({open:'Aperta',closed:'Chiusa',opening:'Si sta aprendo',closing:'Si sta chiudendo',stopped:'Ferma'})[d.state] || d.state;
        if(d.pos!=null) txt += ', ' + d.pos + '%';
        const sl = document.getElementById('p-'+id); if(sl && d.pos!=null && document.activeElement!==sl) sl.value = d.pos;
        c.classList.toggle('on', d.state==='open' || d.state==='opening');
      }
      if(d.online && d.armed!==undefined){ txt = d.armed ? 'Inserito' : 'Disinserito'; c.classList.toggle('on', d.armed);
        const ab = document.getElementById('alb'); if(ab && ab.dataset.id===id) ab.textContent = d.armed ? 'Disinserisci' : 'Inserisci allarme'; }
      document.getElementById('s-'+id).textContent = txt;
      const col = document.getElementById('col-'+id);
      if(col && d.rgb && document.activeElement!==col){ col.value = d.rgb; c.style.setProperty('--glow', d.rgb); }
    }
  }catch(e){}
}
for(const id of IDS){
  const bt = document.getElementById('b-'+id);
  if(bt) bt.onclick = () => set(id, !state[id]?.on, document.getElementById('col-'+id)?.value);
  document.querySelectorAll('#c-'+id+' .cvb button[data-a]').forEach(b => b.onclick = () => cov(id, b.dataset.a));
  document.querySelectorAll('#c-'+id+' [data-al]').forEach(b => b.onclick = () => alarmAct(id, b.dataset.al));
  const ps = document.getElementById('p-'+id); if(ps) ps.onchange = () => cov(id, 'pos', +ps.value);
  const col = document.getElementById('col-'+id);
  if(col){
    const c = document.getElementById('c-'+id);
    col.onchange = () => set(id, true, col.value);
    c.querySelectorAll('.dot').forEach(dt => dt.onclick = () => { c.style.setProperty('--glow', dt.dataset.c); col.value = dt.dataset.c; set(id, true, dt.dataset.c); });
  }
}
const alb = document.getElementById('alb');
if(alb) alb.onclick = () => alarmAct(alb.dataset.id, state[alb.dataset.id]?.armed ? 'disarm' : 'arm');
refresh(); setInterval(refresh, 5000);
</script>
<?php endif; ?>
</body>
</html>
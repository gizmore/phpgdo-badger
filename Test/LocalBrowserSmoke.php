<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run-local-only') { exit(1); }
$stage = 'initialization';
set_exception_handler(static function (Throwable $ex) use (&$stage): void {
    fwrite(STDERR, "Local browser smoke test failed at {$stage}: " . $ex::class . PHP_EOL);
    exit(1);
});
$root = dirname(__DIR__, 3);
require $root . '/protected/config_badger.php';
$db = new mysqli(GDO_DB_HOST, GDO_DB_USER, GDO_DB_PASS, GDO_DB_NAME, GDO_DB_PORT);
$identity = $db->query('SELECT DATABASE() AS name, CURRENT_USER() AS principal')->fetch_assoc();
if (($identity['name'] ?? null) !== 'phpgdo_badger' || ($identity['principal'] ?? null) !== 'mira@localhost') { throw new RuntimeException(); }
$suffix = bin2hex(random_bytes(6));
$name = 'badgerbrowser_' . $suffix;
$password = bin2hex(random_bytes(24));
$badgeName = 'Private browser ' . $suffix;
$profile = sys_get_temp_dir() . '/badger-browser-test-' . bin2hex(random_bytes(12));
$id = null;
$process = null;
$session = null;
$port = 0;
$driver = static function (string $method, string $path, array|object|null $payload = null) use (&$port, &$stage): array {
    $handle = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_CUSTOMREQUEST => $method]);
    if ($payload !== null)
    {
        curl_setopt_array($handle, [CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    try
    {
        $body = curl_exec($handle);
        if (!is_string($body)) { throw new RuntimeException(); }
        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new RuntimeException(); }
        if (curl_getinfo($handle, CURLINFO_HTTP_CODE) >= 400)
        {
            $error = $data['value']['error'] ?? '';
            if (in_array($error, ['no such element', 'element not interactable', 'element click intercepted', 'invalid selector', 'unknown error'], true)) { $stage .= ' (' . $error . ')'; }
            throw new RuntimeException();
        }
        return $data;
    }
    finally { curl_close($handle); }
};
try
{
    $stage = 'create synthetic account';
    $db->begin_transaction();
    $create = $db->prepare("INSERT INTO gdo_user (user_type, user_name, user_display_name) VALUES ('member', ?, ?)");
    $create->bind_param('ss', $name, $name);
    $create->execute();
    $id = (int) $db->insert_id;
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $setting = $db->prepare("INSERT INTO gdo_usersetting (uset_user, uset_name, uset_var) VALUES (?, 'password', ?)");
    $setting->bind_param('is', $id, $hash);
    $setting->execute();
    $db->commit();
    $stage = 'start isolated browser';
    if (!mkdir($profile, 0700)) { throw new RuntimeException(); }
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) { throw new RuntimeException(); }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int) substr(strrchr($address, ':'), 1);
    $process = proc_open(['/usr/bin/chromedriver', '--port=' . $port, '--allowed-ips=127.0.0.1'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException(); }
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++)
    {
        try { $ready = ($driver('GET', '/status')['value']['ready'] ?? false) === true; }
        catch (Throwable) { usleep(100000); }
        if ($ready) { break; }
    }
    if (!$ready) { throw new RuntimeException(); }
    $response = $driver('POST', '/session', ['capabilities' => ['alwaysMatch' => [
        'browserName' => 'chrome', 'acceptInsecureCerts' => true,
        'goog:chromeOptions' => ['binary' => '/usr/bin/chromium', 'args' => [
            '--headless', '--no-sandbox', '--disable-gpu', '--no-proxy-server', '--window-size=1280,900', '--user-data-dir=' . $profile,
            '--host-resolver-rules=MAP badger.mogwai.mira-gpt.org 127.0.0.1',
        ]],
    ]]]);
    $session = $response['value']['sessionId'] ?? null;
    if (!is_string($session) || !preg_match('/\A[a-f0-9]+\z/D', $session)) { throw new RuntimeException(); }
    $prefix = '/session/' . $session;
    $element = static function (string $selector) use ($driver, $prefix): string {
        $result = $driver('POST', $prefix . '/element', ['using' => 'css selector', 'value' => $selector]);
        $id = $result['value']['element-6066-11e4-a52e-4f735466cecf'] ?? null;
        if (!is_string($id)) { throw new RuntimeException(); }
        return rawurlencode($id);
    };
    $stage = 'browser login';
    $driver('POST', $prefix . '/url', ['url' => 'https://badger.mogwai.mira-gpt.org:8443/login.form.html']);
    $stage = 'browser login fields';
    foreach (['input[name="login"]' => $name, 'input[name="password"]' => $password] as $selector => $value)
    {
        $driver('POST', $prefix . '/element/' . $element($selector) . '/value', ['text' => $value]);
    }
    $stage = 'browser login submission';
    $invalid = $driver('POST', $prefix . '/execute/sync', ['script' => 'return [...document.querySelector("form:has(input[name=password])").elements].filter(e => e.willValidate && !e.validity.valid).map(e => e.name);', 'args' => []])['value'] ?? [];
    if ($invalid) { $stage .= ' (form validation)'; throw new RuntimeException(); }
    $driver('POST', $prefix . '/execute/sync', ['script' => 'document.querySelector("form:has(input[name=password]) input[name=submit]").scrollIntoView({block:"center"});', 'args' => []]);
    $driver('POST', $prefix . '/element/' . $element('form:has(input[name="password"]) input[name="submit"], form:has(input[name="password"]) button[name="submit"]') . '/click', (object) []);
    $cookieNames = [];
    for ($attempt = 0; $attempt < 50; $attempt++)
    {
        $cookies = $driver('GET', $prefix . '/cookie')['value'] ?? [];
        $cookieNames = array_map(static fn ($cookie) => $cookie['name'] ?? '', is_array($cookies) ? $cookies : []);
        if (in_array('BADGER_tls', $cookieNames, true)) { break; }
        usleep(100000);
    }
    if (!in_array('BADGER_tls', $cookieNames, true))
    {
        $current = (string) ($driver('GET', $prefix . '/url')['value'] ?? '');
        $stage = 'browser login session cookie (port ' . (int) (parse_url($current, PHP_URL_PORT) ?? 443) . ')';
        $history = $db->prepare('SELECT COUNT(*) AS n FROM gdo_loginhistory WHERE lh_user_id=?');
        $history->bind_param('i', $id);
        $history->execute();
        $stage .= ' (successful logins ' . (int) ($history->get_result()->fetch_assoc()['n'] ?? 0) . ')';
        $visible = $driver('POST', $prefix . '/execute/sync', ['script' => 'return document.body ? document.body.innerText : "";', 'args' => []])['value'] ?? '';
        $safeText = str_replace([$name, $password], '[redacted]', (string) $visible);
        if (str_contains($safeText, 'Maximum')) { $stage .= ' (maximum attempts notice)'; }
        if (str_contains($safeText, 'Invalid')) { $stage .= ' (invalid input notice)'; }
        $stage .= ' (cookie names ' . implode(',', array_filter($cookieNames, static fn ($v) => is_string($v) && preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $v))) . ')';
        $stage .= str_contains($safeText, 'Logout') ? ' (logged-in navigation)' : '';
        throw new RuntimeException();
    }
    $stage = 'wait for authenticated navigation';
    $authenticated = false;
    for ($attempt = 0; $attempt < 50; $attempt++)
    {
        $authenticated = ($driver('POST', $prefix . '/execute/sync', ['script' => 'return !!document.querySelector("a[href*=logout]");', 'args' => []])['value'] ?? false) === true;
        if ($authenticated) { break; }
        usleep(100000);
    }
    if (!$authenticated) { throw new RuntimeException(); }
    $stage = 'authenticated badge form';
    $driver('POST', $prefix . '/url', ['url' => 'https://badger.mogwai.mira-gpt.org:8443/badger.add.html']);
    $source = $driver('GET', $prefix . '/source')['value'] ?? '';
    if (!is_string($source) || !str_contains($source, 'name="bb_name"'))
    {
        $current = (string) ($driver('GET', $prefix . '/url')['value'] ?? '');
        $stage .= str_starts_with($current, 'chrome-error:') ? ' (network error)' : (str_contains((string) $source, 'name="password"') ? ' (login page)' : ' (other page)');
        $stage .= ' (port ' . (int) (parse_url($current, PHP_URL_PORT) ?? 443) . ', contains login ' . (str_contains($current, '/login') ? 'yes' : 'no') . ')';
        $stage .= str_contains((string) $source, 'ERR_CONNECTION_REFUSED') ? ' (connection refused)' : '';
        $attempts = $db->prepare('SELECT COUNT(*) AS n FROM gdo_loginattempt WHERE la_user_id=?');
        $attempts->bind_param('i', $id);
        $attempts->execute();
        $stage .= ' (failed logins ' . (int) ($attempts->get_result()->fetch_assoc()['n'] ?? 0) . ')';
        $stage .= ' (cookie count ' . count($cookieNames) . ')';
        throw new RuntimeException();
    }
    $element('input[name="bb_name"]');
    $stage = 'authenticated form token';
    $element('input[name="xsrf"]');
    $stage = 'browser badge creation';
    $driver('POST', $prefix . '/element/' . $element('input[name="bb_name"]') . '/value', ['text' => $badgeName]);
    $stage = 'browser badge privacy';
    $driver('POST', $prefix . '/element/' . $element('select[name="bb_visibility"] option[value="acl_noone"]') . '/click', (object) []);
    $stage = 'browser badge submission';
    $driver('POST', $prefix . '/execute/sync', ['script' => 'document.querySelector("form:has(input[name=bb_name]) input[name=submit]").scrollIntoView({block:"center"});', 'args' => []]);
    $clickable = false;
    for ($attempt = 0; $attempt < 50; $attempt++)
    {
        $clickable = ($driver('POST', $prefix . '/execute/sync', ['script' => 'const e=document.querySelector("form:has(input[name=bb_name]) input[name=submit]"), r=e.getBoundingClientRect(); return document.elementFromPoint((r.left+r.right)/2,(r.top+r.bottom)/2)===e;', 'args' => []])['value'] ?? false) === true;
        if ($clickable) { break; }
        usleep(100000);
    }
    if (!$clickable) { throw new RuntimeException(); }
    $driver('POST', $prefix . '/element/' . $element('form:has(input[name="bb_name"]) input[name="submit"]') . '/click', (object) []);
    $badgeQuery = $db->prepare('SELECT bb_id, bb_visibility FROM gdo_badger_badge WHERE bb_name=? AND bb_owner=?');
    $badgeQuery->bind_param('si', $badgeName, $id);
    $badge = null;
    for ($attempt = 0; $attempt < 50; $attempt++)
    {
        $badgeQuery->execute();
        $badge = $badgeQuery->get_result()->fetch_assoc();
        if ($badge) { break; }
        usleep(100000);
    }
    if (!$badge || $badge['bb_visibility'] !== 'acl_noone') { throw new RuntimeException(); }
    $balanceQuery = $db->prepare("SELECT uset_var FROM gdo_usersetting WHERE uset_user=? AND uset_name='credits'");
    $balanceQuery->bind_param('i', $id);
    $balanceQuery->execute();
    $balance = $balanceQuery->get_result()->fetch_assoc();
    if (!$balance || (int) $balance['uset_var'] !== 150) { throw new RuntimeException(); }
    echo "Local Chromium login and private badge purchase: OK\n";
}
finally
{
    if (is_string($session)) { try { $driver('DELETE', '/session/' . $session); } catch (Throwable) {} }
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    if ($id !== null)
    {
        $private = $db->prepare("UPDATE gdo_badger_badge SET bb_visibility='acl_noone' WHERE bb_owner=? AND bb_name=?");
        $private->bind_param('is', $id, $badgeName);
        $private->execute();
        $disable = $db->prepare("UPDATE gdo_user SET user_type='ghost' WHERE user_id=? AND user_name=? AND user_type='member'");
        $disable->bind_param('is', $id, $name);
        $disable->execute();
    }
    $db->close();
}

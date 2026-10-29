<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run-local-only') { exit(1); }
$stage = 'initialization';
set_exception_handler(static function (Throwable $ex) use (&$stage): void {
    fwrite(STDERR, "Local activation smoke test failed at {$stage}: " . $ex::class . PHP_EOL);
    exit(1);
});
$root = dirname(__DIR__, 3);
require $root . '/protected/config_badger.php';
$db = new mysqli(GDO_DB_HOST, GDO_DB_USER, GDO_DB_PASS, GDO_DB_NAME, GDO_DB_PORT);
$identity = $db->query('SELECT DATABASE() AS name, CURRENT_USER() AS principal')->fetch_assoc();
if (($identity['name'] ?? null) !== 'phpgdo_badger' || ($identity['principal'] ?? null) !== 'mira@localhost') { throw new RuntimeException(); }
$suffix = bin2hex(random_bytes(6));
$name = 'badgeractivate_' . $suffix;
$token = bin2hex(random_bytes(8));
$password = bin2hex(random_bytes(24));
$hash = password_hash($password, PASSWORD_BCRYPT);
$email = 'badger-' . $suffix . '@example.invalid';
$id = null;
$handle = null;
try
{
    $stage = 'create synthetic activation';
    $create = $db->prepare("INSERT INTO gdo_useractivation (ua_token, ua_time, user_name, user_password, user_email, user_register_ip) VALUES (?, CURRENT_TIMESTAMP(3), ?, ?, ?, '127.0.0.1')");
    $create->bind_param('ssss', $token, $name, $hash, $email);
    $create->execute();
    $activationId = (int) $db->insert_id;
    $stage = 'activate through local GET';
    $handle = curl_init();
    curl_setopt_array($handle, [CURLOPT_URL => 'https://badger.mogwai.mira-gpt.org:8443/register.form.html',
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_PROXY => '',
        CURLOPT_COOKIEFILE => '', CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO => $root . '/temp_badger/local_tls/cert.pem', CURLOPT_RESOLVE => ['badger.mogwai.mira-gpt.org:8443:127.0.0.1']]);
    if (!is_string(curl_exec($handle)) || curl_getinfo($handle, CURLINFO_HTTP_CODE) >= 400) { throw new RuntimeException(); }
    curl_setopt_array($handle, [CURLOPT_URL => 'https://badger.mogwai.mira-gpt.org:8443/register.activate.id.' . $activationId . '.token.' . $token . '.html', CURLOPT_HTTPGET => true]);
    $response = curl_exec($handle);
    if (!is_string($response) || curl_getinfo($handle, CURLINFO_HTTP_CODE) >= 400) { throw new RuntimeException(); }
    $stage = 'verify activated account and credits';
    $query = $db->prepare("SELECT u.user_id, u.user_type, c.uset_var AS credits, w.uset_var AS granted, p.uset_var AS password_hash FROM gdo_user u LEFT JOIN gdo_usersetting c ON c.uset_user=u.user_id AND c.uset_name='credits' LEFT JOIN gdo_usersetting w ON w.uset_user=u.user_id AND w.uset_name='bb_welcome_granted' LEFT JOIN gdo_usersetting p ON p.uset_user=u.user_id AND p.uset_name='password' WHERE u.user_name=?");
    $query->bind_param('s', $name);
    $query->execute();
    $user = $query->get_result()->fetch_assoc();
    if ($user) { $id = (int) $user['user_id']; }
    if (!$user || $user['user_type'] !== 'member' || (int) $user['credits'] !== 200 || $user['granted'] !== '1' || !password_verify($password, $user['password_hash'] ?? ''))
    {
        $stage .= $user ? ' (type ' . $user['user_type'] . ', credits ' . (int) $user['credits'] . ', grant ' . ($user['granted'] === null ? 'missing' : 'present') . ', password ' . (password_verify($password, $user['password_hash'] ?? '') ? 'valid' : 'missing or invalid') . ')' : ' (account missing, HTTP ' . curl_getinfo($handle, CURLINFO_HTTP_CODE) . ', error ' . (str_contains($response, 'gdt-error') ? 'yes' : 'no') . ')';
        if (!$user)
        {
            $dom = new DOMDocument();
            @$dom->loadHTML($response);
            $error = (new DOMXPath($dom))->query('//*[contains(concat(" ", normalize-space(@class), " "), " gdt-error ")]')->item(0);
            if ($error) { $stage .= ' (' . substr(preg_replace('/\s+/', ' ', str_replace([$name, $token, $password, $email], '[redacted]', $error->textContent)), 0, 160) . ')'; }
        }
        throw new RuntimeException();
    }
    $query = $db->prepare('SELECT ua_deleted FROM gdo_useractivation WHERE ua_id=? AND user_name=?');
    $query->bind_param('is', $activationId, $name);
    $query->execute();
    if (($query->get_result()->fetch_assoc()['ua_deleted'] ?? null) === null) { throw new RuntimeException(); }
    $stage = 'login after activation';
    curl_setopt_array($handle, [CURLOPT_URL => 'https://badger.mogwai.mira-gpt.org:8443/login.form.html',
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['login' => $name, 'password' => $password, 'submit' => 'Login'])]);
    if (!is_string(curl_exec($handle)) || curl_getinfo($handle, CURLINFO_HTTP_CODE) >= 400) { throw new RuntimeException(); }
    curl_setopt_array($handle, [CURLOPT_URL => 'https://badger.mogwai.mira-gpt.org:8443/badger.add.html', CURLOPT_HTTPGET => true]);
    $form = curl_exec($handle);
    if (!is_string($form) || !str_contains($form, 'name="bb_name"')) { throw new RuntimeException(); }
    $stage = 'activation-token log isolation';
    foreach (['/home/gizmore/www/phpgdo.badger.access.log', '/home/gizmore/www/phpgdo.badger.error.log'] as $log)
    {
        $stream = fopen($log, 'rb');
        if (!$stream) { throw new RuntimeException(); }
        try
        {
            $size = fstat($stream)['size'];
            fseek($stream, max(0, $size - 65536));
            $tail = stream_get_contents($stream);
            if (!is_string($tail) || str_contains($tail, $token)) { throw new RuntimeException(); }
        }
        finally { fclose($stream); }
    }
    echo "Local member activation and once-only welcome credits: OK\n";
}
finally
{
    if ($handle instanceof CurlHandle) { curl_close($handle); }
    if (isset($activationId))
    {
        $expire = $db->prepare('UPDATE gdo_useractivation SET ua_deleted=CURRENT_TIMESTAMP(3) WHERE ua_id=? AND user_name=? AND ua_deleted IS NULL');
        $expire->bind_param('is', $activationId, $name);
        $expire->execute();
    }
    if ($id === null)
    {
        $lookup = $db->prepare('SELECT user_id FROM gdo_user WHERE user_name=?');
        $lookup->bind_param('s', $name);
        $lookup->execute();
        $found = $lookup->get_result()->fetch_assoc();
        if ($found) { $id = (int) $found['user_id']; }
    }
    if ($id !== null)
    {
        $disable = $db->prepare("UPDATE gdo_user SET user_type='ghost' WHERE user_id=? AND user_name=? AND user_type='member'");
        $disable->bind_param('is', $id, $name);
        $disable->execute();
    }
    $db->close();
}

<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
set_exception_handler(static function (Throwable $ex): void {
    $safe = ['Local test account unavailable', 'cURL unavailable', 'Login form unavailable', 'Login request failed', 'Authenticated badge form unavailable', 'Unexpected test database', 'CSRF rejection failed'];
    $reason = in_array($ex->getMessage(), $safe, true) ? $ex->getMessage() : $ex::class;
    fwrite(STDERR, 'Local Badger smoke test failed: ' . $reason . PHP_EOL);
    exit(1);
});
$root = dirname(__DIR__, 3);
$secret = require $root . '/GDO/Badger/secret.php';
$entry = is_array($secret) ? ($secret['gizmore'] ?? null) : null;
if (!is_array($entry) || !is_string($entry[0] ?? null) || !is_string($entry[2] ?? null))
{
    throw new RuntimeException('Local test account unavailable');
}
require $root . '/protected/config_badger.php';
$db = new mysqli(GDO_DB_HOST, GDO_DB_USER, GDO_DB_PASS, GDO_DB_NAME, GDO_DB_PORT);
$identity = $db->query('SELECT DATABASE() AS name, CURRENT_USER() AS principal')->fetch_assoc();
if (($identity['name'] ?? null) !== 'phpgdo_badger' || ($identity['principal'] ?? null) !== 'mira@localhost')
{
    throw new RuntimeException('Unexpected test database');
}
$userQuery = $db->prepare('SELECT user_id FROM gdo_user WHERE user_name=?');
$userQuery->bind_param('s', $entry[0]);
$userQuery->execute();
$user = $userQuery->get_result()->fetch_assoc();
if (!$user) { throw new RuntimeException('Local test account unavailable'); }
$userId = (int) $user['user_id'];
$snapshot = static function () use ($db, $userId): array {
    return [$db->query('SELECT COUNT(*) AS n FROM gdo_badger_badge')->fetch_assoc()['n'],
        $db->query("SELECT uset_name, uset_var FROM gdo_usersetting WHERE uset_user={$userId} AND uset_name IN ('credits','bb_welcome_granted') ORDER BY uset_name")->fetch_all(MYSQLI_ASSOC)];
};
$base = 'https://badger.mogwai.mira-gpt.org:8443';
$curl = curl_init();
if (!$curl) { throw new RuntimeException('cURL unavailable'); }
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10,
    CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => '', CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CAINFO => $root . '/temp_badger/local_tls/cert.pem',
    CURLOPT_RESOLVE => ['badger.mogwai.mira-gpt.org:8443:127.0.0.1'],
]);
try
{
    curl_setopt($curl, CURLOPT_URL, $base . '/login.form.html');
    $loginForm = curl_exec($curl);
    if (!is_string($loginForm) || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200 || !str_contains($loginForm, 'name="password"'))
    {
        throw new RuntimeException('Login form unavailable');
    }
    curl_setopt_array($curl, [CURLOPT_URL => $base . '/login.form.html', CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['login' => $entry[0], 'password' => $entry[2], 'submit' => 'Login'])]);
    $login = curl_exec($curl);
    if (!is_string($login) || curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 400)
    {
        fwrite(STDERR, 'Login HTTP status: ' . curl_getinfo($curl, CURLINFO_HTTP_CODE) . PHP_EOL);
        throw new RuntimeException('Login request failed');
    }
    curl_setopt_array($curl, [CURLOPT_URL => $base . '/badger.add.html', CURLOPT_HTTPGET => true]);
    $form = curl_exec($curl);
    if (!is_string($form) || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200 ||
        !str_contains($form, 'name="bb_name"') || !str_contains($form, 'name="xsrf"'))
    {
        throw new RuntimeException('Authenticated badge form unavailable');
    }
    $before = $snapshot();
    $dom = new DOMDocument();
    @$dom->loadHTML($form);
    $xpath = new DOMXPath($dom);
    $creation = $xpath->query('//form[.//input[@name="bb_name"]]')->item(0);
    if (!$creation) { throw new RuntimeException('Authenticated badge form unavailable'); }
    $fields = [];
    foreach ($xpath->query('.//input[@name]', $creation) as $input)
    {
        $type = strtolower($input->getAttribute('type'));
        if (in_array($type, ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) { continue; }
        $fields[$input->getAttribute('name')] = $input->getAttribute('value');
    }
    foreach ($xpath->query('.//select[@name]', $creation) as $select)
    {
        $option = $xpath->query('./option[@selected]', $select)->item(0) ?? $xpath->query('./option', $select)->item(0);
        if ($option) { $fields[$select->getAttribute('name')] = $option->getAttribute('value'); }
    }
    foreach ($xpath->query('.//textarea[@name]', $creation) as $textarea) { $fields[$textarea->getAttribute('name')] = $textarea->textContent; }
    $fields['bb_name'] = 'Badger CSRF smoke test';
    $fields['xsrf'] = 'deliberately-invalid';
    curl_setopt_array($curl, [CURLOPT_URL => $base . '/badger.add.html', CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields)]);
    $rejected = curl_exec($curl);
    if (!is_string($rejected) || !str_contains($rejected, 'Your form token is invalid') || $snapshot() !== $before)
    {
        throw new RuntimeException('CSRF rejection failed');
    }
    echo "Local TLS login, badge form and CSRF rejection without data changes: OK\n";
}
finally { curl_close($curl); $db->close(); }

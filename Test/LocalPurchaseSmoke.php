<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run-local-only') { exit(1); }
set_exception_handler(static function (Throwable $ex): void {
    $safe = ['Wrong database', 'Login form failed', 'Login failed', 'Badge form failed', 'Badge form unavailable', 'Missing form token', 'Badge submission failed', 'Badge purchase did not match expected state'];
    $reason = in_array($ex->getMessage(), $safe, true) ? $ex->getMessage() : $ex::class;
    fwrite(STDERR, 'Local purchase smoke test failed: ' . $reason . PHP_EOL);
    exit(1);
});
$root = dirname(__DIR__, 3);
require $root . '/protected/config_badger.php';
$db = new mysqli(GDO_DB_HOST, GDO_DB_USER, GDO_DB_PASS, GDO_DB_NAME, GDO_DB_PORT);
$identity = $db->query('SELECT DATABASE() AS name, CURRENT_USER() AS principal')->fetch_assoc();
if (($identity['name'] ?? null) !== 'phpgdo_badger' || ($identity['principal'] ?? null) !== 'mira@localhost')
{
    throw new RuntimeException('Wrong database');
}
$suffix = bin2hex(random_bytes(6));
$username = 'badgersmoke_' . $suffix;
$password = bin2hex(random_bytes(24));
$badgeName = 'Private Badger smoke ' . $suffix;
$hash = password_hash($password, PASSWORD_BCRYPT);
$userId = null;
$curl = null;
try
{
    $db->begin_transaction();
    $create = $db->prepare("INSERT INTO gdo_user (user_type, user_name, user_display_name) VALUES ('member', ?, ?)");
    $create->bind_param('ss', $username, $username);
    $create->execute();
    $userId = (int) $db->insert_id;
    $setting = $db->prepare("INSERT INTO gdo_usersetting (uset_user, uset_name, uset_var) VALUES (?, 'password', ?)");
    $setting->bind_param('is', $userId, $hash);
    $setting->execute();
    $db->commit();

    $base = 'https://badger.mogwai.mira-gpt.org:8443';
    $curl = curl_init();
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10,
        CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => '', CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO => $root . '/temp_badger/local_tls/cert.pem',
        CURLOPT_RESOLVE => ['badger.mogwai.mira-gpt.org:8443:127.0.0.1']]);
    curl_setopt($curl, CURLOPT_URL, $base . '/login.form.html');
    if (!is_string(curl_exec($curl)) || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) { throw new RuntimeException('Login form failed'); }
    curl_setopt_array($curl, [CURLOPT_URL => $base . '/login.form.html', CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['login' => $username, 'password' => $password, 'submit' => 'Login'])]);
    if (!is_string(curl_exec($curl)) || curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 400) { throw new RuntimeException('Login failed'); }
    curl_setopt_array($curl, [CURLOPT_URL => $base . '/badger.add.html', CURLOPT_HTTPGET => true]);
    $html = curl_exec($curl);
    if (!is_string($html) || curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200) { throw new RuntimeException('Badge form failed'); }
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $form = $xpath->query('//form[.//input[@name="bb_name"]]')->item(0);
    if (!$form) { throw new RuntimeException('Badge form unavailable'); }
    $fields = [];
    foreach ($xpath->query('.//input[@name]', $form) as $input)
    {
        $type = strtolower($input->getAttribute('type'));
        if (in_array($type, ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) { continue; }
        $fields[$input->getAttribute('name')] = $input->getAttribute('value');
    }
    foreach ($xpath->query('.//select[@name]', $form) as $select)
    {
        $option = $xpath->query('./option[@selected]', $select)->item(0) ?? $xpath->query('./option', $select)->item(0);
        if ($option) { $fields[$select->getAttribute('name')] = $option->getAttribute('value'); }
    }
    foreach ($xpath->query('.//textarea[@name]', $form) as $textarea) { $fields[$textarea->getAttribute('name')] = $textarea->textContent; }
    if (empty($fields['xsrf'])) { throw new RuntimeException('Missing form token'); }
    $fields['bb_name'] = $badgeName;
    $fields['bb_visibility'] = 'acl_noone';
    $fields['bb_xp'] = '1';
    $fields['bb_hp'] = $fields['bb_str'] = $fields['bb_int'] = '0';
    curl_setopt_array($curl, [CURLOPT_URL => $base . '/badger.add.html', CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields)]);
    if (!is_string(curl_exec($curl)) || curl_getinfo($curl, CURLINFO_HTTP_CODE) >= 400) { throw new RuntimeException('Badge submission failed'); }
    $query = $db->prepare('SELECT bb_owner, bb_visibility, bb_xp FROM gdo_badger_badge WHERE bb_name=?');
    $query->bind_param('s', $badgeName);
    $query->execute();
    $badge = $query->get_result()->fetch_assoc();
    $balance = $db->query("SELECT uset_var FROM gdo_usersetting WHERE uset_user=" . (int) $userId . " AND uset_name='credits'")->fetch_assoc();
    if (!$badge || (int) $badge['bb_owner'] !== $userId || $badge['bb_visibility'] !== 'acl_noone' || (int) $badge['bb_xp'] !== 1 ||
        !$balance || $balance['uset_var'] !== '150')
    {
        throw new RuntimeException('Badge purchase did not match expected state');
    }
    echo "Local private badge purchase and 50-credit charge: OK\n";
}
finally
{
    if (is_resource($curl) || $curl instanceof CurlHandle) { curl_close($curl); }
    if ($userId !== null)
    {
        $disable = $db->prepare("UPDATE gdo_user SET user_type='ghost' WHERE user_id=? AND user_name=? AND user_type='member'");
        $disable->bind_param('is', $userId, $username);
        $disable->execute();
    }
    $db->close();
}

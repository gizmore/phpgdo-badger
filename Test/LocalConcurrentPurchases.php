<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run-local-only') { exit(1); }
$stage = 'initialization';
set_exception_handler(static function (Throwable $ex) use (&$stage): void {
    fwrite(STDERR, "Local concurrent purchase test failed at {$stage}: " . $ex::class . PHP_EOL);
    exit(1);
});
$root = dirname(__DIR__, 3);
require $root . '/protected/config_badger.php';
$db = new mysqli(GDO_DB_HOST, GDO_DB_USER, GDO_DB_PASS, GDO_DB_NAME, GDO_DB_PORT);
$identity = $db->query('SELECT DATABASE() AS name, CURRENT_USER() AS principal')->fetch_assoc();
if (($identity['name'] ?? null) !== 'phpgdo_badger' || ($identity['principal'] ?? null) !== 'mira@localhost') { throw new RuntimeException(); }
$suffix = bin2hex(random_bytes(6));
$name = 'badgerconcur_' . $suffix;
$password = bin2hex(random_bytes(24));
$id = null;
$handles = [];
$multi = null;
$base = 'https://badger.mogwai.mira-gpt.org:8443';
try
{
    $stage = 'create synthetic account';
    $db->begin_transaction();
    $insert = $db->prepare("INSERT INTO gdo_user (user_type, user_name, user_display_name) VALUES ('member', ?, ?)");
    $insert->bind_param('ss', $name, $name);
    $insert->execute();
    $id = (int) $db->insert_id;
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $setting = $db->prepare("INSERT INTO gdo_usersetting (uset_user, uset_name, uset_var) VALUES (?, 'password', ?)");
    $setting->bind_param('is', $id, $hash);
    $setting->execute();
    $db->commit();
    $multi = curl_multi_init();
    for ($i = 0; $i < 5; $i++)
    {
        $stage = 'prepare independent authenticated sessions';
        $handle = curl_init();
        $handles[] = $handle;
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15,
            CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => '', CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => $root . '/temp_badger/local_tls/cert.pem', CURLOPT_RESOLVE => ['badger.mogwai.mira-gpt.org:8443:127.0.0.1']]);
        curl_setopt($handle, CURLOPT_URL, $base . '/login.form.html');
        if (!is_string(curl_exec($handle)) || curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200) { throw new RuntimeException(); }
        curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['login' => $name, 'password' => $password, 'submit' => 'Login'])]);
        if (!is_string(curl_exec($handle)) || curl_getinfo($handle, CURLINFO_HTTP_CODE) >= 400) { throw new RuntimeException(); }
        curl_setopt_array($handle, [CURLOPT_URL => $base . '/badger.add.html', CURLOPT_HTTPGET => true]);
        $html = curl_exec($handle);
        if (!is_string($html) || curl_getinfo($handle, CURLINFO_HTTP_CODE) !== 200) { throw new RuntimeException(); }
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $form = $xpath->query('//form[.//input[@name="bb_name"]]')->item(0);
        if (!$form) { throw new RuntimeException(); }
        $fields = [];
        foreach ($xpath->query('.//input[@name]', $form) as $input)
        {
            if (in_array(strtolower($input->getAttribute('type')), ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) { continue; }
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        foreach ($xpath->query('.//select[@name]', $form) as $select)
        {
            $option = $xpath->query('./option[@selected]', $select)->item(0) ?? $xpath->query('./option', $select)->item(0);
            if ($option) { $fields[$select->getAttribute('name')] = $option->getAttribute('value'); }
        }
        foreach ($xpath->query('.//textarea[@name]', $form) as $textarea) { $fields[$textarea->getAttribute('name')] = $textarea->textContent; }
        if (empty($fields['xsrf'])) { throw new RuntimeException(); }
        $fields['bb_name'] = 'Private concurrent ' . $suffix . ' ' . $i;
        $fields['bb_visibility'] = 'acl_noone';
        $fields['bb_xp'] = '1';
        $fields['bb_hp'] = $fields['bb_str'] = $fields['bb_int'] = '0';
        curl_setopt_array($handle, [CURLOPT_URL => $base . '/badger.add.html', CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RESOLVE => ['badger.mogwai.mira-gpt.org:8443:127.0.0.1']]);
        if (curl_multi_add_handle($multi, $handle) !== CURLM_OK) { throw new RuntimeException(); }
    }
    $stage = 'concurrent purchases';
    do
    {
        $status = curl_multi_exec($multi, $running);
        if ($status !== CURLM_OK) { throw new RuntimeException(); }
        if ($running) { curl_multi_select($multi, 1.0); }
    } while ($running);
    $results = [];
    while ($info = curl_multi_info_read($multi)) { $results[] = (int) $info['result']; }
    $insufficient = 0;
    foreach ($handles as $handle)
    {
        if (curl_errno($handle) !== 0) { $stage .= ' (curl ' . curl_errno($handle) . ', results ' . implode('/', $results) . ')'; throw new RuntimeException(); }
        $body = curl_multi_getcontent($handle);
        if (!is_string($body)) { throw new RuntimeException(); }
        $httpCodes[] = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if (end($httpCodes) >= 500) { throw new RuntimeException(); }
        if (str_contains($body, 'You do not have enough credits for this badge.')) { $insufficient++; }
        $markers[] = str_contains($body, 'form token') ? 'token' : (str_contains($body, 'credit') ? 'credits' : (str_contains($body, 'Exception') ? 'exception' : 'other'));
    }
    $stage = 'verify serialized credits';
    $query = $db->prepare('SELECT COUNT(*) AS n, SUM(bb_visibility<>\'acl_noone\') AS public_count FROM gdo_badger_badge WHERE bb_owner=?');
    $query->bind_param('i', $id);
    $query->execute();
    $badges = $query->get_result()->fetch_assoc();
    $query = $db->prepare("SELECT uset_var FROM gdo_usersetting WHERE uset_user=? AND uset_name='credits'");
    $query->bind_param('i', $id);
    $query->execute();
    $balance = $query->get_result()->fetch_assoc();
    if ((int) ($badges['n'] ?? -1) !== 4 || (int) ($badges['public_count'] ?? -1) !== 0 || !$balance || (int) $balance['uset_var'] !== 0 || $insufficient !== 1)
    {
        $stage .= ' (badges ' . (int) ($badges['n'] ?? -1) . ', public ' . (int) ($badges['public_count'] ?? -1) . ', balance ' . ($balance ? (int) $balance['uset_var'] : 'missing') . ', HTTP ' . implode('/', $httpCodes) . ', curl ' . implode('/', $results) . ', markers ' . implode('/', $markers) . ')';
        throw new RuntimeException();
    }
    echo "Five concurrent purchases: four private badges, zero credits: OK\n";
}
finally
{
    foreach ($handles as $handle)
    {
        if ($multi instanceof CurlMultiHandle) { curl_multi_remove_handle($multi, $handle); }
        curl_close($handle);
    }
    if ($multi instanceof CurlMultiHandle) { curl_multi_close($multi); }
    if ($id !== null)
    {
        $disable = $db->prepare("UPDATE gdo_user SET user_type='ghost' WHERE user_id=? AND user_name=? AND user_type='member'");
        $disable->bind_param('is', $id, $name);
        $disable->execute();
    }
    $db->close();
}

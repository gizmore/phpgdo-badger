<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run-local-only') { exit(1); }
$stage = 'initialization';
set_exception_handler(static function (Throwable $ex) use (&$stage): void {
    fwrite(STDERR, "Local lifecycle smoke test failed at {$stage}: " . $ex::class . PHP_EOL);
    exit(1);
});
$root = dirname(__DIR__, 3);
require $root . '/protected/config_badger.php';
$db = new mysqli(GDO_DB_HOST, GDO_DB_USER, GDO_DB_PASS, GDO_DB_NAME, GDO_DB_PORT);
$identity = $db->query('SELECT DATABASE() AS name, CURRENT_USER() AS principal')->fetch_assoc();
if (($identity['name'] ?? null) !== 'phpgdo_badger' || ($identity['principal'] ?? null) !== 'mira@localhost') { throw new RuntimeException(); }
$suffix = bin2hex(random_bytes(6));
$names = ['badgerowner_' . $suffix, 'badgeruser_' . $suffix];
$passwords = [bin2hex(random_bytes(24)), bin2hex(random_bytes(24))];
$ids = [];
$handles = [];
$uploadPath = null;
$badgeName = 'Private lifecycle ' . $suffix;
$autoBadgeName = 'No review ' . $suffix;
$tag = 'test' . $suffix;
$base = 'https://badger.mogwai.mira-gpt.org:8443';

$request = static function (CurlHandle $client, string $path, ?array $fields = null) use ($base, &$stage): string {
    curl_setopt_array($client, [CURLOPT_URL => $base . $path, CURLOPT_HTTPGET => true]);
    if ($fields !== null) { curl_setopt_array($client, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields)]); }
    $body = curl_exec($client);
    if (!is_string($body) || curl_getinfo($client, CURLINFO_HTTP_CODE) >= 400)
    {
        if (is_string($body))
        {
            foreach (['Permission denied', 'Database error', 'Internal Server Error', 'Unknown column', 'Method not found'] as $marker)
            {
                if (str_contains($body, $marker)) { $stage .= ' (' . $marker . ')'; break; }
            }
        }
        throw new RuntimeException();
    }
    return $body;
};
$formFields = static function (string $html, string $selector): array {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $form = $xpath->query($selector)->item(0);
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
    return $fields;
};
try
{
    $stage = 'create test accounts';
    $db->begin_transaction();
    foreach ($names as $i => $name)
    {
        $create = $db->prepare("INSERT INTO gdo_user (user_type, user_name, user_display_name) VALUES ('member', ?, ?)");
        $create->bind_param('ss', $name, $name);
        $create->execute();
        $ids[$i] = (int) $db->insert_id;
        $hash = password_hash($passwords[$i], PASSWORD_BCRYPT);
        $setting = $db->prepare("INSERT INTO gdo_usersetting (uset_user, uset_name, uset_var) VALUES (?, 'password', ?)");
        $setting->bind_param('is', $ids[$i], $hash);
        $setting->execute();
    }
    $db->commit();
    foreach ($names as $i => $name)
    {
        $stage = 'login test account ' . $i;
        $client = curl_init();
        $handles[$i] = $client;
        curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10,
            CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => '', CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CAINFO => $root . '/temp_badger/local_tls/cert.pem', CURLOPT_RESOLVE => ['badger.mogwai.mira-gpt.org:8443:127.0.0.1']]);
        $request($client, '/login.form.html');
        $request($client, '/login.form.html', ['login' => $name, 'password' => $passwords[$i], 'submit' => 'Login']);
    }
    $stage = 'buy additional equipment slot';
    $slotPath = '/badger.buyslot.html';
    $slotFields = $formFields($request($handles[1], $slotPath), '//form[.//input[@name="xsrf"]]');
    $request($handles[1], $slotPath, $slotFields);
    $slotQuery = $db->prepare("SELECT uset_name, uset_var FROM gdo_usersetting WHERE uset_user=? AND uset_name IN ('credits','bb_welcome_granted','bb_add_max_equip')");
    $slotQuery->bind_param('i', $ids[1]);
    $slotQuery->execute();
    $slotValues = [];
    foreach ($slotQuery->get_result()->fetch_all(MYSQLI_ASSOC) as $row) { $slotValues[$row['uset_name']] = $row['uset_var']; }
    if (($slotValues['credits'] ?? null) !== '150' || ($slotValues['bb_welcome_granted'] ?? null) !== '1' || ($slotValues['bb_add_max_equip'] ?? null) !== '1') { throw new RuntimeException(); }
    $stage = 'create badge';
    $fields = $formFields($request($handles[0], '/badger.add.html'), '//form[.//input[@name="bb_name"]]');
    $fields['bb_name'] = $badgeName;
    $fields['bb_visibility'] = 'acl_all';
    $fields['bb_has_image'] = '1';
    $fields['bb_xp'] = '1';
    $fields['bb_hp'] = $fields['bb_str'] = $fields['bb_int'] = '0';
    $request($handles[0], '/badger.add.html', $fields);
    $query = $db->prepare('SELECT bb_id FROM gdo_badger_badge WHERE bb_name=? AND bb_owner=?');
    $query->bind_param('si', $badgeName, $ids[0]);
    $query->execute();
    $badge = $query->get_result()->fetch_assoc();
    if (!$badge) { throw new RuntimeException(); }
    $badgeId = (int) $badge['bb_id'];
    $stage = 'set badge tag';
    $editPath = '/badger.edit.badge.' . $badgeId . '.html';
    try { $editHtml = $request($handles[0], $editPath); }
    catch (Throwable $ex) { $stage .= ' (HTTP ' . curl_getinfo($handles[0], CURLINFO_HTTP_CODE) . ')'; throw $ex; }
    $stage = 'find tag form';
    $editFields = $formFields($editHtml, '//form[.//*[@name="badge_tags"]]');
    $editFields['badge_tags'] = $tag;
    $stage = 'submit badge tag';
    $request($handles[0], $editPath, $editFields);
    $stage = 'verify badge tag';
    $tagQuery = $db->prepare('SELECT bt_name FROM gdo_badger_tag WHERE bt_badge=? AND bt_name=?');
    $tagQuery->bind_param('is', $badgeId, $tag);
    $tagQuery->execute();
    if (!$tagQuery->get_result()->fetch_assoc()) { throw new RuntimeException(); }
    $stage = 'upload badge background';
    $imagePath = '/badger.image.badge.' . $badgeId . '.html';
    $uploadPath = tempnam($root . '/temp_badger', 'badger-image-');
    if ($uploadPath === false) { throw new RuntimeException(); }
    $image = imagecreatetruecolor(2, 2);
    if (!$image || !imagepng($image, $uploadPath)) { throw new RuntimeException(); }
    imagedestroy($image);
    foreach (['background', 'icon', 'item'] as $kind)
    {
        $imageFields = $formFields($request($handles[0], $imagePath), '//form[.//input[@name="item_upload"]]');
        $imageFields['image_kind'] = $kind;
        $imageFields['item_upload'] = new CURLFile($uploadPath, 'image/png', 'synthetic.png');
        curl_setopt_array($handles[0], [CURLOPT_URL => $base . $imagePath, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $imageFields]);
        if (!is_string(curl_exec($handles[0])) || curl_getinfo($handles[0], CURLINFO_HTTP_CODE) >= 400) { throw new RuntimeException(); }
    }
    $imageQuery = $db->prepare('SELECT bb_image, bb_icon, bb_item FROM gdo_badger_badge WHERE bb_id=? AND bb_owner=?');
    $imageQuery->bind_param('ii', $badgeId, $ids[0]);
    $imageQuery->execute();
    $images = $imageQuery->get_result()->fetch_assoc();
    if (empty($images['bb_image']) || empty($images['bb_icon']) || empty($images['bb_item'])) { throw new RuntimeException(); }
    $stage = 'open achievement';
    $path = '/badger.achieve.badge.' . $badgeId . '.html';
    $fields = $formFields($request($handles[1], $path), '//form[.//input[@name="xsrf"]]');
    $request($handles[1], $path, $fields);
    $query = $db->prepare('SELECT ba_id, ba_status, ba_claimed FROM gdo_badger_achieve WHERE ba_badge=? AND ba_user=?');
    $query->bind_param('ii', $badgeId, $ids[1]);
    $query->execute();
    $achievement = $query->get_result()->fetch_assoc();
    if (!$achievement || $achievement['ba_status'] !== 'viewed') { throw new RuntimeException(); }
    $achievementId = (int) $achievement['ba_id'];
    $path = '/badger.achieve.achievement.' . $achievementId . '.html';
    $total = $db->prepare("SELECT COALESCE(SUM(b.bb_xp), 0) AS xp FROM gdo_badger_achieve a JOIN gdo_badger_badge b ON b.bb_id=a.ba_badge WHERE a.ba_user=? AND a.ba_status='claimed'");
    $total->bind_param('i', $ids[1]);
    foreach ([['submit', 1, 'pending'], ['approve', 0, 'verified'], ['claim', 1, 'claimed']] as [$action, $actor, $expected])
    {
        $stage = $action . ' achievement';
        $html = $request($handles[$actor], $path);
        if ($action === 'claim')
        {
            if (!str_contains($html, 'badger-award') || !str_contains($html, 'badger.awarditem')) { throw new RuntimeException(); }
            $itemPath = '/badger.awarditem.achievement.' . $achievementId . '.html';
            if (!str_starts_with($request($handles[1], $itemPath), 'GIF8')) { throw new RuntimeException(); }
            curl_setopt_array($handles[0], [CURLOPT_URL => $base . $itemPath, CURLOPT_HTTPGET => true]);
            if (!is_string(curl_exec($handles[0])) || curl_getinfo($handles[0], CURLINFO_HTTP_CODE) !== 404) { throw new RuntimeException(); }
        }
        $fields = $formFields($html, '//form[.//*[@name="action"]]');
        $fields['action'] = $action;
        if ($action === 'submit') { $fields['claim_message'] = 'Synthetic local test'; }
        $request($handles[$actor], $path, $fields);
        $query->execute();
        $achievement = $query->get_result()->fetch_assoc();
        if (!$achievement || $achievement['ba_status'] !== $expected) { throw new RuntimeException(); }
        $total->execute();
        $xp = (int) $total->get_result()->fetch_assoc()['xp'];
        if ($xp !== ($action === 'claim' ? 1 : 0) || (($achievement['ba_claimed'] !== null) !== ($action === 'claim'))) { throw new RuntimeException(); }
    }
    $stage = 'equip item';
    $itemsPath = '/badger.items.html';
    $fields = $formFields($request($handles[1], $itemsPath), '//form[.//*[@name="achievement"]]');
    $fields['achievement'] = (string) $achievementId;
    $fields['equip'] = '1';
    $request($handles[1], $itemsPath, $fields);
    $stage = 'equipped database state';
    $equippedQuery = $db->prepare('SELECT ba_equipped FROM gdo_badger_achieve WHERE ba_id=? AND ba_user=?');
    $equippedQuery->bind_param('ii', $achievementId, $ids[1]);
    $equippedQuery->execute();
    if ((int) ($equippedQuery->get_result()->fetch_assoc()['ba_equipped'] ?? 0) !== 1) { throw new RuntimeException(); }
    $stage = 'create no-review badge';
    $fields = $formFields($request($handles[0], '/badger.add.html'), '//form[.//input[@name="bb_name"]]');
    $fields['bb_name'] = $autoBadgeName;
    $fields['bb_visibility'] = 'acl_all';
    $fields['bb_xp'] = '1';
    $fields['bb_hp'] = $fields['bb_str'] = $fields['bb_int'] = '0';
    $request($handles[0], '/badger.add.html', $fields);
    $autoQuery = $db->prepare('SELECT bb_id FROM gdo_badger_badge WHERE bb_name=? AND bb_owner=?');
    $autoQuery->bind_param('si', $autoBadgeName, $ids[0]);
    $autoQuery->execute();
    $autoBadge = $autoQuery->get_result()->fetch_assoc();
    if (!$autoBadge) { throw new RuntimeException(); }
    $autoBadgeId = (int) $autoBadge['bb_id'];
    $stage = 'disable staff verification';
    $autoEdit = '/badger.edit.badge.' . $autoBadgeId . '.html';
    $fields = $formFields($request($handles[0], $autoEdit), '//form[.//*[@name="bb_verify_staff"]]');
    $fields['bb_verify_staff'] = '0';
    $request($handles[0], $autoEdit, $fields);
    $verifyQuery = $db->prepare('SELECT bb_verify_staff FROM gdo_badger_badge WHERE bb_id=? AND bb_owner=?');
    $verifyQuery->bind_param('ii', $autoBadgeId, $ids[0]);
    $verifyQuery->execute();
    if ((int) ($verifyQuery->get_result()->fetch_assoc()['bb_verify_staff'] ?? 1) !== 0) { throw new RuntimeException(); }
    $stage = 'submit no-review claim';
    $autoStart = '/badger.achieve.badge.' . $autoBadgeId . '.html';
    $fields = $formFields($request($handles[1], $autoStart), '//form[.//input[@name="xsrf"]]');
    $request($handles[1], $autoStart, $fields);
    $autoAchieveQuery = $db->prepare('SELECT ba_id, ba_status FROM gdo_badger_achieve WHERE ba_badge=? AND ba_user=?');
    $autoAchieveQuery->bind_param('ii', $autoBadgeId, $ids[1]);
    $autoAchieveQuery->execute();
    $autoAchieve = $autoAchieveQuery->get_result()->fetch_assoc();
    if (!$autoAchieve || $autoAchieve['ba_status'] !== 'viewed') { throw new RuntimeException(); }
    $autoPath = '/badger.achieve.achievement.' . (int) $autoAchieve['ba_id'] . '.html';
    $fields = $formFields($request($handles[1], $autoPath), '//form[.//*[@name="action"]]');
    $fields['action'] = 'submit';
    $request($handles[1], $autoPath, $fields);
    $autoAchieveQuery->execute();
    $autoAchieve = $autoAchieveQuery->get_result()->fetch_assoc();
    $total->execute();
    if (($autoAchieve['ba_status'] ?? null) !== 'verified' || (int) $total->get_result()->fetch_assoc()['xp'] !== 1) { throw new RuntimeException(); }
    $stage = 'claim no-review award';
    $fields = $formFields($request($handles[1], $autoPath), '//form[.//*[@name="action"]]');
    $fields['action'] = 'claim';
    $request($handles[1], $autoPath, $fields);
    $autoAchieveQuery->execute();
    $autoAchieve = $autoAchieveQuery->get_result()->fetch_assoc();
    $total->execute();
    if (($autoAchieve['ba_status'] ?? null) !== 'claimed' || (int) $total->get_result()->fetch_assoc()['xp'] !== 2) { throw new RuntimeException(); }
    $stage = 'equipped avatar layer';
    $avatar = $request($handles[1], $itemsPath);
    if (!str_contains($avatar, 'badger-avatar-item') || !str_contains($avatar, 'badger.item.achievement.')) { throw new RuntimeException(); }
    $stage = 'equipped item image';
    if (!str_starts_with($request($handles[1], '/badger.item.achievement.' . $achievementId . '.html'), 'GIF8')) { throw new RuntimeException(); }
    foreach ([['revoke', 0, 'revoked', 1], ['restore', 0, 'verified', 1], ['claim', 1, 'claimed', 2]] as [$action, $actor, $expected, $expectedXp])
    {
        $stage = $action . ' corrected award';
        $fields = $formFields($request($handles[$actor], $path), '//form[.//*[@name="action"]]');
        $fields['action'] = $action;
        if ($action !== 'claim') { $fields['reason'] = 'Synthetic correction test'; }
        $request($handles[$actor], $path, $fields);
        $query->execute();
        $updated = $query->get_result()->fetch_assoc();
        $total->execute();
        $xp = (int) $total->get_result()->fetch_assoc()['xp'];
        $equippedQuery->execute();
        $equipped = (int) ($equippedQuery->get_result()->fetch_assoc()['ba_equipped'] ?? 1);
        if (!$updated || $updated['ba_status'] !== $expected || $xp !== $expectedXp || $equipped !== 0) { throw new RuntimeException(); }
    }
    $stage = 'reequip after acknowledgement';
    $fields = $formFields($request($handles[1], $itemsPath), '//form[.//*[@name="achievement"]]');
    $fields['achievement'] = (string) $achievementId;
    $fields['equip'] = '1';
    $request($handles[1], $itemsPath, $fields);
    $equippedQuery->execute();
    if ((int) ($equippedQuery->get_result()->fetch_assoc()['ba_equipped'] ?? 0) !== 1) { throw new RuntimeException(); }
    $stage = 'anonymous badge visibility';
    $guest = curl_init();
    $handles[2] = $guest;
    curl_setopt_array($guest, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10,
        CURLOPT_PROXY => '', CURLOPT_COOKIEFILE => '', CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO => $root . '/temp_badger/local_tls/cert.pem', CURLOPT_RESOLVE => ['badger.mogwai.mira-gpt.org:8443:127.0.0.1']]);
    $stage = 'anonymous achievement detail';
    $publicDetail = $request($guest, $path);
    if (!str_contains($publicDetail, $badgeName) || !str_contains($publicDetail, 'Synthetic correction test') || str_contains($publicDetail, 'name="action"') || str_contains($publicDetail, 'Synthetic local test')) { throw new RuntimeException(); }
    if (!str_contains($request($handles[0], $path), 'Synthetic local test') || !str_contains($request($handles[1], $path), 'Synthetic local test')) { throw new RuntimeException(); }
    $stage = 'anonymous request denied';
    curl_setopt_array($guest, [CURLOPT_URL => $base . '/badger.achieve.badge.' . $badgeId . '.html', CURLOPT_HTTPGET => true]);
    $startPage = curl_exec($guest);
    if (!is_string($startPage) || str_contains($startPage, 'name="xsrf"')) { throw new RuntimeException(); }
    $countQuery = $db->prepare('SELECT COUNT(*) AS n FROM gdo_badger_achieve WHERE ba_badge=?');
    $countQuery->bind_param('i', $badgeId);
    $countQuery->execute();
    $before = (int) $countQuery->get_result()->fetch_assoc()['n'];
    curl_setopt_array($guest, [CURLOPT_URL => $base . '/badger.achieve.badge.' . $badgeId . '.html', CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['submit' => '1', 'xsrf' => 'invalid'])]);
    if (!is_string(curl_exec($guest))) { throw new RuntimeException(); }
    $countQuery->execute();
    if ((int) $countQuery->get_result()->fetch_assoc()['n'] !== $before) { throw new RuntimeException(); }
    $stage = 'public achievement profile';
    $profilePath = '/user.profile.for.' . $names[1] . '.html';
    $profileIconPath = '/badger.profileicon.achievement.' . $achievementId . '.html';
    $profile = $request($guest, $profilePath);
    if (!str_contains($profile, $badgeName) || !str_starts_with($request($guest, $profileIconPath), 'GIF8')) { throw new RuntimeException(); }
    $stage = 'make achievement private';
    $fields = $formFields($request($handles[1], $path), '//form[.//*[@name="action"]]');
    $fields['action'] = 'share';
    $fields['visibility'] = 'acl_noone';
    $request($handles[1], $path, $fields);
    if (str_contains($request($guest, $profilePath), $badgeName)) { throw new RuntimeException(); }
    if (!str_contains($request($handles[1], $profilePath), $badgeName)) { throw new RuntimeException(); }
    curl_setopt_array($guest, [CURLOPT_URL => $base . $profileIconPath, CURLOPT_HTTPGET => true]);
    if (!is_string(curl_exec($guest)) || curl_getinfo($guest, CURLINFO_HTTP_CODE) !== 404) { throw new RuntimeException(); }
    $stage = 'public badge listing';
    $listing = $request($guest, '/badger.badges.html');
    if (!str_contains($listing, $badgeName) || !str_contains($listing, 'badger.badgeicon')) { throw new RuntimeException(); }
    $filterPath = '/badger.badges.html?f%5Bbadge_tags%5D=' . rawurlencode($tag);
    if (!str_contains($request($guest, $filterPath), $badgeName)) { throw new RuntimeException(); }
    if (!str_starts_with($request($guest, '/badger.badgeicon.badge.' . $badgeId . '.html'), 'GIF8')) { throw new RuntimeException(); }
    $backgroundPath = '/badger.badgebackground.badge.' . $badgeId . '.html';
    $stage = 'public background';
    if (!str_starts_with($request($guest, $backgroundPath), 'GIF8')) { throw new RuntimeException(); }
    $stage = 'private badge listing';
    $private = $db->prepare("UPDATE gdo_badger_badge SET bb_visibility='acl_noone' WHERE bb_id=? AND bb_owner=? AND bb_name=?");
    $private->bind_param('iis', $badgeId, $ids[0], $badgeName);
    $private->execute();
    if ($private->affected_rows !== 1 || str_contains($request($guest, '/badger.badges.html'), $badgeName)) { throw new RuntimeException(); }
    if (str_contains($request($guest, $filterPath), $badgeName)) { throw new RuntimeException(); }
    $stage = 'literal name filter';
    if (str_contains($request($guest, '/badger.badges.html?f%5Bbb_name%5D=' . rawurlencode("x' OR 1=1 --")), $badgeName)) { throw new RuntimeException(); }
    $stage = 'private background';
    curl_setopt_array($guest, [CURLOPT_URL => $base . $backgroundPath, CURLOPT_HTTPGET => true]);
    $deniedBody = curl_exec($guest);
    $deniedStatus = curl_getinfo($guest, CURLINFO_HTTP_CODE);
    if (!is_string($deniedBody) || $deniedStatus !== 404) { $stage .= ' (HTTP ' . $deniedStatus . ', GIF ' . (is_string($deniedBody) && str_starts_with($deniedBody, 'GIF8') ? 'yes' : 'no') . ')'; throw new RuntimeException(); }
    curl_setopt_array($guest, [CURLOPT_URL => $base . '/badger.badgeicon.badge.' . $badgeId . '.html', CURLOPT_HTTPGET => true]);
    if (!is_string(curl_exec($guest)) || curl_getinfo($guest, CURLINFO_HTTP_CODE) !== 404) { throw new RuntimeException(); }
    $stage = 'public equipped item';
    if (!str_starts_with($request($guest, '/badger.item.achievement.' . $achievementId . '.html'), 'GIF8')) { throw new RuntimeException(); }
    $stage = 'general file access';
    curl_setopt_array($guest, [CURLOPT_URL => $base . '/file.getfile.file.' . (int) $images['bb_image'] . '.html', CURLOPT_HTTPGET => true]);
    $generalBody = curl_exec($guest);
    if (!is_string($generalBody) || str_starts_with($generalBody, 'GIF8')) { throw new RuntimeException(); }
    $stage = 'direct file access';
    curl_setopt_array($guest, [CURLOPT_URL => $base . '/files_badger/' . (int) $images['bb_image'], CURLOPT_HTTPGET => true]);
    $directBody = curl_exec($guest);
    if (!is_string($directBody) || curl_getinfo($guest, CURLINFO_HTTP_CODE) !== 403 || str_starts_with($directBody, 'GIF8')) { throw new RuntimeException(); }
    echo "Local badge, image, approval, claim and anonymous visibility: OK\n";
}
finally
{
    foreach ($handles as $handle) { curl_close($handle); }
    if (is_string($uploadPath) && is_file($uploadPath)) { unlink($uploadPath); }
    if (isset($achievementId, $badgeId, $ids[1]))
    {
        $unequip = $db->prepare('UPDATE gdo_badger_achieve SET ba_equipped=0 WHERE ba_id=? AND ba_badge=? AND ba_user=?');
        $unequip->bind_param('iii', $achievementId, $badgeId, $ids[1]);
        $unequip->execute();
    }
    if (isset($ids[0]))
    {
        $private = $db->prepare("UPDATE gdo_badger_badge SET bb_visibility='acl_noone' WHERE bb_owner=? AND bb_name=?");
        $private->bind_param('is', $ids[0], $badgeName);
        $private->execute();
        $private->bind_param('is', $ids[0], $autoBadgeName);
        $private->execute();
    }
    foreach ($ids as $i => $id)
    {
        $disable = $db->prepare("UPDATE gdo_user SET user_type='ghost' WHERE user_id=? AND user_name=? AND user_type='member'");
        $disable->bind_param('is', $id, $names[$i]);
        $disable->execute();
    }
    $db->close();
}

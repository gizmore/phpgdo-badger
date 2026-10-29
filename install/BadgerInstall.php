<?php
namespace GDO\Badger\install;

use GDO\Avatar\GDO_Avatar;
use GDO\Avatar\GDO_UserAvatar;
use GDO\Badger\Module_Badger;
use GDO\Crypto\BCrypt;
use GDO\Core\Application;
use GDO\Date\Time;
use GDO\File\GDO_File;
use GDO\User\GDO_User;
use GDO\User\GDO_UserPermission;
use RuntimeException;

final class BadgerInstall
{
    public static function install(): void
    {
        $secret = SeedConfig::load();
        if (!is_array($secret) || !isset($secret['gizmore'], $secret['dejavu']))
        {
            throw new RuntimeException('Missing required Badger seed accounts');
        }
        $accounts = [];
        $names = [];
        foreach (['gizmore', 'dejavu', 'mira'] as $key)
        {
            if (!array_key_exists($key, $secret)) { continue; }
            $entry = $secret[$key];
            if (!is_array($entry) || count($entry) !== 3 || !is_string($entry[0] ?? null) || trim($entry[0]) === '' ||
                !is_string($entry[1] ?? null) || !is_string($entry[2] ?? null) || $entry[2] === '')
            {
                throw new RuntimeException('Invalid Badger seed account');
            }
            $name = mb_strtolower($entry[0], 'UTF-8');
            if (isset($names[$name]) || GDO_User::getBy('user_name', $entry[0]))
            {
                throw new RuntimeException('Badger seed account already exists');
            }
            $names[$name] = true;
            $accounts[$key] = $entry;
        }
        $seedOwner = null;
        foreach ($accounts as $key => $entry)
        {
            $user = self::installAdmin(...$entry);
            if ($key === 'gizmore') { $seedOwner = $user; }
            if ($key === 'gizmore' || $key === 'mira') { self::installAvatar($user, $key . '.png'); }
        }
        if (!$seedOwner) { throw new RuntimeException('Missing Badger seed owner'); }
        // The core test harness later replaces its fixture users by ID.
        // Seed badges have restrictive owner foreign keys, so populate them
        // separately in tests after fixture setup, not during test bootstrap.
        if (!Application::$INSTANCE->isUnitTests()) { BadgerInstallBadges::install($seedOwner); }
    }

    private static function installAdmin(string $username,  string $email, string $password): GDO_User
    {
        $user = self::installUser($username, $email, $password);
        GDO_UserPermission::grant($user, 'admin');
        GDO_UserPermission::grant($user, 'staff');
        return $user;
    }

    private static function installUser(string $username, ?string $email=null, ?string $password=null): GDO_User
    {
        $user = GDO_User::blank([
            'user_type' => 'member',
            'user_name' => $username,
            'user_display_name' => $username,
        ])->insert();
        if ($password)
        {
            $user->saveSettingVar('Login', 'password', BCrypt::create($password)->__toString());
        }
        if ($email)
        {
            $user->saveSettingVar('Mail', 'email', $email);
            $user->saveSettingVar('Mail', 'email_confirmed', Time::getDate());
        }
        return $user;
    }

    private static function installAvatar(GDO_User $user, string $filename): void
    {
        if (!GDO_UserAvatar::getById($user->getID()))
        {
            $file = GDO_File::fromPath(
                $filename,
                Module_Badger::instance()->filePath("install/data/$filename"),
            )->insert();
            $avatar = GDO_Avatar::blank([
                'avatar_file_id' => $file->getID(),
                'avatar_public' => '0',
            ])->insert();
            GDO_UserAvatar::updateAvatar($user, $avatar->getID());
        }

    }


}

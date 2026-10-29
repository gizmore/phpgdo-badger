<?php
namespace GDO\Badgeg\install;

use GDO\Avatar\GDO_Avatar;
use GDO\Avatar\GDO_UserAvatar;
use GDO\Badger\Module_Badger;
use GDO\Crypto\BCrypt;
use GDO\Date\Time;
use GDO\File\GDO_File;
use GDO\User\GDO_User;
use GDO\User\GDO_UserPermission;

final class BadgerInstall
{
    public static function install(): void
    {
        $secret = require_once Module_Badger::instance()->filePath('secret.php');
        $gizmore = self::installAdmin(...$secret['gizmore']);
        self::installAvatar($gizmore, 'gizmore.png');
        self::installAdmin(...$secret['dejavu']);
        $mira = self::installAdmin(...$secret['mira']);
        self::installAvatar($mira, 'mira.png');
        BadgerInstallBadges::install();
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
        $user = GDO_User::make([
            'user_type' => 'member',
            'user_name' => $username,
            'user_display_name' => $username,
        ])->save();
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

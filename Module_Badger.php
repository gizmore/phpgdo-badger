<?php
declare(strict_types=1);

namespace GDO\Badger;

use GDO\Badgeg\install\BadgerInstall;
use GDO\Core\GDO_Module;

final class Module_Badger extends GDO_Module
{
    public function getClasses(): array
    {
        return [
        ];
    }

    public function getDependencies(): array
    {
        return [
            'AboutMe',
            'Bootstrap5Theme',
            'Category',
            'Forum', 'Friends',
            'News',
            'PaymentAdmin', 'PaymentPaypal', 'PaymentCredits',
            'PM',
            'Table','Tags',
        ];
    }

    public function getConfig(): array
    {
        return [];
    }

    public function onInstall(): void
    {
        BadgerInstall::install();
    }
}

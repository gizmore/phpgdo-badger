<?php
declare(strict_types=1);
namespace GDO\Badger\Method;

use GDO\Core\GDT;
use GDO\Core\Method;
use GDO\UI\GDT_HTML;
use GDO\UI\Module_UI;

final class FAQ extends Method
{
    public function isUserRequired(): bool { return false; }

    public function execute(): GDT
    {
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $color = Module_UI::instance()->cfgThemeColor();
        if (!preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/iD', $color)) { $color = '#ff9900'; }
        return GDT_HTML::make()->var('<section><h1 class="badger-faq-heading" data-theme-color="' . $escape($color) . '">' .
            $escape(t('mt_badger_faq')) . '</h1><p>' . $escape(t('badger_faq_badge')) . '</p><p>' .
            $escape(t('badger_faq_claim')) . '</p></section>');
    }
}

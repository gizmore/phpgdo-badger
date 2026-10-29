<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Badger\GDO_Achieve;
use GDO\Badger\GDO_Attest;
use GDO\Badger\GDO_Badge;

use GDO\Core\GDO;
use GDO\User\GDO_User;
use RuntimeException;

final class AchievementHistory
{
    public static function render(GDO_User $viewer, GDO_User $recipient, GDO_Badge $badge, GDO_Achieve $achievement): string
    {
        if (!BadgerPermissions::canView($viewer, $recipient, $badge, $achievement) || !$achievement->getID())
        {
            throw new RuntimeException('Permission denied');
        }
        $private = $viewer->isMember() && ($viewer->getID() === $recipient->getID() || BadgerPermissions::canEditBadge($viewer, $badge));
        $decisions = $private ? "('revoked','rejected','pending','recommendation_approve','recommendation_reject','recommendation_inconclusive')" : "('revoked')";
        $rows = GDO_Attest::table()->select()->where('bat_achieve=' . GDO::quoteS($achievement->getID()))
            ->where('(bat_decision IN ' . $decisions . " OR (bat_from_status='revoked' AND bat_to_status='verified'))")->order('bat_id ASC')->exec()->fetchAllObjects();
        if (!$rows)
        {
            return '';
        }
        $output = '<ul class="badger-history">';
        foreach ($rows as $row)
        {
            if ($row->gdoVar('bat_decision') === 'pending' && !trim($row->gdoVar('bat_reason') ?? '')) { continue; }
            $label = $row->gdoVar('bat_from_status') === 'revoked' && $row->gdoVar('bat_to_status') === 'verified'
                ? t('badger_history_correction') : ($row->gdoVar('bat_decision') ?? '');
            $output .= '<li>' . html($label) . ': ' . html($row->gdoVar('bat_reason') ?? '') . '</li>';
        }
        return $output . '</ul>';
    }
}

<?php
declare(strict_types=1);
namespace GDO\Badger;

use GDO\Core\GDO;
use GDO\Date\Time;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;
use Throwable;

final class AchievementTransition
{
    public static function apply(GDO_Achieve $achievement, string $to, string $actorType, ?GDO_User $actor, ?string $reason = null, bool $ownsTransaction = true, ?string $metadata = null): void
    {
        $from = $achievement->gdoVar('ba_status');
        if (!AchievementLifecycle::allows($from, $to) || !in_array($actorType, ['staff', 'community', 'ai', 'system'], true))
        {
            throw new RuntimeException('Invalid achievement transition');
        }
        if ($to === 'revoked' && !trim($reason ?? ''))
        {
            throw new RuntimeException('Revocation requires a reason');
        }
        if ($from === 'revoked' && $to === 'verified' && ($actorType !== 'staff' || !$actor || !trim($reason ?? '')))
        {
            throw new RuntimeException('Staff correction requires a reason');
        }
        $db = Database::instance();
        if ($ownsTransaction && !$db->transactionBegin())
        {
            throw new RuntimeException('Could not begin achievement transaction');
        }
        try
        {
            $query = $achievement->updateQuery()->set('ba_status', GDO::quoteS($to))
                ->where('ba_status=' . GDO::quoteS($from));
            $round = (int)$achievement->gdoVar('ba_round');
            $query->where('ba_round=' . $round);
            if ($to === 'pending')
            {
                $round++;
                $query->set('ba_round', (string)$round)->set('ba_staff_approvals', '0')->set('ba_community_approvals', '0');
            }
            if ($to === 'revoked')
            {
                $query->set('ba_equipped', '0');
            }
            if ($from === 'revoked' && $to === 'verified')
            {
                $query->set('ba_claimed', 'NULL')->set('ba_equipped', '0');
            }
            $timestamp = ['pending' => 'ba_submitted', 'verified' => 'ba_verified', 'claimed' => 'ba_claimed', 'revoked' => 'ba_revoked'][$to] ?? null;
            if ($timestamp !== null)
            {
                $query->set($timestamp, GDO::quoteS(Time::getDate()));
            }
            if (!$query->exec() || $db->affectedRows() !== 1)
            {
                throw new RuntimeException('Achievement changed concurrently');
            }
            GDO_Attest::blank([
                'bat_achieve' => $achievement->getID(),
                'bat_actor_type' => $actorType,
                'bat_actor' => $actor?->getID(),
                'bat_decision' => $to,
                'bat_round' => (string)$round,
                'bat_reason' => $reason,
                'bat_metadata' => $metadata,
                'bat_from_status' => $from,
                'bat_to_status' => $to,
            ])->insert();
            if ($ownsTransaction && !$db->transactionEnd())
            {
                throw new RuntimeException('Could not commit achievement transaction');
            }
        }
        catch (Throwable $ex)
        {
            if ($ownsTransaction)
            {
                $db->transactionRollback();
            }
            throw $ex;
        }
        $achievement->uncache();
    }
}

<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

use GDO\Core\GDO;
use GDO\Date\Time;
use GDO\DB\Database;
use GDO\User\GDO_User;
use RuntimeException;

final class BadgeRateLimit
{
    /** The caller must hold a transaction until its attestation is inserted. */
    public static function check(GDO_User $actor, string $kind, int $limit, int $now): void
    {
        if ($limit === 0) { return; }
        if ($limit < 0 || !$actor->getID()) { throw new RuntimeException('Invalid rate limit'); }
        [$type, $decision] = match ($kind) {
            'claim' => ['system', 'pending'],
            'community' => ['community', 'approve'],
            default => throw new RuntimeException('Invalid rate limit'),
        };
        $db = Database::instance();
        $actorId = GDO::quoteS($actor->getID());
        if (!$db->queryRead("SELECT user_id FROM gdo_user WHERE user_id={$actorId} FOR UPDATE")->fetch_assoc())
        {
            throw new RuntimeException('Permission denied');
        }
        $cutoff = GDO::quoteS(Time::getDate($now - 3600));
        $rows = $db->queryRead('SELECT bat_id FROM gdo_badger_attest WHERE bat_actor=' . $actorId .
            ' AND bat_actor_type=' . GDO::quoteS($type) . ' AND bat_decision=' . GDO::quoteS($decision) . " AND bat_created > {$cutoff} FOR UPDATE");
        $count = 0;
        while ($rows->fetch_assoc()) { $count++; }
        if ($count >= $limit) { throw new RuntimeException('Badger rate limit exceeded'); }
    }
}

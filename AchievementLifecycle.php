<?php
declare(strict_types=1);
namespace GDO\Badger;

final class AchievementLifecycle
{
    private const TRANSITIONS = [
        'viewed' => ['draft', 'pending'],
        'draft' => ['pending', 'withdrawn'],
        'pending' => ['verified', 'rejected', 'withdrawn'],
        'verified' => ['claimed', 'revoked'],
        'claimed' => ['revoked'],
        'rejected' => ['draft', 'pending'],
        'withdrawn' => ['draft'],
        'revoked' => ['verified'],
    ];

    public static function allows(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function countsRewards(string $status): bool
    {
        return $status === 'claimed';
    }

    public static function deletionState(string $state, bool $byRecipient): string
    {
        return match ($state) {
            'not_deleted' => $byRecipient ? 'deleted_user' : 'deleted_badger',
            'deleted_user' => $byRecipient ? 'deleted_user' : 'deleted_both',
            'deleted_badger' => $byRecipient ? 'deleted_both' : 'deleted_badger',
            'deleted_both' => 'deleted_both',
            default => throw new \InvalidArgumentException('Unknown deletion state'),
        };
    }
}

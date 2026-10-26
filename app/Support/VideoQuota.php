<?php

namespace App\Support;

use App\Models\Post;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Monthly video quota. 1 video (Post) = 1 unit, no matter how many platforms
 * (PostTargets) it is published to.
 */
class VideoQuota
{
    /**
     * Start of the user's current billing month: the active paid period when
     * there is one, otherwise the calendar month (Free plan).
     */
    public static function periodStart(User $user): CarbonInterface
    {
        $periodEnd = $user->subscription()?->current_period_end;

        if ($periodEnd && $periodEnd->isFuture()) {
            return $periodEnd->copy()->subMonthNoOverflow();
        }

        return now()->startOfMonth();
    }

    /**
     * Distinct videos created or scheduled in the current billing month.
     */
    public static function used(User $user): int
    {
        return self::countedQuery($user, self::periodStart($user))->count();
    }

    /**
     * Whether an existing video already consumed a unit this billing month
     * (e.g. scheduling an uploaded draft, adding a carousel slide).
     */
    public static function alreadyCounted(User $user, string $postId): bool
    {
        return self::countedQuery($user, self::periodStart($user))->whereKey($postId)->exists();
    }

    private static function countedQuery(User $user, CarbonInterface $start): Builder
    {
        $activeTarget = fn ($t) => $t->whereNotIn('status', ['cancelled', 'failed']);

        // Trashed videos still count once they were scheduled or published,
        // so deleting a published video doesn't hand the unit back.
        return Post::withTrashed()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('deleted_at')->whereNotIn('status', ['failed', 'deleted']))
                ->orWhereHas('targets', $activeTarget))
            ->where(fn ($q) => $q
                ->where('created_at', '>=', $start)
                ->orWhereHas('targets', fn ($t) => $activeTarget($t)->where('created_at', '>=', $start)));
    }
}

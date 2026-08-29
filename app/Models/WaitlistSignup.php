<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaitlistSignup extends Model
{
    use HasFactory;

    protected $guarded = ['id'];
    
    protected $casts = [
        'confirmed_at' => 'datetime',
    ];

    /**
     * Users referred by this signup.
     */
    public function referrals()
    {
        return $this->hasMany(WaitlistSignup::class, 'referred_by', 'referral_code');
    }

    /**
     * The user who referred this signup.
     */
    public function referrer()
    {
        return $this->belongsTo(WaitlistSignup::class, 'referred_by', 'referral_code');
    }

    /**
     * Calculate the dynamic position of this user.
     */
    public function calculatePosition(): int
    {
        if (!$this->confirmed_at) {
            return 0; // Unconfirmed users don't have a valid position
        }

        // Count of confirmed signups created before or at the same time as this user
        $basePosition = self::whereNotNull('confirmed_at')
            ->where('created_at', '<=', $this->created_at)
            ->count();

        // Calculate referral boost
        $confirmedReferralsCount = $this->referrals()->whereNotNull('confirmed_at')->count();
        $boost = (int) config('services.waitlist.referral_boost', 10);

        $position = $basePosition - ($confirmedReferralsCount * $boost);

        return max(1, $position);
    }
}

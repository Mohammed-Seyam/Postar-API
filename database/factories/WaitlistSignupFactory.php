<?php

namespace Database\Factories;

use App\Models\WaitlistSignup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistSignup>
 */
class WaitlistSignupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'referral_code' => \Illuminate\Support\Str::random(8),
            'status_token' => \Illuminate\Support\Str::random(32),
            'confirmation_token' => \Illuminate\Support\Str::random(32),
            'confirmed_at' => null,
            'source' => null,
            'referred_by' => null,
        ];
    }
}

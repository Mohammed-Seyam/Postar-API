<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'platform' => $this->faker->randomElement(['tiktok', 'youtube']),
            'platform_user_id' => 'ext_'.$this->faker->unique()->word(),
            'username' => $this->faker->userName(),
            'account_name' => $this->faker->name(),
            'avatar_url' => $this->faker->imageUrl(),
            'access_token' => 'access_token_secret',
            'refresh_token' => 'refresh_token_secret',
            'is_active' => true,
        ];
    }
}

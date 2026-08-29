<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class PostTargetFactory extends Factory
{
    protected $model = PostTarget::class;

    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'social_account_id' => SocialAccount::factory(),
            'status' => 'pending',
            'platform_post_id' => null,
            'error_message' => null,
            'published_at' => null,
        ];
    }
}

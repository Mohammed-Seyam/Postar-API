<?php

namespace Database\Factories;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => $this->faker->sentence(),
            'caption' => $this->faker->paragraph(),
            'video_path' => 'videos/test.mp4',
            'video_url' => $this->faker->url(),
            'thumbnail_path' => $this->faker->imageUrl(),
            'file_size_mb' => 10.00,
            'duration' => $this->faker->numberBetween(10, 300),
            'status' => 'draft',
        ];
    }
}

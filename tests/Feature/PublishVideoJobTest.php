<?php

namespace App\Models {
    // Define the deprecated ScheduledPost class inline for testing if it doesn't exist
    if (!class_exists(ScheduledPost::class)) {
        class ScheduledPost extends \Illuminate\Database\Eloquent\Model {
            protected $guarded = [];
        }
    }
}

namespace Tests\Feature {

    use App\Jobs\PublishVideoJob;
    use App\Models\ScheduledPost;
    use App\Models\SocialAccount;
    use App\Models\User;
    use Illuminate\Foundation\Testing\RefreshDatabase;
    use Illuminate\Support\Facades\Crypt;
    use Illuminate\Support\Facades\Http;
    use Tests\TestCase;

    class PublishVideoJobTest extends TestCase
    {
        use RefreshDatabase;

        public function test_refresh_access_token_for_tiktok(): void
        {
            $user = User::factory()->create();
            $account = SocialAccount::factory()->create([
                'user_id' => $user->id,
                'platform' => 'tiktok',
                'access_token' => 'old-tiktok-access-token',
                'refresh_token' => 'old-tiktok-refresh-token',
                'token_expires_at' => now()->subMinutes(10),
                'refresh_expires_at' => now()->addDays(30),
            ]);

            Http::fake([
                'https://open.tiktokapis.com/v2/oauth/token/' => Http::response([
                    'access_token' => 'new-tiktok-access-token',
                    'refresh_token' => 'new-tiktok-refresh-token',
                    'expires_in' => 86400,
                    'refresh_expires_in' => 2592000,
                    'scope' => 'video.publish',
                ], 200),
            ]);

            $scheduledPost = new ScheduledPost();
            $job = new PublishVideoJob($scheduledPost);

            // Call the private refreshAccessToken method via reflection
            $reflector = new \ReflectionClass(PublishVideoJob::class);
            $method = $reflector->getMethod('refreshAccessToken');
            $method->setAccessible(true);
            $method->invokeArgs($job, [$account]);

            // Reload the account and check changes
            $account->refresh();

            $this->assertEquals('new-tiktok-access-token', $account->access_token);
            $this->assertEquals('new-tiktok-refresh-token', $account->refresh_token);
            $this->assertTrue($account->token_expires_at->isFuture());
        }

        public function test_refresh_access_token_for_youtube(): void
        {
            $user = User::factory()->create();
            $account = SocialAccount::factory()->create([
                'user_id' => $user->id,
                'platform' => 'youtube',
                'access_token' => Crypt::encrypt('old-youtube-access-token'),
                'refresh_token' => Crypt::encrypt('old-youtube-refresh-token'),
                'token_expires_at' => now()->subMinutes(10),
            ]);

            Http::fake([
                'https://oauth2.googleapis.com/token' => Http::response([
                    'access_token' => 'new-youtube-access-token',
                    'expires_in' => 3600,
                ], 200),
            ]);

            $scheduledPost = new ScheduledPost();
            $job = new PublishVideoJob($scheduledPost);

            // Call the private refreshAccessToken method via reflection
            $reflector = new \ReflectionClass(PublishVideoJob::class);
            $method = $reflector->getMethod('refreshAccessToken');
            $method->setAccessible(true);
            $method->invokeArgs($job, [$account]);

            // Reload the account and check changes
            $account->refresh();

            $this->assertEquals('new-youtube-access-token', Crypt::decrypt($account->access_token));
            $this->assertTrue($account->token_expires_at->isFuture());
        }
    }
}

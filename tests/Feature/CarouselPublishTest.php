<?php

namespace Tests\Feature;

use App\Jobs\SchedulePostJob;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Platforms\PlatformCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CarouselPublishTest extends TestCase
{
    use RefreshDatabase;

    // ── Capability registry ──────────────────────────────────────────────────

    public function test_youtube_rejects_images_and_carousels(): void
    {
        $images = collect([(object) ['type' => 'image'], (object) ['type' => 'image']]);

        $errors = PlatformCapabilities::validate('youtube', $images);

        $this->assertNotEmpty($errors);
    }

    public function test_tiktok_allows_image_carousel_but_rejects_mixed(): void
    {
        $carousel = collect([
            (object) ['type' => 'image'],
            (object) ['type' => 'image'],
            (object) ['type' => 'image'],
        ]);
        $this->assertSame([], PlatformCapabilities::validate('tiktok', $carousel));

        $mixed = collect([(object) ['type' => 'image'], (object) ['type' => 'video']]);
        $this->assertNotEmpty(PlatformCapabilities::validate('tiktok', $mixed));

        $tooMany = collect(array_fill(0, 36, (object) ['type' => 'image']));
        $this->assertNotEmpty(PlatformCapabilities::validate('tiktok', $tooMany));
    }

    // ── Publish pipeline ─────────────────────────────────────────────────────

    private function makeImagePost(User $user, int $count): Post
    {
        Storage::fake('public');

        $post = Post::factory()->create([
            'user_id' => $user->id,
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
            'video_path' => null,
            'video_url' => null,
            'thumbnail_path' => null,
            'caption' => 'Hello carousel',
        ]);

        for ($i = 0; $i < $count; $i++) {
            $path = "media/{$user->id}/img_{$i}.jpg";
            Storage::disk('public')->put($path, 'fake-image-bytes');

            $post->media()->create([
                'type' => 'image',
                'path' => $path,
                'position' => $i,
            ]);
        }

        return $post;
    }

    public function test_schedule_job_rejects_image_carousel_on_youtube(): void
    {
        $user = User::factory()->create();
        $account = SocialAccount::factory()->create([
            'user_id' => $user->id,
            'platform' => 'youtube',
            'is_active' => true,
        ]);
        $post = $this->makeImagePost($user, 2);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => 'pending',
        ]);

        SchedulePostJob::dispatchSync($target->id);

        $target->refresh();
        $this->assertSame('failed', $target->status);
        $this->assertStringContainsStringIgnoringCase('youtube', $target->error_message);
    }

    public function test_schedule_job_publishes_tiktok_photo_carousel(): void
    {
        // TikTok pulls slides over HTTP, so the app must present a public host it
        // can reach and that is verifiable — never localhost.
        config(['app.url' => 'https://api.postar.test']);
        URL::forceRootUrl('https://api.postar.test');
        URL::forceScheme('https');

        $user = User::factory()->create();
        $account = SocialAccount::factory()->create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'is_active' => true,
            'access_token' => 'tiktok-access-token',
            'token_expires_at' => now()->addDay(),
        ]);
        $post = $this->makeImagePost($user, 3);
        $target = $post->targets()->create([
            'social_account_id' => $account->id,
            'status' => 'pending',
            'platform_options' => ['privacy_level' => 'SELF_ONLY'],
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/content/init/' => Http::response([
                'data' => ['publish_id' => 'pub_123'],
                'error' => ['code' => 'ok'],
            ], 200),
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
                'data' => [
                    'status' => 'PUBLISH_COMPLETE',
                    'publicaly_available_post_id' => ['real_987'],
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        SchedulePostJob::dispatchSync($target->id);

        $target->refresh();
        $this->assertSame('published', $target->status);
        $this->assertSame('real_987', $target->platform_post_id);

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://open.tiktokapis.com/v2/post/publish/content/init/') {
                return false;
            }
            $body = $request->data();
            $photos = $body['source_info']['photo_images'] ?? [];

            // Slides must be pulled from our own verifiable domain via the signed
            // proxy route — never from R2's unverifiable S3 endpoint or localhost.
            $allProxied = collect($photos)->every(
                fn ($url) => str_starts_with($url, 'https://api.postar.test/api/media/')
                    && str_contains($url, 'signature=')
            );

            return ($body['media_type'] ?? null) === 'PHOTO'
                && count($photos) === 3
                && $allProxied;
        });
    }
}

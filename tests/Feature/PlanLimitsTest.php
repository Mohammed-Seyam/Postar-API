<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear caches
        Cache::flush();

        // Define test routes protected by the middleware
        Route::middleware(['api', 'plan:videos'])->post('/test/videos', fn () => response('ok'));
        Route::middleware(['api', 'plan:posts'])->post('/test/posts', fn () => response('ok'));
        Route::middleware(['api', 'plan:uploads'])->post('/test/uploads', fn () => response('ok'));
        Route::middleware(['api', 'plan:bulk_upload'])->post('/test/bulk-upload', fn () => response('ok'));
        Route::middleware(['api', 'plan:social_accounts'])->post('/test/social', fn () => response('ok'));
        Route::middleware(['api', 'plan:analytics'])->get('/test/analytics', fn () => response('ok'));
        Route::middleware(['api', 'plan:file_size'])->post('/test/file-size', fn () => response('ok'));
    }

    private function createUser(int $planId): User
    {
        return User::factory()->create(['plan_id' => $planId]);
    }

    private function createVideos(User $user, int $count)
    {
        return Post::factory()->count($count)->create([
            'user_id' => $user->id,
            'status' => 'draft',
            'created_at' => now(),
            'file_size_mb' => 10,
        ]);
    }

    private function createPosts(User $user, int $count)
    {
        $posts = collect();
        for ($i = 0; $i < $count; $i++) {
            $post = Post::factory()->create([
                'user_id' => $user->id,
                'status' => 'draft',
                'file_size_mb' => 10,
            ]);

            $posts->push(PostTarget::factory()->create([
                'post_id' => $post->id,
                'status' => 'pending',
                'created_at' => now(),
            ]));
        }

        return $posts;
    }

    private function createPostWithTargets(User $user, int $targets): Post
    {
        $post = Post::factory()->create(['user_id' => $user->id, 'status' => 'scheduled']);
        PostTarget::factory()->count($targets)->create([
            'post_id' => $post->id,
            'status' => 'pending',
            'created_at' => now(),
        ]);

        return $post;
    }

    private function createSocialAccounts(User $user, int $count, int $offset = 0)
    {
        for ($i = $offset; $i < $offset + $count; $i++) {
            DB::table('social_accounts')->insert([
                'user_id' => $user->id,
                'platform' => 'tiktok',
                'platform_user_id' => 'ext_'.$i,
                'access_token' => encrypt('token'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // --- Video quota (1 video = 1 unit, regardless of platforms) ---

    public function test_video_published_to_3_platforms_consumes_one_unit()
    {
        $user = $this->createUser(1);
        $this->createPostWithTargets($user, 3);

        $this->actingAs($user)->getJson('/api/user/plan-context')
            ->assertOk()
            ->assertJsonPath('usage.videos_this_month', 1)
            ->assertJsonPath('limits.videos_per_month', 5);
    }

    public function test_free_user_with_4_multi_platform_videos_can_add_5th()
    {
        $user = $this->createUser(1);
        for ($i = 0; $i < 4; $i++) {
            $this->createPostWithTargets($user, 3);
        }

        $this->actingAs($user)->postJson('/test/videos', ['platforms' => ['tiktok', 'youtube']])
            ->assertStatus(200);
    }

    public function test_free_user_schedules_6th_video()
    {
        $user = $this->createUser(1);
        $this->createPosts($user, 5);

        $this->actingAs($user)->postJson('/test/posts', ['platforms' => ['tiktok']])
            ->assertStatus(402)
            ->assertJsonPath('limit_type', 'videos')
            ->assertJsonPath('upgrade_to', 'creator')
            ->assertJsonPath('cta_action', 'upgrade:creator');
    }

    public function test_free_user_uploads_6th_video()
    {
        $user = $this->createUser(1);
        $this->createVideos($user, 5);

        $this->actingAs($user)->postJson('/test/uploads', [
            'files' => [UploadedFile::fake()->create('video.mp4', 100)],
        ])->assertStatus(402)->assertJsonPath('upgrade_to', 'creator');
    }

    public function test_scheduling_an_already_counted_video_at_quota_is_allowed()
    {
        $user = $this->createUser(1);
        $videos = $this->createVideos($user, 5);

        $this->actingAs($user)->postJson('/test/posts', [
            'video_id' => $videos->first()->id,
            'platforms' => ['tiktok', 'youtube'],
        ])->assertStatus(200);
    }

    public function test_creator_user_can_add_30th_video()
    {
        $user = $this->createUser(2);
        $this->createVideos($user, 29);

        $this->actingAs($user)->postJson('/test/videos')->assertStatus(200);
    }

    public function test_creator_user_blocked_at_31st_video()
    {
        $user = $this->createUser(2);
        $this->createVideos($user, 30);

        $this->actingAs($user)->postJson('/test/videos')
            ->assertStatus(402)
            ->assertJsonPath('upgrade_to', 'pro')
            ->assertJsonPath('message', 'وصلت لحد 30 فيديو هذا الشهر. رقّي لخطة المحترف لنشر حتى 150 فيديو.');
    }

    public function test_pro_user_can_add_150th_video()
    {
        $user = $this->createUser(3);
        $this->createVideos($user, 149);

        $this->actingAs($user)->postJson('/test/videos')->assertStatus(200);
    }

    public function test_pro_user_blocked_at_151st_video()
    {
        $user = $this->createUser(3);
        $this->createVideos($user, 150);

        $this->actingAs($user)->postJson('/test/videos')
            ->assertStatus(402)
            ->assertJsonPath('limit_type', 'videos')
            ->assertJsonPath('maximum', 150)
            ->assertJsonPath('upgrade_to', null);
    }

    // --- Linked accounts (2 / 3 / 5) ---

    public function test_free_user_can_link_2nd_account()
    {
        $user = $this->createUser(1);
        $this->createSocialAccounts($user, 1);

        $this->actingAs($user)->postJson('/test/social')->assertStatus(200);
    }

    public function test_free_user_cannot_link_3rd_account()
    {
        $user = $this->createUser(1);
        $this->createSocialAccounts($user, 2);

        $this->actingAs($user)->postJson('/test/social')
            ->assertStatus(402)
            ->assertJsonPath('upgrade_to', 'creator');
    }

    public function test_creator_user_can_link_3rd_account()
    {
        $user = $this->createUser(2);
        $this->createSocialAccounts($user, 2);

        $this->actingAs($user)->postJson('/test/social')->assertStatus(200);
    }

    public function test_creator_user_blocked_at_4th_account()
    {
        $user = $this->createUser(2);
        $this->createSocialAccounts($user, 3);

        $this->actingAs($user)->postJson('/test/social')
            ->assertStatus(402)
            ->assertJsonPath('upgrade_to', 'pro');
    }

    public function test_pro_user_can_link_5th_but_not_6th_account()
    {
        $user = $this->createUser(3);
        $this->createSocialAccounts($user, 4);
        $this->actingAs($user)->postJson('/test/social')->assertStatus(200);

        $this->createSocialAccounts($user, 1, 4);
        $this->actingAs($user)->postJson('/test/social')->assertStatus(402);
    }

    // --- Bulk upload ---

    public function test_free_user_bulk_upload_2_files_fails()
    {
        $user = $this->createUser(1);

        $this->actingAs($user)->postJson('/test/bulk-upload', [
            'files' => [
                UploadedFile::fake()->create('v1.mp4', 100),
                UploadedFile::fake()->create('v2.mp4', 100),
            ],
        ])->assertStatus(402)->assertJsonPath('upgrade_to', 'creator');
    }

    public function test_creator_user_bulk_upload_6_files_fails()
    {
        $user = $this->createUser(2);
        $files = array_fill(0, 6, UploadedFile::fake()->create('v.mp4', 100));

        $this->actingAs($user)->postJson('/test/bulk-upload', ['files' => $files])
            ->assertStatus(402)
            ->assertJsonPath('upgrade_to', 'pro');
    }

    public function test_pro_user_bulk_upload_20_files_success()
    {
        $user = $this->createUser(3);
        $files = array_fill(0, 20, UploadedFile::fake()->create('v.mp4', 100));

        $this->actingAs($user)->postJson('/test/bulk-upload', ['files' => $files])->assertStatus(200);
    }

    public function test_pro_user_bulk_upload_21_files_fails()
    {
        $user = $this->createUser(3);
        $files = array_fill(0, 21, UploadedFile::fake()->create('v.mp4', 100));

        $this->actingAs($user)->postJson('/test/bulk-upload', ['files' => $files])->assertStatus(402);
    }

    // --- Analytics (Pro only) ---

    public function test_free_user_analytics_fails()
    {
        $user = $this->createUser(1);

        $this->actingAs($user)->getJson('/test/analytics')
            ->assertStatus(402)
            ->assertJsonPath('upgrade_to', 'pro');
    }

    public function test_creator_user_analytics_fails()
    {
        $user = $this->createUser(2);

        $this->actingAs($user)->getJson('/test/analytics')
            ->assertStatus(402)
            ->assertJsonPath('upgrade_to', 'pro');
    }

    public function test_pro_user_analytics_success()
    {
        $user = $this->createUser(3);

        $this->actingAs($user)->getJson('/test/analytics')->assertStatus(200);
    }

    // --- File size (150 MB on all plans) ---

    public function test_any_user_uploads_160mb_file_fails()
    {
        $user = $this->createUser(3);

        $this->actingAs($user)->postJson('/test/file-size', ['file_size_mb' => 160])->assertStatus(402);
    }

    public function test_any_user_uploads_149mb_file_success()
    {
        $user = $this->createUser(1);

        $this->actingAs($user)->postJson('/test/file-size', ['file_size_mb' => 149])->assertStatus(200);
    }
}

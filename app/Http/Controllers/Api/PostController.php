<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Jobs\SchedulePostJob;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->posts()->with('targets.socialAccount', 'targets.analytics')->latest();
        
        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('date') && !empty($request->query('date'))) {
            $query->whereDate('scheduled_at', $request->query('date'));
        }

        if ($request->has('platform') && !empty($request->query('platform'))) {
            $platform = $request->query('platform');
            $query->whereHas('targets.socialAccount', function($q) use ($platform) {
                $q->where('platform', $platform);
            });
        }
        
        $posts = $query->paginate(20);
        return response()->json($posts);
    }

    public function store(StorePostRequest $request)
    {
        $validated = $request->validated();

        $user = $request->user();

        // Resolve platform names to the user's linked social account IDs
        $accounts = $user->socialAccounts()
            ->whereIn('platform', $validated['platforms'])
            ->where('is_active', true)
            ->get()
            ->keyBy('platform');

        $missingPlatforms = array_diff($validated['platforms'], $accounts->keys()->toArray());
        if (!empty($missingPlatforms)) {
            return response()->json([
                'error'   => 'Some platforms are not connected to your account.',
                'missing' => array_values($missingPlatforms),
            ], 422);
        }

        // Ensure the post (video) belongs to this user
        $post = $user->posts()->findOrFail($validated['video_id']);

        // Pre-flight: verify the video file exists on disk before scheduling
        if (!$post->video_path || !Storage::disk('public')->exists($post->video_path)) {
            return response()->json([
                'error' => 'Video file not found on disk. Please re-upload the video.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            $isImmediate = empty($validated['publish_at']);
            
            // Update post scheduling details
            $post->update(array_filter([
                'caption'      => $request->has('caption') ? $validated['caption'] : $post->caption,
                'scheduled_at' => $isImmediate ? now() : $validated['publish_at'],
                'status'       => 'scheduled',
            ], fn($v) => $v !== null));

            // Remove any stale pending targets for platforms not included in this request
            $post->targets()
                 ->where('status', 'pending')
                 ->whereNotIn('social_account_id', $accounts->pluck('id'))
                 ->delete();

            foreach ($accounts as $platform => $account) {
                $target = $post->targets()->updateOrCreate(
                    ['social_account_id' => $account->id],
                    [
                        'status'           => 'pending',
                        'error_message'    => null,
                        'platform_post_id' => null,
                        'published_at'     => null,
                    ]
                );

                if (class_exists(SchedulePostJob::class)) {
                    if ($isImmediate) {
                        SchedulePostJob::dispatch($target->id);
                    } else {
                        SchedulePostJob::dispatch($target->id)->delay($post->scheduled_at);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Post scheduled successfully.',
                'post'    => $post->load('targets.socialAccount'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Scheduling failed.', 'details' => $e->getMessage()], 500);
        }
    }

    public function update(UpdatePostRequest $request, $id)
    {
        $validated = $request->validated();

        $post = Post::findOrFail($id);
        $this->authorize('update', $post);

        $user = $request->user();

        DB::beginTransaction();
        try {
            $post->update(array_filter([
                'caption'      => $request->has('caption') ? $validated['caption'] : $post->caption,
                'scheduled_at' => $validated['publish_at'] ?? $post->scheduled_at,
            ], fn($v) => $v !== null));

            // If the platform is changing, update the first pending target
            if (!empty($validated['platform'])) {
                $account = $user->socialAccounts()
                    ->where('platform', $validated['platform'])
                    ->where('is_active', true)
                    ->first();

                if (!$account) {
                    DB::rollBack();
                    return response()->json(['error' => 'Platform account not connected.'], 422);
                }

                $post->targets()->where('status', 'pending')->update([
                    'social_account_id' => $account->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Schedule updated successfully.',
                'post'    => $post->load('targets.socialAccount'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Update failed.', 'details' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $post = Post::with('targets.socialAccount', 'targets.analytics')->findOrFail($id);
        $this->authorize('view', $post);
        return response()->json(['data' => $post]);
    }

    public function destroy(Request $request, $id)
    {
        $post = Post::findOrFail($id);
        $this->authorize('delete', $post);

        DB::beginTransaction();
        try {
            // Delete all pending targets
            $post->targets()->where('status', 'pending')->delete();

            // Reset post status and schedule
            $post->update([
                'scheduled_at' => null,
                'status' => 'draft',
            ]);

            DB::commit();
            return response()->json(['message' => 'Schedule removed successfully.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to remove schedule.', 'details' => $e->getMessage()], 500);
        }
    }
}


<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Jobs\SchedulePostJob;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->user()->posts()->with('media', 'targets.socialAccount', 'targets.analytics')->latest();

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('date') && ! empty($request->query('date'))) {
            $query->whereDate('scheduled_at', $request->query('date'));
        }

        if ($request->has('month') && ! empty($request->query('month'))) {
            $month = $request->query('month');
            $query->whereYear('scheduled_at', substr($month, 0, 4))
                ->whereMonth('scheduled_at', substr($month, 5, 2));
        }

        if ($request->has('day') && ! empty($request->query('day'))) {
            $query->whereDay('scheduled_at', $request->query('day'));
        }

        if ($request->has('platform') && ! empty($request->query('platform'))) {
            $platform = $request->query('platform');
            $query->whereHas('targets.socialAccount', function ($q) use ($platform) {
                $q->where('platform', $platform);
            });
        }

        if ($request->has('search') && ! empty($request->query('search'))) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('caption', 'like', "%{$search}%");
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
        if (! empty($missingPlatforms)) {
            \Illuminate\Support\Facades\Log::error('422 Error: Some platforms are not connected', ['missing' => $missingPlatforms, 'accounts' => $accounts->keys()->toArray(), 'user_id' => $user->id]);
            return response()->json([
                'error' => 'Some platforms are not connected to your account.',
                'missing' => array_values($missingPlatforms),
            ], 422);
        }

        // Ensure the post (video) belongs to this user
        $post = $user->posts()->with('media')->findOrFail($validated['video_id']);

        // Persist any requested slide order before validating/publishing.
        if (! empty($validated['media_order'])) {
            $this->applyMediaOrder($post, $validated['media_order']);
            $post->load('media');
        }

        $media = $post->media;

        // Pre-flight: a post must carry at least one media item.
        if ($media->isEmpty()) {
            return response()->json([
                'error' => 'This post has no media. Please upload at least one file.',
            ], 422);
        }

        // Pre-flight: verify each media file actually exists on disk before scheduling.
        foreach ($media as $item) {
            $exists = $item->path
                && (Storage::disk('r2')->exists($item->path) || Storage::disk('public')->exists($item->path));

            if (! $exists) {
                \Illuminate\Support\Facades\Log::error('422 Error: Media file not found on disk', ['path' => $item->path, 'user_id' => $user->id]);

                return response()->json([
                    'error' => 'One or more media files were not found. Please re-upload.',
                ], 422);
            }
        }

        // Pre-flight: the media set must satisfy every selected platform's capabilities.
        $capabilityErrors = [];
        foreach ($validated['platforms'] as $platform) {
            $errors = \App\Services\Platforms\PlatformCapabilities::validate($platform, $media);
            if (! empty($errors)) {
                $capabilityErrors[$platform] = $errors;
            }
        }

        if (! empty($capabilityErrors)) {
            return response()->json([
                'error' => 'The selected media is not compatible with one or more platforms.',
                'platform_errors' => $capabilityErrors,
            ], 422);
        }

        DB::beginTransaction();
        try {
            $isImmediate = empty($validated['publish_at']);

            // Update post scheduling details
            $post->update([
                'title' => $request->has('title') ? $validated['title'] : $post->title,
                'caption' => $request->has('caption') ? $validated['caption'] : $post->caption,
                'scheduled_at' => $isImmediate ? now() : $validated['publish_at'],
                'status' => 'scheduled',
            ]);

            // Remove any stale pending targets for platforms not included in this request
            $post->targets()
                ->where('status', 'pending')
                ->whereNotIn('social_account_id', $accounts->pluck('id'))
                ->delete();

            foreach ($accounts as $platform => $account) {
                $targetOptions = null;
                if ($platform === 'youtube' && $request->has('youtube_options')) {
                    $targetOptions = $validated['youtube_options'];
                } elseif ($platform === 'tiktok' && $request->has('tiktok_options')) {
                    $targetOptions = $validated['tiktok_options'];
                }

                $target = $post->targets()->updateOrCreate(
                    ['social_account_id' => $account->id],
                    [
                        'status' => 'pending',
                        'error_message' => null,
                        'platform_post_id' => null,
                        'published_at' => null,
                        'platform_options' => $targetOptions,
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
                'post' => $post->load('targets.socialAccount'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Scheduling failed.', 'details' => $e->getMessage()], 500);
        }
    }

    /**
     * Rewrite slide positions to match the given ordered list of media ids.
     * Ids not belonging to the post are ignored; missing ids keep their order.
     */
    private function applyMediaOrder(Post $post, array $orderedIds): void
    {
        $ownedIds = $post->media->pluck('id')->all();
        $ordered = array_values(array_filter($orderedIds, fn ($id) => in_array($id, $ownedIds, true)));

        if (empty($ordered)) {
            return;
        }

        // Append any owned media the client didn't mention, preserving current order.
        foreach ($post->media->sortBy('position') as $item) {
            if (! in_array($item->id, $ordered, true)) {
                $ordered[] = $item->id;
            }
        }

        DB::transaction(function () use ($post, $ordered) {
            // Two-phase to avoid unique(post_id, position) collisions mid-swap.
            foreach ($ordered as $index => $mediaId) {
                $post->media()->where('id', $mediaId)->update(['position' => 1000 + $index]);
            }
            foreach ($ordered as $index => $mediaId) {
                $post->media()->where('id', $mediaId)->update(['position' => $index]);
            }
        });
    }

    public function update(UpdatePostRequest $request, $id)
    {
        $validated = $request->validated();

        $post = Post::findOrFail($id);
        $this->authorize('update', $post);

        $user = $request->user();

        DB::beginTransaction();
        try {
            $post->update([
                'title' => $request->has('title') ? $validated['title'] : $post->title,
                'caption' => $request->has('caption') ? $validated['caption'] : $post->caption,
                'scheduled_at' => $request->has('publish_at') ? $validated['publish_at'] : $post->scheduled_at,
            ]);

            // If the platform is changing, update the first pending target
            if (! empty($validated['platform'])) {
                $account = $user->socialAccounts()
                    ->where('platform', $validated['platform'])
                    ->where('is_active', true)
                    ->first();

                if (! $account) {
                    DB::rollBack();

                    return response()->json(['error' => 'Platform account not connected.'], 422);
                }

                $targetUpdate = [
                    'social_account_id' => $account->id,
                ];

                if ($validated['platform'] === 'youtube' && $request->has('youtube_options')) {
                    $targetUpdate['platform_options'] = json_encode($validated['youtube_options']);
                } elseif ($validated['platform'] === 'tiktok' && $request->has('tiktok_options')) {
                    $targetUpdate['platform_options'] = json_encode($validated['tiktok_options']);
                }

                $post->targets()->where('status', 'pending')->update($targetUpdate);
            }

            DB::commit();

            return response()->json([
                'message' => 'Schedule updated successfully.',
                'post' => $post->load('targets.socialAccount'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Update failed.', 'details' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $post = Post::with('media', 'targets.socialAccount', 'targets.analytics')->findOrFail($id);
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

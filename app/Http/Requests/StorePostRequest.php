<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        // Platforms are whitelisted from the capability registry so a new platform
        // only needs a config/platforms.php entry, not a change here.
        $platforms = implode(',', array_keys(config('platforms', [])));

        return [
            'video_id' => 'required|string|exists:posts,id',
            'platforms' => 'required|array|min:1',
            'platforms.*' => 'required|string|in:'.$platforms,
            // Optional ordered list of post_media ids to persist before publishing.
            'media_order' => 'nullable|array',
            'media_order.*' => 'string',
            'publish_at' => 'nullable|date',
            'caption' => 'nullable|string',
            'title' => 'nullable|string|max:255',
            'youtube_options' => 'nullable|array',
            'youtube_options.privacy' => 'nullable|string|in:public,unlisted,private',
            'youtube_options.category' => 'nullable|string',
            'youtube_options.keywords' => 'nullable|string',
            'youtube_options.madeForKids' => 'nullable|boolean',
            'tiktok_options' => 'nullable|array',
            'tiktok_options.privacy_level' => 'nullable|string|in:PUBLIC_TO_EVERYONE,MUTUAL_FOLLOW_FRIENDS,FOLLOWER_OF_CREATOR,SELF_ONLY',
            'tiktok_options.disable_comment' => 'nullable|boolean',
            'tiktok_options.disable_duet' => 'nullable|boolean',
            'tiktok_options.disable_stitch' => 'nullable|boolean',
            'tiktok_options.video_cover_timestamp_ms' => 'nullable|integer|min:0',
            'tiktok_options.photo_cover_index' => 'nullable|integer|min:0',
            'tiktok_options.auto_add_music' => 'nullable|boolean',
            'instagram_options' => 'nullable|array',
            'instagram_options.share_to_feed' => 'nullable|boolean',
        ];
    }
}

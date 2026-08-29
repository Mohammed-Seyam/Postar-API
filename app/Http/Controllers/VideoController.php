<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVideoRequest;
use App\Http\Resources\VideoResource;
use App\Models\Post;
use App\Services\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VideoController extends Controller
{
    public function __construct(protected VideoService $videoService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $platform = $request->query('platform');
        $perPage = $request->query('per_page', 10);
        $videos = $this->videoService->list($request->user(), $platform, $perPage);

        return VideoResource::collection($videos);
    }

    public function store(StoreVideoRequest $request): VideoResource|JsonResponse
    {
        $user = $request->user();

        $video = $this->videoService->upload(
            $user,
            $request->file('video'),
            $request->input('title'),
            $request->file('thumbnail')
        );

        return new VideoResource($video);
    }

    public function show(Post $video): VideoResource
    {
        $this->authorize('view', $video);

        return new VideoResource($video);
    }

    public function update(\App\Http\Requests\UpdateVideoRequest $request, Post $video): VideoResource
    {
        $this->authorize('update', $video);
        $video = $this->videoService->update(
            $video,
            $request->input('title'),
            $request->file('thumbnail')
        );

        return new VideoResource($video);
    }

    public function destroy(Post $video): JsonResponse
    {
        $this->authorize('delete', $video);
        $this->videoService->delete($video);

        return response()->json(null, 204);
    }
}

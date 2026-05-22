<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Storage;
use App\Models\Post;

$post = Post::find('019dbcac-cb66-7347-8816-b0e3522c286c');

if (!$post) {
    echo "Post not found!\n";
    exit(1);
}

echo "Post: {$post->title}\n";
echo "Video path: {$post->video_path}\n";

$exists = Storage::disk('public')->exists($post->video_path);
echo "File exists on disk: " . ($exists ? 'YES' : 'NO') . "\n";

if ($exists) {
    $size = Storage::disk('public')->size($post->video_path);
    echo "File size: " . round($size / 1024 / 1024, 2) . " MB\n";
    
    $absolutePath = Storage::disk('public')->path($post->video_path);
    echo "Absolute path: {$absolutePath}\n";
    echo "Absolute file exists: " . (file_exists($absolutePath) ? 'YES' : 'NO') . "\n";
}

// Test the TikTokPublishService can resolve the path
echo "\n--- Testing TikTokPublishService path resolution ---\n";
try {
    $service = new App\Services\Platforms\TikTokPublishService();
    $reflection = new ReflectionMethod($service, 'resolveVideoPath');
    $reflection->setAccessible(true);
    $resolvedPath = $reflection->invoke($service, $post);
    echo "Resolved path: {$resolvedPath}\n";
    echo "Resolved file size: " . round(filesize($resolvedPath) / 1024 / 1024, 2) . " MB\n";
    echo "\n✅ Path resolution works!\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

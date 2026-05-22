<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Post;

$post = Post::first();
if (!$post) {
    echo "No post found\n";
    exit;
}

$oldCaption = $post->caption;
$post->caption = "Test Caption " . time();
$post->save();

$newCaption = $post->fresh()->caption;
echo "Old: $oldCaption\n";
echo "New: $newCaption\n";

<?php
require 'vendor/autoload.php';

$getID3 = new getID3();
$videoPath = 'storage/app/public/videos/1/1777206262_WhatsApp_Video_2026-04-26_at_3.21.17_PM.mp4'; // Example path based on ls output

if (!file_exists($videoPath)) {
    // Try another path if the above doesn't work
    $videoPath = 'storage/app/public/videos/019d9d13-67d8-7194-b2d9-6a8c3bfd59e0/1776459120_73508.mp4';
}

if (file_exists($videoPath)) {
    $fileInfo = $getID3->analyze($videoPath);
    echo "Playtime: " . ($fileInfo['playtime_seconds'] ?? 'N/A') . "\n";
    echo "Frame Rate: " . ($fileInfo['video']['frame_rate'] ?? 'N/A') . "\n";
    echo "Resolution: " . ($fileInfo['video']['resolution_x'] ?? 'N/A') . "x" . ($fileInfo['video']['resolution_y'] ?? 'N/A') . "\n";
} else {
    echo "File not found: $videoPath\n";
}

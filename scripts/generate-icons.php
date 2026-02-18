<?php
/**
 * Generate placeholder PWA icons
 * Run: ddev exec wp eval-file scripts/generate-icons.php
 */

$sizes = [
    'icon-180' => 180,
    'icon-192' => 192,
    'icon-512' => 512,
    'icon-maskable-192' => 192,
    'icon-maskable-512' => 512,
];

$basePath = dirname(__DIR__) . '/web/app/themes/stride/assets/img/';

// Ensure directory exists
if (!is_dir($basePath)) {
    mkdir($basePath, 0755, true);
}

// Colors from design system
$bgColor = [45, 62, 80];  // #2D3E50
$textColor = [255, 255, 255];

foreach ($sizes as $name => $size) {
    $img = imagecreatetruecolor($size, $size);

    // Fill with theme color
    $bg = imagecolorallocate($img, $bgColor[0], $bgColor[1], $bgColor[2]);
    imagefill($img, 0, 0, $bg);

    // Add "S" letter shape
    $white = imagecolorallocate($img, $textColor[0], $textColor[1], $textColor[2]);

    // Draw a large S using filled rectangles for visibility
    $padding = (int)($size * 0.25);
    $thickness = (int)(($size - $padding * 2) * 0.2);

    // S shape: top bar, left upper, middle, right lower, bottom bar
    // Top horizontal
    imagefilledrectangle($img, $padding, $padding, $size - $padding, $padding + $thickness, $white);
    // Left upper vertical
    imagefilledrectangle($img, $padding, $padding, $padding + $thickness, (int)($size / 2), $white);
    // Middle horizontal
    imagefilledrectangle($img, $padding, (int)($size / 2) - (int)($thickness / 2), $size - $padding, (int)($size / 2) + (int)($thickness / 2), $white);
    // Right lower vertical
    imagefilledrectangle($img, $size - $padding - $thickness, (int)($size / 2), $size - $padding, $size - $padding, $white);
    // Bottom horizontal
    imagefilledrectangle($img, $padding, $size - $padding - $thickness, $size - $padding, $size - $padding, $white);

    $filePath = $basePath . $name . '.png';
    imagepng($img, $filePath);
    imagedestroy($img);
    echo "Created: {$name}.png ({$size}x{$size})\n";
}

echo "\nAll icons created in: {$basePath}\n";

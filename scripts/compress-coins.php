<?php
/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */

declare(strict_types=1);

/**
 * Compress coin images into an archive for distribution.
 *
 * Run this script to create coins.tar.gz from the public/coins/ directory.
 *
 * Usage: php scripts/compress-coins.php
 */

$coinsDir = __DIR__ . '/../public/coins';
$archiveFile = __DIR__ . '/../public/coins.tar.gz';

if (!is_dir($coinsDir)) {
    echo "Error: Coins directory not found at: {$coinsDir}\n";
    exit(1);
}

// Get list of coin images
$files = glob($coinsDir . '/*.svg');
$count = count($files);

if ($count === 0) {
    echo "Error: No .svg files found in {$coinsDir}\n";
    exit(1);
}

// Calculate original size
$originalSize = 0;
foreach ($files as $file) {
    $originalSize += filesize($file);
}

echo "Found {$count} coin images (" . number_format($originalSize / 1024, 1) . " KB)\n";
echo "Compressing...\n";

// Use PharData to create tar.gz
try {
    // Remove existing archive
    if (file_exists($archiveFile)) {
        unlink($archiveFile);
    }

    // Create tar archive
    $phar = new PharData(__DIR__ . '/../public/coins.tar');
    $phar->buildFromDirectory($coinsDir, '/\.svg$/');
    $phar->compress(Phar::GZ);

    // Remove uncompressed tar
    if (file_exists(__DIR__ . '/../public/coins.tar')) {
        unlink(__DIR__ . '/../public/coins.tar');
    }

    $compressedSize = filesize($archiveFile);
    $savings = (1 - ($compressedSize / $originalSize)) * 100;

    echo "✓ Archive created: {$archiveFile}\n";
    echo "  Compressed size: " . number_format($compressedSize / 1024, 1) . " KB\n";
    echo "  Savings: " . number_format($savings, 1) . "%\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

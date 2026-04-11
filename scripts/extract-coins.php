<?php

declare(strict_types=1);

/**
 * Auto-extract coin images after composer install/update.
 *
 * This script is loaded via composer autoload 'files' section
 * and runs automatically when the package is installed.
 */

(function () {
    $coinsPath = __DIR__ . '/../public/coins';
    $archivePath = __DIR__ . '/../public/coins.tar.gz';

    // Skip if coins already extracted
    if (is_dir($coinsPath) && count(glob($coinsPath . '/*.svg')) > 0) {
        return;
    }

    // Skip if archive doesn't exist
    if (!file_exists($archivePath)) {
        return;
    }

    try {
        if (!is_dir($coinsPath)) {
            mkdir($coinsPath, 0755, true);
        }

        $phar = new PharData($archivePath);
        $phar->extractTo($coinsPath, null, true);
    } catch (Exception $e) {
        // Silently fail - coins can be extracted manually via artisan command
    }
})();

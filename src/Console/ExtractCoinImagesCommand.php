<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


namespace SerenityTechnologies\CashierNowPayments\Console;

use Illuminate\Console\Command;
use PharData;
use Symfony\Component\Console\Command\Command as CommandAlias;

class ExtractCoinImagesCommand extends Command
{
    protected $signature = 'cashier-nowpayments:extract-coins
                            {--force : Overwrite existing coin images}';

    protected $description = 'Extract coin images from the compressed archive';

    /**
     * Path to the compressed archive.
     */
    protected function getArchivePath(): string
    {
        return __DIR__ . '/../../public/coins.tar.gz';
    }

    /**
     * Path to the coins directory.
     */
    protected function getCoinsPath(): string
    {
        return __DIR__ . '/../../public/coins';
    }

    public function handle(): int
    {
        $archivePath = $this->getArchivePath();
        $coinsPath = $this->getCoinsPath();

        // Check archive exists
        if (!file_exists($archivePath)) {
            $this->components->error("Coin archive not found: {$archivePath}");
            $this->components->info('Run `php scripts/compress-coins.php` to create the archive.');
            return CommandAlias::FAILURE;
        }

        // Check if already extracted
        if (is_dir($coinsPath) && !$this->option('force')) {
            $count = count(glob($coinsPath . '/*.svg'));
            if ($count > 0) {
                $this->components->info("Coin images already extracted ({$count} files). Use --force to overwrite.");
                return CommandAlias::SUCCESS;
            }
        }

        // Ensure directory exists
        if (!is_dir($coinsPath)) {
            mkdir($coinsPath, 0755, true);
        }

        $this->components->info('Extracting coin images...');

        try {
            // Extract tar.gz
            $phar = new PharData($archivePath);
            $phar->extractTo($coinsPath, null, true);

            $count = count(glob($coinsPath . '/*.svg'));
            $size = $this->getDirectorySize($coinsPath);

            $this->components->info("Extracted {$count} coin images (" . number_format($size / 1024, 1) . " KB)");
            $this->components->info("Location: {$coinsPath}");

            return CommandAlias::SUCCESS;
        } catch (\Exception $e) {
            $this->components->error("Extraction failed: {$e->getMessage()}");
            return CommandAlias::FAILURE;
        }
    }

    /**
     * Get directory size in bytes.
     */
    protected function getDirectorySize(string $path): int
    {
        $size = 0;
        foreach (glob(rtrim($path, '/') . '/*', GLOB_NOSORT) as $file) {
            $size += filesize($file);
        }
        return $size;
    }
}

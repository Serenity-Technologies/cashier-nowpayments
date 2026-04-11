<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use SerenityTechnologies\NowPayments\Facades\NowPayments;
use Symfony\Component\Console\Command\Command as CommandAlias;

class DownloadCurrencyImagesCommand extends Command
{
    protected $signature = 'cashier-nowpayments:download-currency-images
                            {--force : Re-download existing images}';

    protected $description = 'Download all supported currency coin images from NOWPayments';

    /**
     * Base URL for NOWPayments coin images.
     */
    protected const IMAGE_BASE_URL = 'https://nowpayments.io/images/coins';

    /**
     * Local storage path for coin images.
     */
    protected function getImagePath(): string
    {
        return resource_path('views/vendor/cashier-nowpayments/assets/coins');
    }

    /**
     * Public URL path for coin images.
     */
    protected function getImageUrl(): string
    {
        return asset('vendor/cashier-nowpayments/coins');
    }

    public function handle(): int
    {
        $this->components->info('Fetching available currencies from NOWPayments...');

        try {
            $response = NowPayments::getAvailableCurrencies();
            $currencies = $response->currencies ?? [];
        } catch (\Exception $e) {
            $this->components->error('Failed to fetch currencies: ' . $e->getMessage());
            return CommandAlias::FAILURE;
        }

        $this->components->info('Found ' . count($currencies) . ' currencies. Downloading images...');

        $imagePath = $this->getImagePath();
        $this->ensureDirectoryExists($imagePath);

        $downloaded = 0;
        $skipped = 0;
        $failed = 0;
        $bar = $this->output->createProgressBar(count($currencies));
        $bar->start();

        foreach ($currencies as $currency) {
            $fileName = strtolower($currency) . '.svg';
            $filePath = $imagePath . '/' . $fileName;
            $imageUrl = self::IMAGE_BASE_URL . '/' . $fileName;

            // Skip if exists and not forcing
            if (file_exists($filePath) && !$this->option('force')) {
                $skipped++;
                $bar->advance();
                continue;
            }

            // Download image
            try {
                $response = Http::timeout(10)->get($imageUrl);

                if ($response->successful()) {
                    file_put_contents($filePath, $response->body());
                    $downloaded++;
                } else {
                    // Try .png fallback
                    $pngUrl = self::IMAGE_BASE_URL . '/' . strtolower($currency) . '.png';
                    $pngResponse = Http::timeout(10)->get($pngUrl);

                    if ($pngResponse->successful()) {
                        $pngPath = $imagePath . '/' . strtolower($currency) . '.png';
                        file_put_contents($pngPath, $pngResponse->body());
                        $downloaded++;
                    } else {
                        $failed++;
                    }
                }
            } catch (\Exception $e) {
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->components->info("Image download complete!");
        $this->components->info("Downloaded: <fg=green>{$downloaded}</>");
        $this->components->info("Skipped (existing): <fg=yellow>{$skipped}</>");
        $this->components->info("Failed: <fg=red>{$failed}</>");

        if ($downloaded > 0) {
            $this->components->info("Images saved to: {$imagePath}");
            $this->components->info("Publish assets with: php artisan vendor:publish --tag=cashier-nowpayments-assets");
        }

        return CommandAlias::SUCCESS;
    }

    protected function ensureDirectoryExists(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}

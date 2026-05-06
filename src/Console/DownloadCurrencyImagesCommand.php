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
use Illuminate\Support\Facades\Http;
use SerenityTechnologies\NowPayments\DTOs\Response\FullCurrencyItemResponse;
use SerenityTechnologies\NowPayments\DTOs\Response\FullCurrencyResponse;
use SerenityTechnologies\NowPayments\Facades\NowPayments;
use Symfony\Component\Console\Command\Command as CommandAlias;

class DownloadCurrencyImagesCommand extends Command
{
    protected $signature = 'cashier-nowpayments:download-currency-images
                            {--force : Re-download existing images}';

    protected $description = 'Download all supported currency coin images from NOWPayments';

    /**
     * Base URL prefix for NOWPayments (used with logo_url paths).
     */
    protected const API_BASE_URL = 'https://nowpayments.io';

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
        $this->components->info('Fetching currencies from NOWPayments...');

        try {
            // Use getFullCurrencies() which returns FullCurrencyItemResponse DTOs
            $fullResponse = NowPayments::getFullCurrencies();
            $allCurrencies = $fullResponse->currencies ?? [];

            // Filter to only currencies available for payment
            $currencies = array_filter($allCurrencies, function (FullCurrencyItemResponse $currency) {
                return !empty($currency->availableForPayment);
            });
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

        /** @var FullCurrencyItemResponse $currency */
        foreach ($currencies as $currency) {
            $code = strtolower($currency->code ?? '');
            $logoUrl = $currency->logoUrl ?? null;

            if (!$logoUrl) {
                $failed++;
                $bar->advance();
                continue;
            }

            // Build full URL from logo_url path (e.g., "/images/coins/btc.svg")
            $fullImageUrl = self::API_BASE_URL . $logoUrl;

            // Determine file extension from URL
            $extension = pathinfo($logoUrl, PATHINFO_EXTENSION) ?: 'svg';
            $fileName = $code . '.' . $extension;
            $filePath = $imagePath . '/' . $fileName;

            // Skip if exists and not forcing
            if (file_exists($filePath) && !$this->option('force')) {
                $skipped++;
                $bar->advance();
                continue;
            }

            // Download image
            try {
                $response = Http::timeout(10)->get($fullImageUrl);

                if ($response->successful()) {
                    file_put_contents($filePath, $response->body());
                    $downloaded++;
                } else {
                    $failed++;
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

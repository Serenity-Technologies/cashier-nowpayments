<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SerenityTechnologies\CashierNowPayments\Console\DownloadCurrencyImagesCommand;
use SerenityTechnologies\CashierNowPayments\Console\ExtractCoinImagesCommand;
use SerenityTechnologies\CashierNowPayments\Console\InstallMigrationsCommand;
use SerenityTechnologies\CashierNowPayments\Console\PruneWebhookLogsCommand;
use SerenityTechnologies\CashierNowPayments\Http\Controllers\WebhookController;
use SerenityTechnologies\CashierNowPayments\Http\Middleware\EnsurePaymentBelongsToUser;
use SerenityTechnologies\NowPayments\Handlers\IpnHandler;

class CashierNowPaymentsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'cashier-nowpayments');

        // Register middleware alias
        Route::aliasMiddleware(
            'nowpayments.payment.auth',
            EnsurePaymentBelongsToUser::class
        );

        $this->publishes([
            __DIR__ . '/../config/cashier-nowpayments.php' => config_path('cashier-nowpayments.php'),
        ], 'cashier-nowpayments-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/cashier-nowpayments'),
        ], 'cashier-nowpayments-views');

        $this->publishes([
            __DIR__ . '/../resources/js' => resource_path('js/vendor/cashier-nowpayments'),
        ], 'cashier-nowpayments-assets');

        $this->publishes([
            __DIR__ . '/../public/coins' => public_path('vendor/cashier-nowpayments/coins'),
        ], 'cashier-nowpayments-coin-images');

        $this->publishes([
            __DIR__ . '/../database/migrations/stubs' => database_path('migrations'),
        ], 'cashier-nowpayments-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallMigrationsCommand::class,
                PruneWebhookLogsCommand::class,
                DownloadCurrencyImagesCommand::class,
                ExtractCoinImagesCommand::class,
            ]);

            // Auto-extract coin images after install
            $this->extractCoinsIfNotExists();
        }

        $this->registerRoutes();
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/cashier-nowpayments.php',
            'cashier-nowpayments'
        );

        // Register CheckoutService as a singleton
        $this->app->singleton(
            \SerenityTechnologies\CashierNowPayments\Services\CheckoutService::class,
            function ($app) {
                return new \SerenityTechnologies\CashierNowPayments\Services\CheckoutService();
            }
        );

        // Note: Builder classes are not bound to the container.
        // They are instantiated directly via Billable trait methods:
        //   - $user->charge()   => PaymentBuilder
        //   - $user->invoice()  => InvoiceBuilder
        //   - $user->newSubscription() => SubscriptionBuilder

        // Register webhook controller with IpnHandler dependency
        $this->app->when(WebhookController::class)
            ->needs(IpnHandler::class)
            ->give(function () {
                /** @var IpnHandler $ipnHandler */
                return app(IpnHandler::class);
            });
    }

    /**
     * Register the package's routes.
     */
    protected function registerRoutes(): void
    {
        // Webhook route (API middleware)
        Route::post(
            config('cashier-nowpayments.webhook.path', '/nowpayments/webhook'),
            WebhookController::class
        )->name('cashier-nowpayments.webhook')->middleware(['api']);

        // Checkout routes (web middleware)
        Route::group([
            'middleware' => ['web'],
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        });
    }

    /**
     * Auto-extract coin images if not already present.
     */
    protected function extractCoinsIfNotExists(): void
    {
        $coinsPath = __DIR__ . '/../../public/coins';
        $archivePath = __DIR__ . '/../../public/coins.tar.gz';

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

            $phar = new \PharData($archivePath);
            $phar->extractTo($coinsPath, null, true);
        } catch (\Exception $e) {
            // Silently fail - coins can be extracted manually later
        }
    }
}

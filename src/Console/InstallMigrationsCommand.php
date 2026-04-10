<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Command\Command as CommandAlias;

class InstallMigrationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cashier-nowpayments:install
                            {--force : Overwrite existing migration files}';

    /**
     * The console command description.
     */
    protected $description = 'Install Cashier NOWPayments migrations from stubs';

    /**
     * Available migration stubs.
     */
    protected function stubs() : array
    {
        $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');
        return [
            "create_{$prefix}customer_table.php" => 'create_customer_table.stub',
            "create_{$prefix}plans_table.php" => 'create_plans_table.stub',
            "create_{$prefix}subscription_table.php" => 'create_subscription_table.stub',
            "create_{$prefix}subscription_item_table.php" => 'create_subscription_item_table.stub',
            "create_{$prefix}payment_table.php" => 'create_payment_table.stub',
            "create_{$prefix}invoice_table.php" => 'create_invoice_table.stub',
            "create_{$prefix}payout_table.php" => 'create_payout_table.stub',
            "create_{$prefix}credits_table.php" => 'create_credits_table.stub',
            "create_{$prefix}payout_withdrawals_table.php" => 'create_payout_withdrawals_table.stub',
            "create_{$prefix}webhook_logs_table.php" => 'create_webhook_log_table.stub',
        ];
    }

    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * Create a new command instance.
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
    }

    /**
     * Execute the console command.
     * @throws FileNotFoundException
     */
    public function handle(): int
    {
        $this->components->info('Installing Cashier NOWPayments migrations...');

        $stubsPath = __DIR__ . '/../../database/migrations/stubs';
        $targetPath = database_path('migrations');

        $published = 0;
        $skipped = 0;

        $counter = 1;
        foreach ($this->stubs() as $stubName => $stubFile) {
            $stubContent = $this->files->get($stubsPath . '/' . $stubFile);
            $timestamp = now()->format('Y_m_d_His');
            $targetFile = "{$timestamp}.{$counter}_{$stubName}";
            $targetFilepath = $targetPath . '/' . $targetFile;

            if ($this->files->exists($targetFilepath) && !$this->option('force')) {
                $this->components->twoColumnDetail($targetFile, '<fg=yellow;options=bold>EXISTS</>');
                $skipped++;
                continue;
            }

            $this->files->ensureDirectoryExists($targetPath);
            $this->files->put($targetFilepath, $stubContent);

            $this->components->twoColumnDetail($targetFile, '<fg=green;options=bold>CREATED</>');
            $published++;
            $counter++;
        }

        $this->newLine();
        $this->components->info("Migration installation complete! ({$published} created, {$skipped} skipped)");

        if ($published > 0) {
            $this->components->info('Run `php artisan migrate` to execute the migrations.');
        }

        return CommandAlias::SUCCESS;
    }
}

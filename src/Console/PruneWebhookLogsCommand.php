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
use SerenityTechnologies\CashierNowPayments\Models\WebhookLog;
use Symfony\Component\Console\Command\Command as CommandAlias;

class PruneWebhookLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'cashier-nowpayments:prune-webhook-logs
                            {--days= : Number of days to retain (overrides config)}';

    /**
     * The console command description.
     */
    protected $description = 'Prune old webhook logs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('cashier-nowpayments.webhook.log_retention_days', 30));

        if ($days <= 0) {
            $this->components->warn('Webhook log retention is set to 0 (indefinite). No logs will be pruned.');

            return CommandAlias::SUCCESS;
        }

        $cutoff = now()->subDays($days);

        $webhookLogModel = config('cashier-nowpayments.model.webhook_log', WebhookLog::class);
        $pruned = $webhookLogModel::where('created_at', '<', $cutoff)->count();
        $webhookLogModel::where('created_at', '<', $cutoff)->delete();

        $this->components->info("Pruned {$pruned} webhook logs older than {$days} days.");

        return CommandAlias::SUCCESS;
    }
}

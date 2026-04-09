<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Tests;

use Illuminate\Database\Schema\Blueprint;
use Orchestra\Testbench\TestCase as BaseTestCase;
use SerenityTechnologies\CashierNowPayments\CashierNowPaymentsServiceProvider;
use SerenityTechnologies\NowPayments\NowPaymentsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrations();
    }

    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            NowPaymentsServiceProvider::class,
            CashierNowPaymentsServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-nowpayments.prefix', 'test_cashier_');
        $app['config']->set('cashier-nowpayments.currency', 'usd');
        $app['config']->set('nowpayments.api_key', 'test-api-key');
        $app['config']->set('nowpayments.ipn_secret', 'test-ipn-secret');
        $app['config']->set('app.url', 'http://localhost');
    }

    /**
     * Load migrations for testing.
     */
    protected function loadMigrations(): void
    {
        $this->app['db']->connection()->getSchemaBuilder()->create('test_cashier_customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulidMorphs('billable');
            $table->string('nowpayments_customer_id')->unique()->nullable();
            $table->string('email')->nullable()->index();
            $table->string('name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['billable_type', 'billable_id']);
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('test_cashier_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id')->index();
            $table->string('type')->default('default')->index();
            $table->string('nowpayments_plan_id')->index();
            $table->string('nowpayments_subscription_id')->unique()->index();
            $table->string('status')->index();
            $table->string('currency')->default('usd');
            $table->decimal('total_price', 10, 2);
            $table->integer('quantity')->default(1);
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('cancels_at')->nullable();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->foreign('customer_id')
                ->references('id')
                ->on('test_cashier_customers')
                ->onDelete('cascade');
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('test_cashier_subscription_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('subscription_id')->index();
            $table->string('nowpayments_plan_id');
            $table->string('description')->nullable();
            $table->decimal('amount', 10, 2);
            $table->integer('quantity')->default(1);
            $table->timestamps();

            $table->foreign('subscription_id')
                ->references('id')
                ->on('test_cashier_subscriptions')
                ->onDelete('cascade');
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('test_cashier_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id')->nullable()->index();
            $table->ulidMorphs('billable');
            $table->ulid('subscription_id')->nullable()->index();
            $table->string('nowpayments_payment_id')->nullable()->index();
            $table->string('nowpayments_purchase_id')->unique()->nullable();
            $table->string('parent_payment_id')->nullable();
            $table->string('type')->default('onetime')->index();
            $table->string('status')->index();
            $table->string('currency');
            $table->decimal('amount', 20, 8);
            $table->decimal('amount_paid', 20, 8)->default(0);
            $table->string('pay_currency')->nullable();
            $table->decimal('pay_amount', 20, 8)->nullable();
            $table->string('pay_address')->nullable();
            $table->string('order_id')->nullable()->index();
            $table->text('order_description')->nullable();
            $table->string('payin_hash')->nullable();
            $table->string('payout_hash')->nullable();
            $table->json('fee')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')
                ->references('id')
                ->on('test_cashier_customers')
                ->nullOnDelete();

            $table->foreign('subscription_id')
                ->references('id')
                ->on('test_cashier_subscriptions')
                ->nullOnDelete();

            $table->index(['billable_type', 'billable_id']);
            $table->index(['status', 'created_at']);
        });

        $this->app['db']->connection()->getSchemaBuilder()->create('test_cashier_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('customer_id')->nullable()->index();
            $table->ulidMorphs('billable');
            $table->string('nowpayments_invoice_id')->nullable()->unique()->index();
            $table->string('status')->index();
            $table->string('currency');
            $table->decimal('amount', 20, 8);
            $table->decimal('amount_paid', 20, 8)->default(0);
            $table->string('order_id')->nullable()->index();
            $table->text('order_description')->nullable();
            $table->string('invoice_url')->nullable();
            $table->string('success_url')->nullable();
            $table->string('cancel_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')
                ->references('id')
                ->on('test_cashier_customers')
                ->nullOnDelete();

            $table->index(['billable_type', 'billable_id']);
        });
    }
}

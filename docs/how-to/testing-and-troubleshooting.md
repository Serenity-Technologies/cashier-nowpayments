# Testing & Troubleshooting

## Overview

This guide covers how to write unit tests, feature tests, and webhook tests for the Laravel Cashier NOWPayments package, along with a reference for common issues, debugging techniques, performance optimization, and a security checklist.

All examples assume you are using **Orchestra Testbench** (the standard for testing Laravel packages) and the package's base `TestCase` located at `tests/TestCase.php`.

---

## 1. Unit Testing

### Base TestCase Setup

The package provides an abstract `TestCase` that bootstraps Orchestra Testbench with both the NOWPayments SDK and Cashier service providers, runs migrations, and configures the test environment.

```php
// tests/TestCase.php
namespace SerenityTechnologies\CashierNowPayments\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use SerenityTechnologies\CashierNowPayments\CashierNowPaymentsServiceProvider;
use SerenityTechnologies\NowPayments\NowPaymentsServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrations();
    }

    protected function getPackageProviders($app): array
    {
        return [
            NowPaymentsServiceProvider::class,
            CashierNowPaymentsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cashier-nowpayments.prefix', 'test_cashier_');
        $app['config']->set('cashier-nowpayments.currency', 'usd');
        $app['config']->set('nowpayments.api_key', 'test-api-key');
        $app['config']->set('nowpayments.ipn_secret', 'test-ipn-secret');
        $app['config']->set('app.url', 'http://localhost');
    }

    protected function loadMigrations(): void
    {
        // Creates test_cashier_customers, test_cashier_payments,
        // test_cashier_invoices, test_cashier_subscriptions,
        // test_cashier_subscription_items tables
    }
}
```

All your test classes should extend this `TestCase`.

---

### Testing the Billable Trait

The `Billable` trait aggregates all concerns. Test it by creating a model that uses the trait (the package ships a `User` model at `tests/Models/User.php`):

```php
use SerenityTechnologies\CashierNowPayments\Tests\Models\User;
use SerenityTechnologies\CashierNowPayments\PaymentBuilder;
use SerenityTechnologies\CashierNowPayments\InvoiceBuilder;
use SerenityTechnologies\CashierNowPayments\SubscriptionBuilder;
use SerenityTechnologies\CashierNowPayments\Models\Customer;

/** @test */
public function it_can_create_customer_from_billable_model(): void
{
    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $customer = $user->createOrGetCustomer();

    $this->assertInstanceOf(Customer::class, $customer);
    $this->assertNotNull($customer->nowpayments_customer_id);
}

/** @test */
public function it_can_begin_payment_charge(): void
{
    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $builder = $user->charge(100.00, 'usd');

    $this->assertInstanceOf(PaymentBuilder::class, $builder);
}

/** @test */
public function it_can_begin_invoice_creation(): void
{
    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $builder = $user->invoice(100.00, 'usd');

    $this->assertInstanceOf(InvoiceBuilder::class, $builder);
}

/** @test */
public function it_can_begin_subscription_creation(): void
{
    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $builder = $user->newSubscription('default', 'plan_123');

    $this->assertInstanceOf(SubscriptionBuilder::class, $builder);
}
```

---

### Testing Builders

#### PaymentBuilder

Test builder methods by asserting fluent return types. To test `create()` or `charge()`, mock the `NowPayments` facade:

```php
use SerenityTechnologies\CashierNowPayments\PaymentBuilder;
use SerenityTechnologies\NowPayments\Facades\NowPayments;
use SerenityTechnologies\NowPayments\DTOs\Response\PaymentResponse;

/** @test */
public function it_can_build_payment_with_options(): void
{
    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $customer = $user->createOrGetCustomer();

    $builder = new PaymentBuilder($user, $customer, 100.00, 'usd');
    $builder->withDescription('Test payment')
            ->withOrderId('ORDER-123')
            ->withPayCurrency('btc')
            ->withFixedRate(true)
            ->withFeePaidByUser(true)
            ->withMetadata(['key' => 'value']);

    $this->assertInstanceOf(PaymentBuilder::class, $builder);
}

/** @test */
public function it_throws_exception_for_negative_amount(): void
{
    $this->expectException(\InvalidArgumentException::class);

    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $customer = $user->createOrGetCustomer();
    $builder = new PaymentBuilder($user, $customer, -100.00, 'usd');
    $builder->charge(); // throws on create()
}

/** @test */
public function it_throws_exception_for_empty_currency(): void
{
    $this->expectException(\InvalidArgumentException::class);

    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $customer = $user->createOrGetCustomer();
    $builder = new PaymentBuilder($user, $customer, 100.00, '');
    $builder->charge();
}

/** @test */
public function it_creates_payment_via_nowpayments_api(): void
{
    NowPayments::shouldReceive('createPayment')
        ->once()
        ->andReturn(new PaymentResponse([
            'payment_id' => '12345',
            'purchase_id' => 'purch_abc',
            'payment_status' => 'waiting',
            'price_amount' => 100.00,
            'price_currency' => 'usd',
            'pay_amount' => 0.0015,
            'pay_currency' => 'btc',
            'pay_address' => 'bc1q...',
            'actually_paid' => 0,
            'order_id' => 'ORDER-123',
            'order_description' => 'Test payment',
            'payin_hash' => null,
            'payout_hash' => null,
            'fee' => null,
            'parent_payment_id' => null,
        ]));

    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $customer = $user->createOrGetCustomer();

    $payment = $user->charge(100.00, 'usd')
        ->withDescription('Test payment')
        ->withOrderId('ORDER-123')
        ->charge();

    $this->assertEquals('12345', $payment->nowpayments_payment_id);
    $this->assertEquals('waiting', $payment->status);
    $this->assertEquals('ORDER-123', $payment->order_id);
}
```

#### InvoiceBuilder

```php
use SerenityTechnologies\CashierNowPayments\InvoiceBuilder;
use SerenityTechnologies\NowPayments\Facades\NowPayments;
use SerenityTechnologies\NowPayments\DTOs\Response\InvoiceResponse;

/** @test */
public function it_generates_invoice_via_api(): void
{
    NowPayments::shouldReceive('createInvoice')
        ->once()
        ->andReturn(new InvoiceResponse([
            'id' => 'inv_12345',
            'payment_status' => 'active',
            'price_amount' => 50.00,
            'price_currency' => 'usd',
            'order_id' => 'INV-123',
            'order_description' => 'Monthly subscription',
            'invoice_url' => 'https://nowpayments.io/invoice/inv_12345',
            'success_url' => 'https://example.com/success',
            'cancel_url' => 'https://example.com/cancel',
        ]));

    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $customer = $user->createOrGetCustomer();

    $invoice = $user->invoice(50.00, 'usd')
        ->withDescription('Monthly subscription')
        ->withOrderId('INV-123')
        ->withSuccessUrl('https://example.com/success')
        ->withCancelUrl('https://example.com/cancel')
        ->generate();

    $this->assertEquals('inv_12345', $invoice->nowpayments_invoice_id);
    $this->assertEquals('active', $invoice->status);
    $this->assertNotNull($invoice->invoice_url);
}
```

#### SubscriptionBuilder

```php
use SerenityTechnologies\CashierNowPayments\SubscriptionBuilder;
use SerenityTechnologies\NowPayments\Facades\NowPayments;
use SerenityTechnologies\NowPayments\DTOs\Response\{PlanResponse, SubscriptionResponse};

/** @test */
public function it_creates_subscription_via_api(): void
{
    NowPayments::shouldReceive('getPlan')
        ->with('plan_123')
        ->once()
        ->andReturn(new PlanResponse([
            'id' => 'plan_123',
            'title' => 'Pro Plan',
            'amount' => 29.99,
            'currency' => 'usd',
            'interval_days' => 30,
            'status' => 'active',
        ]));

    NowPayments::shouldReceive('createSubscription')
        ->once()
        ->andReturn(new SubscriptionResponse([
            'id' => 'sub_456',
            'status' => 'waiting_pay',
            'plan_id' => 'plan_123',
        ]));

    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $customer = $user->createOrGetCustomer();

    $subscription = $user->newSubscription('default', 'plan_123')
        ->quantity(2)
        ->withTrialDays(7)
        ->create();

    $this->assertEquals('sub_456', $subscription->nowpayments_subscription_id);
    $this->assertEquals('waiting_pay', $subscription->status);
    $this->assertEquals(2, $subscription->quantity);
    $this->assertNotNull($subscription->trial_ends_at);
}

/** @test */
public function it_throws_when_plan_does_not_exist(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Plan '999' does not exist in NOWPayments");

    NowPayments::shouldReceive('getPlan')
        ->with('999')
        ->andThrow(new \SerenityTechnologies\NowPayments\Exceptions\NowPaymentsException(
            'Plan not found', 404
        ));

    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $customer = $user->createOrGetCustomer();
    $user->newSubscription('default', '999')->create();
}
```

#### PayoutBuilder

```php
use SerenityTechnologies\CashierNowPayments\PayoutBuilder;
use SerenityTechnologies\NowPayments\Facades\NowPayments;
use SerenityTechnologies\NowPayments\DTOs\Response\{PayoutResponse, PayoutWithdrawalResponse};

/** @test */
public function it_sends_payout_via_api(): void
{
    $withdrawalResponse = new PayoutWithdrawalResponse([
        'id' => 'wd_001',
        'address' => '0xabc...',
        'currency' => 'usdttrc20',
        'amount' => 100.00,
    ]);

    NowPayments::shouldReceive('createPayout')
        ->once()
        ->andReturn(new PayoutResponse([
            'id' => 'payout_001',
            'status' => 'creating',
            'withdrawals' => [$withdrawalResponse],
        ]));

    $user = new User();
    $user->email = 'test@example.com';
    $user->name = 'Test User';
    $user->save();

    $customer = $user->createOrGetCustomer();

    $payout = $user->payout()
        ->to('0xabc...', 'usdttrc20', 100.00)
        ->withDescription('Refund')
        ->send();

    $this->assertEquals('payout_001', $payout->nowpayments_payout_id);
    $this->assertEquals('creating', $payout->status);
}
```

#### PlanBuilder

```php
use SerenityTechnologies\CashierNowPayments\PlanBuilder;
use SerenityTechnologies\NowPayments\Facades\NowPayments;
use SerenityTechnologies\NowPayments\DTOs\Response\PlanResponse;

/** @test */
public function it_creates_plan_via_api(): void
{
    NowPayments::shouldReceive('createPlan')
        ->once()
        ->andReturn(new PlanResponse([
            'id' => 'plan_new',
            'title' => 'Starter Plan',
            'amount' => 9.99,
            'currency' => 'usd',
            'interval_days' => 30,
            'status' => 'active',
        ]));

    $plan = (new PlanBuilder('starter'))
        ->withName('Starter Plan')
        ->withAmount(9.99)
        ->withCurrency('usd')
        ->withIntervalDays(30)
        ->withSuccessUrl('https://example.com/welcome')
        ->create();

    $this->assertEquals('plan_new', $plan->id);
    $this->assertEquals('Starter Plan', $plan->title);
}
```

---

### Mocking the NOWPayments API Facade

The `NowPayments` facade supports Laravel's built-in mock system. Use `shouldReceive()` for fluent mocking:

```php
use SerenityTechnologies\NowPayments\Facades\NowPayments;

// Simple mock — one call, return a value
NowPayments::shouldReceive('getPaymentStatus')
    ->with('purch_abc')
    ->once()
    ->andReturn($paymentResponse);

// Mock with exception
NowPayments::shouldReceive('createPayment')
    ->once()
    ->andThrow(new NowPaymentsException('Invalid API key', 401));

// Multiple mocks in one test
NowPayments::shouldReceive('getEstimate')->once()->andReturn($estimate);
NowPayments::shouldReceive('getMinAmount')->once()->andReturn($minAmount);
NowPayments::shouldReceive('createPayment')->once()->andReturn($payment);

// Mock with partial matching (any arguments)
NowPayments::shouldReceive('createPayment')
    ->withArgs(function ($request) {
        return $request->price_amount === 100.00
            && $request->price_currency === 'usd';
    })
    ->once()
    ->andReturn($paymentResponse);
```

---

### Testing Models

#### Payment Model

```php
/** @test */
public function it_can_scope_successful_payments(): void
{
    $payment = Payment::create([
        'status' => 'finished',
        'currency' => 'usd',
        'amount' => 100.00,
        'amount_paid' => 100.00,
    ]);

    $successful = Payment::successful()->get();
    $this->assertTrue($successful->contains($payment));
}

/** @test */
public function it_can_scope_pending_payments(): void
{
    $pending = Payment::create([
        'status' => 'waiting',
        'currency' => 'usd',
        'amount' => 50.00,
        'amount_paid' => 0,
    ]);

    $this->assertTrue($pending->isPending());
    $this->assertFalse($pending->isSuccessful());
}

/** @test */
public function it_can_sync_status_with_nowpayments(): void
{
    NowPayments::shouldReceive('getPaymentStatus')
        ->with('12345')
        ->once()
        ->andReturn(new PaymentResponse([
            'payment_id' => '12345',
            'payment_status' => 'finished',
            'actually_paid' => 100.00,
            'payin_hash' => 'hash123',
        ]));

    $payment = Payment::create([
        'nowpayments_payment_id' => '12345',
        'status' => 'waiting',
        'currency' => 'usd',
        'amount' => 100.00,
        'amount_paid' => 0,
    ]);

    $payment->syncStatus();

    $this->assertEquals('finished', $payment->fresh()->status);
    $this->assertEquals(100.00, $payment->fresh()->amount_paid);
    $this->assertNotNull($payment->fresh()->payin_hash);
}
```

#### Customer Model

```php
/** @test */
public function it_calculates_credit_balance(): void
{
    $customer = Customer::create([
        'email' => 'credits@test.com',
        'name' => 'Credit User',
    ]);

    $customer->credits()->createMany([
        ['amount' => 10.00, 'currency' => 'usd', 'source' => 'overpayment'],
        ['amount' => 5.00, 'currency' => 'usd', 'source' => 'refund'],
    ]);

    $this->assertEquals('15.00000000', $customer->creditBalance());
}

/** @test */
public function it_applies_credits_in_fifo_order(): void
{
    $customer = Customer::create([
        'email' => 'fifo@test.com',
    ]);

    $older = $customer->credits()->create([
        'amount' => 5.00, 'currency' => 'usd', 'source' => 'overpayment',
    ]);
    $newer = $customer->credits()->create([
        'amount' => 10.00, 'currency' => 'usd', 'source' => 'refund',
    ]);

    $result = $customer->applyCredits(8.00);

    $this->assertEquals('5.00000000', $result['covered']); // older fully used
    $this->assertEquals('3.00000000', $result['remaining']); // 3 still owed

    $this->assertNotNull($older->fresh()->applied_at); // fully consumed
    $this->assertEquals('7.00000000', $newer->fresh()->amount); // partially used
}

/** @test */
public function it_checks_active_subscription(): void
{
    $customer = Customer::create([
        'email' => 'sub@test.com',
    ]);

    $customer->subscriptions()->create([
        'type' => 'default',
        'nowpayments_plan_id' => 'plan_123',
        'nowpayments_subscription_id' => 'sub_456',
        'status' => 'paid',
        'currency' => 'usd',
        'total_price' => 29.99,
        'quantity' => 1,
    ]);

    $this->assertTrue($customer->subscribed('default'));
    $this->assertTrue($customer->subscribed('default', 'plan_123'));
    $this->assertFalse($customer->subscribed('default', 'plan_999'));
}
```

#### Invoice Model

```php
/** @test */
public function it_can_scope_paid_invoices(): void
{
    $invoice = Invoice::create([
        'status' => 'finished',
        'currency' => 'usd',
        'amount' => 75.00,
        'amount_paid' => 75.00,
        'paid_at' => now(),
    ]);

    $this->assertTrue($invoice->isPaid());
}
```

#### Subscription Model

```php
/** @test */
public function it_can_cancel_subscription(): void
{
    NowPayments::shouldReceive('cancelSubscription')
        ->with('sub_456')
        ->once()
        ->andReturn(true);

    $subscription = Subscription::create([
        'nowpayments_subscription_id' => 'sub_456',
        'status' => 'paid',
        'nowpayments_plan_id' => 'plan_123',
        'currency' => 'usd',
        'total_price' => 29.99,
        'quantity' => 1,
    ]);

    $subscription->cancel();

    $this->assertEquals('cancelled', $subscription->fresh()->status);
}
```

#### Payout Model

```php
/** @test */
public function it_can_scope_completed_payouts(): void
{
    $payout = Payout::create([
        'status' => 'finished',
        'currency' => 'usdttrc20',
        'amount' => 100.00,
        'address' => '0xabc...',
        'processed_at' => now(),
    ]);

    $this->assertTrue($payout->isCompleted());
}
```

#### Credit Model

```php
/** @test */
public function it_expires_outdated_credits(): void
{
    $customer = Customer::create(['email' => 'expire@test.com']);

    // Expired credit
    $customer->credits()->create([
        'amount' => 10.00,
        'expires_at' => now()->subDays(1),
    ]);

    // Valid credit
    $customer->credits()->create([
        'amount' => 5.00,
        'expires_at' => now()->addDays(30),
    ]);

    $expired = $customer->expireCredits();
    $this->assertEquals(1, $expired);
    $this->assertEquals('5.00000000', $customer->creditBalance());
}
```

---

## 2. Feature Testing

### Testing Checkout Endpoints

The checkout routes are registered under the prefix defined in `config('cashier-nowpayments.routes.prefix')` (default: `cashier-nowpayments`). Use `$this->get()` and `$this->post()` from Orchestra Testbench.

#### Show Checkout Overlay

```php
/** @test */
public function it_renders_checkout_view(): void
{
    $response = $this->get('/cashier-nowpayments/checkout?amount=10&currency=usd');

    $response->assertOk();
    $response->assertViewIs('cashier-nowpayments::checkout');
    $response->assertViewHas('checkoutData');
}
```

#### Create Payment

```php
/** @test */
public function it_creates_payment_for_authenticated_user(): void
{
    $user = new User();
    $user->email = 'buyer@example.com';
    $user->name = 'Buyer';
    $user->save();

    // Ensure a customer record exists
    $user->createOrGetCustomer();

    NowPayments::shouldReceive('getEstimate')->once()->andReturn((object) [
        'estimated_amount' => 0.0015,
        'fee_estimated' => 0.0001,
    ]);

    NowPayments::shouldReceive('getMinAmount')->once()->andReturn((object) [
        'min_amount' => 0.0001,
    ]);

    NowPayments::shouldReceive('createPayment')->once()->andReturn(new PaymentResponse([
        'payment_id' => '12345',
        'purchase_id' => 'purch_xyz',
        'payment_status' => 'waiting',
        'price_amount' => 10.00,
        'price_currency' => 'usd',
        'pay_amount' => 0.0015,
        'pay_currency' => 'btc',
        'pay_address' => 'bc1q...',
        'actually_paid' => 0,
        'order_id' => 'CHECKOUT-abc',
        'payin_hash' => null,
        'payout_hash' => null,
        'fee' => null,
        'parent_payment_id' => null,
    ]));

    $response = $this->actingAs($user)->postJson('/cashier-nowpayments/checkout/payment', [
        'amount' => 10.00,
        'currency' => 'usd',
        'pay_currency' => 'btc',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    $response->assertJsonPath('pay_address', 'bc1q...');
    $response->assertJsonPath('pay_currency', 'btc');

    // Assert persistence
    $this->assertDatabaseHas(config('cashier-nowpayments.prefix', 'cashier_nowpayments_') . 'payments', [
        'nowpayments_payment_id' => '12345',
        'status' => 'waiting',
    ]);
}
```

#### Create Invoice

```php
/** @test */
public function it_creates_invoice_and_returns_url(): void
{
    NowPayments::shouldReceive('createInvoice')->once()->andReturn(new InvoiceResponse([
        'id' => 'inv_999',
        'payment_status' => 'active',
        'price_amount' => 25.00,
        'price_currency' => 'usd',
        'order_id' => 'INV-abc',
        'order_description' => 'Invoice test',
        'invoice_url' => 'https://nowpayments.io/invoice/inv_999',
        'success_url' => 'https://example.com/done',
        'cancel_url' => 'https://example.com/cancel',
    ]));

    $response = $this->postJson('/cashier-nowpayments/checkout/invoice', [
        'amount' => 25.00,
        'currency' => 'usd',
        'description' => 'Invoice test',
        'success_url' => 'https://example.com/done',
        'cancel_url' => 'https://example.com/cancel',
    ]);

    $response->assertOk();
    $response->assertJson(['success' => true]);
    $response->assertJsonPath('invoice_url', 'https://nowpayments.io/invoice/inv_999');
}
```

#### Get Estimate

```php
/** @test */
public function it_returns_payment_estimate(): void
{
    NowPayments::shouldReceive('getEstimate')->once()->andReturn((object) [
        'estimated_amount' => 0.002,
        'fee_estimated' => 0.0001,
    ]);

    NowPayments::shouldReceive('getMinAmount')->once()->andReturn((object) [
        'min_amount' => 0.0001,
    ]);

    $response = $this->postJson('/cashier-nowpayments/checkout/estimate', [
        'amount' => 50.00,
        'from_currency' => 'usd',
        'to_currency' => 'btc',
    ]);

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonPath('estimated_amount', 0.002);
}
```

#### Get Supported Currencies

```php
/** @test */
public function it_returns_supported_currencies(): void
{
    NowPayments::shouldReceive('getAvailableCurrencies')->once()->andReturn((object) [
        'currencies' => ['btc', 'eth', 'ltc', 'usdttrc20'],
    ]);

    $response = $this->getJson('/cashier-nowpayments/checkout/currencies');

    $response->assertOk();
    $response->assertJsonPath('success', true);
    $response->assertJsonCount(4, 'currencies');
}
```

---

### Testing Payment Status Endpoints

#### Check Remote Status

```php
/** @test */
public function it_checks_remote_payment_status(): void
{
    $user = new User();
    $user->email = 'buyer@example.com';
    $user->name = 'Buyer';
    $user->save();

    $customer = $user->createOrGetCustomer();

    $payment = $customer->payments()->create([
        'billable_id' => $user->getKey(),
        'billable_type' => $user->getMorphClass(),
        'nowpayments_payment_id' => '12345',
        'nowpayments_purchase_id' => 'purch_xyz',
        'status' => 'waiting',
        'currency' => 'usd',
        'amount' => 10.00,
        'amount_paid' => 0,
        'pay_currency' => 'btc',
        'pay_amount' => 0.0015,
        'pay_address' => 'bc1q...',
    ]);

    NowPayments::shouldReceive('getPaymentStatus')
        ->with('purch_xyz')
        ->once()
        ->andReturn(new PaymentResponse([
            'payment_id' => '12345',
            'purchase_id' => 'purch_xyz',
            'payment_status' => 'finished',
            'price_amount' => 10.00,
            'price_currency' => 'usd',
            'pay_amount' => 0.0015,
            'pay_currency' => 'btc',
            'pay_address' => 'bc1q...',
            'actually_paid' => 0.0015,
        ]));

    $response = $this->actingAs($user)
        ->getJson("/cashier-nowpayments/payment/status/purch_xyz");

    $response->assertOk();
    $response->assertJsonPath('status', 'completed');
}
```

#### Check Local Status

```php
/** @test */
public function it_checks_local_payment_status(): void
{
    $user = new User();
    $user->email = 'buyer@example.com';
    $user->name = 'Buyer';
    $user->save();

    $customer = $user->createOrGetCustomer();

    $payment = $customer->payments()->create([
        'billable_id' => $user->getKey(),
        'billable_type' => $user->getMorphClass(),
        'nowpayments_payment_id' => '12345',
        'nowpayments_purchase_id' => 'purch_xyz',
        'status' => 'waiting',
        'currency' => 'usd',
        'amount' => 10.00,
        'amount_paid' => 0,
        'pay_currency' => 'btc',
        'pay_amount' => 0.0015,
        'pay_address' => 'bc1q...',
    ]);

    NowPayments::shouldReceive('getPaymentStatus')
        ->with('12345')
        ->once()
        ->andReturn(new PaymentResponse([
            'payment_id' => '12345',
            'purchase_id' => 'purch_xyz',
            'payment_status' => 'finished',
            'actually_paid' => 0.0015,
            'pay_amount' => 0.0015,
            'pay_currency' => 'btc',
            'pay_address' => 'bc1q...',
            'payin_hash' => null,
            'payout_hash' => null,
        ]));

    $response = $this->actingAs($user)
        ->getJson("/cashier-nowpayments/payment/local/{$payment->id}");

    $response->assertOk();
    $response->assertJsonPath('status', 'completed');

    // Assert the local record was updated
    $this->assertDatabaseHas(config('cashier-nowpayments.prefix', 'cashier_nowpayments_') . 'payments', [
        'id' => $payment->id,
        'status' => 'finished',
    ]);
}
```

#### Unauthenticated Access Returns 401

```php
/** @test */
public function it_returns_401_when_unauthenticated(): void
{
    config(['cashier-nowpayments.payment_status.auth.enabled' => true]);

    $response = $this->getJson('/cashier-nowpayments/payment/status/some_id');

    $response->assertUnauthorized();
}
```

---

### Testing Webhook Handling

#### Mocking HMAC Signature

The webhook controller computes an HMAC-SHA512 signature from the raw request body and the configured IPN secret:

```php
protected function generateHmacSignature(string $payload, string $ipnSecret): string
{
    return hash_hmac('sha512', $payload, trim($ipnSecret));
}
```

In tests, generate valid signatures for your payloads:

```php
/** @test */
public function it_rejects_webhook_with_invalid_signature(): void
{
    $payload = json_encode([
        'payment_id' => '12345',
        'payment_status' => 'finished',
        'price_amount' => 10.00,
        'price_currency' => 'usd',
    ]);

    $response = $this->postJson('/nowpayments/webhook', json_decode($payload, true), [
        'Content-Type' => 'application/json',
        'x-nowpayments-sig' => 'invalid-signature-here',
    ]);

    $response->assertForbidden();
    $response->assertJson(['error' => 'Invalid signature']);
}

/** @test */
public function it_accepts_webhook_with_valid_signature(): void
{
    $payload = json_encode([
        'payment_id' => '12345',
        'purchase_id' => 'purch_test',
        'payment_status' => 'finished',
        'price_amount' => 10.00,
        'price_currency' => 'usd',
        'pay_amount' => 0.0015,
        'pay_currency' => 'btc',
        'pay_address' => 'bc1q...',
        'actually_paid' => 0.0015,
        'order_id' => 'ORDER-WEBHOOK-1',
        'payin_hash' => null,
        'payout_hash' => null,
        'fee' => null,
    ]);

    $ipnSecret = config('nowpayments.ipn_secret');
    $signature = hash_hmac('sha512', $payload, $ipnSecret);

    $response = $this->post('/nowpayments/webhook', json_decode($payload, true), [
        'Content-Type' => 'application/json',
        'x-nowpayments-sig' => $signature,
    ]);

    $response->assertOk();
    $response->assertJson(['status' => 'success']);
}
```

#### Testing Timestamp Tolerance

```php
/** @test */
public function it_rejects_webhook_with_expired_timestamp(): void
{
    $payload = [
        'payment_id' => '12345',
        'payment_status' => 'finished',
        'price_amount' => 10.00,
        'price_currency' => 'usd',
        'created_at' => now()->subHours(2)->toIso8601String(), // Way outside tolerance
        'pay_amount' => 0.0015,
        'pay_currency' => 'btc',
        'actually_paid' => 0.0015,
    ];

    $jsonPayload = json_encode($payload);
    $ipnSecret = config('nowpayments.ipn_secret');
    $signature = hash_hmac('sha512', $jsonPayload, $ipnSecret);

    $response = $this->post('/nowpayments/webhook', $payload, [
        'Content-Type' => 'application/json',
        'x-nowpayments-sig' => $signature,
    ]);

    $response->assertForbidden();
    $response->assertJson(['error' => 'Timestamp outside tolerance']);
}
```

#### Testing Payment Created from Webhook

```php
/** @test */
public function it_creates_payment_record_from_webhook(): void
{
    $payload = [
        'payment_id' => 'np_webhook_001',
        'purchase_id' => 'purch_webhook_001',
        'payment_status' => 'finished',
        'price_amount' => 50.00,
        'price_currency' => 'usd',
        'pay_amount' => 0.00075,
        'pay_currency' => 'btc',
        'pay_address' => 'bc1qtest',
        'actually_paid' => 0.00075,
        'order_id' => 'ORDER-WEBHOOK-2',
        'order_description' => 'Test order',
        'payin_hash' => 'txhash123',
        'payout_hash' => null,
        'fee' => ['0.00001'],
        'email' => 'buyer@example.com',
    ];

    $jsonPayload = json_encode($payload);
    $ipnSecret = config('nowpayments.ipn_secret');
    $signature = hash_hmac('sha512', $jsonPayload, $ipnSecret);

    $response = $this->post('/nowpayments/webhook', $payload, [
        'Content-Type' => 'application/json',
        'x-nowpayments-sig' => $signature,
    ]);

    $response->assertOk();

    // Assert payment was created in the database
    $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');
    $this->assertDatabaseHas($prefix . 'payments', [
        'nowpayments_payment_id' => 'np_webhook_001',
        'status' => 'finished',
        'currency' => 'usd',
        'amount' => 50.00,
    ]);
}
```

#### Testing Subscription Webhook

```php
/** @test */
public function it_handles_subscription_status_update(): void
{
    $subscription = Subscription::create([
        'nowpayments_subscription_id' => 'sub_webhook_001',
        'status' => 'waiting_pay',
        'nowpayments_plan_id' => 'plan_123',
        'currency' => 'usd',
        'total_price' => 29.99,
        'quantity' => 1,
    ]);

    $payload = [
        'subscription_id' => 'sub_webhook_001',
        'status' => 'paid',
        'plan_id' => 'plan_123',
    ];

    $jsonPayload = json_encode($payload);
    $ipnSecret = config('nowpayments.ipn_secret');
    $signature = hash_hmac('sha512', $jsonPayload, $ipnSecret);

    $response = $this->post('/nowpayments/webhook', $payload, [
        'Content-Type' => 'application/json',
        'x-nowpayments-sig' => $signature,
    ]);

    $response->assertOk();

    $this->assertDatabaseHas(config('cashier-nowpayments.prefix', 'cashier_nowpayments_') . 'subscriptions', [
        'nowpayments_subscription_id' => 'sub_webhook_001',
        'status' => 'paid',
    ]);
}
```

#### Testing Invoice Webhook

```php
/** @test */
public function it_handles_invoice_payment(): void
{
    $invoice = Invoice::create([
        'nowpayments_invoice_id' => 'inv_webhook_001',
        'status' => 'active',
        'currency' => 'usd',
        'amount' => 75.00,
        'amount_paid' => 0,
    ]);

    $payload = [
        'invoice_id' => 'inv_webhook_001',
        'payment_status' => 'finished',
        'actually_paid' => 75.00,
    ];

    $jsonPayload = json_encode($payload);
    $ipnSecret = config('nowpayments.ipn_secret');
    $signature = hash_hmac('sha512', $jsonPayload, $ipnSecret);

    $response = $this->post('/nowpayments/webhook', $payload, [
        'Content-Type' => 'application/json',
        'x-nowpayments-sig' => $signature,
    ]);

    $response->assertOk();

    $this->assertDatabaseHas(config('cashier-nowpayments.prefix', 'cashier_nowpayments_') . 'invoices', [
        'nowpayments_invoice_id' => 'inv_webhook_001',
        'status' => 'finished',
        'amount_paid' => 75.00,
    ]);
}
```

#### Testing Payout Webhook

```php
/** @test */
public function it_handles_payout_status_update(): void
{
    $payout = Payout::create([
        'nowpayments_payout_id' => 'payout_webhook_001',
        'status' => 'sending',
        'currency' => 'usdttrc20',
        'amount' => 100.00,
        'address' => '0xabc...',
    ]);

    $payload = [
        'id' => 'payout_webhook_001',
        'status' => 'finished',
        'hash' => 'txhash456',
        'currency' => 'usdttrc20',
        'address' => '0xabc...',
    ];

    $jsonPayload = json_encode($payload);
    $ipnSecret = config('nowpayments.ipn_secret');
    $signature = hash_hmac('sha512', $jsonPayload, $ipnSecret);

    $response = $this->post('/nowpayments/webhook', $payload, [
        'Content-Type' => 'application/json',
        'x-nowpayments-sig' => $signature,
    ]);

    $response->assertOk();

    $this->assertDatabaseHas(config('cashier-nowpayments.prefix', 'cashier_nowpayments_') . 'payouts', [
        'nowpayments_payout_id' => 'payout_webhook_001',
        'status' => 'finished',
        'hash' => 'txhash456',
    ]);
}
```

---

### Testing Auth Middleware (`EnsurePaymentBelongsToUser`)

```php
use SerenityTechnologies\CashierNowPayments\Http\Middleware\EnsurePaymentBelongsToUser;

/** @test */
public function middleware_returns_401_when_unauthenticated(): void
{
    config(['cashier-nowpayments.payment_status.auth.enabled' => true]);

    $request = Request::create('/cashier-nowpayments/payment/status/abc123', 'GET');
    $middleware = new EnsurePaymentBelongsToUser();

    $response = $middleware->handle($request, fn($req) => response('OK'));

    $this->assertEquals(401, $response->getStatusCode());
}

/** @test */
public function middleware_passes_when_authenticated(): void
{
    config(['cashier-nowpayments.payment_status.auth.enabled' => true]);

    $user = new User();
    $user->id = 1;
    $user->email = 'auth@test.com';

    $request = Request::create('/cashier-nowpayments/payment/status/abc123', 'GET');
    $request->setUserResolver(fn() => $user);

    $middleware = new EnsurePaymentBelongsToUser();
    $response = $middleware->handle($request, fn($req) => response('OK'));

    $this->assertEquals(200, $response->getStatusCode());
}

/** @test */
public function middleware_is_disabled_when_config_disabled(): void
{
    config(['cashier-nowpayments.payment_status.auth.enabled' => false]);

    $request = Request::create('/cashier-nowpayments/payment/status/abc123', 'GET');
    $middleware = new EnsurePaymentBelongsToUser();

    $response = $middleware->handle($request, fn($req) => response('OK'));

    $this->assertEquals(200, $response->getStatusCode());
}
```

---

### Using `assertDatabaseHas()` and `assertDatabaseMissing()`

Always use the configured table prefix (which defaults to `cashier_nowpayments_` but is `test_cashier_` in the test environment):

```php
$prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');

// Assert a payment was persisted
$this->assertDatabaseHas($prefix . 'payments', [
    'nowpayments_payment_id' => '12345',
    'status' => 'finished',
]);

// Assert a subscription was NOT created
$this->assertDatabaseMissing($prefix . 'subscriptions', [
    'nowpayments_subscription_id' => 'nonexistent',
]);

// Assert a customer was linked to a billable
$this->assertDatabaseHas($prefix . 'customers', [
    'email' => 'test@example.com',
]);

// Assert an invoice was updated
$this->assertDatabaseHas($prefix . 'invoices', [
    'nowpayments_invoice_id' => 'inv_123',
    'amount_paid' => 75.00,
]);

// Assert a payout was not refunded
$this->assertDatabaseMissing($prefix . 'payouts', [
    'status' => 'refunded',
]);
```

---

## 3. Webhook Testing

### Generating Valid HMAC Signatures

Use this helper in your test files to generate valid signatures for any payload:

```php
function generateWebhookSignature(array $payload): string
{
    $json = json_encode($payload);
    $ipnSecret = config('nowpayments.ipn_secret', 'test-ipn-secret');

    return hash_hmac('sha512', $json, $ipnSecret);
}

// Usage in a test:
$payload = ['payment_id' => '12345', 'payment_status' => 'finished'];
$signature = generateWebhookSignature($payload);
```

### Creating Test Webhook Payloads

#### Payment Finished

```php
$payload = [
    'payment_id' => '12345',
    'purchase_id' => 'purch_abc',
    'payment_status' => 'finished',
    'price_amount' => '100.00',
    'price_currency' => 'usd',
    'pay_amount' => '0.0015',
    'pay_currency' => 'btc',
    'pay_address' => 'bc1q...',
    'actually_paid' => '0.0015',
    'actually_paid_fiat' => '100.00',
    'order_id' => 'ORDER-001',
    'order_description' => 'Order #001',
    'payin_hash' => 'txhash123',
    'payout_hash' => null,
    'fee' => ['0.00001'],
    'type' => 'payment',
    'status' => 'finished',
    'created_at' => now()->toIso8601String(),
    'updated_at' => now()->toIso8601String(),
];
```

#### Payment Failed

```php
$payload = [
    'payment_id' => '12346',
    'purchase_id' => 'purch_fail',
    'payment_status' => 'failed',
    'price_amount' => '50.00',
    'price_currency' => 'usd',
    'pay_amount' => '0.00075',
    'pay_currency' => 'btc',
    'pay_address' => 'bc1q...',
    'actually_paid' => '0',
    'order_id' => 'ORDER-002',
    'payin_hash' => null,
    'payout_hash' => null,
    'fee' => null,
    'type' => 'payment',
    'status' => 'failed',
    'created_at' => now()->toIso8601String(),
];
```

#### Subscription Updated

```php
$payload = [
    'subscription_id' => 'sub_12345',
    'plan_id' => 'plan_123',
    'status' => 'paid',
    'next_payment_date' => now()->addDays(30)->toIso8601String(),
    'created_at' => now()->toIso8601String(),
];
```

#### Subscription Cancelled

```php
$payload = [
    'subscription_id' => 'sub_12345',
    'plan_id' => 'plan_123',
    'status' => 'cancelled',
    'cancelled_at' => now()->toIso8601String(),
    'created_at' => now()->subDays(30)->toIso8601String(),
];
```

#### Invoice Paid

```php
$payload = [
    'invoice_id' => 'inv_98765',
    'payment_status' => 'finished',
    'price_amount' => '75.00',
    'price_currency' => 'usd',
    'actually_paid' => '75.00',
    'order_id' => 'INV-003',
    'order_description' => 'Invoice #003',
    'created_at' => now()->toIso8601String(),
];
```

---

### Testing with ngrok for Local Development

To receive real webhooks from NOWPayments during development:

1. **Install ngrok** (https://ngrok.com):
   ```bash
   brew install ngrok        # macOS
   sudo snap install ngrok   # Linux
   ```

2. **Start your Laravel development server**:
   ```bash
   php artisan serve
   ```

3. **Expose it via ngrok**:
   ```bash
   ngrok http 8000
   ```

4. **Configure the webhook URL in NOWPayments**:
   - Go to the NOWPayments dashboard > Settings > IPN Callback URL
   - Set it to `https://<your-ngrok-url>/nowpayments/webhook`

5. **Set the IPN secret** in your `.env`:
   ```env
   NOWPAYMENTS_IPN_SECRET=your-ipn-secret
   ```

6. **Verify webhooks are arriving**:
   ```bash
   tail -f storage/logs/laravel.log
   ```

---

### Using the Postman Collection

The package includes a Postman collection at the project root: `NOWPayments API.postman_collection.json`.

To use it:

1. Open Postman and import the collection:
   - File > Import > Select `NOWPayments API.postman_collection.json`

2. Set environment variables:
   - `baseUrl`: Your local or production API URL (e.g., `https://api.nowpayments.io/v1`)
   - `apiKey`: Your NOWPayments API key

3. Test endpoints:
   - **Create Payment**: POST `/v1/payment`
   - **Get Payment Status**: GET `/v1/payment/{payment_id}`
   - **Get Plan**: GET `/v1/subscription-plan/{plan_id}`
   - **Create Subscription**: POST `/v1/subscription`

4. Use the "Send" button to execute requests and verify responses match expected schemas.

---

### Mock Webhook Endpoint for Development

For rapid iteration without ngrok, create a temporary route that logs incoming webhooks:

```php
// routes/web.php (development only)
if (app()->environment('local')) {
    Route::post('/dev/webhook-inspector', function (\Illuminate\Http\Request $request) {
        \Illuminate\Support\Facades\Log::info('Webhook received', $request->all());
        return response()->json(['received' => true]);
    })->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class]);
}
```

Then point NOWPayments IPN to `https://your-domain/dev/webhook-inspector` and inspect `storage/logs/laravel.log`.

---

## 4. Common Issues & Solutions

### "Class 'Billable' not found"

**Cause:** The package is not installed or the autoloader is stale.

**Solution:**
```bash
# Ensure the package is in composer.json
composer require serenity-technologies/laravel-cashier-nowpayments

# Regenerate the autoloader
composer dump-autoload

# Verify the trait is imported in your model
use SerenityTechnologies\CashierNowPayments\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

Also verify that the underlying NOWPayments SDK is installed:
```bash
composer require serenity-technologies/nowpayments-php
```

---

### "Table not found" — Migrations Not Run

**Cause:** The Cashier NOWPayments tables have not been created, or the table prefix does not match.

**Solution:**
```bash
# Publish and run the migrations
php artisan cashier-nowpayments:install-migrations
php artisan migrate
```

If you have a custom prefix, verify it matches:
```bash
# Check configured prefix
php artisan tinker
>>> config('cashier-nowpayments.prefix')
// Should output: 'cashier_nowpayments_' (or your custom value)
```

If you are using a different prefix, update your `.env`:
```env
CASHIER_NOWPAYMENTS_TABLE_PREFIX=cashier_nowpayments_
```

---

### "Webhook returns 403"

**Cause:** IPN secret is misconfigured, missing, or the HMAC signature does not match.

**Solution:**

1. Verify the IPN secret is set in `.env`:
   ```env
   NOWPAYMENTS_IPN_SECRET=your-exact-ipn-secret
   ```

2. Clear the config cache:
   ```bash
   php artisan config:clear
   php artisan config:cache
   ```

3. Verify the IPN secret matches exactly what is set in the NOWPayments dashboard (Settings > IPN Secret). The comparison is case-sensitive and whitespace-sensitive.

4. Check the Laravel logs for the mismatch reason:
   ```bash
   tail -f storage/logs/laravel.log | grep "NOWPayments webhook"
   ```

5. If the secret is empty, the controller logs a report and allows the request through (relying on the `IpnHandler`). Set the secret to enable secondary HMAC verification.

---

### "Customer not created"

**Cause:** The billable model does not have `email` or `name` attributes, or the `createOrGetCustomer()` method fails.

**Solution:**

Ensure your billable model has the required attributes:

```php
// Your User model must have at minimum:
class User extends Authenticatable
{
    use Billable;

    protected $guarded = [];
    // Must have 'email' attribute (used as customer email)
    // 'name' is optional but recommended
}
```

If your billable model uses different column names (e.g., `user_email` instead of `email`), override the customer creation method:

```php
public function createOrGetCustomer(array $options = []): Customer
{
    $customerModel = config('cashier-nowpayments.model.customer', Customer::class);

    $customer = $customerModel::where('billable_type', $this->getMorphClass())
        ->where('billable_id', $this->getKey())
        ->first();

    if ($customer === null) {
        $customer = new $customerModel();
        $customer->fill([
            'billable_id' => $this->getKey(),
            'billable_type' => $this->getMorphClass(),
            'email' => $this->user_email,  // Custom attribute
            'name' => $this->display_name, // Custom attribute
            'nowpayments_customer_id' => config('cashier-nowpayments.prefix') . 'user_' . $this->getKey(),
        ]);
        $customer->save();
    }

    return $customer;
}
```

---

### "Payment status stale"

**Cause:** The status polling cache duration is too high, or the sync cooldown prevents fresh data from being fetched.

**Solution:**

Adjust the cache and cooldown settings in `.env`:

```env
# Reduce polling cache duration (default: 10 seconds)
CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS=5

# Reduce sync cooldown (default: 15 seconds)
CASHIER_NOWPAYMENTS_SYNC_COOLDOWN=10
```

To manually force a fresh status sync:

```php
$payment = Payment::find($paymentId);
$payment->syncStatus(); // Bypasses cooldown — calls NOWPayments API directly
```

---

### "Subscription not billed"

**Cause:** The plan does not exist in NOWPayments. The `SubscriptionBuilder` requires the plan to be created in NOWPayments **first** before subscribing.

**Solution:**

Create the plan before creating a subscription:

```php
// Step 1: Create the plan in NOWPayments (and persist locally)
$plan = (new PlanBuilder('my_plan'))
    ->withName('My Subscription Plan')
    ->withAmount(29.99)
    ->withCurrency('usd')
    ->withIntervalDays(30)
    ->create();

// Step 2: Use the returned NOWPayments plan ID to subscribe
$subscription = $user->newSubscription('default', $plan->id)->create();
```

If you see the error `Plan 'X' does not exist in NOWPayments`, create the plan first using the `newPlan()` helper:

```php
$plan = $user->newPlan('my_plan')
    ->withName('Pro Plan')
    ->withAmount(49.99)
    ->create();
```

---

### "Credits not applied"

**Cause:** The `withCredits()` method was not called on the `PaymentBuilder`.

**Solution:**

Call `withCredits()` on the builder chain before `charge()`:

```php
// WRONG — credits are NOT applied
$payment = $user->charge(100.00, 'usd')->charge();

// CORRECT — credits will be consumed in FIFO order
$payment = $user->charge(100.00, 'usd')
    ->withCredits()
    ->charge();
```

To verify credits were applied, check the payment's metadata:

```php
echo $payment->metadata['credits_applied'];  // Amount covered by credits
echo $payment->metadata['original_amount'];  // Original charge amount before credits
```

---

### "Guest checkout fails"

**Cause:** The session is not started, or the guest customer lookup by `session_key` fails.

**Solution:**

1. Ensure the `web` middleware is applied to the checkout routes (the service provider does this by default):
   ```php
   // In CashierNowPaymentsServiceProvider
   Route::group(['middleware' => ['web']], function () {
       $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
   });
   ```

2. Verify the session driver is configured:
   ```env
   SESSION_DRIVER=file
   # or: database, redis, cookie
   ```

3. Ensure the checkout view initializes a session before making AJAX requests:
   ```javascript
   // The checkout view should include session initialization
   // The session ID is used to create/look up the guest customer
   ```

4. If guest customers are being duplicated, check that the session persists between the checkout page load and the payment creation request.

---

## 5. Debugging Techniques

### Enabling Query Logging

To inspect all database queries during a test or request:

```php
use Illuminate\Support\Facades\DB;

// Enable query logging
DB::enableQueryLog();

// Run your operation
$payment = $user->charge(100.00, 'usd')->charge();

// Inspect queries
$queries = DB::getQueryLog();
foreach ($queries as $query) {
    echo $query['query'] . "\n";
    echo json_encode($query['bindings']) . "\n";
    echo "Time: {$query['time']}ms\n\n";
}
```

In tests:

```php
/** @test */
public function it_logs_correct_queries_for_payment(): void
{
    DB::enableQueryLog();

    $user = new User();
    $user->email = 'test@example.com';
    $user->save();
    $user->createOrGetCustomer();

    // ... execute payment operation

    $queries = DB::getQueryLog();
    $insertQueries = collect($queries)->filter(fn($q) => str_contains($q['query'], 'insert'));

    $this->assertGreaterThanOrEqual(1, $insertQueries->count());
}
```

---

### Checking Laravel Logs

The package uses `report()` to log webhook errors and API failures. Check the log file:

```bash
# Tail the log in real-time
tail -f storage/logs/laravel.log

# Search for Cashier NOWPayments errors
grep "NOWPayments" storage/logs/laravel.log

# Search for webhook-related entries
grep "webhook" storage/logs/laravel.log

# Search for recent errors
grep -A5 "ERROR" storage/logs/laravel.php | tail -50
```

Common log messages to look for:

| Log Message | Meaning |
|---|---|
| `NOWPayments webhook: HMAC signature mismatch.` | IPN secret does not match |
| `NOWPayments webhook: Timestamp outside tolerance.` | Webhook is older than configured tolerance |
| `NOWPayments webhook: IPN secret not configured` | `nowpayments.ipn_secret` is empty |
| `NOWPayments webhook: Invoice {id} not found locally` | Invoice was created outside this package |

---

### Inspecting Cached Values

The package uses several cache keys. Inspect them with Artisan Tinker:

```bash
php artisan tinker
```

```php
// Check checkout session billable mapping
Cache::get('checkout.billable.ORDER-abc123');
// Returns: ['billable_type' => 'App\Models\User', 'billable_id' => 1]

// Check idempotency cache
Cache::get('checkout.idempotency.<hash>');
// Returns cached payment response if duplicate request was made

// Check remote status poll cache
Cache::get('nowpayments.status.remote.purch_xyz');
// Returns: ['status' => 'completed', 'payment_status' => 'finished', ...]

// Check currency cache
Cache::get('nowpayments.currencies.available');
// Returns: ['btc', 'eth', 'ltc', ...]

// Check specific idempotency key (reconstruct from request params)
Cache::get('checkout.payment.checkout.idempotency.<sha256hash>');
```

To clear all cache:
```bash
php artisan cache:clear
```

---

### Using `report()` Output for Webhook Errors

The webhook controller uses Laravel's `report()` helper for errors. To see these in tests:

```php
/** @test */
public function it_reports_on_signature_mismatch(): void
{
    // Create a custom report handler for testing
    ReportFake::swap(new ReportFake());

    $payload = json_encode(['payment_id' => '12345', 'payment_status' => 'finished']);

    $this->post('/nowpayments/webhook', json_decode($payload, true), [
        'Content-Type' => 'application/json',
        'x-nowpayments-sig' => 'bad-signature',
    ]);

    ReportFake::assertReported(function ($report) {
        return str_contains($report, 'HMAC signature mismatch');
    });
}
```

For production debugging, set the log level to `debug`:
```env
LOG_LEVEL=debug
LOG_CHANNEL=stack
```

---

### Checking NOWPayments Dashboard

When local data looks correct but the API disagrees:

1. **Log in to the NOWPayments Dashboard** (https://nowpayments.io)
2. Navigate to **Payments** and search by `payment_id` or `purchase_id`
3. Verify the payment status, actually paid amount, and currency
4. For subscriptions, go to **Recurring Payments** and check the plan and status
5. For invoices, go to **Invoices** and verify the payment status

If the dashboard shows a different status than your local database, call `syncStatus()`:
```php
Payment::where('status', 'waiting')
    ->get()
    ->each(fn($p) => $p->syncStatus());
```

---

## 6. Performance Optimization

### Currency Caching

Supported currencies are cached with a **1-hour TTL** to avoid excessive API calls:

```php
// In CheckoutController::getSupportedCurrencies()
$currencies = Cache::remember(
    'nowpayments.currencies.available',
    now()->addHour(),
    fn() => NowPayments::getAvailableCurrencies()->currencies ?? []
);
```

To invalidate the currency cache (e.g., when NOWPayments adds new currencies):

```php
Cache::forget('nowpayments.currencies.available');
```

---

### Payment Status Polling Cache

The remote status endpoint caches responses to prevent hammering the NOWPayments API during frontend polling:

```php
// Config: cashier-nowpayments.payment_status.cache_seconds (default: 10)
$cacheSeconds = config('cashier-nowpayments.payment_status.cache_seconds', 10);
$cacheKey = "nowpayments.status.remote.{$purchaseId}";

$cached = Cache::get($cacheKey);
if ($cached !== null) {
    return response()->json($cached);
}
```

Tune this value based on your polling frequency:

```env
# Faster updates (more API calls)
CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS=5

# Slower updates (fewer API calls)
CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS=30
```

---

### Sync Cooldown for Local Status Checks

The local status endpoint (`/payment/local/{paymentId}`) only syncs with NOWPayments if the payment is pending and the last sync was longer than the cooldown period:

```php
// Config: cashier-nowpayments.checkout.sync_cooldown_seconds (default: 15)
$cooldownSeconds = config('cashier-nowpayments.checkout.sync_cooldown_seconds', 15);
$lastSync = $payment->metadata['last_status_sync'] ?? null;

if ($lastSync === null || now()->diffInSeconds(new \DateTime($lastSync)) > $cooldownSeconds) {
    $payment->syncStatus();
    $payment->update([
        'metadata' => array_merge($payment->metadata ?? [], [
            'last_status_sync' => now()->toIso8601String(),
        ]),
    ]);
}
```

Tune this value based on your traffic:

```env
# More frequent syncs (higher API usage, fresher data)
CASHIER_NOWPAYMENTS_SYNC_COOLDOWN=5

# Less frequent syncs (lower API usage, slightly stale data)
CASHIER_NOWPAYMENTS_SYNC_COOLDOWN=60
```

---

### Queue Configuration for Events/Notifications

Events fired by the package (e.g., `PaymentReceived`, `SubscriptionUpdated`) are dispatched synchron by default. For production workloads, queue them:

```php
// In your EventServiceProvider, use ShouldQueue on listeners:
use Illuminate\Contracts\Queue\ShouldQueue;

class SendPaymentConfirmation implements ShouldQueue
{
    use Queueable;

    public function handle(PaymentReceived $event): void
    {
        // Send email, update analytics, etc.
    }
}
```

Configure your queue driver:
```env
QUEUE_CONNECTION=redis
# or: database, sqs, rabbitmq
```

For high-throughput applications, configure dedicated queues:

```php
class SendPaymentConfirmation implements ShouldQueue
{
    public $queue = 'nowpayments-notifications';
}
```

---

### Database Indexes for Common Queries

The migrations include indexes for the most common query patterns. Verify they exist:

```sql
-- Customers
INDEX email
INDEX [billable_type, billable_id]

-- Payments
INDEX [status, created_at]
INDEX nowpayments_payment_id
INDEX nowpayments_purchase_id (UNIQUE)
INDEX order_id
INDEX customer_id

-- Invoices
INDEX nowpayments_invoice_id (UNIQUE)
INDEX status
INDEX order_id
INDEX customer_id

-- Subscriptions
INDEX nowpayments_subscription_id (UNIQUE)
INDEX status
INDEX nowpayments_plan_id
INDEX customer_id
```

For large datasets, consider adding composite indexes:

```sql
-- Optimize "find all successful payments for a user"
ALTER TABLE cashier_nowpayments_payments
    ADD INDEX billable_status (billable_type, billable_id, status);

-- Optimize "find pending subscriptions by plan"
ALTER TABLE cashier_nowpayments_subscriptions
    ADD INDEX plan_status (nowpayments_plan_id, status);
```

---

## 7. Security Checklist

Review each item before deploying to production:

### IPN Secret Configuration

- [ ] `NOWPAYMENTS_IPN_SECRET` is set in `.env` and matches the NOWPayments dashboard
- [ ] The IPN secret is **not** committed to version control
- [ ] The IPN secret is **not** logged or exposed in API responses

```env
# .env
NOWPAYMENTS_IPN_SECRET=your-secret-here  # NOT in .env.example
```

### Webhook Route

- [ ] The webhook route (`POST /nowpayments/webhook`) is **not** behind CSRF middleware
- [ ] The webhook route uses the `api` middleware group (registered automatically by the service provider)
- [ ] No `VerifyCsrfToken` middleware is applied to the webhook path

```php
// In CashierNowPaymentsServiceProvider — verify this is present:
Route::post(
    config('cashier-nowpayments.webhook.path', '/nowpayments/webhook'),
    WebhookController::class
)->name('cashier-nowpayments.webhook')->middleware(['api']);
// NOTE: No 'web' middleware, no CSRF
```

### Payment Status Endpoint Authentication

- [ ] `CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH` is enabled (default: `true`)
- [ ] The `EnsurePaymentBelongsToUser` middleware is active
- [ ] Only authenticated users can check their own payment status

```env
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH=true
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_GUARD=web
```

### Rate Limiting

- [ ] Checkout endpoints are rate-limited to prevent abuse
- [ ] The following throttle limits are in place (from `routes/web.php`):

| Route | Limit | Window |
|---|---|---|
| `POST /checkout/payment` | 30 requests | 1 minute |
| `POST /checkout/invoice` | 20 requests | 1 minute |
| `POST /checkout/subscription` | 10 requests | 1 minute |
| `POST /checkout/estimate` | 60 requests | 1 minute |
| `GET /payment/status/{id}` | 30 requests | 1 minute |
| `GET /payment/local/{id}` | 30 requests | 1 minute |

### Idempotency

- [ ] Payment creation uses idempotency keys to prevent duplicate charges
- [ ] The idempotency key is cached for 5 minutes
- [ ] Duplicate requests within the window return the cached response

The key is generated from: user ID, amount, currency, pay_currency, order_id, and session ID, plus a server-generated ULID suffix.

### HMAC Signature Verification

- [ ] Incoming webhooks are verified via HMAC-SHA512
- [ ] The `x-nowpayments-sig` header is checked
- [ ] The `hash_equals()` function is used (constant-time comparison)

```php
// In WebhookController::verifySignature()
$computed = hash_hmac('sha512', $payload, trim($ipnSecret));
return hash_equals($computed, $signature); // Constant-time comparison
```

### Timestamp Tolerance

- [ ] Webhook timestamps are validated against a configurable tolerance
- [ ] Default tolerance: **300 seconds (5 minutes)**
- [ ] Old webhooks are rejected with a 403 response

```env
# Adjust tolerance (default: 300 seconds)
CASHIER_NOWPAYMENTS_WEBHOOK_TOLERANCE=300
```

To tighten the window:
```env
CASHIER_NOWPAYMENTS_WEBHOOK_TOLERANCE=120  # 2 minutes
```

### Additional Recommendations

- [ ] HTTPS is enforced on all routes (use `Illuminate\Support\Facades\URL::forceScheme('https')` behind proxies)
- [ ] API keys are stored in environment variables, not hardcoded
- [ ] The `nowpayments.api_key` config is never exposed in logs or responses
- [ ] Payment addresses are transmitted over HTTPS only
- [ ] Webhook responses are minimal (no internal data leaked in error responses)
- [ ] Database queries use parameterized bindings (Eloquent handles this)
- [ ] Payout operations are restricted to authenticated users with appropriate permissions

---

## Appendix: Running the Test Suite

```bash
# Run all tests
vendor/bin/phpunit

# Run only unit tests
vendor/bin/phpunit --testsuite=Unit

# Run only feature tests
vendor/bin/phpunit --testsuite=Feature

# Run a specific test file
vendor/bin/phpunit tests/Unit/PaymentBuilderTest.php

# Run a specific test method
vendor/bin/phpunit --filter=it_creates_payment_via_nowpayments_api

# Run tests with verbose output
vendor/bin/phpunit --testdox

# Run tests with code coverage
vendor/bin/phpunit --coverage-html=coverage
```

### phpunit.xml Environment

The `phpunit.xml` file configures the test environment:

```xml
<php>
    <env name="APP_KEY" value="AckfSECXIvnK5r28GVIWUAxmbBSjTsmF"/>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
    <env name="CACHE_DRIVER" value="array"/>
    <env name="SESSION_DRIVER" value="array"/>
    <env name="QUEUE_CONNECTION" value="sync"/>
</php>
```

Key settings:
- **SQLite in-memory database** — fast, isolated, no persistence between tests
- **Array cache driver** — no cross-test cache pollution
- **Array session driver** — sessions exist only for the duration of the test
- **Sync queue** — jobs run immediately (no queue worker needed)

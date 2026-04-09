# Laravel Cashier NOWPayments — How-To Guides

Comprehensive step-by-step guides for every feature in the package.

## Getting Started

| Guide | Description |
|-------|-------------|
| [Installation & Setup](installation-and-setup.md) | Requirements, composer install, publishing assets, migrations, environment config, Billable trait setup, queue configuration, webhook registration |

## Payment Flows

| Guide | Description |
|-------|-------------|
| [One-Time Payments](one-time-payments.md) | Direct payments via `PaymentBuilder`, checkout overlay UI, guest checkout, JS modal module, payment status polling, checkout button helper |
| [Invoice Payments](invoice-payments.md) | Hosted invoice flow via `InvoiceBuilder`, checkout controller, paying existing invoices, guest invoice persistence, invoice webhook handling |

## Recurring Billing

| Guide | Description |
|-------|-------------|
| [Subscriptions & Plans](subscriptions-and-plans.md) | Creating plans via `PlanBuilder`, subscribing users, trial periods, plan swaps with proration, cancellation, subscription webhooks, full lifecycle example |

## Payouts

| Guide | Description |
|-------|-------------|
| [Payouts](payouts.md) | Single and batch payouts via `PayoutBuilder`, scheduled payouts, PayoutWithdrawal records, address validation, payout webhooks, common patterns |

## Credit System

| Guide | Description |
|-------|-------------|
| [Credit System](credit-system.md) | Plan swap proration credits, FIFO credit consumption with pessimistic locking, using credits with PaymentBuilder, credit expiration, audit trail for partial applications |

## Webhooks

| Guide | Description |
|-------|-------------|
| [Webhooks](webhooks.md) | IPN architecture, dashboard configuration, dual-layer HMAC + timestamp security, webhook routing, payment/subscription/invoice/payout handling, customer reconciliation, local testing with ngrok |

## Events & Notifications

| Guide | Description |
|-------|-------------|
| [Events & Notifications](events-and-notifications.md) | All 17 events across 5 domains, base event class, listener registration, Laravel notifications, configuration, event payloads, common patterns |

## Advanced Features

| Guide | Description |
|-------|-------------|
| [Advanced Features](advanced-features.md) | Currency management, crypto estimation, crypto conversions, balance checking, fiat payouts, address validation, remote data queries, payment refunds, auth middleware, custom models |

## Testing & Operations

| Guide | Description |
|-------|-------------|
| [Testing & Troubleshooting](testing-and-troubleshooting.md) | Unit testing with Orchestra Testbench, feature testing HTTP flows, webhook testing with HMAC, common issues & solutions, debugging techniques, performance optimization, security checklist |

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Checkout') }} - {{ config('app.name', 'Laravel') }}</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- QR Code Library (client-side, no external API calls) -->
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

    <style>
        /* Overlay styles */
        .checkout-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(4px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .checkout-modal {
            background: white;
            border-radius: 16px;
            max-width: 500px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Loading spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3b82f6;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Pulse animation for pending payment */
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .pulse {
            animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }

        /* Currency selector */
        .currency-option {
            cursor: pointer;
            transition: all 0.2s;
        }

        .currency-option:hover {
            background-color: #f3f4f6;
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .currency-option.selected {
            background-color: #eff6ff;
            border-color: #3b82f6;
            border-width: 2px;
        }

        /* Search input */
        .currency-search {
            width: 100%;
            padding: 10px 14px 10px 38px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }

        .currency-search:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-wrapper {
            position: relative;
            margin-bottom: 12px;
        }

        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 18px;
            height: 18px;
            color: #9ca3af;
            pointer-events: none;
        }

        .no-results {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }

        .no-results svg {
            width: 48px;
            height: 48px;
            margin: 0 auto 12px;
            color: #d1d5db;
        }

        .currency-badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 500;
            background: #f3f4f6;
            color: #6b7280;
            margin-left: 6px;
        }

        .popular-badge {
            background: #fef3c7;
            color: #92400e;
        }
    </style>
</head>
<body class="bg-gray-100">

<div class="checkout-overlay" id="checkoutOverlay">
    <div class="checkout-modal">
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-600 to-purple-600 p-6 rounded-t-2xl">
            <div class="flex justify-between items-center">
                <h2 class="text-2xl font-bold text-white">{{ __('Cryptocurrency Checkout') }}</h2>
                <button onclick="closeCheckout()" class="text-white hover:text-gray-200 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Checkout Form -->
        <div id="checkoutForm" class="p-6">
            <!-- Amount Display -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Amount to Pay') }}</label>
                <div class="text-3xl font-bold text-gray-900">
                    {{ number_format($checkoutData['amount'], 2) }} {{ strtoupper($checkoutData['currency']) }}
                </div>
                @if($checkoutData['description'])
                    <p class="text-sm text-gray-600 mt-2">{{ $checkoutData['description'] }}</p>
                @endif
            </div>

            <!-- Currency Selector -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">
                    {{ __('Select Payment Currency') }}
                </label>

                <!-- Search Input -->
                <div class="search-wrapper">
                    <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input
                        type="text"
                        id="currencySearch"
                        class="currency-search"
                        placeholder="Search currencies (e.g., bitcoin, btc, ethereum...)"
                        oninput="filterCurrencies(this.value)"
                    >
                </div>

                <div id="currencySelector" class="space-y-2 max-h-64 overflow-y-auto pr-1">
                    <div class="spinner"></div>
                </div>

                <!-- No Results Message -->
                <div id="noResults" class="no-results" style="display: none;">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div class="text-sm">No currencies match your search</div>
                </div>
            </div>

            <!-- Estimate Display -->
            <div id="estimateDisplay" class="hidden mb-6 p-4 bg-blue-50 rounded-lg">
                <div class="flex justify-between items-center">
                    <span class="text-sm text-gray-700">{{ __('You will pay') }}:</span>
                    <span id="estimateAmount" class="text-lg font-bold text-blue-900"></span>
                </div>
            </div>

            <!-- Error Message -->
            <div id="errorMessage" class="hidden mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                <p class="text-red-800 text-sm"></p>
            </div>

            <!-- Pay Button -->
            <button
                id="payButton"
                onclick="createPayment()"
                disabled
                class="w-full bg-gradient-to-r from-blue-600 to-purple-600 text-white font-bold py-3 px-6 rounded-lg hover:from-blue-700 hover:to-purple-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
            >
                {{ __('Continue to Payment') }}
            </button>

            <!-- Cancel Button -->
            <button
                onclick="closeCheckout()"
                class="w-full mt-3 text-gray-600 hover:text-gray-900 font-medium py-2"
            >
                {{ __('Cancel') }}
            </button>
        </div>

        <!-- Payment Details (shown after payment creation) -->
        <div id="paymentDetails" class="hidden p-6">
            <div class="text-center mb-6">
                <div class="inline-block p-4 bg-green-100 rounded-full mb-4">
                    <svg class="w-12 h-12 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-gray-900">{{ __('Send Payment') }}</h3>
            </div>

            <!-- Payment Amount -->
            <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                <div class="text-sm text-gray-600 mb-1">{{ __('Amount to Send') }}</div>
                <div id="payAmountDisplay" class="text-2xl font-bold text-gray-900"></div>
            </div>

            <!-- QR Code -->
            <div class="mb-6 text-center">
                <div id="qrCodeContainer" class="inline-block p-2 bg-white rounded-lg border-2 border-gray-200"></div>
            </div>

            <!-- Payment Address -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">{{ __('Payment Address') }}</label>
                <div class="flex items-center gap-2">
                    <code id="payAddress" class="flex-1 p-3 bg-gray-100 rounded-lg text-sm break-all"></code>
                    <button onclick="copyAddress()" class="p-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Status Indicator -->
            <div id="paymentStatus" class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <div class="flex items-center gap-3">
                    <div class="spinner" style="width: 24px; height: 24px; border-width: 2px;"></div>
                    <div>
                        <div class="font-medium text-yellow-900">{{ __('Waiting for Payment') }}</div>
                        <div class="text-sm text-yellow-700">{{ __('Send the exact amount to the address above') }}</div>
                    </div>
                </div>
            </div>

            <!-- Timer -->
            <div class="text-center text-sm text-gray-600 mb-4">
                {{ __('Payment expires in') }}: <span id="paymentTimer" class="font-bold">15:00</span>
            </div>
        </div>

        <!-- Success State -->
        <div id="paymentSuccess" class="hidden p-6 text-center">
            <div class="inline-block p-6 bg-green-100 rounded-full mb-6">
                <svg class="w-16 h-16 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="text-2xl font-bold text-gray-900 mb-2">{{ __('Payment Successful!') }}</h3>
            <p class="text-gray-600 mb-6">{{ __('Your payment has been received. Redirecting you...') }}</p>
            <button onclick="closeCheckout()" class="bg-green-600 text-white font-bold py-3 px-8 rounded-lg hover:bg-green-700 transition">
                {{ __('Continue') }}
            </button>
        </div>

        <!-- Failed State -->
        <div id="paymentFailed" class="hidden p-6 text-center">
            <div class="inline-block p-6 bg-red-100 rounded-full mb-6">
                <svg class="w-16 h-16 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h3 class="text-2xl font-bold text-gray-900 mb-2">{{ __('Payment Failed') }}</h3>
            <p class="text-gray-600 mb-6">{{ __('The payment could not be completed') }}</p>
            <div class="flex gap-3">
                <button onclick="retryPayment()" class="flex-1 bg-blue-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-blue-700 transition">
                    {{ __('Try Again') }}
                </button>
                <button onclick="closeCheckout()" class="flex-1 bg-gray-200 text-gray-900 font-bold py-3 px-6 rounded-lg hover:bg-gray-300 transition">
                    {{ __('Cancel') }}
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Checkout configuration
const CheckoutConfig = @json($checkoutData);
let selectedCurrency = null;
let currentPayment = null;
let statusCheckInterval = null;
let timerInterval = null;
// Use server-provided timeout (seconds), fall back to 15 minutes
let timeRemaining = CheckoutConfig.timeout_seconds ?? 900;
let qrCodeInstance = null;
let allCurrencies = [];

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    loadCurrencies();
});

// Load supported currencies
async function loadCurrencies() {
    try {
        const response = await fetch('{{ route('cashier-nowpayments.checkout.currencies') }}');
        const data = await response.json();

        if (data.success) {
            allCurrencies = data.currencies;
            displayCurrencies(data.currencies);
        }
    } catch (error) {
        console.error('Failed to load currencies:', error);
    }
}

// Filter currencies based on search
function filterCurrencies(query) {
    const searchTerm = query.toLowerCase().trim();

    if (!searchTerm) {
        displayCurrencies(allCurrencies);
        document.getElementById('noResults').style.display = 'none';
        return;
    }

    const filtered = allCurrencies.filter(currency =>
        currency.code.toLowerCase().includes(searchTerm) ||
        currency.name.toLowerCase().includes(searchTerm) ||
        currency.ticker.toLowerCase().includes(searchTerm) ||
        (currency.blockchain && currency.blockchain.toLowerCase().includes(searchTerm))
    );

    if (filtered.length === 0) {
        document.getElementById('currencySelector').innerHTML = '';
        document.getElementById('noResults').style.display = 'block';
    } else {
        document.getElementById('noResults').style.display = 'none';
        displayCurrencies(filtered);
    }
}

// Display currency options
function displayCurrencies(currencies) {
    const container = document.getElementById('currencySelector');
    container.innerHTML = '';

    currencies.forEach(currency => {
        const div = document.createElement('div');
        div.className = 'currency-option p-3 border-2 border-gray-200 rounded-lg flex items-center gap-3';
        div.dataset.currency = currency.code;

        const popularBadge = currency.is_popular ?
            `<span class="currency-badge popular-badge">★ Popular</span>` : '';

        const networkBadge = currency.network ?
            `<span class="text-xs text-gray-500">· ${escapeHtml(currency.network)}</span>` : '';

        div.innerHTML = `
            <img src="${currency.logo}" alt="${currency.name}" class="w-8 h-8 rounded-full" loading="lazy"
                 onerror="this.src='https://nowpayments.io/images/coins/${currency.code}.svg'">
            <div class="flex-1 min-w-0">
                <div class="font-semibold text-sm text-gray-900 flex items-center flex-wrap gap-1">
                    ${escapeHtml(currency.name)}
                    ${popularBadge}
                </div>
                <div class="text-xs text-gray-500 mt-0.5 flex items-center flex-wrap gap-1">
                    <span class="font-medium text-gray-700 uppercase">${escapeHtml(currency.ticker)}</span>
                    ${networkBadge}
                    <span class="text-gray-400">on</span>
                    <span>${escapeHtml(currency.blockchain || currency.network || currency.ticker)}</span>
                </div>
            </div>
        `;
        div.onclick = (e) => selectCurrency(currency.code, e);
        container.appendChild(div);
    });
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (typeof text !== 'string') return String(text || '');
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Select payment currency
async function selectCurrency(currencyCode, event) {
    if (event) event.stopPropagation();
    selectedCurrency = currencyCode;

    // Update UI
    document.querySelectorAll('.currency-option').forEach(el => el.classList.remove('selected'));
    if (event && event.target) {
        const closest = event.target.closest('.currency-option');
        if (closest) closest.classList.add('selected');
    }

    // Get estimate
    try {
        const response = await fetch('{{ route('cashier-nowpayments.checkout.estimate') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                amount: CheckoutConfig.amount,
                from_currency: CheckoutConfig.currency,
                to_currency: currencyCode
            })
        });

        const data = await response.json();

        if (data.success) {
            document.getElementById('estimateDisplay').classList.remove('hidden');
            document.getElementById('estimateAmount').textContent = `${data.estimated_amount} ${currencyCode.toUpperCase()}`;
            document.getElementById('payButton').disabled = false;
        } else {
            showError(data.message);
        }
    } catch (error) {
        console.error('Failed to get estimate:', error);
    }
}

// Create payment
async function createPayment() {
    const payButton = document.getElementById('payButton');
    payButton.disabled = true;
    payButton.innerHTML = '<div class="spinner" style="width: 20px; height: 20px; border-width: 2px; display: inline-block; vertical-align: middle;"></div> Processing...';

    try {
        const response = await fetch('{{ route('cashier-nowpayments.checkout.payment') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({
                amount: CheckoutConfig.amount,
                currency: CheckoutConfig.currency,
                pay_currency: selectedCurrency,
                description: CheckoutConfig.description,
                order_id: CheckoutConfig.order_id,
                success_url: CheckoutConfig.success_url,
                cancel_url: CheckoutConfig.cancel_url
            })
        });

        const data = await response.json();

        if (data.success) {
            currentPayment = data;
            // Update timeout from server response if provided
            if (data.timeout_seconds) {
                timeRemaining = data.timeout_seconds;
            }
            showPaymentDetails(data);
            startStatusPolling();
            startTimer();
        } else {
            showError(data.message);
            payButton.disabled = false;
            payButton.innerHTML = '{{ __("Continue to Payment") }}';
        }
    } catch (error) {
        console.error('Failed to create payment:', error);
        showError('Failed to create payment. Please try again.');
        payButton.disabled = false;
        payButton.innerHTML = '{{ __("Continue to Payment") }}';
    }
}

// Show payment details
function showPaymentDetails(payment) {
    document.getElementById('checkoutForm').classList.add('hidden');
    document.getElementById('paymentDetails').classList.remove('hidden');

    document.getElementById('payAmountDisplay').textContent = `${payment.pay_amount} ${payment.pay_currency.toUpperCase()}`;
    document.getElementById('payAddress').textContent = payment.pay_address;

    // Render QR code using client-side library
    renderQRCode(payment.pay_address, payment.pay_amount);
}

// Render QR code using qrcode.js library
function renderQRCode(address, amount) {
    const container = document.getElementById('qrCodeContainer');
    container.innerHTML = '';

    const uri = `crypto:${address}?amount=${amount}`;

    qrCodeInstance = new QRCode(container, {
        text: uri,
        width: 200,
        height: 200,
        colorDark: '#000000',
        colorLight: '#ffffff',
        correctLevel: QRCode.CorrectLevel.M
    });
}

// Copy payment address
function copyAddress() {
    const address = document.getElementById('payAddress').textContent;
    navigator.clipboard.writeText(address).then(() => {
        // Show feedback
        const btn = event.target.closest('button');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
        setTimeout(() => {
            btn.innerHTML = originalHTML;
        }, 2000);
    });
}

// Start polling payment status
function startStatusPolling() {
    statusCheckInterval = setInterval(async () => {
        try {
            const response = await fetch(`{{ route('cashier-nowpayments.payment.status', '') }}/${currentPayment.purchase_id}`);
            const data = await response.json();

            if (data.success) {
                if (data.status === 'completed') {
                    paymentCompleted();
                } else if (data.status === 'failed') {
                    paymentFailed();
                }
            }
        } catch (error) {
            console.error('Status check failed:', error);
        }
    }, 5000); // Check every 5 seconds
}

// Start payment timer
function startTimer() {
    timerInterval = setInterval(() => {
        timeRemaining--;

        if (timeRemaining <= 0) {
            clearInterval(timerInterval);
            paymentFailed();
            return;
        }

        const minutes = Math.floor(timeRemaining / 60);
        const seconds = timeRemaining % 60;
        document.getElementById('paymentTimer').textContent =
            `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }, 1000);
}

// Payment completed
function paymentCompleted() {
    clearInterval(statusCheckInterval);
    clearInterval(timerInterval);

    document.getElementById('paymentDetails').classList.add('hidden');
    document.getElementById('paymentSuccess').classList.remove('hidden');

    // Redirect after delay
    setTimeout(() => {
        window.location.href = CheckoutConfig.success_url;
    }, 3000);
}

// Payment failed
function paymentFailed() {
    clearInterval(statusCheckInterval);
    clearInterval(timerInterval);

    document.getElementById('paymentDetails').classList.add('hidden');
    document.getElementById('paymentFailed').classList.remove('hidden');
}

// Retry payment
function retryPayment() {
    document.getElementById('paymentFailed').classList.add('hidden');
    document.getElementById('checkoutForm').classList.remove('hidden');
    document.getElementById('payButton').disabled = false;
    document.getElementById('payButton').innerHTML = '{{ __("Continue to Payment") }}';
    timeRemaining = CheckoutConfig.timeout_seconds ?? 900;
}

// Show error
function showError(message) {
    const errorDiv = document.getElementById('errorMessage');
    errorDiv.querySelector('p').textContent = message;
    errorDiv.classList.remove('hidden');

    setTimeout(() => {
        errorDiv.classList.add('hidden');
    }, 5000);
}

// Close checkout overlay
function closeCheckout() {
    window.location.href = CheckoutConfig.cancel_url;
}

// -------------------------------------------------------
// postMessage support for JS modal flow (CashierCheckout.open)
// -------------------------------------------------------
(function() {
    // Notify parent window when payment completes (for iframe/modal flow)
    var _origPaymentCompleted = paymentCompleted;
    paymentCompleted = function() {
        _origPaymentCompleted();
        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    type: 'cashier-checkout-complete',
                    payload: currentPayment
                }, '*');
            }
        } catch (e) { /* cross-origin — parent handles via polling */ }
    };

    // Notify parent window on cancel
    var _origClose = closeCheckout;
    closeCheckout = function() {
        try {
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    type: 'cashier-checkout-cancel'
                }, '*');
            }
        } catch (e) { /* fall through to redirect */ }
        _origClose();
    };
})();
</script>

</body>
</html>

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    {{--
     * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
     * @copyright 2026 Serenity Technologies
     * @license MIT License
     * @package serenity_technologies/cashier-nowpayments
     * @version 1.2.9
     --}}
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('Checkout') }} - {{ config('app.name', 'Laravel') }}</title>

    <style>
        /* Reset and base styles */
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            overflow: hidden;
        }

        /* Modal Overlay */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            z-index: 999999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeIn 0.3s ease-out;
            cursor: pointer;
        }

        @keyframes fadeIn {
            from { 
                opacity: 0;
                backdrop-filter: blur(0px);
                -webkit-backdrop-filter: blur(0px);
            }
            to { 
                opacity: 1;
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
            }
        }

        /* Modal Container */
        .modal-container {
            position: relative;
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4), 
                        0 0 0 1px rgba(255, 255, 255, 0.1);
            overflow: hidden;
            animation: modalSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            max-width: 460px;
            width: 100%;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            cursor: default;
        }

        @keyframes modalSlideUp {
            from {
                opacity: 0;
                transform: translateY(50px) scale(0.92);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        /* Modal Header */
        .modal-header {
            position: relative;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 22px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            flex-shrink: 0;
        }

        .modal-header-content {
            flex: 1;
            min-width: 0;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 700;
            letter-spacing: -0.025em;
            margin-bottom: 4px;
        }

        .modal-description {
            font-size: 13px;
            opacity: 0.9;
            font-weight: 500;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Close Button */
        .modal-close-btn {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 32px;
            height: 32px;
            border: none;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            z-index: 10;
            flex-shrink: 0;
        }

        .modal-close-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: scale(1.1) rotate(90deg);
        }

        .modal-close-btn:active {
            transform: scale(0.95);
        }

        .modal-close-btn svg {
            width: 18px;
            height: 18px;
            stroke-width: 2.5;
        }

        /* Modal Body */
        .modal-body {
            flex: 1;
            overflow: hidden;
            position: relative;
            background: #f8f9fa;
        }

        /* Loading State */
        .modal-loading {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            background: #f8f9fa;
            z-index: 5;
        }

        .modal-spinner {
            width: 44px;
            height: 44px;
            border: 3px solid rgba(102, 126, 234, 0.2);
            border-top-color: #667eea;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .modal-loading-text {
            color: #6b7280;
            font-size: 14px;
            font-weight: 500;
        }

        /* Iframe Container */
        .modal-iframe-wrapper {
            width: 100%;
            height: 100%;
            display: none;
            background: white;
        }

        .modal-iframe-wrapper iframe {
            display: block;
            width: 100%;
            height: 100%;
            min-height: 600px;
            border: none;
        }

        /* Error State */
        .modal-error {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 30px;
            gap: 16px;
            text-align: center;
            background: #f8f9fa;
        }

        .modal-error-icon {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 8px;
        }

        .modal-error-icon svg {
            width: 36px;
            height: 36px;
            color: #dc2626;
        }

        .modal-error-title {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
        }

        .modal-error-message {
            font-size: 14px;
            color: #6b7280;
            line-height: 1.6;
            max-width: 320px;
        }

        .modal-error-btn {
            margin-top: 8px;
            padding: 12px 28px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 6px -1px rgba(102, 126, 234, 0.3);
        }

        .modal-error-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 8px -1px rgba(102, 126, 234, 0.4);
        }

        .modal-error-btn:active {
            transform: translateY(0);
        }

        /* Modal Footer */
        .modal-footer {
            padding: 14px 22px;
            background: #f8f9fa;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            flex-shrink: 0;
        }

        .modal-footer p {
            font-size: 12px;
            color: #6b7280;
        }

        .modal-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.2s;
        }

        .modal-footer a:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        /* Mobile Responsive */
        @media (max-width: 640px) {
            .modal-overlay {
                padding: 0;
                align-items: flex-end;
            }

            .modal-container {
                max-width: 100%;
                border-radius: 20px 20px 0 0;
                max-height: 95vh;
                animation: modalSlideUpMobile 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            }

            @keyframes modalSlideUpMobile {
                from {
                    opacity: 0;
                    transform: translateY(100%);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }

            .modal-iframe-wrapper iframe {
                min-height: 500px;
            }
        }

        /* Dark mode support (optional) */
        @media (prefers-color-scheme: dark) {
            .modal-body,
            .modal-error {
                background: #1f2937;
            }
            
            .modal-loading-text {
                color: #9ca3af;
            }
            
            .modal-error-title {
                color: #f9fafb;
            }
            
            .modal-error-message {
                color: #9ca3af;
            }
            
            .modal-footer {
                background: #1f2937;
                border-top-color: #374151;
            }
            
            .modal-footer p {
                color: #9ca3af;
            }
        }
    </style>
</head>
<body>

<div class="modal-overlay" id="modalOverlay" onclick="onOverlayClick(event)">
    <div class="modal-container" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <!-- Header -->
        <div class="modal-header">
            <div class="modal-header-content">
                <div class="modal-title" id="modalTitle">{{ __('Cryptocurrency Payment') }}</div>
                @if($checkoutData['description'])
                    <div class="modal-description">{{ $checkoutData['description'] }}</div>
                @endif
            </div>
            <button class="modal-close-btn" onclick="closeCheckout(event)" aria-label="{{ __('Close') }}">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Body -->
        <div class="modal-body">
            <!-- Loading State -->
            <div class="modal-loading" id="loadingState">
                <div class="modal-spinner"></div>
                <div class="modal-loading-text">{{ __('Loading payment widget...') }}</div>
            </div>

            <!-- Iframe Wrapper -->
            <div class="modal-iframe-wrapper" id="iframeWrapper">
                <iframe
                    id="nowpaymentsIframe"
                    src="{{ $checkoutData['widget_url'] }}"
                    width="100%"
                    height="696"
                    frameborder="0"
                    scrolling="no"
                    style="overflow-y: hidden;"
                    onload="onIframeLoad()"
                    onerror="onIframeError()"
                >
                    {{ __("Can't load widget") }}
                </iframe>
            </div>

            <!-- Error State -->
            <div class="modal-error" id="errorState">
                <div class="modal-error-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="modal-error-title">{{ __('Unable to Load Payment Widget') }}</div>
                <div class="modal-error-message">
                    {{ __('The payment widget could not be loaded. Please check your internet connection and try again.') }}
                </div>
                <button class="modal-error-btn" onclick="retryLoad()">{{ __('Try Again') }}</button>
            </div>
        </div>

        <!-- Footer -->
        <div class="modal-footer">
            <p>
                {{ __('Secure payment powered by') }} <a href="https://nowpayments.io" target="_blank" rel="noopener noreferrer">NOWPayments</a>
            </p>
        </div>
    </div>
</div>

<script>
// Checkout configuration
const CheckoutConfig = @json($checkoutData);
let iframeLoaded = false;
let loadTimeout = null;

// Set timeout for iframe loading (10 seconds)
document.addEventListener('DOMContentLoaded', function() {
    loadTimeout = setTimeout(function() {
        if (!iframeLoaded) {
            showError();
        }
    }, 10000);
    
    // Prevent body scroll when modal is open
    document.body.style.overflow = 'hidden';
});

// Called when iframe finishes loading
function onIframeLoad() {
    iframeLoaded = true;
    clearTimeout(loadTimeout);

    // Hide loading, show iframe
    document.getElementById('loadingState').style.display = 'none';
    document.getElementById('iframeWrapper').style.display = 'block';

    // Notify parent window (for modal/embedded use)
    notifyParent('cashier-checkout-loaded');
}

// Called when iframe fails to load
function onIframeError() {
    clearTimeout(loadTimeout);
    showError();
}

// Show error state
function showError() {
    document.getElementById('loadingState').style.display = 'none';
    document.getElementById('iframeWrapper').style.display = 'none';
    document.getElementById('errorState').style.display = 'flex';
}

// Retry loading the iframe
function retryLoad() {
    document.getElementById('errorState').style.display = 'none';
    document.getElementById('loadingState').style.display = 'flex';

    // Reload iframe
    const iframe = document.getElementById('nowpaymentsIframe');
    iframe.src = iframe.src;

    // Reset timeout
    loadTimeout = setTimeout(function() {
        if (!iframeLoaded) {
            showError();
        }
    }, 10000);
}

// Close checkout and redirect
function closeCheckout(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    notifyParent('cashier-checkout-cancel');
    
    // Restore body scroll
    document.body.style.overflow = '';
    
    // Redirect with smooth transition
    setTimeout(() => {
        window.location.href = CheckoutConfig.cancel_url || '/';
    }, 150);
}

// Handle overlay click (close on background click)
function onOverlayClick(event) {
    if (event.target === event.currentTarget) {
        closeCheckout(event);
    }
}

// Notify parent window (for postMessage integration)
function notifyParent(eventType, payload = {}) {
    try {
        if (window.parent && window.parent !== window) {
            window.parent.postMessage({
                type: eventType,
                payload: payload
            }, '*');
        }
    } catch (e) {
        // Cross-origin restriction - parent handles via polling
    }
}

// Listen for messages from NOWPayments iframe
window.addEventListener('message', function(event) {
    try {
        const data = event.data;

        // Payment completed
        if (data && data.type === 'iframe_loaded') {
            onIframeLoad();
        }

        // You can add more event handlers based on NOWPayments widget events
    } catch (e) {
        // Ignore cross-origin errors
    }
});

// Close modal on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        e.preventDefault();
        closeCheckout();
    }
});

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    document.body.style.overflow = '';
});
</script>

</body>
</html>

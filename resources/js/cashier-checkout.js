/**
 * Cashier NOWPayments Checkout
 *
 * This module provides a simple API to open the checkout overlay
 * from anywhere in your application.
 *
 * Usage:
 * import { CashierCheckout } from './cashier-checkout';
 *
 * // Custom checkout overlay
 * CashierCheckout.open({
 *     amount: 100.00,
 *     currency: 'usd',
 *     description: 'Premium Plan',
 *     success_url: 'https://yoursite.com/success',
 *     cancel_url: 'https://yoursite.com/cancel'
 * });
 *
 * // Embedded payment widget (recommended - zero UI maintenance)
 * CashierCheckout.openEmbedded({
 *     amount: 100.00,
 *     currency: 'usd',
 *     description: 'Premium Plan',
 *     success_url: 'https://yoursite.com/success',
 *     cancel_url: 'https://yoursite.com/cancel'
 * });
 */

class CashierCheckout {
    /**
     * Open the custom checkout overlay.
     *
     * @param {Object} options
     * @param {number} options.amount - Payment amount
     * @param {string} options.currency - Currency code (e.g., 'usd')
     * @param {string} [options.description] - Payment description
     * @param {string} [options.order_id] - Order ID
     * @param {string} [options.success_url] - Success redirect URL
     * @param {string} [options.cancel_url] - Cancel redirect URL
     * @param {string} [options.pay_currency] - Preferred payment currency
     * @param {string} [options.type] - Checkout type: 'payment', 'invoice', or 'subscription'
     * @returns {Promise<Object>} Payment result
     */
    static async open(options) {
        const params = new URLSearchParams({
            amount: options.amount,
            currency: options.currency,
        });

        if (options.description) params.set('description', options.description);
        if (options.order_id) params.set('order_id', options.order_id);
        if (options.success_url) params.set('success_url', options.success_url);
        if (options.cancel_url) params.set('cancel_url', options.cancel_url);
        if (options.pay_currency) params.set('pay_currency', options.pay_currency);
        if (options.type) params.set('type', options.type);

        // Open in modal iframe
        return new Promise((resolve, reject) => {
            const modal = this.createModal();
            const iframe = modal.querySelector('iframe');

            iframe.src = `/cashier-nowpayments/checkout?${params.toString()}`;

            // Listen for messages from iframe
            const handler = (event) => {
                if (event.data.type === 'cashier-checkout-complete') {
                    window.removeEventListener('message', handler);
                    this.closeModal(modal);
                    resolve(event.data.payload);
                } else if (event.data.type === 'cashier-checkout-cancel') {
                    window.removeEventListener('message', handler);
                    this.closeModal(modal);
                    reject(new Error('Checkout cancelled'));
                }
            };

            window.addEventListener('message', handler);
        });
    }

    /**
     * Open the embedded payment widget modal (recommended).
     *
     * Uses NOWPayments' official payment widget for zero-maintenance UI.
     * Widget handles: currency selection, QR codes, exchange rates, payment tracking.
     *
     * @param {Object} options
     * @param {number} options.amount - Payment amount
     * @param {string} options.currency - Currency code (e.g., 'usd')
     * @param {string} [options.description] - Payment description
     * @param {string} [options.order_id] - Order ID
     * @param {string} [options.success_url] - Success redirect URL
     * @param {string} [options.cancel_url] - Cancel redirect URL
     * @param {Object} [options.metadata] - Additional metadata
     * @returns {Promise<Object>} Payment result
     */
    static async openEmbedded(options) {
        const params = new URLSearchParams({
            amount: options.amount,
            currency: options.currency,
        });

        if (options.description) params.set('description', options.description);
        if (options.order_id) params.set('order_id', options.order_id);
        if (options.success_url) params.set('success_url', options.success_url);
        if (options.cancel_url) params.set('cancel_url', options.cancel_url);
        if (options.metadata) params.set('metadata', JSON.stringify(options.metadata));

        // Open embedded widget modal
        return new Promise((resolve, reject) => {
            const modal = this.createEmbeddedModal();
            const iframe = modal.querySelector('iframe');

            iframe.src = `/cashier-nowpayments/checkout/embedded?${params.toString()}`;

            // Listen for messages from iframe
            const handler = (event) => {
                if (event.data.type === 'cashier-checkout-loaded') {
                    // Widget loaded successfully
                }
                if (event.data.type === 'cashier-checkout-complete') {
                    window.removeEventListener('message', handler);
                    this.closeModal(modal);
                    resolve(event.data.payload);
                } else if (event.data.type === 'cashier-checkout-cancel') {
                    window.removeEventListener('message', handler);
                    this.closeModal(modal);
                    reject(new Error('Checkout cancelled'));
                }
            };

            window.addEventListener('message', handler);
        });
    }

    /**
     * Create a payment directly via API.
     *
     * @param {Object} options
     * @returns {Promise<Object>}
     */
    static async createPayment(options) {
        const response = await fetch('/cashier-nowpayments/checkout/payment', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.getCSRFToken()
            },
            body: JSON.stringify(options)
        });

        return response.json();
    }

    /**
     * Create an invoice directly via API.
     *
     * @param {Object} options
     * @returns {Promise<Object>}
     */
    static async createInvoice(options) {
        const response = await fetch('/cashier-nowpayments/checkout/invoice', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.getCSRFToken()
            },
            body: JSON.stringify(options)
        });

        return response.json();
    }

    /**
     * Create a subscription directly via API (requires authentication).
     *
     * @param {Object} options
     * @param {number} options.plan_id - NOWPayments plan ID
     * @param {string} [options.success_url] - Success redirect URL
     * @param {string} [options.cancel_url] - Cancel redirect URL
     * @returns {Promise<Object>}
     */
    static async createSubscription(options) {
        const response = await fetch('/cashier-nowpayments/checkout/subscription', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.getCSRFToken()
            },
            body: JSON.stringify(options)
        });

        return response.json();
    }

    /**
     * Get payment estimate.
     *
     * @param {number} amount
     * @param {string} fromCurrency
     * @param {string} toCurrency
     * @returns {Promise<Object>}
     */
    static async getEstimate(amount, fromCurrency, toCurrency) {
        const response = await fetch('/cashier-nowpayments/checkout/estimate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.getCSRFToken()
            },
            body: JSON.stringify({
                amount,
                from_currency: fromCurrency,
                to_currency: toCurrency
            })
        });

        return response.json();
    }

    /**
     * Get supported currencies with full details.
     *
     * Returns array of currency objects with:
     * - code, name, ticker, network, blockchain
     * - logo (URL from NOWPayments API)
     * - is_popular, is_fiat, precision
     *
     * @returns {Promise<Object>}
     */
    static async getCurrencies() {
        const response = await fetch('/cashier-nowpayments/checkout/currencies');
        return response.json();
    }

    /**
     * Check payment status.
     *
     * @param {string} purchaseId
     * @returns {Promise<Object>}
     */
    static async checkStatus(purchaseId) {
        const response = await fetch(`/cashier-nowpayments/payment/status/${purchaseId}`);
        return response.json();
    }

    /**
     * Create custom checkout modal element.
     * Uses the custom checkout overlay with currency selector.
     */
    static createModal() {
        const modal = document.createElement('div');
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(4px);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        `;

        const container = document.createElement('div');
        container.style.cssText = `
            background: white;
            border-radius: 16px;
            max-width: 600px;
            width: 100%;
            height: 80vh;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            position: relative;
        `;

        const iframe = document.createElement('iframe');
        iframe.style.cssText = `
            width: 100%;
            height: 100%;
            border: none;
        `;

        const closeBtn = document.createElement('button');
        closeBtn.innerHTML = '×';
        closeBtn.style.cssText = `
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0, 0, 0, 0.5);
            color: white;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            font-size: 24px;
            cursor: pointer;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
        `;
        closeBtn.onclick = () => {
            window.postMessage({ type: 'cashier-checkout-cancel' }, '*');
        };

        container.appendChild(iframe);
        container.appendChild(closeBtn);
        modal.appendChild(container);
        document.body.appendChild(modal);

        // Close on background click
        modal.onclick = (e) => {
            if (e.target === modal) {
                window.postMessage({ type: 'cashier-checkout-cancel' }, '*');
            }
        };

        return modal;
    }

    /**
     * Create embedded payment widget modal element.
     * Uses NOWPayments' official payment widget for zero-maintenance UI.
     */
    static createEmbeddedModal() {
        const modal = document.createElement('div');
        modal.style.cssText = `
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
        `;

        const container = document.createElement('div');
        container.style.cssText = `
            background: white;
            border-radius: 20px;
            max-width: 460px;
            width: 100%;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.4);
            position: relative;
            cursor: default;
            animation: modalSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        `;

        // Add animation keyframes
        if (!document.getElementById('cashier-checkout-styles')) {
            const style = document.createElement('style');
            style.id = 'cashier-checkout-styles';
            style.textContent = `
                @keyframes fadeIn {
                    from { opacity: 0; backdrop-filter: blur(0px); }
                    to { opacity: 1; backdrop-filter: blur(12px); }
                }
                @keyframes modalSlideUp {
                    from { opacity: 0; transform: translateY(50px) scale(0.92); }
                    to { opacity: 1; transform: translateY(0) scale(1); }
                }
            `;
            document.head.appendChild(style);
        }

        const iframe = document.createElement('iframe');
        iframe.style.cssText = `
            width: 100%;
            height: 100%;
            min-height: 600px;
            border: none;
        `;

        const closeBtn = document.createElement('button');
        closeBtn.innerHTML = '×';
        closeBtn.style.cssText = `
            position: absolute;
            top: 14px;
            right: 14px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            font-size: 24px;
            cursor: pointer;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        `;
        closeBtn.onmouseenter = () => {
            closeBtn.style.background = 'rgba(255, 255, 255, 0.3)';
            closeBtn.style.transform = 'scale(1.1) rotate(90deg)';
        };
        closeBtn.onmouseleave = () => {
            closeBtn.style.background = 'rgba(255, 255, 255, 0.2)';
            closeBtn.style.transform = 'scale(1) rotate(0deg)';
        };
        closeBtn.onclick = () => {
            window.postMessage({ type: 'cashier-checkout-cancel' }, '*');
        };

        container.appendChild(iframe);
        container.appendChild(closeBtn);
        modal.appendChild(container);
        document.body.appendChild(modal);

        // Close on background click
        modal.onclick = (e) => {
            if (e.target === modal) {
                window.postMessage({ type: 'cashier-checkout-cancel' }, '*');
            }
        };

        return modal;
    }

    /**
     * Close modal.
     */
    static closeModal(modal) {
        if (modal && modal.parentNode) {
            modal.parentNode.removeChild(modal);
        }
    }

    /**
     * Get CSRF token from meta tag.
     */
    static getCSRFToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }
}

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CashierCheckout;
}

// Make available globally for script tag usage
if (typeof window !== 'undefined') {
    window.CashierCheckout = CashierCheckout;
}

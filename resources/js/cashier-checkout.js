/**
 * Cashier NOWPayments Checkout
 *
 * This module provides a simple API to open the checkout overlay
 * from anywhere in your application.
 *
 * Usage:
 * import { openCheckout } from './cashier-checkout';
 *
 * openCheckout({
 *     amount: 100.00,
 *     currency: 'usd',
 *     description: 'Premium Plan',
 *     success_url: 'https://yoursite.com/success',
 *     cancel_url: 'https://yoursite.com/cancel'
 * });
 */

class CashierCheckout {
    /**
     * Open the checkout overlay.
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
     * Get supported currencies.
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
     * Create modal element.
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

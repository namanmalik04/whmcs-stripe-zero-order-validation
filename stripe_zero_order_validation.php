<?php
/**
 * WHMCS Stripe SetupIntent Zero-Order Card Validation
 *
 * Validates customer credit cards on $0 orders using Stripe's SetupIntent API.
 * This allows collection and validation of payment methods even when no immediate
 * charge is required, enabling future usage-based billing.
 *
 * @author DataHorders LLC
 * @version 1.0.0
 * @license MIT
 * @link https://github.com/datahorders/whmcs-stripe-zero-order-validation
 *
 * Requirements:
 * - WHMCS 8.x with Stripe payment gateway configured
 * - Stripe PHP SDK (included with WHMCS)
 *
 * Installation:
 * 1. Place this file in /includes/hooks/
 * 2. Clear WHMCS template cache
 * 3. Test with a $0 product order
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Get decrypted Stripe gateway settings
 *
 * @return array Gateway parameters including publishableKey and secretKey
 */
function stripe_zero_order_getStripeParams(): array
{
    static $params = null;

    if ($params === null) {
        require_once __DIR__ . '/../../includes/gatewayfunctions.php';
        $params = getGatewayVariables('stripe');
    }

    return $params;
}

/**
 * Check if the current cart total is zero
 *
 * @return bool True if cart total is $0
 */
function stripe_zero_order_isZeroTotal(): bool
{
    if (!isset($_SESSION['cart']) || empty($_SESSION['cart']['products'])) {
        return false;
    }

    // Calculate cart total
    $total = 0;

    // Get pricing for products in cart
    foreach ($_SESSION['cart']['products'] as $product) {
        $pid = $product['pid'] ?? 0;
        $billingCycle = $product['billingcycle'] ?? 'monthly';

        if ($pid) {
            $pricing = Capsule::table('tblpricing')
                ->where('type', 'product')
                ->where('relid', $pid)
                ->where('currency', $_SESSION['currency'] ?? 1)
                ->first();

            if ($pricing) {
                switch (strtolower($billingCycle)) {
                    case 'monthly':
                        $total += (float) $pricing->monthly;
                        break;
                    case 'quarterly':
                        $total += (float) $pricing->quarterly;
                        break;
                    case 'semiannually':
                        $total += (float) $pricing->semiannually;
                        break;
                    case 'annually':
                        $total += (float) $pricing->annually;
                        break;
                    case 'biennially':
                        $total += (float) $pricing->biennially;
                        break;
                    case 'triennially':
                        $total += (float) $pricing->triennially;
                        break;
                    case 'free':
                        // Zero cost
                        break;
                    default:
                        $total += (float) $pricing->monthly;
                }
            }
        }
    }

    // Consider setup fees
    foreach ($_SESSION['cart']['products'] as $product) {
        $pid = $product['pid'] ?? 0;
        if ($pid) {
            $productData = Capsule::table('tblproducts')->where('id', $pid)->first();
            if ($productData) {
                // Check for setup fee in pricing
                $pricing = Capsule::table('tblpricing')
                    ->where('type', 'product')
                    ->where('relid', $pid)
                    ->where('currency', $_SESSION['currency'] ?? 1)
                    ->first();

                if ($pricing && $pricing->msetupfee > 0) {
                    $total += (float) $pricing->msetupfee;
                }
            }
        }
    }

    return $total <= 0;
}

/**
 * Create or get Stripe customer ID for a WHMCS client
 *
 * @param int $clientId WHMCS client ID
 * @return string|null Stripe customer ID or null on failure
 */
function stripe_zero_order_getOrCreateStripeCustomer(int $clientId): ?string
{
    $params = stripe_zero_order_getStripeParams();

    if (empty($params['secretKey'])) {
        return null;
    }

    // Check if client already has a Stripe customer ID stored
    $client = Capsule::table('tblclients')->where('id', $clientId)->first();

    if (!$client) {
        return null;
    }

    // Try to find existing Stripe customer from payment methods
    $existingPayMethod = Capsule::table('tblpaymethods')
        ->where('userid', $clientId)
        ->where('gateway_name', 'stripe')
        ->whereNull('deleted_at')
        ->first();

    // Initialize Stripe
    \Stripe\Stripe::setApiKey($params['secretKey']);

    // If we have an existing payment method, try to get its customer
    if ($existingPayMethod) {
        try {
            // Get the credit card data which contains the payment_method ID
            $ccData = Capsule::table('tblcreditcards')
                ->where('id', $existingPayMethod->payment_id)
                ->whereNull('deleted_at')
                ->first();

            if ($ccData && !empty($ccData->card_data)) {
                // Decrypt the card data to get payment_method ID
                // WHMCS stores encrypted data, but for Stripe it's the payment_method ID
                // We need to use WHMCS decrypt function
                require_once __DIR__ . '/../../includes/functions.php';
                $paymentMethodId = decrypt($ccData->card_data);

                if (strpos($paymentMethodId, 'pm_') === 0) {
                    $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
                    if ($paymentMethod && $paymentMethod->customer) {
                        return $paymentMethod->customer;
                    }
                }
            }
        } catch (\Exception $e) {
            // Fall through to create new customer
            logActivity("Stripe Zero Order: Could not retrieve existing customer - " . $e->getMessage());
        }
    }

    // Create new Stripe customer
    try {
        $customer = \Stripe\Customer::create([
            'email' => $client->email,
            'name' => trim($client->firstname . ' ' . $client->lastname),
            'metadata' => [
                'whmcs_client_id' => $clientId,
            ],
        ]);

        return $customer->id;

    } catch (\Exception $e) {
        logActivity("Stripe Zero Order: Failed to create customer - " . $e->getMessage());
        return null;
    }
}

/**
 * Create a SetupIntent for card validation
 *
 * @param string $customerId Stripe customer ID
 * @return array SetupIntent data or error
 */
function stripe_zero_order_createSetupIntent(string $customerId): array
{
    $params = stripe_zero_order_getStripeParams();

    if (empty($params['secretKey'])) {
        return ['error' => 'Stripe not configured'];
    }

    \Stripe\Stripe::setApiKey($params['secretKey']);

    try {
        $setupIntent = \Stripe\SetupIntent::create([
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'usage' => 'off_session', // Allow future off-session payments
            'metadata' => [
                'source' => 'whmcs_zero_order_validation',
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        return [
            'success' => true,
            'client_secret' => $setupIntent->client_secret,
            'setup_intent_id' => $setupIntent->id,
            'publishable_key' => $params['publishableKey'],
        ];

    } catch (\Exception $e) {
        logActivity("Stripe Zero Order: Failed to create SetupIntent - " . $e->getMessage());
        return ['error' => $e->getMessage()];
    }
}

/**
 * Save a payment method to WHMCS after successful SetupIntent confirmation
 *
 * @param int $clientId WHMCS client ID
 * @param string $paymentMethodId Stripe payment method ID
 * @param string $customerId Stripe customer ID
 * @return bool Success status
 */
function stripe_zero_order_savePaymentMethod(int $clientId, string $paymentMethodId, string $customerId): bool
{
    $params = stripe_zero_order_getStripeParams();

    if (empty($params['secretKey'])) {
        return false;
    }

    \Stripe\Stripe::setApiKey($params['secretKey']);

    try {
        // Retrieve payment method details from Stripe
        $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);

        if (!$paymentMethod || !$paymentMethod->card) {
            logActivity("Stripe Zero Order: Invalid payment method - " . $paymentMethodId);
            return false;
        }

        $card = $paymentMethod->card;

        // Encrypt the payment method ID for storage
        require_once __DIR__ . '/../../includes/functions.php';
        $encryptedData = encrypt($paymentMethodId);

        // Get card brand for display
        $cardType = ucfirst($card->brand);
        $lastFour = $card->last4;
        $expiryMonth = str_pad($card->exp_month, 2, '0', STR_PAD_LEFT);
        $expiryYear = $card->exp_year;
        $expiryDate = $expiryYear . '-' . $expiryMonth . '-01 00:00:00';

        // Start transaction
        Capsule::beginTransaction();

        try {
            // Insert into tblcreditcards
            $creditCardId = Capsule::table('tblcreditcards')->insertGetId([
                'pay_method_id' => 0, // Will update after creating paymethod
                'card_type' => $cardType,
                'last_four' => $lastFour,
                'expiry_date' => $expiryDate,
                'card_data' => $encryptedData,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Insert into tblpaymethods
            $payMethodId = Capsule::table('tblpaymethods')->insertGetId([
                'userid' => $clientId,
                'description' => $cardType . ' ending in ' . $lastFour,
                'contact_id' => $clientId,
                'contact_type' => 'Client',
                'payment_id' => $creditCardId,
                'payment_type' => 'RemoteCreditCard',
                'gateway_name' => 'stripe',
                'order_preference' => 0,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            // Update credit card with pay_method_id
            Capsule::table('tblcreditcards')
                ->where('id', $creditCardId)
                ->update(['pay_method_id' => $payMethodId]);

            Capsule::commit();

            logActivity("Stripe Zero Order: Saved payment method for client #" . $clientId . " - " . $cardType . " ending in " . $lastFour);

            return true;

        } catch (\Exception $e) {
            Capsule::rollBack();
            throw $e;
        }

    } catch (\Exception $e) {
        logActivity("Stripe Zero Order: Failed to save payment method - " . $e->getMessage());
        return false;
    }
}

/**
 * Hook: ShoppingCartCheckoutOutput
 *
 * Injects the Stripe SetupIntent form for zero-total orders.
 * This hook fires when rendering the checkout page.
 */
add_hook('ShoppingCartCheckoutOutput', 1, function ($vars) {
    // Only process if cart has products
    if (!isset($_SESSION['cart']) || empty($_SESSION['cart']['products'])) {
        return '';
    }

    // Check the cart total from $vars first (more reliable)
    $isZeroTotal = false;

    // WHMCS passes the total in $vars
    if (isset($vars['total'])) {
        // $vars['total'] is a WHMCS\View\Formatter\Price object
        $totalValue = $vars['total'];
        if (is_object($totalValue) && method_exists($totalValue, 'toNumeric')) {
            $isZeroTotal = ($totalValue->toNumeric() <= 0);
        } elseif (is_string($totalValue)) {
            $numericTotal = (float) preg_replace('/[^0-9.-]/', '', $totalValue);
            $isZeroTotal = ($numericTotal <= 0);
        }
    }

    // Fallback to our calculation if $vars['total'] not available
    if (!$isZeroTotal && !isset($vars['total'])) {
        $isZeroTotal = stripe_zero_order_isZeroTotal();
    }

    // Only process for zero-total orders
    if (!$isZeroTotal) {
        return '';
    }

    // Log for debugging
    logActivity("Stripe Zero Order: Detected zero-total order, injecting card validation form");

    // Get Stripe params
    $params = stripe_zero_order_getStripeParams();

    if (empty($params['publishableKey'])) {
        logActivity("Stripe Zero Order: Stripe publishable key not configured");
        return '';
    }

    // Get or determine client ID (may be logged in or new registration)
    $clientId = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;

    // For logged-in users, pre-create the setup intent
    $setupIntentData = [];
    $stripeCustomerId = '';

    if ($clientId > 0) {
        $stripeCustomerId = stripe_zero_order_getOrCreateStripeCustomer($clientId);
        if ($stripeCustomerId) {
            $setupIntentData = stripe_zero_order_createSetupIntent($stripeCustomerId);
        }
    }

    // Generate JavaScript and HTML for zero-order card validation
    $publishableKey = htmlspecialchars($params['publishableKey'], ENT_QUOTES, 'UTF-8');
    $clientSecret = !empty($setupIntentData['client_secret'])
        ? htmlspecialchars($setupIntentData['client_secret'], ENT_QUOTES, 'UTF-8')
        : '';
    $stripeCustomerIdSafe = htmlspecialchars($stripeCustomerId, ENT_QUOTES, 'UTF-8');

    $output = <<<HTML
<!-- Stripe Zero Order Card Validation -->
<style>
#stripe-zero-order-container {
    margin: 20px 0;
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 8px;
    background: #f9f9f9;
}
#stripe-zero-order-container .sub-heading {
    margin-bottom: 15px;
}
#stripe-zero-order-container .sub-heading span {
    background: #4a90d9;
    color: white;
    padding: 8px 15px;
    border-radius: 4px;
    font-weight: 600;
}
#stripe-card-element {
    padding: 12px;
    border: 1px solid #ccc;
    border-radius: 4px;
    background: white;
    min-height: 40px;
}
#stripe-card-errors {
    color: #dc3545;
    margin-top: 10px;
    font-size: 14px;
}
.stripe-zero-order-info {
    margin-bottom: 15px;
    padding: 10px;
    background: #e7f3ff;
    border-radius: 4px;
    font-size: 14px;
    color: #0c5460;
}
.stripe-zero-order-info i {
    margin-right: 8px;
}
#stripe-zero-order-success {
    display: none;
    padding: 15px;
    background: #d4edda;
    border: 1px solid #c3e6cb;
    border-radius: 4px;
    color: #155724;
    margin-top: 15px;
}
#stripe-zero-order-success i {
    margin-right: 8px;
}
</style>

<script>
// Wait for Stripe.js to be available (WHMCS may load it)
(function() {
    'use strict';

    function waitForStripe(callback, maxAttempts) {
        var attempts = 0;
        var checkStripe = function() {
            attempts++;
            if (typeof Stripe !== 'undefined') {
                callback();
            } else if (attempts < maxAttempts) {
                setTimeout(checkStripe, 100);
            } else {
                // Load Stripe.js ourselves if not loaded
                var script = document.createElement('script');
                script.src = 'https://js.stripe.com/v3/';
                script.onload = callback;
                document.head.appendChild(script);
            }
        };
        checkStripe();
    }

    var stripeZeroOrder = {
        stripe: null,
        elements: null,
        cardElement: null,
        clientSecret: '{$clientSecret}',
        stripeCustomerId: '{$stripeCustomerIdSafe}',
        publishableKey: '{$publishableKey}',
        isProcessing: false,
        cardValidated: false,

        init: function() {
            var self = this;

            // Wait for DOM to be ready
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() { self.setup(); });
            } else {
                this.setup();
            }
        },

        setup: function() {
            var self = this;

            // Check if this is a zero-order scenario
            var totalElement = document.getElementById('totalCartPrice');
            if (totalElement) {
                var totalText = totalElement.textContent || totalElement.innerText;
                // Check if total is $0.00 or equivalent
                var total = parseFloat(totalText.replace(/[^0-9.-]/g, ''));
                if (total > 0) {
                    // Not a zero order, don't show card validation
                    return;
                }
            }

            // Find the payment details section
            var paymentSection = document.querySelector('.sub-heading span.primary-bg-color');
            var paymentContainer = null;

            // Find the payment section by looking for "Payment Details" heading
            var headings = document.querySelectorAll('.sub-heading span.primary-bg-color');
            for (var i = 0; i < headings.length; i++) {
                if (headings[i].textContent.indexOf('Payment') !== -1) {
                    paymentContainer = headings[i].closest('.sub-heading');
                    break;
                }
            }

            if (!paymentContainer) {
                console.log('Stripe Zero Order: Payment section not found');
                return;
            }

            // Create and insert our container after the payment heading
            var container = document.createElement('div');
            container.id = 'stripe-zero-order-container';
            container.innerHTML = this.getFormHTML();

            // Insert after the payment gateway container
            var gatewayContainer = document.getElementById('paymentGatewaysContainer');
            if (gatewayContainer) {
                gatewayContainer.parentNode.insertBefore(container, gatewayContainer.nextSibling);
                // Hide the regular payment gateway selection for zero-total orders
                gatewayContainer.style.display = 'none';
            } else {
                paymentContainer.parentNode.insertBefore(container, paymentContainer.nextSibling);
            }

            // Hide the credit card input fields (we use our own Stripe Elements)
            var ccFields = document.getElementById('creditCardInputFields');
            if (ccFields) {
                ccFields.style.display = 'none';
            }

            // For zero-order, do NOT select any payment method
            // This prevents WHMCS from trying to call stripe_storeremote
            var paymentRadios = document.querySelectorAll('input[name="paymentmethod"]');
            for (var i = 0; i < paymentRadios.length; i++) {
                paymentRadios[i].checked = false;
            }

            // Initialize Stripe
            this.initStripe();

            // Intercept form submission
            this.interceptFormSubmission();
        },

        getFormHTML: function() {
            return '<div class="sub-heading"><span class="primary-bg-color">Card Verification Required</span></div>' +
                '<div class="stripe-zero-order-info">' +
                '<i class="fas fa-info-circle"></i> ' +
                'This service has no upfront charge, but we need to verify your payment method for potential future billing.' +
                '</div>' +
                '<div id="stripe-card-element"></div>' +
                '<div id="stripe-card-errors" role="alert"></div>' +
                '<div id="stripe-zero-order-success">' +
                '<i class="fas fa-check-circle"></i> Card verified successfully!' +
                '</div>';
        },

        initStripe: function() {
            var self = this;

            // Initialize Stripe.js
            this.stripe = Stripe(this.publishableKey);
            this.elements = this.stripe.elements();

            // Create card element
            var style = {
                base: {
                    color: '#32325d',
                    fontFamily: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
                    fontSmoothing: 'antialiased',
                    fontSize: '16px',
                    '::placeholder': {
                        color: '#aab7c4'
                    }
                },
                invalid: {
                    color: '#dc3545',
                    iconColor: '#dc3545'
                }
            };

            this.cardElement = this.elements.create('card', { style: style });
            this.cardElement.mount('#stripe-card-element');

            // Handle real-time validation errors
            this.cardElement.on('change', function(event) {
                var displayError = document.getElementById('stripe-card-errors');
                if (event.error) {
                    displayError.textContent = event.error.message;
                } else {
                    displayError.textContent = '';
                }
            });
        },

        interceptFormSubmission: function() {
            var self = this;
            var form = document.getElementById('frmCheckout');

            if (!form) {
                console.log('Stripe Zero Order: Checkout form not found');
                return;
            }

            form.addEventListener('submit', function(event) {
                // If card is already validated, allow form to submit
                if (self.cardValidated) {
                    return true;
                }

                // If already processing, prevent duplicate submissions
                if (self.isProcessing) {
                    event.preventDefault();
                    return false;
                }

                event.preventDefault();
                self.isProcessing = true;

                // Show processing state
                var submitBtn = form.querySelector('button[type="submit"]');
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying card...';
                }

                // Create SetupIntent and confirm card
                self.handleCardValidation(form, submitBtn);

                return false;
            });
        },

        handleCardValidation: function(form, submitBtn) {
            var self = this;

            // If we don't have a client secret yet (new user), we need to create one via AJAX
            if (!this.clientSecret) {
                this.createSetupIntent(function(data) {
                    if (data.error) {
                        self.showError(data.error);
                        self.resetButton(submitBtn);
                        return;
                    }

                    self.clientSecret = data.client_secret;
                    self.stripeCustomerId = data.stripe_customer_id || '';
                    self.confirmCardSetup(form, submitBtn);
                });
            } else {
                this.confirmCardSetup(form, submitBtn);
            }
        },

        createSetupIntent: function(callback) {
            var self = this;
            var form = document.getElementById('frmCheckout');
            var formData = new FormData(form);
            formData.append('action', 'create_setup_intent');

            fetch(window.location.pathname + '?a=checkout', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(response) { return response.json(); })
            .then(callback)
            .catch(function(error) {
                callback({ error: 'Failed to initialize card validation. Please try again.' });
            });
        },

        confirmCardSetup: function(form, submitBtn) {
            var self = this;

            // Get billing details from form
            var billingDetails = {
                name: (form.querySelector('[name="firstname"]')?.value || '') + ' ' +
                      (form.querySelector('[name="lastname"]')?.value || ''),
                email: form.querySelector('[name="email"]')?.value || '',
                address: {
                    line1: form.querySelector('[name="address1"]')?.value || '',
                    line2: form.querySelector('[name="address2"]')?.value || '',
                    city: form.querySelector('[name="city"]')?.value || '',
                    state: form.querySelector('[name="state"]')?.value || '',
                    postal_code: form.querySelector('[name="postcode"]')?.value || '',
                    country: form.querySelector('[name="country"]')?.value || ''
                }
            };

            this.stripe.confirmCardSetup(this.clientSecret, {
                payment_method: {
                    card: this.cardElement,
                    billing_details: billingDetails
                }
            }).then(function(result) {
                if (result.error) {
                    self.showError(result.error.message);
                    self.resetButton(submitBtn);
                } else {
                    // Card validated successfully
                    self.cardValidated = true;

                    // Add payment method ID to form (our custom field)
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'stripe_payment_method_id';
                    input.value = result.setupIntent.payment_method;
                    form.appendChild(input);

                    // Add stripe customer ID to form
                    var customerInput = document.createElement('input');
                    customerInput.type = 'hidden';
                    customerInput.name = 'stripe_customer_id';
                    customerInput.value = self.stripeCustomerId;
                    form.appendChild(customerInput);

                    // CRITICAL: Also add as remoteStorageToken for WHMCS Stripe module
                    var tokenInput = document.createElement('input');
                    tokenInput.type = 'hidden';
                    tokenInput.name = 'remoteStorageToken';
                    tokenInput.value = result.setupIntent.payment_method;
                    form.appendChild(tokenInput);

                    // Set flag to skip card processing since we already saved it
                    var skipInput = document.createElement('input');
                    skipInput.type = 'hidden';
                    skipInput.name = 'stripe_zero_order_validated';
                    skipInput.value = '1';
                    form.appendChild(skipInput);

                    // Show success message
                    document.getElementById('stripe-zero-order-success').style.display = 'block';
                    document.getElementById('stripe-card-element').style.display = 'none';

                    // Reset button and submit form
                    self.resetButton(submitBtn, 'Complete Order');
                    submitBtn.disabled = false;

                    // Auto-submit the form
                    form.submit();
                }
            });
        },

        showError: function(message) {
            var errorDiv = document.getElementById('stripe-card-errors');
            if (errorDiv) {
                errorDiv.textContent = message;
            }
            this.isProcessing = false;
        },

        resetButton: function(button, text) {
            if (button) {
                button.disabled = false;
                button.innerHTML = (text || 'Complete Order') + ' <i class="fas fa-arrow-circle-right"></i>';
            }
            this.isProcessing = false;
        }
    };

    // Initialize with Stripe.js wait
    waitForStripe(function() {
        stripeZeroOrder.init();
    }, 50);
})();
</script>
<!-- End Stripe Zero Order Card Validation -->
HTML;

    return $output;
});

/**
 * Hook: ShoppingCartCheckoutCompletePage
 *
 * After successful checkout, save the payment method if it was validated
 */
add_hook('AcceptOrder', 1, function ($vars) {
    // Check if we have a validated payment method from zero-order flow
    if (empty($_POST['stripe_payment_method_id'])) {
        return;
    }

    $paymentMethodId = $_POST['stripe_payment_method_id'];
    $stripeCustomerId = $_POST['stripe_customer_id'] ?? '';
    $orderId = $vars['orderid'] ?? 0;

    if (!$orderId) {
        return;
    }

    // Get the order to find client ID
    $order = Capsule::table('tblorders')->where('id', $orderId)->first();

    if (!$order) {
        return;
    }

    $clientId = $order->userid;

    // If we don't have a customer ID, create one
    if (empty($stripeCustomerId)) {
        $stripeCustomerId = stripe_zero_order_getOrCreateStripeCustomer($clientId);
    }

    if (empty($stripeCustomerId)) {
        logActivity("Stripe Zero Order: Could not get/create Stripe customer for order #" . $orderId);
        return;
    }

    // Attach payment method to customer if not already attached
    $params = stripe_zero_order_getStripeParams();
    if (!empty($params['secretKey'])) {
        \Stripe\Stripe::setApiKey($params['secretKey']);

        try {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);

            if (!$paymentMethod->customer) {
                $paymentMethod->attach(['customer' => $stripeCustomerId]);
            }

            // Set as default payment method
            \Stripe\Customer::update($stripeCustomerId, [
                'invoice_settings' => [
                    'default_payment_method' => $paymentMethodId,
                ],
            ]);

        } catch (\Exception $e) {
            logActivity("Stripe Zero Order: Failed to attach payment method - " . $e->getMessage());
        }
    }

    // Save payment method to WHMCS
    stripe_zero_order_savePaymentMethod($clientId, $paymentMethodId, $stripeCustomerId);

    logActivity("Stripe Zero Order: Payment method saved for order #" . $orderId . " (Client #" . $clientId . ")");
});

/**
 * Hook: PreShoppingCartCheckout
 *
 * Handle AJAX requests for creating SetupIntents for new users
 */
add_hook('PreShoppingCartCheckout', 1, function ($vars) {
    // Check if this is an AJAX request for SetupIntent creation
    if (empty($_POST['action']) || $_POST['action'] !== 'create_setup_intent') {
        return;
    }

    // This is an AJAX request - we need to create a SetupIntent
    header('Content-Type: application/json');

    // For new users, we need to create a temporary Stripe customer
    // The real customer will be created after order is placed

    $params = stripe_zero_order_getStripeParams();

    if (empty($params['secretKey'])) {
        echo json_encode(['error' => 'Stripe not configured']);
        exit;
    }

    \Stripe\Stripe::setApiKey($params['secretKey']);

    try {
        // Create a temporary customer
        $email = $_POST['email'] ?? 'pending@temp.local';
        $name = trim(($_POST['firstname'] ?? '') . ' ' . ($_POST['lastname'] ?? ''));

        $customer = \Stripe\Customer::create([
            'email' => $email,
            'name' => $name ?: 'Pending Customer',
            'metadata' => [
                'whmcs_temp' => 'true',
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        // Create SetupIntent
        $setupIntent = \Stripe\SetupIntent::create([
            'customer' => $customer->id,
            'payment_method_types' => ['card'],
            'usage' => 'off_session',
            'metadata' => [
                'source' => 'whmcs_zero_order_validation',
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        echo json_encode([
            'success' => true,
            'client_secret' => $setupIntent->client_secret,
            'stripe_customer_id' => $customer->id,
        ]);

    } catch (\Exception $e) {
        logActivity("Stripe Zero Order: AJAX SetupIntent creation failed - " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }

    exit;
});

/**
 * Hook: ClientAreaPageCart
 *
 * Intercept cart processing to handle zero-order validation
 * This prevents WHMCS from trying to process empty card fields
 */
add_hook('ClientAreaPageCart', 1, function ($vars) {
    // If we've already validated via SetupIntent, ensure WHMCS knows
    if (!empty($_POST['stripe_zero_order_validated']) && !empty($_POST['stripe_payment_method_id'])) {
        // Do NOT set payment method - we handle card storage ourselves
        // This prevents WHMCS from calling stripe_storeremote
        unset($_POST['paymentmethod']);
        unset($_POST['ccinfo']);

        logActivity("Stripe Zero Order: Intercepted cart processing, bypassing payment gateway. PM: " . $_POST['stripe_payment_method_id']);
    }

    return $vars;
});

/**
 * Hook: ShoppingCartValidateCheckout
 *
 * Skip credit card validation for zero-order SetupIntent flows
 */
add_hook('ShoppingCartValidateCheckout', -1, function ($vars) {
    // If we've validated via SetupIntent, don't require card number
    if (!empty($_POST['stripe_zero_order_validated']) && !empty($_POST['stripe_payment_method_id'])) {
        // Return empty array (no errors) to skip normal CC validation
        return [];
    }
});

/**
 * Activity Log helper (if not already available)
 */
if (!function_exists('logActivity')) {
    function logActivity($message, $userId = 0) {
        try {
            Capsule::table('tblactivitylog')->insert([
                'date' => date('Y-m-d H:i:s'),
                'description' => $message,
                'user' => '',
                'userid' => $userId,
                'ipaddr' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        } catch (\Exception $e) {
            // Silent fail
        }
    }
}

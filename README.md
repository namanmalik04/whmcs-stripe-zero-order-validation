# WHMCS Stripe Zero-Order Card Validation

A WHMCS hook that validates customer credit cards on $0 orders using Stripe's SetupIntent API. This allows collection and validation of payment methods even when no immediate charge is required, enabling future usage-based billing.

## The Problem

WHMCS doesn't validate or store credit cards for $0 orders. This is problematic for:

- **Usage-based billing** - Services billed based on actual usage after the fact
- **Free trials** - Collect payment info upfront for conversion
- **Metered services** - Storage, bandwidth, API calls billed monthly
- **Freemium products** - Validate payment method for potential upgrades

## The Solution

This hook intercepts $0 checkout flows and:

1. Detects when cart total is $0
2. Displays a Stripe Elements card input
3. Uses Stripe's SetupIntent API to validate the card (no charge)
4. Handles 3D Secure authentication automatically
5. Saves the validated payment method to WHMCS for future billing

## Features

- Automatically detects $0 total orders at checkout
- Uses Stripe Elements for PCI-compliant card collection
- SetupIntent API - validates without charging
- Full 3D Secure / SCA support
- Saves card to WHMCS for future invoices and auto-pay
- Works with logged-in users and guest checkout
- Uses existing WHMCS Stripe gateway configuration
- Activity logging for debugging

## Requirements

- WHMCS 8.x or later
- Stripe payment gateway configured in WHMCS
- PHP 7.4 or later
- Stripe PHP SDK (included with WHMCS)

## Installation

1. Download `stripe_zero_order_validation.php`
2. Upload to your WHMCS `/includes/hooks/` directory
3. Clear WHMCS template cache:
   - Admin > System Settings > General Settings > Template > Clear Cache
   - Or delete contents of `templates_c/` directory
4. Test with a $0 product order

## How It Works

### For Logged-In Users

```
1. User adds $0 product to cart
2. Hook detects zero total at checkout
3. Stripe customer created/retrieved
4. SetupIntent created with customer ID
5. User enters card details
6. stripe.confirmCardSetup() validates card
7. Payment method saved to WHMCS
8. Order completes
```

### For Guest Checkout

```
1. Guest adds $0 product to cart
2. Hook detects zero total at checkout
3. Card form displayed
4. On submit, temporary Stripe customer created via AJAX
5. SetupIntent created
6. Card validated
7. After order, payment method attached to real customer
8. Card saved to WHMCS
```

## Database Storage

The validated card is stored in standard WHMCS tables:

| Table | Purpose |
|-------|---------|
| `tblpaymethods` | Payment method record |
| `tblcreditcards` | Card details (encrypted Stripe payment_method ID) |

This enables:
- Future invoice payments
- Auto-pay functionality
- Manual payment capture
- Client area card management

## Configuration

No additional configuration needed. The hook automatically uses your existing Stripe gateway settings:

- **Publishable Key** - For Stripe.js on frontend
- **Secret Key** - For API calls on backend

## Logging

Activity is logged to WHMCS Activity Log:

```
Stripe Zero Order: Detected zero-total order, injecting card validation form
Stripe Zero Order: Saved payment method for client #123 - Visa ending in 4242
```

## Troubleshooting

### Hook not appearing on checkout

- Clear WHMCS template cache
- Verify file permissions (readable by web server)
- Check PHP syntax: `php -l stripe_zero_order_validation.php`
- Ensure Stripe gateway is active in WHMCS

### Card validation fails

- Check browser console for JavaScript errors
- Verify Stripe API keys are correct in WHMCS
- Check WHMCS Activity Log for error details
- Test with Stripe test card: `4242 4242 4242 4242`

### Payment method not saved after order

- Verify `AcceptOrder` hook is firing
- Check that form contains `stripe_payment_method_id` hidden field
- Review WHMCS Activity Log for database errors

### 3D Secure / SCA issues

- Ensure you're using a card that triggers 3D Secure in test mode
- Test card for 3DS: `4000 0027 6000 3184`
- The hook handles 3DS automatically via `confirmCardSetup()`

## Use Cases

### Usage-Based Cloud Services

```
Product: Cloud Storage (Pay per GB)
Price: $0.00/month (billed based on usage)

- Customer signs up with $0 order
- Card validated and stored
- Monthly cron calculates usage
- Invoice generated with actual charges
- Saved card charged automatically
```

### Free Trial with Card Required

```
Product: Premium Plan - 14 Day Trial
Price: $0.00 first month, then $29/month

- Customer gets free trial
- Card validated upfront
- After trial, regular billing begins
- No friction at conversion
```

### Metered API Access

```
Product: API Access
Price: $0.00 base + $0.001 per request

- Developer signs up free
- Card on file for overages
- Usage tracked via webhooks
- Monthly invoice for actual usage
```

## License

MIT License - see [LICENSE](LICENSE) file.

## Contributing

Issues and pull requests welcome on GitHub.

## Credits

Created by [DataHorders LLC](https://datahorders.org)

## Version History

- **1.0.0** - Initial release
  - Zero-order detection
  - SetupIntent card validation
  - 3D Secure support
  - WHMCS payment method storage

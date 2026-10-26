# Gateway card-lock (ZarinPal)

ReactorID can restrict a payment to the customer's own verified bank card. ZarinPal supports this through the `card_pan` field in the request `metadata`: it rejects any other card **at the gateway, before the money is taken** — so, unlike a post-payment check, nothing is charged to the customer.

## Step 1 — Read the verified card

ReactorID exposes a public method that returns the customer's full verified card number (decrypted), or an empty string when the account is not verified / has no stored card:

```php
$card = \Reactor\Kyc\Verification::verifiedCardNumber($userId); // '6037...' or ''
```

## Step 2 — Add `card_pan` to the ZarinPal request

In the gateway request module (`modules/gateways/zarinpal.php`), where the `metadata` array is built, add the following. The helper below loads the addon bootstrap behind a safe guard and returns the card only when card-locking is on — if the addon is not installed/active, payments keep working without the lock:

```php
function zarinpal_locked_card($userId)
{
    $boot = __DIR__ . '/../addons/reactorid/lib/bootstrap.php';
    if (!is_file($boot)) {
        return '';
    }
    try {
        require_once $boot;
        if (!class_exists('\\Reactor\\Kyc\\Verification') || !class_exists('\\Reactor\\Kyc\\Settings')) {
            return '';
        }
        if (!\Reactor\Kyc\Settings::isOn('enforce_payment_card')) {
            return '';
        }
        $card = \Reactor\Kyc\Verification::verifiedCardNumber((int) $userId);
        return preg_match('/^\d{16}$/', $card) ? $card : '';
    } catch (\Throwable $e) {
        return '';
    }
}
```

Then, when building the request:

```php
$lockedCard = zarinpal_locked_card($invoice->userid);
if ($lockedCard !== '') {
    $data['metadata']['card_pan'] = $lockedCard;
}
```

## Notes

- This feature applies to gateways that support card restriction. For ZarinPal, see their "pay with a specific card" documentation.
- Accounts verified before card storage was added have no stored card; ReactorID requires those users to register their card once before purchasing.

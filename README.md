# ReactorID

**Iranian identity verification (KYC) addon for WHMCS**, powered by [Zohal](https://zohal.io) inquiries — Shahkar (mobile ↔ national ID) and bank-card ↔ national-code matching. It gates the client area until a customer is verified, and locks payments to the customer's own verified bank card.

---

## Features

- **Three-step verification**
  1. Mobile number confirmation via SMS one-time code (SMS.ir)
  2. National ID ↔ mobile match through the **Shahkar** service (Zohal)
  3. Bank **card ↔ national-ID** match (Zohal)
- **Client-area gating** — every page is locked until verification is complete; only the support-ticket pages stay open.
- **Gateway card-lock** — the customer can only pay with their own verified card (enforced by the gateway, before any charge).
- **Admin panel** — user list, full detail + event log, manual approve / revoke / reset, a **blacklist** (national ID / mobile / card), and a dashboard stats widget.
- **Clean, responsive RTL UI** with a step-by-step wizard.
- **Abuse protection** — rate limiting on inquiries and SMS to control cost.

## Requirements

- WHMCS 8.0 or newer
- PHP 7.4 – 8.3 (with the `curl` and `openssl` extensions)
- A [Zohal](https://zohal.io) account and API token
- An [SMS.ir](https://sms.ir) account with a verification (OTP) template

## How it works

| Step | What the user enters | What is checked |
|------|----------------------|-----------------|
| 1 | Mobile number | A 5-digit OTP is sent via SMS.ir and confirmed |
| 2 | National ID + birth date | Shahkar matches the mobile to the national ID |
| 3 | Bank card number | Zohal matches the card to the national ID |

Once all three pass, the account is marked **verified** and purchasing is enabled.

## Installation

1. Copy the contents of this repository into `modules/addons/reactorid/`.
2. In the admin area go to **System Settings → Addon Modules**, activate **ReactorID** and grant admin access.
3. Click **Configure** and fill in the settings below.

> On activation, the required tables and an encryption key are created automatically.

## Configuration

| Setting | Description |
|---|---|
| Zohal token | Bearer access token from your Zohal panel |
| SMS.ir API key | Your SMS.ir web-service key |
| OTP template ID | The verification template ID in SMS.ir |
| OTP parameter name | The code parameter name in the SMS template (default `CODE`) |
| Enforce checkout | Lock the client area / purchasing until verification (default: on) |
| Block guest checkout | Guests (not logged in) cannot complete checkout |
| Lock payment card | Payment is allowed only with the verified card (default: on) |

## Client-area gating

Until the account is fully verified (and its card registered when card-locking is on), **every client-area page** redirects to the verification page (`index.php?m=reactorid`). The only exceptions are the **support-ticket pages**, the verification page itself and logout. This can be turned off with the *Enforce checkout* setting. A `ShoppingCartValidateCheckout` hook acts as a second layer that blocks order completion.

## Gateway card-lock (ZarinPal)

ReactorID exposes the customer's verified card so the gateway can restrict payment to that card. With ZarinPal the card number is sent as `metadata.card_pan` in the payment request, and any other card is rejected **at the gateway, before the money is taken**.

Integration guide and example: [`docs/zarinpal-card-lock.md`](docs/zarinpal-card-lock.md). The public helper:

```php
$card = \Reactor\Kyc\Verification::verifiedCardNumber($userId); // '6037...' or ''
```

## Security

- The full card number is stored **encrypted (AES-256-CBC)** and used only for card-locking and admin display.
- All database queries are parameterised (Capsule / PDO) — no raw SQL.
- **CSRF** protection on both the client and admin sides.
- OTP codes are stored **hashed**: 2-minute expiry, 5-attempt cap, 90-second resend cooldown, and a daily send cap.
- **Rate limiting** on Zohal inquiries (identity / card) to control cost and prevent abuse.
- A national ID can be verified on only one account.
- Input validation (national-ID checksum, card Luhn, mobile normalisation, Jalali date validity).
- All PHP files are guarded against direct access.

## Database tables

| Table | Purpose |
|---|---|
| `mod_reactorid_records` | Per-user verification record (status, national ID, encrypted card, …) |
| `mod_reactorid_otps` | One-time codes (hashed) |
| `mod_reactorid_logs` | Event log and the basis for rate limiting |
| `mod_reactorid_blacklist` | Blacklist of national IDs / mobiles / cards |

## Links

- Client verification page: `index.php?m=reactorid`
- Admin panel: `addonmodules.php?module=reactorid`

## Roadmap

ReactorID is actively developed — **new features, improvements and upgrades are coming soon.** Suggestions and bug reports are welcome via Issues.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

Released under the [MIT License](LICENSE).

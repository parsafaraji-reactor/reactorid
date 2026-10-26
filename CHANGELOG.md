# Changelog

All notable changes to ReactorID are documented here. This project follows semantic versioning.

## [1.0.0]

Initial release.

- Three-step identity verification: mobile OTP (SMS.ir), Shahkar (mobile ↔ national ID), and bank card ↔ national-ID matching (Zohal).
- Client-area gating until the account is verified; support-ticket pages stay open.
- Gateway card-lock: payment restricted to the customer's own verified card (ZarinPal `metadata.card_pan`).
- Encrypted (AES-256-CBC) card storage, used only for card-locking and admin display.
- Admin panel: user list, detail view with event log, manual approve / revoke / reset, blacklist (national ID / mobile / card), and a dashboard stats widget.
- Security: parameterised queries, CSRF protection, hashed OTP with attempt/expiry/cooldown limits, rate-limited inquiries, and single-account national-ID binding.
- Clean, responsive RTL interface.

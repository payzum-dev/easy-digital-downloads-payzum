=== Payzum Crypto & Stablecoin Payments for Easy Digital Downloads ===
Contributors: payzum
Tags: easy-digital-downloads, edd, cryptocurrency, stablecoin, payment gateway
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept crypto and stablecoins (USDC/USDT, multi-chain) in Easy Digital Downloads with Payzum. Non-custodial — funds settle to your own wallet.

== Description ==

Payzum lets your Easy Digital Downloads store accept cryptocurrency and stablecoin payments
(USDC/USDT and more, across multiple chains). Payments are **non-custodial**: funds settle directly
to your own wallet — Payzum never takes custody.

At checkout the buyer is sent to a secure Payzum hosted checkout (QR + deposit address, live
status), or the checkout can be embedded in your page as an overlay or inline. The order is
completed automatically from a signed IPN webhook, so a closed browser tab never loses a paid
order.

This is an add-on gateway: it requires **Easy Digital Downloads** to be installed and active, and
it does not change any of EDD's default behaviour.

Features:

* Crypto & stablecoin checkout (USDC/USDT, multi-chain).
* Non-custodial — settles to your wallet.
* Hosted checkout — no card data or crypto handling on your server.
* Redirect, modal or inline render modes.
* Signed IPN webhooks (HMAC-SHA-512) verify every payment server-side.
* The settled amount and currency are checked against the order before the download is delivered;
  a mismatch is put on hold instead of being completed.
* Fiat pricing (Payzum converts) or crypto pricing (`pricing_mode: "direct"`).
* No chargebacks.

The buyer picks the coin on the Payzum checkout, limited to the tokens your merchant account
accepts. There is no coin selector in the plugin, by design.

== Installation ==

1. Install and activate **Easy Digital Downloads** first. This plugin does nothing without it and
   will show an admin notice if it is missing.
2. Upload the `payzum-edd` folder to `/wp-content/plugins/`, or install the zip via
   Plugins → Add New → Upload.
3. Activate the plugin.
4. Go to Downloads → Settings → Payments → **Payzum**.
5. Paste your **API key** and **Webhook secret** from your Payzum dashboard, and pick the
   **Environment** (Production — merchant.payzum.com, or Staging / sandbox — staging.payzum.com,
   which has separate API keys). The gateway stays hidden until both credentials are set.
6. Copy the **Your IPN URL** value shown on that screen into your Payzum webhook settings. The IPN
   signature header is fixed (`x-nowpayments-sig`) — no configuration needed.
7. Enable the gateway in EDD's payment-gateway list. It appears as "Payzum (Crypto & Stablecoins)"
   in the admin and "Crypto / Stablecoins (Payzum)" at checkout.
8. Save, then place a small test order to confirm the flow.

Your site needs **PHP 8.1 or newer**. The plugin bundles the official `payzum/payzum-php` SDK,
which uses enums and named arguments.

== External services ==

This plugin connects to the Payzum API to create payment invoices and receive payment
notifications. It is required for the gateway to work.

* What it sends: when a buyer chooses Payzum at checkout, the plugin sends the order total,
  currency, order reference and your store's callback/return URLs to Payzum to create the invoice.
  Payment confirmations arrive as signed webhooks from Payzum; the plugin verifies their
  signature before updating the order. No customer personal data is sent by the plugin.
* When: only when the gateway is enabled and a buyer pays.
* Endpoints: `https://merchant.payzum.com` (production) or `https://staging.payzum.com`
  (staging), as selected in the plugin settings.
* Service provider: Payzum — [terms](https://payzum.com/terms), [privacy](https://payzum.com/privacy).

If your site sends a Content-Security-Policy, allow `merchant.payzum.com` in `script-src`,
`frame-src` and `connect-src` for the modal and inline render modes.

== Frequently Asked Questions ==

= Is it custodial? =
No. Funds settle directly to your own wallet.

= Which coins are supported? =
USDC/USDT and other crypto across supported chains. Which coins your store accepts is configured
in your Payzum dashboard (Merchants → Settings → Accepted tokens); the buyer picks one of those on
the Payzum checkout.

= The gateway does not appear at checkout. =
It hides itself until both the API key and the webhook secret are filled in, and it still has to be
enabled in EDD's payment-gateway list.

= What happens if the buyer underpays? =
The order is not completed and the download is not delivered. A `partially_paid` invoice, and a
`finished` invoice whose settled amount or currency does not match the order, both put the order
**on hold** with an explanatory note for you to review. Overpayment is fine and completes normally.

= What about refunds? =
Refunds are recorded as a note only. EDD 3.x models refunds as their own order records, so flipping
the order status from a webhook would desync the refund ledger — handle refunds through EDD.

= Do I need to write code? =
No. Enter your API key and webhook secret and you are live.

== Changelog ==

= 1.3.1 =
* Plugin URI now points at the plugin's own repository, so it differs from the Author URI as
  the plugin directory requires. No functional change.

= 1.3.0 =
* The settled amount and currency are verified against the order before it is completed. A
  `finished` invoice that does not match is put **on hold** with a note instead of delivering the
  download.
* IPN deliveries are deduplicated by event id, and the whole transition runs under a per-order lock
  so two simultaneous deliveries cannot both complete an order and deliver twice.
* The gateway hides itself when it has no credentials, instead of offering a checkout that cannot
  settle.

= 1.2.0 =
* Rebuilt on the official `payzum/payzum-php` SDK (bundled in `vendor/`): HTTP client, webhook
  signature verification, decimal-exact amounts and the payment-status vocabulary now live in one
  tested library instead of plugin code.
* New Environment setting (production / staging) for end-to-end testing against the sandbox.
* Every invoice create carries an idempotency key, so a transport retry cannot mint a second
  invoice.
* Requires PHP 8.1 (the SDK's floor).

= 1.1.0 =
* The buyer picks the coin on the Payzum hosted checkout (`pay_currency: "all"`); dropped the
  in-plugin settlement-currency setting and the IPN signature-header setting. The old default coin
  was USDT on Tron, whose $100 network minimum rejected every ordinary order.
* Added modal and inline render modes, and a crypto currency mode (`pricing_mode: "direct"`).
* Zero-total orders complete without an invoice; repeated IPNs no longer duplicate order notes.

= 1.0.0 =
* Initial release: hosted-checkout gateway with signed IPN verification.

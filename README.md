# Payzum for Easy Digital Downloads — Accept Crypto & Stablecoin Payments (USDC, USDT)

Accept **cryptocurrency and stablecoin payments** (USDC, USDT and more, multi-chain) in
[Easy Digital Downloads](https://easydigitaldownloads.com) through [Payzum](https://payzum.com) —
**non-custodial**: funds settle directly to your own wallet, Payzum never takes
custody. No chargebacks, no card networks, no PCI surface.

- **Plugin:** `payzum-edd` · **Version:** 1.3.0 · **License:** GPL-2.0-or-later
- **Requires:** WordPress ≥ 5.6, Easy Digital Downloads 3.x, PHP ≥ 8.1

## How it works

1. The buyer picks **Payzum** at checkout and pays on a hosted checkout page
   (QR code + deposit address, live status) — or in an embedded widget, see
   render modes below — where they choose the coin and chain. No wallet or
   card data touches your server.
2. Crypto confirmation is **asynchronous**, so the payment is completed from
   Payzum's signed server-to-server IPN webhook, never from the buyer's browser
   return — a closed tab never loses a paid order.
3. Every webhook is verified with **HMAC-SHA-512 over the raw request bytes**
   (constant-time compare, replay window) before a single field of it is read.
   Redelivered webhooks are deduplicated and the settled amount and currency
   are re-checked against the order before it completes, so a download is
   never delivered twice — or for the wrong amount.

## Features

- **Stablecoin-first**: USDC and USDT across multiple chains (Polygon, Ethereum,
  Arbitrum, Base, Optimism, Tron, Solana and more), plus major cryptocurrencies.
- **Non-custodial** — payments settle to the merchant's own wallet.
- **Three render modes** — full redirect to the hosted checkout, a modal
  overlay, or an inline widget embedded in your checkout page.
- **Signed IPN webhooks** (HMAC-SHA-512) complete payments server-side, with
  amount/currency verification and per-order locking against double delivery.
- **Fiat or crypto pricing** — price in your store currency and let Payzum
  convert, or denominate directly in a stablecoin (`pricing_mode: direct`).
- **Production / staging selector** built into the settings screen.
- **Zero chargebacks** — crypto payments are final.

## Installation

**From the release zip (recommended).** Download
[`payzum-edd-1.3.0.zip`](https://github.com/payzum-dev/easy-digital-downloads-payzum/releases/latest), then in WordPress go to
**Plugins → Add New → Upload Plugin**, pick the zip and activate it (requires Easy Digital Downloads). The archive unpacks to
`payzum-edd/`, the folder name WordPress expects.

**From a clone.** This repository *is* the plugin, so its contents must land in a folder named
`payzum-edd`:

```bash
git clone https://github.com/payzum-dev/easy-digital-downloads-payzum.git payzum-edd
mv payzum-edd /path/to/wp-content/plugins/
```

Do not drop the repository in as `easy-digital-downloads-payzum` — WordPress keys the plugin off the folder name.

The official [`payzum/payzum-php`](https://packagist.org/packages/payzum/payzum-php) SDK is
vendored, so no composer step is needed.

## Configuration

Go to **Downloads → Settings → Payments → Payzum**:

| Setting | Meaning |
|---|---|
| API key | From your [Payzum merchant dashboard](https://merchant.payzum.com) |
| Webhook secret | Verifies incoming payment webhooks (IPN) |
| Currency mode | `fiat` (default — Payzum converts from your store currency) or `crypto` (price directly in a stablecoin) |
| Render mode | `redirect` (default), `modal`, or `inline` widget |
| Environment | Production or staging (staging needs its own API key) |
| Debug log | Writes to **Downloads → Tools → Debug Log**, source `payzum` |

The settings screen shows your store's **IPN URL**
(`https://<your-store>/?edd-listener=payzum`) read-only — paste it into the
Payzum dashboard webhook settings. The signature header is fixed; nothing to
configure.

The buyer chooses the coin on the Payzum checkout, limited to the allowlist you
configure in the Payzum dashboard (**Merchants → Settings → Accepted tokens**)
and enforced server-side.

## Payment status mapping

| Payzum payment status | EDD payment status |
|---|---|
| `finished` (amount and currency match the order) | Complete |
| `finished` (settled amount or currency does not match) | On hold — flagged for review, not completed |
| `partially_paid` | On hold (underpaid — the buyer sent less than the invoice) |
| `expired` | Abandoned |
| `failed` | Failed |
| anything else | Payment note only, status unchanged |

## FAQ

**Is Payzum custodial?**
No. Funds settle directly to your own wallet — Payzum never holds your money.

**Can Easy Digital Downloads accept USDT or USDC?**
Yes — with this plugin, buyers pay in USDC, USDT or other assets on the chains
you allow, and the EDD payment completes automatically from a signed webhook.

**Do buyers need an account or a specific wallet?**
No. They scan a QR or copy a deposit address from the Payzum checkout and pay
from any wallet.

**What about chargebacks?**
There are none — crypto payments are final, which eliminates chargeback fraud
on digital products.

**Can I keep buyers on my site instead of redirecting?**
Yes. The `modal` and `inline` render modes embed the Payzum payment widget in
your checkout page; fulfilment still happens exclusively from the signed IPN.
If your site sets a Content-Security-Policy, allow `merchant.payzum.com` in
`script-src`, `frame-src` and `connect-src`.

**What data is shared with Payzum?**
Only the order total, currency, an order reference and your store's callback
URLs — no customer personal data. Endpoints: `https://merchant.payzum.com`
(production), `https://staging.payzum.com` (staging).

## Related Payzum integrations

Payzum ships official plugins for most major e-commerce, donation and billing
platforms — WooCommerce, Magento 2, PrestaShop, Shopware 6, OpenCart, Zen Cart,
nopCommerce, Ecwid, BigCommerce, Shopify, Wix, Medusa, Vendure, Saleor, Sylius,
GiveWP, Paid Memberships Pro, WHMCS, Blesta, HostBill, ClientExec, pretix,
Frappe/ERPNext, Akaunting and django-payments — plus official SDKs for PHP,
Node.js/TypeScript, Python and Rust. Browse them all at
[github.com/payzum-dev](https://github.com/payzum-dev).

## About Payzum

[Payzum](https://payzum.com) is a non-custodial crypto payment gateway for
merchants: accept USDC, USDT and other digital assets with settlement straight
to your own wallet, optional auto-conversion to stablecoins, and a single REST
API. API docs: [merchant.payzum.com/api/docs](https://merchant.payzum.com/api/docs).

## License

[GPL-2.0-or-later](LICENSE). Contributed and maintained by Payzum.

<?php
/**
 * Payzum Easy Digital Downloads payment gateway.
 *
 * Flow:
 *   process_payment() -> SDK payments->create() with pay_currency:"all" -> hand the buyer to the
 *   hosted checkout (where the buyer picks the crypto). Payzum -> signed IPN (POST) ->
 *   handle_ipn() verifies via the SDK's Verifier (raw body, fixed header, constant time),
 *   deduplicates by event id, maps payment_status to the EDD order.
 *   `finished` = fully paid -> status "complete".
 *
 * Built on the official payzum/payzum-php SDK (vendored under vendor/): HTTP client, HMAC
 * verification, decimal-exact amounts and the status vocabulary all live there, written and
 * tested once.
 *
 * The plugin never lists or validates currencies: `pay_currency:"all"` defers the choice to the
 * buyer, and the hosted checkout offers only the merchant's accepted-tokens allowlist, enforced
 * server-side (Payzum dashboard -> Merchants -> Settings -> Accepted tokens).
 *
 * The order is fulfilled from the IPN, never from the buyer's return, so a closed browser tab
 * can't lose a paid order. Non-custodial: funds settle to the merchant's own wallet.
 *
 * One-off payments only. EDD Recurring requires a gateway to opt in through
 * `edd_recurring_available_gateways`; this one deliberately does not, so a subscription
 * product never offers Payzum rather than charging once and never renewing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Payzum\Errors\ApiException;
use Payzum\Errors\PayzumException;
use Payzum\Errors\SignatureException;
use Payzum\Payzum;
use Payzum\PaymentStatus;
use Payzum\Webhooks\Verifier;

class Payzum_EDD_Gateway {

	/** Gateway id, and the value of the ?edd-listener= query arg that routes the IPN here. */
	const ID = 'payzum';

	/** Order meta holding recently seen IPN event ids — retries reuse the id. */
	const EVENT_IDS_META = '_payzum_ipn_event_ids';

	/** How many past event ids to keep per order for deduplication. */
	const EVENT_IDS_KEEP = 20;

	/** Sentinel that defers the pay-currency choice to the buyer on the hosted checkout. */
	const PAY_CURRENCY_ANY = 'all';

	/**
	 * How far the settled amount may fall short of the order total before the order is held.
	 *
	 * Half a cent: the order total is a decimal string and price_amount arrives as a JSON number,
	 * so the two round differently and `==` on floats would reject good payments.
	 */
	const AMOUNT_TOLERANCE = 0.005;

	/** Seconds to wait for the per-order IPN lock before giving up and asking for a retry. */
	const LOCK_TIMEOUT = 10;

	/** Embeddable checkout widget (modal / inline render modes). */
	const WIDGET_URL = 'https://merchant.payzum.com/widget/v1/payzum.js';

	/** Query arg that turns the EDD checkout page into the widget host (modal / inline). */
	const RENDER_ARG = 'edd-payzum';

	/**
	 * Order being rendered on the checkout page for modal/inline, resolved in template_redirect
	 * so the script can be enqueued before the header is sent.
	 *
	 * @var object|null
	 */
	private $render_order = null;

	public function __construct() {
		add_filter( 'edd_payment_gateways', array( $this, 'register_gateway' ) );
		add_action( 'admin_notices', array( $this, 'missing_credentials_notice' ) );
		add_filter( 'edd_settings_sections_gateways', array( $this, 'register_settings_section' ) );
		add_filter( 'edd_settings_gateways', array( $this, 'register_settings' ) );

		// Redirect gateway — no on-site credit-card form.
		add_action( 'edd_' . self::ID . '_cc_form', '__return_false' );

		// Checkout submit for this gateway.
		add_action( 'edd_gateway_' . self::ID, array( $this, 'process_payment' ) );

		// Signed IPN callback (server-to-server, no session).
		add_action( 'init', array( $this, 'maybe_handle_ipn' ) );

		// Modal / inline render modes host the widget on the EDD checkout page.
		add_action( 'template_redirect', array( $this, 'maybe_prepare_render' ) );
		add_filter( 'the_content', array( $this, 'maybe_render_widget' ), 20 );
	}

	/* ------------------------------------------------------------------ registration + settings */

	public function register_gateway( $gateways ) {
		// Do not offer the gateway until it can actually complete a payment.
		//
		// Without a webhook secret the IPN cannot be verified, so every genuine delivery is
		// rejected and the order never leaves pending — the buyer pays and the store never knows.
		// That costs real money and gives the shop owner no signal, so the method stays off the
		// checkout rather than accept a payment it cannot settle. Same for the API key, without
		// which the invoice cannot be created at all.
		if ( array() !== self::missing_credentials() ) {
			return $gateways;
		}

		$gateways[ self::ID ] = array(
			'admin_label'    => __( 'Payzum (Crypto & Stablecoins)', 'payzum-edd' ),
			'checkout_label' => __( 'Crypto / Stablecoins (Payzum)', 'payzum-edd' ),
		);
		return $gateways;
	}

	/**
	 * Credentials that must be present before the gateway can take a payment.
	 *
	 * @return string[] Names of the missing settings, empty when everything is configured.
	 */
	public static function missing_credentials() {
		$missing = array();
		if ( '' === trim( (string) edd_get_option( 'payzum_api_key', '' ) ) ) {
			$missing[] = __( 'API key', 'payzum-edd' );
		}
		if ( '' === trim( (string) edd_get_option( 'payzum_webhook_secret', '' ) ) ) {
			$missing[] = __( 'webhook secret', 'payzum-edd' );
		}
		return $missing;
	}

	/** Admin notice naming what is missing — the checkout gives no clue on its own. */
	public function missing_credentials_notice() {
		if ( ! current_user_can( 'manage_shop_settings' ) ) {
			return;
		}
		$missing = self::missing_credentials();
		if ( array() === $missing ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Payzum cannot take payments.', 'payzum-edd' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of missing settings */
					__( 'Missing: %s. The payment method is hidden at checkout until these are set, because without them a buyer could pay an invoice that never settles.', 'payzum-edd' ),
					implode( ', ', $missing )
				)
			)
		);
	}

	public function register_settings_section( $sections ) {
		$sections[ self::ID ] = __( 'Payzum', 'payzum-edd' );
		return $sections;
	}

	/**
	 * Settings live under Downloads -> Settings -> Payments -> Payzum.
	 *
	 * There is deliberately no coin selector: which crypto a store accepts is a Payzum dashboard
	 * setting, and no API endpoint exposes a merchant's allowlist for the plugin to mirror.
	 */
	public function register_settings( $settings ) {
		$settings[ self::ID ] = array(
			'payzum_header'         => array(
				'id'   => 'payzum_header',
				'name' => '<strong>' . __( 'Payzum', 'payzum-edd' ) . '</strong>',
				'type' => 'header',
			),
			'payzum_api_key'        => array(
				'id'   => 'payzum_api_key',
				'name' => __( 'API key', 'payzum-edd' ),
				'type' => 'password',
				'desc' => __( 'Your Payzum API key (64-hex). Dashboard → Merchants → API key.', 'payzum-edd' ),
			),
			'payzum_webhook_secret' => array(
				'id'   => 'payzum_webhook_secret',
				'name' => __( 'Webhook secret', 'payzum-edd' ),
				'type' => 'password',
				'desc' => __( 'The IPN signing secret from your Payzum webhook settings. Used to verify HMAC-SHA-512 signatures.', 'payzum-edd' ),
			),
			'payzum_environment'    => array(
				'id'      => 'payzum_environment',
				'name'    => __( 'Environment', 'payzum-edd' ),
				'type'    => 'select',
				'std'     => 'production',
				'options' => array(
					'production' => __( 'Production — merchant.payzum.com', 'payzum-edd' ),
					'staging'    => __( 'Staging / sandbox — staging.payzum.com (separate API keys)', 'payzum-edd' ),
				),
				'desc'    => __( 'Staging is an isolated environment with its own API keys — a production key will not work there.', 'payzum-edd' ),
			),
			'payzum_ipn_url'        => array(
				'id'   => 'payzum_ipn_url',
				'name' => __( 'Your IPN URL', 'payzum-edd' ),
				'type' => 'descriptive_text',
				'desc' => sprintf(
					/* translators: %s: the IPN callback URL */
					__( 'Paste this into your Payzum webhook settings: %s', 'payzum-edd' ),
					'<code>' . esc_html( $this->ipn_url() ) . '</code>'
				),
			),
			'payzum_currencies_help' => array(
				'id'   => 'payzum_currencies_help',
				'name' => __( 'Accepted coins', 'payzum-edd' ),
				'type' => 'descriptive_text',
				'desc' => __( 'Which crypto/stablecoins you accept is configured in your Payzum dashboard, under <strong>Merchants → Settings → Accepted tokens</strong>. The buyer picks one of those on the Payzum checkout — there is nothing to configure here.', 'payzum-edd' ),
			),
			'payzum_currency_mode'  => array(
				'id'      => 'payzum_currency_mode',
				'name'    => __( 'Currency mode', 'payzum-edd' ),
				'type'    => 'select',
				'std'     => 'fiat',
				'options' => array(
					'fiat'   => __( 'Fiat — prices are in a normal currency (default)', 'payzum-edd' ),
					'crypto' => __( 'Crypto — prices are already denominated in a coin', 'payzum-edd' ),
				),
				'desc'    => __( 'Fiat: Payzum converts your order total to whichever coin the buyer picks. Crypto: your prices are already in a coin, so the buyer pays that exact amount with no conversion — and no coin choice.', 'payzum-edd' ),
			),
			'payzum_price_currency' => array(
				'id'      => 'payzum_price_currency',
				'name'    => __( 'Fiat price currency', 'payzum-edd' ),
				'type'    => 'select',
				'std'     => '',
				'options' => $this->fiat_currency_options(),
				'desc'    => __( 'Fiat mode only. Leave on <em>store currency</em> unless you know what you are doing: the order total is sent <strong>as-is</strong>, with no conversion, so picking a different currency here re-denominates the price rather than converting it.', 'payzum-edd' ),
			),
			'payzum_crypto_symbol'  => array(
				'id'          => 'payzum_crypto_symbol',
				'name'        => __( 'Crypto symbol', 'payzum-edd' ),
				'type'        => 'text',
				'std'         => '',
				'placeholder' => 'usdc',
				'desc'        => __( 'Crypto mode only. The bare coin symbol your prices are in — <code>usdc</code>, <code>usdt</code>, <code>btc</code>. Not the network-suffixed ticker (<code>usdcmatic</code> is set via the field below).', 'payzum-edd' ),
			),
			'payzum_crypto_network' => array(
				'id'          => 'payzum_crypto_network',
				'name'        => __( 'Crypto network', 'payzum-edd' ),
				'type'        => 'text',
				'std'         => '',
				'placeholder' => 'polygon',
				'desc'        => __( 'Crypto mode only. The chain for that symbol — <code>polygon</code>, <code>tron</code>, <code>ethereum</code>, <code>solana</code>… Leave empty for a native coin such as <code>btc</code>.', 'payzum-edd' ),
			),
			'payzum_render_mode'    => array(
				'id'      => 'payzum_render_mode',
				'name'    => __( 'Render mode', 'payzum-edd' ),
				'type'    => 'select',
				'std'     => 'redirect',
				'options' => array(
					'redirect' => __( 'Redirect — send the buyer to the Payzum checkout (default)', 'payzum-edd' ),
					'modal'    => __( 'Modal — open the checkout in an overlay on your site', 'payzum-edd' ),
					'inline'   => __( 'Inline — embed the checkout in the page', 'payzum-edd' ),
				),
				'desc'    => __( 'Modal and inline load the Payzum widget on your checkout page instead of sending the buyer away. If your site sends a Content-Security-Policy header, allow <code>merchant.payzum.com</code> in <code>script-src</code>, <code>frame-src</code> and <code>connect-src</code>.', 'payzum-edd' ),
			),
			'payzum_debug'          => array(
				'id'   => 'payzum_debug',
				'name' => __( 'Debug log', 'payzum-edd' ),
				'type' => 'checkbox',
				'desc' => __( 'Log gateway events to Downloads → Tools → Debug Log.', 'payzum-edd' ),
			),
		);
		return $settings;
	}

	/** Fiat options for the price-currency select: store currency first, then EDD's list. */
	private function fiat_currency_options() {
		$options = array( '' => __( 'Store currency (recommended)', 'payzum-edd' ) );
		if ( function_exists( 'edd_get_currencies' ) ) {
			foreach ( edd_get_currencies() as $code => $name ) {
				$options[ strtolower( $code ) ] = $code . ' — ' . $name;
			}
		}
		return $options;
	}

	/** Configured render mode, falling back to the redirect flow for any unknown value. */
	private function render_mode() {
		$mode = (string) edd_get_option( 'payzum_render_mode', 'redirect' );
		return in_array( $mode, array( 'redirect', 'modal', 'inline' ), true ) ? $mode : 'redirect';
	}

	private function ipn_url() {
		return add_query_arg( 'edd-listener', self::ID, home_url( '/' ) );
	}

	/* ------------------------------------------------------------------------- process payment */

	/**
	 * Create the Payzum invoice and hand the buyer off to the checkout.
	 *
	 * @param array $purchase_data EDD's checkout payload.
	 */
	public function process_payment( $purchase_data ) {
		$payment_id = edd_insert_payment( array(
			'price'        => $purchase_data['price'],
			'date'         => $purchase_data['date'],
			'user_email'   => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency'     => edd_get_currency(),
			'downloads'    => $purchase_data['downloads'],
			'cart_details' => $purchase_data['cart_details'],
			'user_info'    => $purchase_data['user_info'],
			'status'       => 'pending',
			'gateway'      => self::ID,
		) );

		if ( ! $payment_id ) {
			$this->log( 'edd_insert_payment failed at checkout.' );
			edd_set_error( 'payzum_error', __( 'Unable to create the order. Please try again.', 'payzum-edd' ) );
			edd_send_back_to_checkout( '?payment-mode=' . self::ID );
			return;
		}

		$purchase_key = (string) $purchase_data['purchase_key'];

		// A 100%-off discount or a free download leaves nothing to charge, and the API answers
		// 400 INVALID_REQUEST ("price_amount: Number must be greater than 0"). Complete the payment
		// here rather than sending the buyer to a checkout that cannot succeed.
		$price = (float) $purchase_data['price'];
		if ( $price <= 0 ) {
			$this->log( 'payment ' . $payment_id . ' has a zero total — completing without an invoice' );
			edd_update_payment_status( $payment_id, 'complete' );
			edd_insert_payment_note( $payment_id, __( 'Payzum: nothing to charge, order completed without a crypto invoice.', 'payzum-edd' ) );
			edd_empty_cart();
			$this->redirect( $this->success_url( $payment_id ), false );
		}

		$pricing   = $this->pricing_payload( $payment_id );
		$reference = $this->order_reference( $payment_id, $purchase_key );

		try {
			// The amount travels as a string end to end: the SDK writes it into the JSON as an
			// exact number (Json::encodeWithExactNumbers). Casting to float would round it on
			// the way out, which is the silent bug the SDK exists to prevent.
			$result = $this->payzum_client()->payments->create(
				priceAmount:      is_string( $purchase_data['price'] ) ? trim( $purchase_data['price'] ) : sprintf( '%.4F', $price ),
				priceCurrency:    $pricing['price_currency'],
				payCurrency:      $pricing['pay_currency'],
				orderId:          $reference,
				orderDescription: $this->order_description( $payment_id ),
				network:          $pricing['network'],
				ipnCallbackUrl:   $this->ipn_url(),
				successUrl:       $this->success_url( $payment_id ),
				cancelUrl:        edd_get_checkout_uri(),
				pricingMode:      $pricing['pricing_mode'],
				// The reference is unique to this (freshly inserted) EDD payment, so the key can
				// be static per payment: it makes a transport retry safe without ever pinning a
				// different payment to a stale invoice.
				idempotencyKey:   'edd-' . $reference,
			);
		} catch ( ApiException $e ) {
			// Branch on the typed code, never on the message — messages change between releases.
			$this->log( 'create failed for payment ' . $payment_id . ' [' . $e->rawCode . ']: ' . $e->getMessage() );
			edd_update_payment_status( $payment_id, 'failed' );
			edd_set_error( 'payzum_error', $this->buyer_notice_for( $e->rawCode ) );
			edd_send_back_to_checkout( '?payment-mode=' . self::ID );
			return;
		} catch ( PayzumException $e ) {
			// Local validation or transport failure. The SDK auto-retries the create only
			// because the Idempotency-Key makes a retry safe; if it still failed, it failed.
			$this->log( 'create failed for payment ' . $payment_id . ': ' . $e->getMessage() );
			edd_update_payment_status( $payment_id, 'failed' );
			edd_set_error( 'payzum_error', __( 'Unable to start the crypto payment. Please try again or pick another method.', 'payzum-edd' ) );
			edd_send_back_to_checkout( '?payment-mode=' . self::ID );
			return;
		}

		$invoice_url = isset( $result['invoice_url'] ) ? (string) $result['invoice_url'] : '';
		if ( '' === $invoice_url ) {
			$this->log( 'create_payment returned no invoice_url for payment ' . $payment_id . ': ' . wp_json_encode( $result ) );
			edd_update_payment_status( $payment_id, 'failed' );
			edd_set_error( 'payzum_error', __( 'Payzum did not return a checkout URL. Please try again.', 'payzum-edd' ) );
			edd_send_back_to_checkout( '?payment-mode=' . self::ID );
			return;
		}

		// Payzum returns the payment id as `payment_id` (alias `id` in older docs); store either.
		$pzid = '';
		if ( isset( $result['payment_id'] ) ) {
			$pzid = (string) $result['payment_id'];
		} elseif ( isset( $result['id'] ) ) {
			$pzid = (string) $result['id'];
		}
		if ( '' !== $pzid ) {
			edd_set_payment_transaction_id( $payment_id, sanitize_text_field( $pzid ) );
			edd_update_order_meta( $payment_id, '_payzum_payment_id', sanitize_text_field( $pzid ) );
		}
		// The invoice is a draft until the buyer picks a coin, and reads don't return the URL —
		// keep it so the order can link back to the checkout.
		edd_update_order_meta( $payment_id, '_payzum_invoice_url', esc_url_raw( $invoice_url ) );

		edd_insert_payment_note( $payment_id, __( 'Payzum invoice created — awaiting payment.', 'payzum-edd' ) );
		$this->log( 'invoice ' . $pzid . ' created for payment ' . $payment_id . ' (' . $this->render_mode() . ')' );

		edd_empty_cart();

		if ( 'redirect' === $this->render_mode() ) {
			$this->redirect( $invoice_url, true );
		}

		// Modal / inline keep the buyer on this site: bounce to the checkout page, which
		// maybe_render_widget() turns into the widget host for this order.
		$this->redirect(
			add_query_arg( self::RENDER_ARG, rawurlencode( $purchase_key ), edd_get_checkout_uri() ),
			false
		);
	}

	/**
	 * The pricing half of the create-payment body.
	 *
	 * Fiat (default): the buyer picks the coin on the Payzum checkout, so pay_currency is "all"
	 * and Payzum converts from the order currency.
	 *
	 * Crypto: prices are already denominated in a coin, so pricing_mode "direct" sends the total
	 * through unconverted. The API requires price_currency to equal the *bare* pay symbol
	 * (max 8 chars — `usdc`, not `usdcmatic`), with the chain in `network`.
	 *
	 * @param int $payment_id only used for logging a misconfiguration.
	 * @return array{price_currency: string, pay_currency: string, pricing_mode: string, network: ?string}
	 */
	private function pricing_payload( $payment_id ) {
		if ( 'crypto' === edd_get_option( 'payzum_currency_mode', 'fiat' ) ) {
			$symbol  = $this->crypto_symbol();
			$network = strtolower( trim( (string) edd_get_option( 'payzum_crypto_network', '' ) ) );

			if ( '' !== $symbol ) {
				return array(
					'price_currency' => $symbol,
					'pay_currency'   => $symbol,
					'pricing_mode'   => 'direct',
					'network'        => '' !== $network ? $network : null,
				);
			}

			// Misconfigured: fall through to fiat rather than failing the checkout outright.
			$this->log( 'currency_mode=crypto but no crypto_symbol configured — falling back to fiat for payment ' . $payment_id );
		}

		$configured = strtolower( trim( (string) edd_get_option( 'payzum_price_currency', '' ) ) );

		return array(
			// Empty setting = the store's own currency, which is the only always-correct value:
			// the total is sent as-is, never converted.
			'price_currency' => '' !== $configured ? $configured : strtolower( edd_get_currency() ),
			// "all" defers the coin choice to the buyer, limited to the merchant's allowlist.
			'pay_currency'   => self::PAY_CURRENCY_ANY,
			'pricing_mode'   => 'fiat',
			'network'        => null,
		);
	}

	/** An actionable checkout notice for the error codes a buyer can do something about. */
	private function buyer_notice_for( $raw_code ) {
		switch ( $raw_code ) {
			case 'AMOUNT_BELOW_MINIMUM':
				return __( 'This order total is below the minimum for crypto payment. Add more to your cart or choose another payment method.', 'payzum-edd' );
			case 'CURRENCY_NOT_SUPPORTED':
			case 'NO_ELIGIBLE_CURRENCIES':
				return __( 'Crypto payment is not available for this currency or amount. Please choose another payment method.', 'payzum-edd' );
			default:
				return __( 'Unable to start the crypto payment. Please try again or pick another method.', 'payzum-edd' );
		}
	}

	/**
	 * Send the buyer onward, surviving the case where output has already started.
	 *
	 * EDD fires the gateway action on `init`, so headers are normally still open. But a single
	 * stray notice or echo from any other active plugin flips headers_sent(), the Location header
	 * is dropped silently, and the buyer is left on a half-rendered checkout holding an invoice
	 * they never saw — with the cart already emptied. Fall back to a client-side hop rather than
	 * lose the payment to someone else's warning.
	 *
	 * @param string $url      where to send the buyer.
	 * @param bool   $external true for the off-site Payzum checkout, false for a page on this site.
	 */
	private function redirect( $url, $external ) {
		if ( ! headers_sent() ) {
			if ( $external ) {
				// Deliberately wp_redirect() and not wp_safe_redirect()/edd_redirect(): the safe
				// variants reject any off-site host and would send the buyer to wp-admin instead.
				wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
			} else {
				wp_safe_redirect( $url );
			}
			exit;
		}

		$this->log( 'headers already sent — falling back to a client-side redirect to ' . $url );

		printf(
			'<meta http-equiv="refresh" content="0;url=%1$s">'
			. '<script>window.location.replace(%2$s);</script>'
			. '<p><a href="%1$s">%3$s</a></p>',
			esc_url( $url ),
			wp_json_encode( $url ),
			esc_html__( 'Continue to payment', 'payzum-edd' )
		);
		exit;
	}

	/** Sanitised bare coin symbol for crypto mode ("usdc"), or '' when unset. */
	private function crypto_symbol() {
		$symbol = strtolower( trim( (string) edd_get_option( 'payzum_crypto_symbol', '' ) ) );
		$symbol = preg_replace( '/[^a-z0-9]/', '', (string) $symbol );
		return (string) substr( (string) $symbol, 0, 8 );
	}

	/* -------------------------------------------------------------------- modal / inline widget */

	/**
	 * On the checkout page with ?edd-payzum=<purchase_key>, resolve the order and enqueue the
	 * widget. Runs on template_redirect so wp_enqueue_script() still lands in the header.
	 */
	public function maybe_prepare_render() {
		if ( 'redirect' === $this->render_mode() ) {
			return;
		}
		if ( ! isset( $_GET[ self::RENDER_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$key   = sanitize_text_field( wp_unslash( $_GET[ self::RENDER_ARG ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = $key ? edd_get_order_by( 'payment_key', $key ) : false;

		if ( ! $order || self::ID !== $order->gateway ) {
			return;
		}
		if ( '' === (string) edd_get_order_meta( $order->id, '_payzum_payment_id', true ) ) {
			return;
		}

		$this->render_order = $order;

		wp_enqueue_script( 'payzum-widget', self::WIDGET_URL, array(), PAYZUM_EDD_VERSION, true );
		wp_add_inline_script( 'payzum-widget', $this->widget_bootstrap( $order ) );
	}

	/**
	 * Replace the checkout page's content with the widget mount point.
	 *
	 * The cart was emptied when the invoice was created, so the stock [download_checkout] output
	 * would only say "your cart is empty" — dropping it is the point, not a side effect.
	 *
	 * @param string $content
	 * @return string
	 */
	public function maybe_render_widget( $content ) {
		if ( null === $this->render_order || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$order       = $this->render_order;
		$mode        = $this->render_mode();
		$container   = 'payzum-checkout-' . (int) $order->id;
		$invoice_url = (string) edd_get_order_meta( $order->id, '_payzum_invoice_url', true );

		$html = sprintf(
			'<div id="%1$s" class="payzum-checkout" data-mode="%2$s"></div>',
			esc_attr( $container ),
			esc_attr( $mode )
		);

		// Fallback for buyers with JS disabled or a CSP that blocks the widget.
		if ( '' !== $invoice_url ) {
			$html .= sprintf(
				'<noscript><p><a class="edd-submit button" href="%1$s">%2$s</a></p></noscript>',
				esc_url( $invoice_url ),
				esc_html__( 'Pay with crypto', 'payzum-edd' )
			);
		}

		return $html;
	}

	/**
	 * Inline JS that opens the widget once it has loaded.
	 *
	 * The callbacks are UI hints only — they move the buyer to the right page. Whether the order is
	 * actually paid is decided solely by the signed IPN.
	 *
	 * @param object $order EDD order.
	 * @return string
	 */
	private function widget_bootstrap( $order ) {
		$config = array(
			'mode'       => $this->render_mode(),
			'paymentId'  => (string) edd_get_order_meta( $order->id, '_payzum_payment_id', true ),
			'container'  => 'payzum-checkout-' . (int) $order->id,
			'returnUrl'  => $this->success_url( $order->id ),
			'cancelUrl'  => edd_get_checkout_uri(),
			'invoiceUrl' => (string) edd_get_order_meta( $order->id, '_payzum_invoice_url', true ),
		);

		// The widget script is loaded in the footer and may still be parsing; poll briefly rather
		// than assuming window.Payzum exists the moment this runs.
		return '(function(){'
			. 'var cfg=' . wp_json_encode( $config ) . ';'
			. 'var tries=0;'
			. 'function go(){'
			. 'if(!window.Payzum||!window.Payzum.open){'
			. 'if(++tries>100){if(cfg.invoiceUrl){window.location.href=cfg.invoiceUrl;}return;}'
			. 'return window.setTimeout(go,100);'
			. '}'
			. 'var opts={'
			. 'onSuccess:function(){window.location.href=cfg.returnUrl;},'
			. 'onPartial:function(){window.location.href=cfg.returnUrl;},'
			. 'onExpired:function(){window.location.href=cfg.cancelUrl;},'
			. 'onCancel:function(){window.location.href=cfg.cancelUrl;}'
			. '};'
			. 'if(cfg.mode==="inline"){'
			. 'var el=document.getElementById(cfg.container);'
			. 'if(el){window.Payzum.openInline(cfg.paymentId,el,opts);}'
			. '}else{'
			. 'window.Payzum.open(cfg.paymentId,opts);'
			. '}'
			. '}'
			. 'go();'
			. '})();';
	}

	/* --------------------------------------------------------------------------- IPN listener */

	/** Route ?edd-listener=payzum to handle_ipn(). */
	public function maybe_handle_ipn() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['edd-listener'] ) || self::ID !== $_GET['edd-listener'] ) {
			return;
		}
		$this->handle_ipn();
	}

	/**
	 * Handle a signed IPN.
	 *
	 * The SDK's Verifier does the dangerous parts: it reads the correct, fixed signature header
	 * itself (case-insensitively, CGI form included), verifies HMAC-SHA-512 over the RAW bytes in
	 * constant time, and enforces the 10-minute replay window on the signed event_at. On top of
	 * that this handler deduplicates by event id — delivery retries reuse it, so a second
	 * delivery must be a no-op, not a second fulfilment.
	 *
	 * Verification deliberately uses `new Verifier($secret)` and not the Payzum entry class:
	 * verifying an already-paid IPN must not depend on the API key being configured.
	 */
	private function handle_ipn() {
		$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( '' === $raw || false === $raw ) {
			$this->respond( 400, 'empty body' );
		}

		$secret = (string) edd_get_option( 'payzum_webhook_secret', '' );
		if ( '' === $secret ) {
			$this->log( 'IPN received but no webhook secret configured.' );
			$this->respond( 500, 'not configured' );
		}

		$headers = $this->request_headers();

		try {
			$verifier = new Verifier( $secret );
			$data     = $verifier->verifyPaymentIpn( $raw, $headers );
		} catch ( SignatureException $e ) {
			$this->log( 'IPN rejected (' . $e->reason . '): ' . $e->getMessage() );
			$this->respond( 401, 'bad signature' );
			return; // respond() exits; this keeps static analysis honest.
		} catch ( PayzumException $e ) {
			$this->log( 'IPN body unusable: ' . $e->getMessage() );
			$this->respond( 400, 'bad json' );
			return;
		}

		$reference = isset( $data['order_id'] ) ? (string) $data['order_id'] : '';
		$status    = isset( $data['payment_status'] ) ? (string) $data['payment_status'] : '';
		$order_id  = $this->resolve_order_id( $reference );

		if ( ! $order_id ) {
			$this->log( 'IPN for unknown order reference: ' . $reference );
			$this->respond( 404, 'order not found' );
		}

		// Everything from here to the release is one critical section: read the event ids, decide
		// the transition, write it, record the event id. Two deliveries carrying DIFFERENT event
		// ids both used to read "not complete yet" and both completed the payment — two purchase
		// receipts, and the download/licence granted twice. The dedup check belongs inside the
		// lock too: on its own it only catches the same event id, and it is itself a
		// read-then-write.
		$lock = $this->acquire_order_lock( $order_id );
		if ( false === $lock ) {
			// Another delivery for this payment is mid-transition. 503 rather than 200: this IPN
			// is not a duplicate, only late, and Payzum retrying it is the right outcome.
			$this->log( 'IPN for payment ' . $order_id . ' could not take the order lock — asking for a retry' );
			$this->respond( 503, 'busy' );
		}

		// Drop the cached order now that the lock is held: it was read before we waited, so a
		// concurrent delivery may have completed it since and apply_status() would otherwise
		// re-read the same stale copy and complete it a second time.
		wp_cache_delete( $order_id, 'edd_orders' );

		// Retries and multi-transition deliveries reuse the event id; a replay must be a no-op.
		$event_id = (string) $verifier->eventId( $headers );
		if ( '' !== $event_id && $this->is_duplicate_event( $order_id, $event_id ) ) {
			$this->log( 'IPN duplicate event ' . $event_id . ' for payment ' . $order_id . ' — ignored' );
			$this->release_order_lock( $lock );
			$this->respond( 200, 'duplicate' );
		}

		// Once the buyer has picked a coin the IPN carries it — record it for the shop manager.
		if ( ! empty( $data['pay_currency'] ) ) {
			edd_update_order_meta( $order_id, '_payzum_pay_currency', sanitize_text_field( (string) $data['pay_currency'] ) );
		}

		$this->log( 'IPN verified for payment ' . $order_id . ' status=' . $status . ( '' !== $event_id ? ' event=' . $event_id : '' ) );
		$this->apply_status( $order_id, $status, $data );

		if ( '' !== $event_id ) {
			$this->remember_event( $order_id, $event_id );
		}

		$this->release_order_lock( $lock );
		$this->respond( 200, 'ok' );
	}

	/**
	 * Take a cross-request lock for this payment, for the length of the IPN transition.
	 *
	 * GET_LOCK is the only lock WordPress can count on here. wp_cache_add() is request-local
	 * unless a persistent object cache is installed, so it would look like a lock on every store
	 * that has none and protect nothing — the failure mode this guard exists to prevent.
	 *
	 * @param int $order_id
	 * @return string|null|false lock name when acquired; false when it timed out (another
	 *                           delivery holds it); null when the database offers no lock, in
	 *                           which case the caller proceeds unlocked rather than refusing a
	 *                           payment it can still process.
	 */
	private function acquire_order_lock( $order_id ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return null;
		}
		// Namespaced by DB_NAME: several WordPress installs can share one MySQL server, and
		// GET_LOCK names are scoped to the whole MySQL server, not to a database or a connection,
		// so every part of "which install, which site, which plugin, which record" has to be in
		// the name or unrelated stores block each other on a shared server.
		//
		// DB_NAME separates installs. $wpdb->prefix separates the sites of a multisite network
		// and the several WordPress installs people put in one database with different prefixes.
		// The 'edd-order' tag separates THIS plugin from the Payzum plugins for WooCommerce,
		// PMPro and GiveWP: they all key by a small integer id, so without it EDD order 500 and
		// WooCommerce order 500 on the same site hash to the same lock and 503 each other. Hashed
		// to stay inside GET_LOCK's 64-character limit.
		$name = 'payzum_' . substr( md5( DB_NAME . '|' . $wpdb->prefix . '|edd-order|' . $order_id ), 0, 32 );

		// 1 = acquired, 0 = timed out, NULL = error (or a server without GET_LOCK).
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::LOCK_TIMEOUT ) );
		if ( '1' === (string) $got ) {
			return $name;
		}
		if ( null === $got ) {
			$this->log( 'GET_LOCK unavailable — processing IPN for payment ' . $order_id . ' without a lock' );
			return null;
		}
		return false;
	}

	/** Release a lock taken by acquire_order_lock(). Safe to call with null (nothing was taken). */
	private function release_order_lock( $name ) {
		global $wpdb;

		if ( ! is_string( $name ) || '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	/**
	 * Request headers for the Verifier. getallheaders() when the SAPI provides it, otherwise the
	 * raw $_SERVER array — the Verifier accepts the CGI form (HTTP_X_NOWPAYMENTS_SIG) directly.
	 *
	 * @return array<string, string>
	 */
	private function request_headers() {
		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			if ( is_array( $headers ) ) {
				return $headers;
			}
		}
		return array_filter( $_SERVER, 'is_string' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- raw bytes needed for HMAC lookup; never echoed.
	}

	/** Whether this event id was already processed for the order. */
	private function is_duplicate_event( $order_id, $event_id ) {
		$seen = edd_get_order_meta( $order_id, self::EVENT_IDS_META, true );
		return is_array( $seen ) && in_array( $event_id, $seen, true );
	}

	/** Record a processed event id, keeping only the most recent ones. */
	private function remember_event( $order_id, $event_id ) {
		$seen   = edd_get_order_meta( $order_id, self::EVENT_IDS_META, true );
		$seen   = is_array( $seen ) ? $seen : array();
		$seen[] = $event_id;
		edd_update_order_meta( $order_id, self::EVENT_IDS_META, array_slice( $seen, -self::EVENT_IDS_KEEP ) );
	}

	/**
	 * Map a Payzum payment_status onto the EDD order. Idempotent — repeated IPNs for an already
	 * settled order add neither a status change nor a duplicate note.
	 *
	 * The SDK's PaymentStatus models the five values the contract promises (waiting,
	 * partially_paid, finished, expired, failed) and throws on anything else. The guard degrades
	 * an unknown value to an order note instead of a 500: a contract change should surface as a
	 * note to investigate, not as six failed delivery retries.
	 *
	 * @param int    $order_id
	 * @param string $status
	 * @param array  $data     the verified payload — `finished` is only honoured when the amount
	 *                         and currency in it match the order.
	 */
	private function apply_status( $order_id, $status, array $data = array() ) {
		$order = edd_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$current = (string) $order->status;

		try {
			$mapped = PaymentStatus::fromMerchant( $status );
		} catch ( PayzumException $e ) {
			$this->log( 'IPN carried an unknown payment_status "' . $status . '" — contract change?' );
			edd_insert_payment_note(
				$order_id,
				sprintf( /* translators: %s: status */ __( 'Payzum: status update — %s.', 'payzum-edd' ), $status )
			);
			return;
		}

		switch ( $mapped ) {
			case PaymentStatus::Finished:
				if ( 'complete' === $current ) {
					return;
				}
				// `finished` only says the Payzum invoice settled — it says nothing about that
				// invoice having been for THIS order's total. Completing on the status alone
				// delivers the download for an invoice raised for less, or in a cheaper
				// currency. The payload carries both numbers, so check them before delivering.
				$mismatch = $this->settlement_mismatch( $order, $data );
				if ( null !== $mismatch ) {
					$this->log( 'IPN finished for payment ' . $order_id . ' rejected: ' . $mismatch );
					if ( 'on_hold' !== $current ) {
						edd_update_payment_status( $order_id, 'on_hold' );
					}
					edd_insert_payment_note(
						$order_id,
						sprintf(
							/* translators: %s: what did not match, e.g. "it settled 3.00 USD while the order is for 6.00 USD" */
							__( 'Payzum: reported as finished but %s — held for review, NOT completed.', 'payzum-edd' ),
							$mismatch
						)
					);
					return;
				}
				edd_update_payment_status( $order_id, 'complete' );
				edd_insert_payment_note( $order_id, __( 'Payzum: payment confirmed in full (finished).', 'payzum-edd' ) );
				break;

			case PaymentStatus::PartiallyPaid:
				if ( 'on_hold' === $current || 'complete' === $current ) {
					return;
				}
				edd_update_payment_status( $order_id, 'on_hold' );
				edd_insert_payment_note( $order_id, __( 'Payzum: partial payment received — awaiting the remainder.', 'payzum-edd' ) );
				break;

			case PaymentStatus::Expired:
				if ( 'complete' === $current || 'abandoned' === $current ) {
					return;
				}
				// EDD has no "cancelled"; abandoned is its terminal unpaid state.
				edd_update_payment_status( $order_id, 'abandoned' );
				edd_insert_payment_note( $order_id, __( 'Payzum: invoice expired before full payment.', 'payzum-edd' ) );
				break;

			case PaymentStatus::Failed:
				if ( 'complete' === $current || 'failed' === $current ) {
					return;
				}
				edd_update_payment_status( $order_id, 'failed' );
				edd_insert_payment_note( $order_id, __( 'Payzum: payment failed.', 'payzum-edd' ) );
				break;

			case PaymentStatus::Waiting:
			default:
				edd_insert_payment_note(
					$order_id,
					sprintf( /* translators: %s: status */ __( 'Payzum: status update — %s.', 'payzum-edd' ), $mapped->value )
				);
				break;
		}
	}

	/* -------------------------------------------------------------------------------- helpers */

	/**
	 * How the settled amount/currency differ from the order, as a human phrase, or null when they
	 * match.
	 *
	 * Compared against the same two values the create call was built from — the order total, and
	 * pricing_payload()'s price_currency rather than the raw order currency, because in crypto
	 * pricing mode the invoice is denominated in the coin symbol and the order currency would
	 * never match.
	 *
	 * An amount that cannot be read as a number is NOT a pass. price_amount is `required` in the
	 * contract, so null, "", an array or an object means either a contract break or a forged
	 * payload — and in both cases the one number that proves the invoice was raised for this
	 * order is missing. Skipping the comparison there would complete the order unverified, which
	 * is exactly how an attacker turns a 1-cent invoice into a paid order. Unverifiable is
	 * treated as mismatched.
	 *
	 * @param object $order EDD order object
	 * @param array  $data  verified payload
	 * @return string|null
	 */
	private function settlement_mismatch( $order, array $data ) {
		$order_id          = (int) $order->id;
		$expected_amount   = (float) $order->total;
		$pricing           = $this->pricing_payload( $order_id );
		$expected_currency = strtolower( (string) $pricing['price_currency'] );

		$has_amount    = isset( $data['price_amount'] ) && is_numeric( $data['price_amount'] );
		$paid_amount   = $has_amount ? (float) $data['price_amount'] : 0.0;
		$paid_currency = isset( $data['price_currency'] ) ? strtolower( trim( (string) $data['price_currency'] ) ) : '';

		if ( ! $has_amount ) {
			$this->log( 'IPN for payment ' . $order_id . ' carried no usable price_amount — settlement could not be verified, not crediting' );
			return sprintf(
				/* translators: 1: order total, 2: order currency */
				__( 'it reported no readable settled amount, so it cannot be shown to cover the %1$s %2$s this order is for', 'payzum-edd' ),
				number_format( $expected_amount, 2, '.', '' ),
				strtoupper( $expected_currency )
			);
		}

		// Never `==` on floats, and never a strict "must be exact": an overpayment is still a
		// paid order, so only a SHORTFALL beyond half a cent counts.
		if ( ( $expected_amount - $paid_amount ) > self::AMOUNT_TOLERANCE ) {
			return sprintf(
				/* translators: 1: settled amount, 2: settled currency, 3: order total, 4: order currency */
				__( 'it settled %1$s %2$s while the order is for %3$s %4$s', 'payzum-edd' ),
				number_format( $paid_amount, 2, '.', '' ),
				'' !== $paid_currency ? strtoupper( $paid_currency ) : strtoupper( $expected_currency ),
				number_format( $expected_amount, 2, '.', '' ),
				strtoupper( $expected_currency )
			);
		}

		// Case-insensitive: the API echoes the currency in whatever case it stored it.
		if ( '' !== $paid_currency && '' !== $expected_currency && $paid_currency !== $expected_currency ) {
			return sprintf(
				/* translators: 1: settled currency, 2: expected currency */
				__( 'it settled in %1$s while the order was invoiced in %2$s', 'payzum-edd' ),
				strtoupper( $paid_currency ),
				strtoupper( $expected_currency )
			);
		}

		return null;
	}

	/**
	 * A per-merchant-unique order reference. EDD payment ids are unique per store; we suffix the
	 * purchase key so a guessed id can't be spoofed into matching.
	 *
	 * @param int    $payment_id
	 * @param string $purchase_key
	 * @return string
	 */
	private function order_reference( $payment_id, $purchase_key ) {
		return (int) $payment_id . '-' . substr( (string) $purchase_key, -8 );
	}

	/**
	 * Find the EDD payment id from the reference we sent as order_id.
	 *
	 * @param string $reference
	 * @return int 0 when it resolves to nothing.
	 */
	private function resolve_order_id( $reference ) {
		if ( '' === $reference ) {
			return 0;
		}
		// We send "<id>-<last 8 of purchase key>"; the numeric prefix is the EDD payment id.
		$order_id = (int) $reference;
		if ( ! $order_id ) {
			return 0;
		}
		$order = edd_get_order( $order_id );
		if ( ! $order ) {
			return 0;
		}
		// Pre-1.1 invoices sent the bare id, so accept that too rather than orphan them.
		$expected = $this->order_reference( $order_id, (string) $order->payment_key );
		if ( $reference !== $expected && $reference !== (string) $order_id ) {
			return 0;
		}
		return $order_id;
	}

	/** Short human description shown on the Payzum hosted checkout (API caps this at 2000). */
	private function order_description( $payment_id ) {
		$text = sprintf(
			/* translators: 1: order number, 2: store name */
			__( 'Order %1$s at %2$s', 'payzum-edd' ),
			$payment_id,
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
		return mb_substr( $text, 0, 2000 );
	}

	/**
	 * Where the buyer lands after paying. Built with add_query_arg() rather than EDD's own string
	 * concatenation, which produces "?page_id=8?payment-confirmation=..." on plain permalinks.
	 */
	private function success_url( $payment_id ) {
		return add_query_arg(
			array(
				'payment-confirmation' => self::ID,
				'payment-id'           => (int) $payment_id,
			),
			edd_get_success_page_uri()
		);
	}

	/**
	 * The SDK entry point, pointed at the configured environment.
	 *
	 * Built per call rather than cached: the admin can save a new key or switch environment and
	 * the next request must honour it.
	 */
	private function payzum_client() {
		$api_key = trim( (string) edd_get_option( 'payzum_api_key', '' ) );

		return 'staging' === edd_get_option( 'payzum_environment', 'production' )
			? Payzum::sandbox( $api_key )
			: new Payzum( $api_key );
	}

	private function respond( $code, $message ) {
		status_header( $code );
		nocache_headers();
		echo esc_html( $message );
		exit;
	}

	public function log( $message ) {
		if ( ! edd_get_option( 'payzum_debug', false ) ) {
			return;
		}
		if ( function_exists( 'edd_debug_log' ) ) {
			// force=true: our own toggle governs this, not EDD's global debug mode.
			edd_debug_log( '[payzum] ' . $message, true );
		}
	}
}

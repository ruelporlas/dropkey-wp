<?php
/**
 * Frontend product checkout.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Frontend;

use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Gateways\GatewayManager;

defined( 'ABSPATH' ) || exit;

final class ProductCheckout {

	/**
	 * Gateway manager.
	 *
	 * @var GatewayManager
	 */
	private $gateways;

	/**
	 * Constructor.
	 *
	 * @param GatewayManager $gateways Gateway manager.
	 */
	public function __construct( GatewayManager $gateways ) {
		$this->gateways = $gateways;
	}

	/**
	 * Register frontend functionality.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode(
			'dropkey_product',
			array( $this, 'render_product' )
		);
	}

	/**
	 * Render a product checkout.
	 *
	 * Usage:
	 * [dropkey_product id="1"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_product( $atts ) {
		$atts = shortcode_atts(
			array(
				'id' => 0,
			),
			$atts,
			'dropkey_product'
		);

		$product_id = absint( $atts['id'] );

		if ( $product_id <= 0 ) {
			return '<p>' .
				esc_html__(
					'Invalid product.',
					'dropkey-wp'
				) .
			'</p>';
		}

		$products = new ProductRepository( $this->get_wpdb() );
		$plans    = new PlanRepository( $this->get_wpdb() );

		$product = $products->find( $product_id );

		if ( ! $product ) {
			return '<p>' .
				esc_html__(
					'Product not found.',
					'dropkey-wp'
				) .
			'</p>';
		}

		/*
		 * Handle the return from the payment provider before rendering
		 * the normal checkout form. This prevents the customer from
		 * immediately seeing the purchase form again after approval.
		 */
		$checkout_return = $this->get_checkout_return_state(
			$product_id
		);

		if ( ! empty( $checkout_return['is_return'] ) ) {
			return $this->render_checkout_return(
				$product,
				$checkout_return
			);
		}

		$active_plans = $plans->all_by_product(
			$product_id,
			'active'
		);

		if ( empty( $active_plans ) ) {
			return '<p>' .
				esc_html__(
					'There are currently no available plans for this product.',
					'dropkey-wp'
				) .
			'</p>';
		}

		$customer = null;

		if ( is_user_logged_in() ) {
			$customers = new CustomerRepository( $this->get_wpdb() );

			$customer = $customers->find_by_user_id(
				get_current_user_id()
			);
		}

		$available_gateways = $this->get_available_gateways();

		/*
		 * Use the actual WordPress page URL as the checkout return
		 * destination. This remains stable even when PayPal adds its
		 * own query parameters during the approval flow.
		 */
		$return_url = get_permalink();

		if ( ! $return_url ) {
			$return_url = home_url( '/' );
		}

		$return_url = esc_url_raw( $return_url );

		ob_start();
		?>
		<div class="dropkey-product-checkout">

			<div class="dropkey-product-header">
				<h2>
					<?php echo esc_html( $product->get_name() ); ?>
				</h2>

				<?php if ( '' !== trim( $product->get_description() ) ) : ?>
					<div class="dropkey-product-description">
						<?php
						echo wp_kses_post(
							wpautop(
								$product->get_description()
							)
						);
						?>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! is_user_logged_in() ) : ?>

				<p>
					<?php
					echo esc_html__(
						'Please log in before purchasing this product.',
						'dropkey-wp'
					);
					?>
				</p>

			<?php elseif ( ! $customer ) : ?>

				<p>
					<?php
					echo esc_html__(
						'Your customer account is not available. Please contact the site administrator.',
						'dropkey-wp'
					);
					?>
				</p>

			<?php else : ?>

				<form class="dropkey-checkout-form">

					<input
						type="hidden"
						name="product_id"
						value="<?php echo esc_attr( $product->get_id() ); ?>"
					>

					<div class="dropkey-checkout-plans">

						<h3>
							<?php
							echo esc_html__(
								'Choose a plan',
								'dropkey-wp'
							);
							?>
						</h3>

						<?php foreach ( $active_plans as $index => $plan ) : ?>

							<label class="dropkey-plan-option">

								<input
									type="radio"
									name="plan_id"
									value="<?php echo esc_attr( $plan->get_id() ); ?>"
									<?php checked( 0 === $index ); ?>
								>

								<span class="dropkey-plan-name">
									<?php echo esc_html( $plan->get_name() ); ?>
								</span>

								<span class="dropkey-plan-price">
									<?php
									echo esc_html(
										$this->format_plan_price( $plan )
									);
									?>
								</span>

							</label>

						<?php endforeach; ?>

					</div>

					<?php if ( ! empty( $available_gateways ) ) : ?>

						<div class="dropkey-checkout-gateways">

							<h3>
								<?php
								echo esc_html__(
									'Payment method',
									'dropkey-wp'
								);
								?>
							</h3>

							<?php foreach ( $available_gateways as $index => $gateway ) : ?>

								<label class="dropkey-gateway-option">

									<input
										type="radio"
										name="gateway"
										value="<?php echo esc_attr( $gateway->get_id() ); ?>"
										<?php checked( 0 === $index ); ?>
									>

									<span>
										<?php echo esc_html( $gateway->get_name() ); ?>
									</span>

								</label>

							<?php endforeach; ?>

						</div>

						<button
							type="submit"
							class="dropkey-checkout-submit"
						>
							<?php
							echo esc_html__(
								'Continue to payment',
								'dropkey-wp'
							);
							?>
						</button>

						<div
							class="dropkey-checkout-message"
							aria-live="polite"
						></div>

					<?php else : ?>

						<p>
							<?php
							echo esc_html__(
								'No payment methods are currently available.',
								'dropkey-wp'
							);
							?>
						</p>

					<?php endif; ?>

				</form>

			<?php endif; ?>

		</div>

		<?php if ( is_user_logged_in() && $customer && ! empty( $available_gateways ) ) : ?>

			<script>
				(function () {
					const forms = document.querySelectorAll(
						'.dropkey-checkout-form'
					);

					forms.forEach(function (form) {
						if (form.dataset.dropkeyInitialized === '1') {
							return;
						}

						form.dataset.dropkeyInitialized = '1';

						form.addEventListener('submit', function (event) {
							event.preventDefault();

							const submitButton = form.querySelector(
								'.dropkey-checkout-submit'
							);

							const message = form.querySelector(
								'.dropkey-checkout-message'
							);

							const plan = form.querySelector(
								'input[name="plan_id"]:checked'
							);

							const gateway = form.querySelector(
								'input[name="gateway"]:checked'
							);

							const productId = form.querySelector(
								'input[name="product_id"]'
							);

							if (!plan || !gateway || !productId) {
								message.textContent =
									'Please select a plan and payment method.';

								return;
							}

							submitButton.disabled = true;
							message.textContent = 'Preparing checkout…';

							fetch(
								<?php
								echo wp_json_encode(
									esc_url_raw(
										rest_url(
											'dropkey-wp/v1/checkout/subscription'
										)
									)
								);
								?>,
								{
									method: 'POST',
									headers: {
										'Content-Type': 'application/json',
										'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>
									},
									body: JSON.stringify({
										customer_id: <?php echo (int) $customer->get_id(); ?>,
										product_id: parseInt(
											productId.value,
											10
										),
										plan_id: parseInt(
											plan.value,
											10
										),
										gateway: gateway.value,
										return_url: <?php echo wp_json_encode( $return_url ); ?>,
										cancel_url: <?php echo wp_json_encode( $return_url ); ?>
									})
								}
							)
							.then(function (response) {
								return response.json().then(function (data) {
									return {
										ok: response.ok,
										data: data
									};
								});
							})
							.then(function (result) {
								if (
									!result.ok ||
									!result.data ||
									!result.data.success
								) {
									const errorMessage =
										result.data &&
										result.data.message
											? result.data.message
											: 'Unable to create checkout.';

									throw new Error(errorMessage);
								}

								const checkoutData =
									result.data.data || {};

								if (
									checkoutData.approval_url
								) {
									window.location.href =
										checkoutData.approval_url;

									return;
								}

								throw new Error(
									'The payment gateway did not provide a checkout URL.'
								);
							})
							.catch(function (error) {
								message.textContent =
									error.message ||
									'Unable to create checkout.';

								submitButton.disabled = false;
							});
						});
					});
				})();
			</script>

		<?php endif; ?>

		<?php

		return ob_get_clean();
	}

	/**
	 * Determine whether the current request is a checkout return.
	 *
	 * @param int $product_id Product ID.
	 * @return array
	 */
	private function get_checkout_return_state( $product_id ) {
		$subscription_id = '';

		if ( isset( $_GET['subscription_id'] ) ) {
			$subscription_id = sanitize_text_field(
				wp_unslash( $_GET['subscription_id'] )
			);
		}

		/*
		 * PayPal may also return a token parameter. We intentionally do
		 * not use or expose it. The provider subscription ID is the
		 * useful identifier for reconciling the local subscription.
		 */
		$provider_token = '';

		if ( isset( $_GET['token'] ) ) {
			$provider_token = sanitize_text_field(
				wp_unslash( $_GET['token'] )
			);
		}

		$is_cancel = false;

		if (
			isset( $_GET['cancel'] )
			&& '1' === sanitize_text_field(
				wp_unslash( $_GET['cancel'] )
			)
		) {
			$is_cancel = true;
		}

		/*
		 * PayPal's cancel URL can arrive with a token but without a
		 * subscription_id. Treat a token-only return as a possible
		 * cancellation only when the request explicitly indicates one.
		 */
		if ( '' === $subscription_id && ! $is_cancel ) {
			return array(
				'is_return' => false,
			);
		}

		$state = array(
			'is_return'        => true,
			'is_cancel'        => $is_cancel,
			'subscription_id'  => $subscription_id,
			'provider_token'   => $provider_token,
			'local_subscription' => null,
		);

		if ( '' !== $subscription_id ) {
			$subscriptions = new SubscriptionRepository(
				$this->get_wpdb()
			);

			$subscription =
				$subscriptions->find_by_gateway_subscription_id(
					'paypal',
					$subscription_id
				);

			if (
				$subscription
				&& $subscription->get_product_id() === absint( $product_id )
			) {
				$state['local_subscription'] = $subscription;
			}
		}

		return $state;
	}

	/**
	 * Render the post-checkout state.
	 *
	 * @param \DropKeyWP\Domain\Product $product Product entity.
	 * @param array                     $state   Checkout return state.
	 * @return string
	 */
	private function render_checkout_return( $product, array $state ) {
		$subscription = isset( $state['local_subscription'] )
			? $state['local_subscription']
			: null;

		$status = $subscription
			? $subscription->get_status()
			: '';

		$account_url = $this->get_account_url();

		if ( ! empty( $state['is_cancel'] ) ) {
			$title = __(
				'Checkout cancelled',
				'dropkey-wp'
			);

			$message = __(
				'Your payment approval was cancelled. No new DropKey WP subscription was activated from this checkout.',
				'dropkey-wp'
			);

			$notice_class = 'dropkey-checkout-return--cancelled';
		} elseif ( 'active' === $status ) {
			$title = __(
				'Subscription active',
				'dropkey-wp'
			);

			$message = __(
				'Your subscription is active. Your DropKey WP license should now be available in your customer account.',
				'dropkey-wp'
			);

			$notice_class = 'dropkey-checkout-return--success';
		} elseif ( 'cancelled' === $status || 'expired' === $status ) {
			$title = __(
				'Subscription was not activated',
				'dropkey-wp'
			);

			$message = __(
				'The payment provider returned a subscription that is no longer active. Please check your customer account for the current status.',
				'dropkey-wp'
			);

			$notice_class = 'dropkey-checkout-return--cancelled';
		} elseif ( $subscription ) {
			$title = __(
				'Checkout received',
				'dropkey-wp'
			);

			$message = __(
				'Your payment approval was received. DropKey WP is waiting for the payment provider confirmation before activating your subscription and license.',
				'dropkey-wp'
			);

			$notice_class = 'dropkey-checkout-return--pending';
		} else {
			$title = __(
				'Checkout received',
				'dropkey-wp'
			);

			$message = __(
				'Your payment approval was received. We are waiting for the payment provider confirmation to finish setting up your subscription.',
				'dropkey-wp'
			);

			$notice_class = 'dropkey-checkout-return--pending';
		}

		ob_start();
		?>
		<div class="dropkey-product-checkout">

			<div class="dropkey-checkout-return <?php echo esc_attr( $notice_class ); ?>">

				<div class="dropkey-checkout-return-icon" aria-hidden="true">
					<?php if ( 'dropkey-checkout-return--cancelled' === $notice_class ) : ?>
						×
					<?php else : ?>
						✓
					<?php endif; ?>
				</div>

				<div class="dropkey-checkout-return-content">

					<p class="dropkey-checkout-return-eyebrow">
						<?php echo esc_html( $product->get_name() ); ?>
					</p>

					<h2>
						<?php echo esc_html( $title ); ?>
					</h2>

					<p>
						<?php echo esc_html( $message ); ?>
					</p>

					<?php if ( '' !== $status ) : ?>

						<div class="dropkey-checkout-return-status">
							<span>
								<?php
								echo esc_html__(
									'Subscription status',
									'dropkey-wp'
								);
								?>
							</span>

							<strong>
								<?php
								echo esc_html(
									$this->format_subscription_status(
										$status
									)
								);
								?>
							</strong>
						</div>

					<?php endif; ?>

					<?php if ( '' !== $state['subscription_id'] ) : ?>

						<div class="dropkey-checkout-return-reference">

							<span>
								<?php
								echo esc_html__(
									'Subscription reference',
									'dropkey-wp'
								);
								?>
							</span>

							<code>
								<?php echo esc_html( $state['subscription_id'] ); ?>
							</code>

						</div>

					<?php endif; ?>

					<div class="dropkey-checkout-return-actions">

						<?php if ( $account_url ) : ?>

							<a
								class="dropkey-checkout-account-link"
								href="<?php echo esc_url( $account_url ); ?>"
							>
								<?php
								echo esc_html__(
									'View My Account',
									'dropkey-wp'
								);
								?>
							</a>

						<?php endif; ?>

						<a
							class="dropkey-checkout-product-link"
							href="<?php echo esc_url( $this->get_clean_product_url() ); ?>"
						>
							<?php
							echo esc_html__(
								'Return to product',
								'dropkey-wp'
							);
							?>
						</a>

					</div>

				</div>

			</div>

		</div>

		<script>
			(function () {
				try {
					const url = new URL(window.location.href);

					[
						'subscription_id',
						'ba_token',
						'token',
						'PayerID',
						'cancel'
					].forEach(function (parameter) {
						url.searchParams.delete(parameter);
					});

					const cleanUrl =
						url.pathname +
						(url.searchParams.toString()
							? '?' + url.searchParams.toString()
							: '') +
						url.hash;

					window.history.replaceState(
						{},
						document.title,
						cleanUrl
					);
				} catch (error) {
					/*
					 * URL cleanup is cosmetic only. Never prevent the
					 * checkout result from being displayed if the browser
					 * does not support URL manipulation.
					 */
				}
			})();
		</script>

		<style>
			.dropkey-checkout-return {
				display: flex;
				gap: 20px;
				align-items: flex-start;
				padding: 28px;
				margin: 24px 0;
				border: 1px solid #ddd;
				border-radius: 12px;
				background: #fff;
			}

			.dropkey-checkout-return-icon {
				display: flex;
				align-items: center;
				justify-content: center;
				flex: 0 0 44px;
				width: 44px;
				height: 44px;
				border-radius: 50%;
				font-size: 28px;
				line-height: 1;
				font-weight: 600;
			}

			.dropkey-checkout-return--success
				.dropkey-checkout-return-icon {
				background: #e7f6ec;
				color: #217a3b;
			}

			.dropkey-checkout-return--pending
				.dropkey-checkout-return-icon {
				background: #f1ecff;
				color: #6b46c1;
			}

			.dropkey-checkout-return--cancelled
				.dropkey-checkout-return-icon {
				background: #fbeaea;
				color: #b42318;
			}

			.dropkey-checkout-return-content {
				flex: 1;
				min-width: 0;
			}

			.dropkey-checkout-return-eyebrow {
				margin: 0 0 6px;
				font-size: 12px;
				font-weight: 600;
				letter-spacing: .08em;
				text-transform: uppercase;
				opacity: .65;
			}

			.dropkey-checkout-return h2 {
				margin: 0 0 10px;
			}

			.dropkey-checkout-return-content > p:not(
				.dropkey-checkout-return-eyebrow
			) {
				margin: 0 0 18px;
			}

			.dropkey-checkout-return-status,
			.dropkey-checkout-return-reference {
				display: flex;
				gap: 10px;
				align-items: center;
				flex-wrap: wrap;
				margin-top: 10px;
				padding: 12px 14px;
				border-radius: 8px;
				background: #f7f7f7;
			}

			.dropkey-checkout-return-status span,
			.dropkey-checkout-return-reference span {
				font-size: 13px;
				opacity: .7;
			}

			.dropkey-checkout-return-reference code {
				overflow-wrap: anywhere;
			}

			.dropkey-checkout-return-actions {
				display: flex;
				gap: 12px;
				flex-wrap: wrap;
				margin-top: 22px;
			}

			.dropkey-checkout-account-link,
			.dropkey-checkout-product-link {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				min-height: 42px;
				padding: 0 18px;
				border-radius: 8px;
				text-decoration: none;
			}

			.dropkey-checkout-account-link {
				background: #111;
				color: #fff;
			}

			.dropkey-checkout-account-link:hover,
			.dropkey-checkout-account-link:focus {
				color: #fff;
				opacity: .9;
			}

			.dropkey-checkout-product-link {
				border: 1px solid #ddd;
				background: #fff;
				color: inherit;
			}

			@media (max-width: 600px) {
				.dropkey-checkout-return {
					flex-direction: column;
					padding: 22px;
				}

				.dropkey-checkout-return-actions {
					flex-direction: column;
				}

				.dropkey-checkout-account-link,
				.dropkey-checkout-product-link {
					width: 100%;
				}
			}
		</style>

		<?php

		return ob_get_clean();
	}

	/**
	 * Get the customer account URL.
	 *
	 * Looks for a page containing the DropKey account shortcode first.
	 *
	 * @return string
	 */
	private function get_account_url() {
		$page = get_pages(
			array(
				'number'      => 1,
				'post_status' => 'publish',
				's',
			)
		);

		/*
		 * Avoid relying on page search alone. The account page may not
		 * have a predictable title or slug, so inspect published pages
		 * for the shortcode.
		 */
		$pages = get_pages(
			array(
				'post_status' => 'publish',
				'number'      => -1,
			)
		);

		foreach ( $pages as $page ) {
			if (
				has_shortcode(
					(string) $page->post_content,
					'dropkey_account'
				)
			) {
				return get_permalink( $page->ID );
			}
		}

		return '';
	}

	/**
	 * Get the current product URL without checkout return parameters.
	 *
	 * @return string
	 */
	private function get_clean_product_url() {
		$url = get_permalink();

		if ( ! $url ) {
			$url = home_url( '/' );
		}

		return esc_url_raw( $url );
	}

	/**
	 * Format a subscription status for display.
	 *
	 * @param string $status Subscription status.
	 * @return string
	 */
	private function format_subscription_status( $status ) {
		$labels = array(
			'pending'   => __( 'Pending', 'dropkey-wp' ),
			'active'    => __( 'Active', 'dropkey-wp' ),
			'past_due'  => __( 'Past due', 'dropkey-wp' ),
			'suspended' => __( 'Suspended', 'dropkey-wp' ),
			'cancelled' => __( 'Cancelled', 'dropkey-wp' ),
			'expired'   => __( 'Expired', 'dropkey-wp' ),
		);

		return isset( $labels[ $status ] )
			? $labels[ $status ]
			: ucfirst( str_replace( '_', ' ', $status ) );
	}

	/**
	 * Get available payment gateways.
	 *
	 * @return array
	 */
	private function get_available_gateways() {
		$gateways = array();

		$gateway_ids = array(
			'paypal',
		);

		foreach ( $gateway_ids as $gateway_id ) {
			$gateway = $this->gateways->get( $gateway_id );

			if ( ! $gateway ) {
				continue;
			}

			if ( ! $gateway->is_available() ) {
				continue;
			}

			$gateways[] = $gateway;
		}

		return $gateways;
	}

	/**
	 * Format plan price.
	 *
	 * @param \DropKeyWP\Domain\Plan $plan Plan entity.
	 * @return string
	 */
	private function format_plan_price( $plan ) {
		$price    = $plan->get_price();
		$currency = $plan->get_currency();
		$interval = $plan->get_billing_interval();
		$count    = $plan->get_billing_interval_count();

		$interval_label = $interval;

		if ( 1 !== $count ) {
			$interval_label .= 's';
		}

		return sprintf(
			'%s %s / %d %s',
			$currency,
			$price,
			$count,
			$interval_label
		);
	}

	/**
	 * Get WordPress database object.
	 *
	 * @return \wpdb
	 */
	private function get_wpdb() {
		global $wpdb;

		return $wpdb;
	}
}
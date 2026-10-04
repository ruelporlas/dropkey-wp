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

		$wpdb = $this->get_wpdb();

		$products      = new ProductRepository( $wpdb );
		$plans         = new PlanRepository( $wpdb );
		$subscriptions = new SubscriptionRepository( $wpdb );

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
		 * the normal checkout form.
		 */
		$checkout_state = $this->get_checkout_state(
			$subscriptions,
			$product_id
		);

		$active_plans = $plans->all_by_product(
			$product_id,
			'active'
		);

		$customer = null;

		if ( is_user_logged_in() ) {
			$customers = new CustomerRepository( $wpdb );

			$customer = $customers->find_by_user_id(
				get_current_user_id()
			);
		}

		$available_gateways = $this->get_available_gateways();

		$return_url = get_permalink();

		if ( ! $return_url ) {
			$return_url = home_url( '/' );
		}

		$return_url = esc_url_raw( $return_url );

		$account_url = $this->get_account_url();

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

			<?php if ( $checkout_state ) : ?>

				<?php $this->render_checkout_state( $checkout_state, $account_url, $return_url ); ?>

			<?php elseif ( empty( $active_plans ) ) : ?>

				<p>
					<?php
					echo esc_html__(
						'There are currently no available plans for this product.',
						'dropkey-wp'
					);
					?>
				</p>

			<?php elseif ( ! is_user_logged_in() ) : ?>

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

		<?php if ( is_user_logged_in() && $customer && ! empty( $available_gateways ) && ! $checkout_state ) : ?>

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

							if (
								!plan ||
								!gateway ||
								!productId ||
								!submitButton ||
								!message
							) {
								if (message) {
									message.textContent =
										'Please select a plan and payment method.';
								}

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

								if (checkoutData.approval_url) {
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

		<style>
			.dropkey-checkout-result {
				margin: 24px 0;
				padding: 24px;
				border: 1px solid #dcdcde;
				border-radius: 12px;
				background: #fff;
			}

			.dropkey-checkout-result h3 {
				margin: 0 0 10px;
			}

			.dropkey-checkout-result p {
				margin: 0 0 16px;
			}

			.dropkey-checkout-reference {
				margin: 16px 0;
				padding: 12px 14px;
				border-radius: 8px;
				background: #f6f7f7;
				font-size: 14px;
			}

			.dropkey-checkout-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 10px;
			}

			.dropkey-checkout-actions a {
				display: inline-block;
				padding: 10px 16px;
				border-radius: 8px;
				text-decoration: none;
			}

			.dropkey-checkout-primary {
				background: #2271b1;
				color: #fff;
			}

			.dropkey-checkout-secondary {
				border: 1px solid #c3c4c7;
				color: #1d2327;
				background: #fff;
			}

			.dropkey-checkout-status {
				display: inline-block;
				margin-bottom: 14px;
				padding: 5px 10px;
				border-radius: 999px;
				background: #f0f0f1;
				font-size: 13px;
				font-weight: 600;
			}
		</style>

		<?php

		$output = ob_get_clean();

		/*
		 * Remove provider return parameters after the current request has
		 * been rendered. This is cosmetic only and does not affect the
		 * server-side subscription state.
		 */
		if ( $checkout_state ) {
			$output .= $this->get_url_cleanup_script();
		}

		return $output;
	}

	/**
	 * Determine whether the current request is a payment-provider return.
	 *
	 * @param SubscriptionRepository $subscriptions Subscription repository.
	 * @param int                    $product_id    Product ID.
	 * @return array|null
	 */
	private function get_checkout_state(
		$subscriptions,
		$product_id
	) {
		$subscription_id = isset( $_GET['subscription_id'] )
			? sanitize_text_field(
				wp_unslash( $_GET['subscription_id'] )
			)
			: '';

		$cancelled = isset( $_GET['cancel'] )
			&& '1' === sanitize_text_field(
				wp_unslash( $_GET['cancel'] )
			);

		if ( $cancelled ) {
			return array(
				'type' => 'cancelled',
			);
		}

		if ( '' === $subscription_id ) {
			return null;
		}

		$subscription = $subscriptions->find_by_gateway_subscription_id(
			'paypal',
			$subscription_id
		);

		/*
		 * Do not display a subscription belonging to another product if
		 * the provider returns to a product checkout page.
		 */
		if (
			$subscription &&
			(int) $subscription->get_product_id() !== (int) $product_id
		) {
			$subscription = null;
		}

		if ( ! $subscription ) {
			return array(
				'type'            => 'processing',
				'provider_id'     => $subscription_id,
				'subscription'    => null,
			);
		}

		$status = $subscription->get_status();

		if ( 'active' === $status ) {
			return array(
				'type'         => 'active',
				'provider_id'  => $subscription_id,
				'subscription' => $subscription,
			);
		}

		if ( 'cancelled' === $status || 'expired' === $status ) {
			return array(
				'type'         => 'inactive',
				'provider_id'  => $subscription_id,
				'subscription' => $subscription,
			);
		}

		return array(
			'type'         => 'processing',
			'provider_id'  => $subscription_id,
			'subscription' => $subscription,
		);
	}

	/**
	 * Render post-checkout state.
	 *
	 * @param array  $state       Checkout state.
	 * @param string $account_url Account URL.
	 * @param string $return_url  Product URL.
	 * @return void
	 */
	private function render_checkout_state(
		$state,
		$account_url,
		$return_url
	) {
		$type = isset( $state['type'] )
			? $state['type']
			: 'processing';

		$subscription = isset( $state['subscription'] )
			? $state['subscription']
			: null;

		$provider_id = isset( $state['provider_id'] )
			? $state['provider_id']
			: '';

		$title   = '';
		$message = '';
		$status  = '';

		switch ( $type ) {
			case 'active':
				$status  = __( 'Active', 'dropkey-wp' );
				$title   = __( 'Subscription active', 'dropkey-wp' );
				$message = __(
					'Your subscription has been activated. Your license is being made available in your account.',
					'dropkey-wp'
				);
				break;

			case 'cancelled':
				$status  = __( 'Cancelled', 'dropkey-wp' );
				$title   = __( 'Checkout cancelled', 'dropkey-wp' );
				$message = __(
					'No subscription was activated. You can return to this product and try again whenever you are ready.',
					'dropkey-wp'
				);
				break;

			case 'inactive':
				$status = $subscription
					? ucfirst( $subscription->get_status() )
					: __( 'Inactive', 'dropkey-wp' );

				$title   = __( 'Subscription not active', 'dropkey-wp' );
				$message = __(
					'The returned subscription is no longer active. Please check your account for the current subscription status.',
					'dropkey-wp'
				);
				break;

			default:
				$status  = __( 'Processing', 'dropkey-wp' );
				$title   = __( 'Checkout received', 'dropkey-wp' );
				$message = __(
					'Your payment-provider checkout has been received. Subscription confirmation can take a moment while the payment provider and DropKey WP finish processing the subscription.',
					'dropkey-wp'
				);
				break;
		}

		?>
		<div class="dropkey-checkout-result">

			<span class="dropkey-checkout-status">
				<?php echo esc_html( $status ); ?>
			</span>

			<h3>
				<?php echo esc_html( $title ); ?>
			</h3>

			<p>
				<?php echo esc_html( $message ); ?>
			</p>

			<?php if ( $subscription ) : ?>

				<div class="dropkey-checkout-reference">
					<strong>
						<?php
						echo esc_html__(
							'Subscription:',
							'dropkey-wp'
						);
						?>
					</strong>

					<?php echo esc_html( $subscription->get_id() ); ?>
				</div>

			<?php elseif ( '' !== $provider_id ) : ?>

				<div class="dropkey-checkout-reference">
					<strong>
						<?php
						echo esc_html__(
							'Payment reference:',
							'dropkey-wp'
						);
						?>
					</strong>

					<?php echo esc_html( $provider_id ); ?>
				</div>

			<?php endif; ?>

			<div class="dropkey-checkout-actions">

				<?php if ( $account_url ) : ?>

					<a
						class="dropkey-checkout-primary"
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
					class="dropkey-checkout-secondary"
					href="<?php echo esc_url( $return_url ); ?>"
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
		<?php
	}

	/**
	 * Find the customer account page.
	 *
	 * @return string
	 */
	private function get_account_url() {
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
				$url = get_permalink( $page->ID );

				if ( $url ) {
					return esc_url_raw( $url );
				}
			}
		}

		return '';
	}

	/**
	 * Get URL cleanup script.
	 *
	 * @return string
	 */
	private function get_url_cleanup_script() {
		return '<script>
			(function () {
				if (!window.history || !window.history.replaceState) {
					return;
				}

				const url = new URL(window.location.href);
				const parameters = [
					"subscription_id",
					"ba_token",
					"token",
					"PayerID",
					"cancel"
				];

				let changed = false;

				parameters.forEach(function (parameter) {
					if (url.searchParams.has(parameter)) {
						url.searchParams.delete(parameter);
						changed = true;
					}
				});

				if (changed) {
					window.history.replaceState(
						{},
						document.title,
						url.pathname +
							(url.search ? url.search : "") +
							(url.hash ? url.hash : "")
					);
				}
			})();
		</script>';
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
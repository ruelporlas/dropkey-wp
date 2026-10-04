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
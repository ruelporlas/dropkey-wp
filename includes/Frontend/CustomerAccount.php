<?php
/**
 * DropKey WP customer account frontend.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Frontend;

use DropKeyWP\Database\Repositories\ActivationRepository;
use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\Activation;
use DropKeyWP\Domain\License;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

/**
 * Customer-facing account dashboard.
 */
final class CustomerAccount {

	/**
	 * Register frontend functionality.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode(
			'dropkey_account',
			array( $this, 'render_account' )
		);
	}

	/**
	 * Render the customer account dashboard.
	 *
	 * Usage:
	 * [dropkey_account]
	 *
	 * @return string
	 */
	public function render_account() {
		if ( ! is_user_logged_in() ) {
			return $this->render_message(
				__( 'Please log in to view your account.', 'dropkey-wp' ),
				'info'
			);
		}

		$customer = $this->get_customer();

		if ( ! $customer ) {
			return $this->render_message(
				__(
					'Your DropKey customer account is not available yet. Please contact the site administrator.',
					'dropkey-wp'
				),
				'info'
			);
		}

		$subscriptions = $this->get_subscriptions(
			$customer->get_id()
		);

		$licenses = $this->get_licenses(
			$customer->get_id()
		);

		$products = new ProductRepository( $this->get_wpdb() );
		$plans    = new PlanRepository( $this->get_wpdb() );

		ob_start();
		?>
		<div class="dropkey-account">

			<div class="dropkey-account-header">
				<div>
					<span class="dropkey-account-eyebrow">
						<?php
						echo esc_html__(
							'DropKey Account',
							'dropkey-wp'
						);
						?>
					</span>

					<h2>
						<?php
						echo esc_html(
							$this->get_customer_name( $customer )
						);
						?>
					</h2>

					<p>
						<?php echo esc_html( $customer->get_email() ); ?>
					</p>
				</div>
			</div>

			<div class="dropkey-account-summary">

				<div class="dropkey-account-summary-card">
					<span class="dropkey-account-summary-label">
						<?php
						echo esc_html__(
							'Subscriptions',
							'dropkey-wp'
						);
						?>
					</span>

					<strong>
						<?php echo esc_html( count( $subscriptions ) ); ?>
					</strong>
				</div>

				<div class="dropkey-account-summary-card">
					<span class="dropkey-account-summary-label">
						<?php
						echo esc_html__(
							'Licenses',
							'dropkey-wp'
						);
						?>
					</span>

					<strong>
						<?php echo esc_html( count( $licenses ) ); ?>
					</strong>
				</div>

				<div class="dropkey-account-summary-card">
					<span class="dropkey-account-summary-label">
						<?php
						echo esc_html__(
							'Active Licenses',
							'dropkey-wp'
						);
						?>
					</span>

					<strong>
						<?php
						echo esc_html(
							$this->count_licenses_by_status(
								$licenses,
								License::STATUS_ACTIVE
							)
						);
						?>
					</strong>
				</div>

			</div>

			<section class="dropkey-account-section">

				<div class="dropkey-account-section-heading">
					<div>
						<span class="dropkey-account-section-eyebrow">
							<?php
							echo esc_html__(
								'Billing',
								'dropkey-wp'
							);
							?>
						</span>

						<h3>
							<?php
							echo esc_html__(
								'Your subscriptions',
								'dropkey-wp'
							);
							?>
						</h3>
					</div>
				</div>

				<?php if ( empty( $subscriptions ) ) : ?>

					<div class="dropkey-account-empty">
						<p>
							<?php
							echo esc_html__(
								'You do not have any subscriptions yet.',
								'dropkey-wp'
							);
							?>
						</p>
					</div>

				<?php else : ?>

					<div class="dropkey-account-list">

						<?php foreach ( $subscriptions as $subscription ) : ?>

							<?php
							$product = $products->find(
								$subscription->get_product_id()
							);

							$plan = $plans->find(
								$subscription->get_plan_id()
							);
							?>

							<article class="dropkey-account-card">

								<div class="dropkey-account-card-header">

									<div>
										<h4>
											<?php
											echo esc_html(
												$product
													? $product->get_name()
													: sprintf(
														/* translators: %d: product ID. */
														__( 'Product #%d', 'dropkey-wp' ),
														$subscription->get_product_id()
													)
											);
											?>
										</h4>

										<?php if ( $plan ) : ?>

											<p>
												<?php echo esc_html( $plan->get_name() ); ?>
											</p>

										<?php endif; ?>
									</div>

									<?php
									echo $this->render_status_badge(
										$subscription->get_status(),
										'subscription'
									);
									?>

								</div>

								<div class="dropkey-account-details">

									<div>
										<span>
											<?php
											echo esc_html__(
												'Current period',
												'dropkey-wp'
											);
											?>
										</span>

										<strong>
											<?php
											echo esc_html(
												$this->format_date_range(
													$subscription->get_current_period_start(),
													$subscription->get_current_period_end()
												)
											);
											?>
										</strong>
									</div>

									<div>
										<span>
											<?php
											echo esc_html__(
												'Payment method',
												'dropkey-wp'
											);
											?>
										</span>

										<strong>
											<?php
											echo esc_html(
												$this->format_gateway(
													$subscription->get_gateway()
												)
											);
										?>
										</strong>
									</div>

									<div>
										<span>
											<?php
											echo esc_html__(
												'Subscription ID',
												'dropkey-wp'
											);
											?>
										</span>

										<strong>
											#<?php echo esc_html( $subscription->get_id() ); ?>
										</strong>
									</div>

								</div>

								<?php if ( $subscription->get_cancel_at_period_end() ) : ?>

									<div class="dropkey-account-notice">
										<?php
										echo esc_html__(
											'This subscription is scheduled to end at the end of the current billing period.',
											'dropkey-wp'
										);
										?>
									</div>

								<?php endif; ?>

							</article>

						<?php endforeach; ?>

					</div>

				<?php endif; ?>

			</section>

			<section class="dropkey-account-section">

				<div class="dropkey-account-section-heading">
					<div>
						<span class="dropkey-account-section-eyebrow">
							<?php
							echo esc_html__(
								'Access',
								'dropkey-wp'
							);
							?>
						</span>

						<h3>
							<?php
							echo esc_html__(
								'Your licenses',
								'dropkey-wp'
							);
							?>
						</h3>
					</div>
				</div>

				<?php if ( empty( $licenses ) ) : ?>

					<div class="dropkey-account-empty">
						<p>
							<?php
							echo esc_html__(
								'Your licenses will appear here once a subscription becomes active.',
								'dropkey-wp'
							);
							?>
						</p>
					</div>

				<?php else : ?>

					<div class="dropkey-account-list">

						<?php foreach ( $licenses as $license ) : ?>

							<?php
							$product = $products->find(
								$license->get_product_id()
							);

							$activations = $this->get_activations(
								$license->get_id()
							);
							?>

							<article class="dropkey-account-card">

								<div class="dropkey-account-card-header">

									<div>
										<h4>
											<?php
											echo esc_html(
												$product
													? $product->get_name()
													: sprintf(
														/* translators: %d: product ID. */
														__( 'Product #%d', 'dropkey-wp' ),
														$license->get_product_id()
													)
											);
											?>
										</h4>

										<p class="dropkey-account-license-key">
											<?php
											echo esc_html(
												$license->get_license_key()
											);
											?>
										</p>
									</div>

									<?php
									echo $this->render_status_badge(
										$license->get_status(),
										'license'
									);
									?>

								</div>

								<div class="dropkey-account-details">

									<div>
										<span>
											<?php
											echo esc_html__(
												'Expires',
												'dropkey-wp'
											);
											?>
										</span>

										<strong>
											<?php
											echo esc_html(
												$this->format_datetime(
													$license->get_expires_at()
												)
											);
											?>
										</strong>
									</div>

									<div>
										<span>
											<?php
											echo esc_html__(
												'Activations',
												'dropkey-wp'
											);
											?>
										</span>

										<strong>
											<?php
											echo esc_html(
												$this->count_active_activations(
													$activations
												)
											);
											?>
											/
											<?php
											echo esc_html(
												$license->get_activation_limit()
											);
											?>
										</strong>
									</div>

									<div>
										<span>
											<?php
											echo esc_html__(
												'License ID',
												'dropkey-wp'
											);
											?>
										</span>

										<strong>
											#<?php echo esc_html( $license->get_id() ); ?>
										</strong>
									</div>

								</div>

								<?php if ( ! empty( $activations ) ) : ?>

									<div class="dropkey-account-activations">

										<h5>
											<?php
											echo esc_html__(
												'Activations',
												'dropkey-wp'
											);
											?>
										</h5>

										<?php foreach ( $activations as $activation ) : ?>

											<div class="dropkey-account-activation">

												<div>
													<strong>
														<?php
														echo esc_html(
															$activation->get_site_url()
														);
														?>
													</strong>

													<span>
														<?php
														echo esc_html(
															$this->format_datetime(
																$activation->get_activated_at()
															)
														);
														?>
													</span>
												</div>

												<?php
												echo $this->render_status_badge(
													$activation->get_status(),
													'activation'
												);
												?>

											</div>

										<?php endforeach; ?>

									</div>

								<?php endif; ?>

							</article>

						<?php endforeach; ?>

					</div>

				<?php endif; ?>

			</section>

		</div>

		<?php $this->render_styles(); ?>

		<?php

		return ob_get_clean();
	}

	/**
	 * Get current customer.
	 *
	 * @return \DropKeyWP\Domain\Customer|null
	 */
	private function get_customer() {
		$customers = new CustomerRepository(
			$this->get_wpdb()
		);

		return $customers->find_by_user_id(
			get_current_user_id()
		);
	}

	/**
	 * Get customer subscriptions.
	 *
	 * @param int $customer_id Customer ID.
	 * @return Subscription[]
	 */
	private function get_subscriptions( $customer_id ) {
		$subscriptions = new SubscriptionRepository(
			$this->get_wpdb()
		);

		return $subscriptions->all_by_customer(
			$customer_id
		);
	}

	/**
	 * Get customer licenses.
	 *
	 * @param int $customer_id Customer ID.
	 * @return License[]
	 */
	private function get_licenses( $customer_id ) {
		$licenses = new LicenseRepository(
			$this->get_wpdb()
		);

		return $licenses->all_by_customer(
			$customer_id
		);
	}

	/**
	 * Get license activations.
	 *
	 * @param int $license_id License ID.
	 * @return Activation[]
	 */
	private function get_activations( $license_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'dropkey_activations';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE license_id = %d
				ORDER BY id DESC",
				absint( $license_id )
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$activations = array();

		foreach ( $rows as $row ) {
			$activations[] = new Activation( $row );
		}

		return $activations;
	}

	/**
	 * Count active licenses.
	 *
	 * @param License[] $licenses Licenses.
	 * @return int
	 */
	private function count_licenses_by_status( $licenses, $status ) {
		$count = 0;

		foreach ( $licenses as $license ) {
			if ( $status === $license->get_status() ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Count active activations.
	 *
	 * @param Activation[] $activations Activations.
	 * @return int
	 */
	private function count_active_activations( $activations ) {
		$count = 0;

		foreach ( $activations as $activation ) {
			if ( Activation::STATUS_ACTIVE === $activation->get_status() ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Get customer display name.
	 *
	 * @param \DropKeyWP\Domain\Customer $customer Customer.
	 * @return string
	 */
	private function get_customer_name( $customer ) {
		$name = trim(
			$customer->get_first_name() . ' ' .
			$customer->get_last_name()
		);

		if ( '' !== $name ) {
			return $name;
		}

		$user = get_userdata(
			$customer->get_user_id()
		);

		if ( $user && '' !== trim( $user->display_name ) ) {
			return $user->display_name;
		}

		return $customer->get_email();
	}

	/**
	 * Render a status badge.
	 *
	 * @param string $status Status.
	 * @param string $type Entity type.
	 * @return string
	 */
	private function render_status_badge( $status, $type ) {
		$label = $status;

		$labels = array(
			'pending'     => __( 'Pending', 'dropkey-wp' ),
			'active'      => __( 'Active', 'dropkey-wp' ),
			'past_due'    => __( 'Past Due', 'dropkey-wp' ),
			'suspended'   => __( 'Suspended', 'dropkey-wp' ),
			'cancelled'   => __( 'Cancelled', 'dropkey-wp' ),
			'expired'     => __( 'Expired', 'dropkey-wp' ),
			'revoked'     => __( 'Revoked', 'dropkey-wp' ),
			'deactivated' => __( 'Deactivated', 'dropkey-wp' ),
		);

		if ( isset( $labels[ $status ] ) ) {
			$label = $labels[ $status ];
		}

		$class = 'dropkey-account-status';

		if ( 'active' === $status ) {
			$class .= ' is-active';
		} elseif (
			in_array(
				$status,
				array( 'past_due', 'suspended' ),
				true
			)
		) {
			$class .= ' is-warning';
		} elseif (
			in_array(
				$status,
				array( 'cancelled', 'expired', 'revoked', 'deactivated' ),
				true
			)
		) {
			$class .= ' is-inactive';
		}

		return sprintf(
			'<span class="%1$s" data-type="%2$s">%3$s</span>',
			esc_attr( $class ),
			esc_attr( $type ),
			esc_html( $label )
		);
	}

	/**
	 * Format gateway name.
	 *
	 * @param string $gateway Gateway identifier.
	 * @return string
	 */
	private function format_gateway( $gateway ) {
		if ( 'paypal' === strtolower( $gateway ) ) {
			return 'PayPal';
		}

		return ucwords(
			str_replace(
				array( '_', '-' ),
				' ',
				$gateway
			)
		);
	}

	/**
	 * Format date range.
	 *
	 * @param string $start Start datetime.
	 * @param string $end End datetime.
	 * @return string
	 */
	private function format_date_range( $start, $end ) {
		$formatted_start = $this->format_datetime( $start );
		$formatted_end   = $this->format_datetime( $end );

		if (
			'Not set' === $formatted_start &&
			'Not set' === $formatted_end
		) {
			return __( 'Not available', 'dropkey-wp' );
		}

		return sprintf(
			'%1$s – %2$s',
			$formatted_start,
			$formatted_end
		);
	}

	/**
	 * Format UTC datetime in WordPress timezone.
	 *
	 * @param string $datetime UTC datetime.
	 * @return string
	 */
	private function format_datetime( $datetime ) {
		if (
			empty( $datetime )
			|| '0000-00-00 00:00:00' === $datetime
		) {
			return __( 'Not set', 'dropkey-wp' );
		}

		$timestamp = strtotime(
			$datetime . ' UTC'
		);

		if ( false === $timestamp ) {
			return $datetime;
		}

		return wp_date(
			get_option( 'date_format' ),
			$timestamp
		);
	}

	/**
	 * Render a frontend message.
	 *
	 * @param string $message Message.
	 * @param string $type Message type.
	 * @return string
	 */
	private function render_message( $message, $type = 'info' ) {
		ob_start();
		?>
		<div class="dropkey-account dropkey-account-message-wrapper">
			<div class="dropkey-account-message dropkey-account-message-<?php echo esc_attr( $type ); ?>">
				<?php echo esc_html( $message ); ?>
			</div>
		</div>
		<?php

		$this->render_styles();

		return ob_get_clean();
	}

	/**
	 * Render frontend styles.
	 *
	 * @return void
	 */
	private function render_styles() {
		?>
		<style>
			.dropkey-account {
				width: 100%;
				max-width: 1100px;
				margin: 32px auto;
				box-sizing: border-box;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				color: #1d2327;
			}

			.dropkey-account *,
			.dropkey-account *::before,
			.dropkey-account *::after {
				box-sizing: border-box;
			}

			.dropkey-account-header {
				padding: 32px;
				border: 1px solid #e2e4e7;
				border-radius: 14px;
				background: #fff;
				box-shadow: 0 4px 20px rgba(29, 35, 39, .05);
			}

			.dropkey-account-eyebrow,
			.dropkey-account-section-eyebrow {
				display: block;
				margin-bottom: 6px;
				color: #646970;
				font-size: 11px;
				font-weight: 700;
				letter-spacing: .1em;
				text-transform: uppercase;
			}

			.dropkey-account-header h2,
			.dropkey-account-section-heading h3,
			.dropkey-account-card h4 {
				margin: 0;
				color: #1d2327;
			}

			.dropkey-account-header h2 {
				font-size: 28px;
				line-height: 1.2;
			}

			.dropkey-account-header p {
				margin: 7px 0 0;
				color: #646970;
			}

			.dropkey-account-summary {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				gap: 16px;
				margin-top: 16px;
			}

			.dropkey-account-summary-card {
				padding: 22px;
				border: 1px solid #e2e4e7;
				border-radius: 12px;
				background: #fff;
			}

			.dropkey-account-summary-label {
				display: block;
				margin-bottom: 8px;
				color: #646970;
				font-size: 13px;
			}

			.dropkey-account-summary-card strong {
				font-size: 28px;
				line-height: 1;
			}

			.dropkey-account-section {
				margin-top: 38px;
			}

			.dropkey-account-section-heading {
				margin-bottom: 14px;
			}

			.dropkey-account-section-heading h3 {
				font-size: 21px;
			}

			.dropkey-account-list {
				display: grid;
				gap: 16px;
			}

			.dropkey-account-card {
				padding: 24px;
				border: 1px solid #e2e4e7;
				border-radius: 12px;
				background: #fff;
				box-shadow: 0 2px 12px rgba(29, 35, 39, .04);
			}

			.dropkey-account-card-header {
				display: flex;
				align-items: flex-start;
				justify-content: space-between;
				gap: 20px;
			}

			.dropkey-account-card h4 {
				font-size: 18px;
				line-height: 1.35;
			}

			.dropkey-account-card-header p {
				margin: 5px 0 0;
				color: #646970;
			}

			.dropkey-account-details {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				gap: 18px;
				margin-top: 22px;
				padding-top: 20px;
				border-top: 1px solid #f0f0f1;
			}

			.dropkey-account-details span,
			.dropkey-account-activation span {
				display: block;
				margin-bottom: 5px;
				color: #646970;
				font-size: 12px;
			}

			.dropkey-account-details strong {
				display: block;
				font-size: 14px;
				line-height: 1.4;
			}

			.dropkey-account-status {
				display: inline-flex;
				align-items: center;
				padding: 5px 9px;
				border-radius: 999px;
				background: #f0f0f1;
				color: #50575e;
				font-size: 12px;
				font-weight: 700;
				line-height: 1;
				white-space: nowrap;
			}

			.dropkey-account-status.is-active {
				background: #edfaef;
				color: #18752a;
			}

			.dropkey-account-status.is-warning {
				background: #fff8e5;
				color: #8a6116;
			}

			.dropkey-account-status.is-inactive {
				background: #f6f7f7;
				color: #646970;
			}

			.dropkey-account-license-key {
				font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
				font-size: 13px !important;
				letter-spacing: .02em;
			}

			.dropkey-account-activations {
				margin-top: 22px;
				padding-top: 20px;
				border-top: 1px solid #f0f0f1;
			}

			.dropkey-account-activations h5 {
				margin: 0 0 10px;
				font-size: 13px;
			}

			.dropkey-account-activation {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 16px;
				padding: 12px 0;
				border-top: 1px solid #f0f0f1;
			}

			.dropkey-account-activation:first-of-type {
				border-top: 0;
			}

			.dropkey-account-activation strong {
				display: block;
				max-width: 100%;
				overflow-wrap: anywhere;
				font-size: 13px;
			}

			.dropkey-account-empty,
			.dropkey-account-message {
				padding: 22px;
				border: 1px solid #e2e4e7;
				border-radius: 12px;
				background: #fff;
			}

			.dropkey-account-empty p,
			.dropkey-account-message {
				margin: 0;
				color: #646970;
			}

			.dropkey-account-notice {
				margin-top: 20px;
				padding: 12px 14px;
				border-radius: 8px;
				background: #fff8e5;
				color: #6f4e00;
				font-size: 13px;
				line-height: 1.5;
			}

			@media (max-width: 700px) {
				.dropkey-account {
					margin: 20px auto;
				}

				.dropkey-account-header,
				.dropkey-account-card {
					padding: 20px;
				}

				.dropkey-account-summary,
				.dropkey-account-details {
					grid-template-columns: 1fr;
				}

				.dropkey-account-card-header,
				.dropkey-account-activation {
					align-items: flex-start;
					flex-direction: column;
				}
			}
		</style>
		<?php
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
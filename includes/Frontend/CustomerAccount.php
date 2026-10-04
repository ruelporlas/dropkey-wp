<?php
/**
 * DropKey WP customer account frontend.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Frontend;

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

		$products = new ProductRepository(
			$this->get_wpdb()
		);

		$plans = new PlanRepository(
			$this->get_wpdb()
		);

		$rest_url = rest_url(
			'dropkey-wp/v1/license/activate'
		);

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
														__(
															'Product #%d',
															'dropkey-wp'
														),
														$subscription->get_product_id()
													)
											);
											?>
										</h4>

										<?php if ( $plan ) : ?>

											<p>
												<?php
												echo esc_html(
													$plan->get_name()
												);
												?>
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

						<p>
							<?php
							echo esc_html__(
								'Manage the sites where your licensed product is activated.',
								'dropkey-wp'
							);
							?>
						</p>
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

							$active_activation_count =
								$this->count_active_activations(
									$activations
								);

							$activation_available =
								License::STATUS_ACTIVE === $license->get_status()
								&& $active_activation_count < $license->get_activation_limit()
								&& $this->license_is_current(
									$license->get_expires_at()
								);
							?>

							<article
								class="dropkey-account-card"
								data-license-card="<?php echo esc_attr( $license->get_id() ); ?>"
							>

								<div class="dropkey-account-card-header">

									<div>
										<h4>
											<?php
											echo esc_html(
												$product
													? $product->get_name()
													: sprintf(
														/* translators: %d: product ID. */
														__(
															'Product #%d',
															'dropkey-wp'
														),
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
											<span class="dropkey-account-activation-count">
												<?php
												echo esc_html(
													$active_activation_count
												);
												?>
											</span>
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

								<?php if ( $activation_available ) : ?>

									<div class="dropkey-account-activate">

										<div class="dropkey-account-activate-heading">
											<h5>
												<?php
												echo esc_html__(
													'Activate another site',
													'dropkey-wp'
												);
												?>
											</h5>

											<p>
												<?php
												echo esc_html__(
													'Enter the full URL of the WordPress site where you want to use this license.',
													'dropkey-wp'
												);
												?>
											</p>
										</div>

										<form
											class="dropkey-account-activation-form"
											data-license-id="<?php echo esc_attr( $license->get_id() ); ?>"
											data-license-key="<?php echo esc_attr( $license->get_license_key() ); ?>"
											data-product="<?php echo esc_attr( $product ? $product->get_slug() : '' ); ?>"
											data-endpoint="<?php echo esc_url( $rest_url ); ?>"
										>

											<div class="dropkey-account-form-row">

												<label>
													<span>
														<?php
														echo esc_html__(
															'Site URL',
															'dropkey-wp'
														);
														?>
													</span>

													<input
														type="url"
														name="site_url"
														placeholder="https://example.com"
														required
													>
												</label>

												<button
													type="submit"
													class="dropkey-account-button"
												>
													<?php
													echo esc_html__(
														'Activate Site',
														'dropkey-wp'
													);
													?>
												</button>

											</div>

											<div
												class="dropkey-account-form-message"
												hidden
											></div>

										</form>

									</div>

								<?php elseif ( License::STATUS_ACTIVE !== $license->get_status() ) : ?>

									<div class="dropkey-account-notice">
										<?php
										echo esc_html__(
											'This license is not currently available for activation.',
											'dropkey-wp'
										);
										?>
									</div>

								<?php elseif ( ! $this->license_is_current( $license->get_expires_at() ) ) : ?>

									<div class="dropkey-account-notice">
										<?php
										echo esc_html__(
											'This license has expired and cannot be activated.',
											'dropkey-wp'
										);
										?>
									</div>

								<?php else : ?>

									<div class="dropkey-account-notice">
										<?php
										echo esc_html__(
											'The activation limit for this license has been reached. Deactivate an existing site before activating another.',
											'dropkey-wp'
										);
										?>
									</div>

								<?php endif; ?>

								<div
									class="dropkey-account-token-result"
									data-token-result="<?php echo esc_attr( $license->get_id() ); ?>"
									hidden
								>
									<div class="dropkey-account-token-result-heading">
										<strong>
											<?php
											echo esc_html__(
												'Activation successful',
												'dropkey-wp'
											);
											?>
										</strong>

										<span>
											<?php
											echo esc_html__(
												'Save this API token now. It will not be displayed again.',
												'dropkey-wp'
											);
											?>
										</span>
									</div>

									<div class="dropkey-account-token-row">
										<code data-api-token></code>

										<button
											type="button"
											class="dropkey-account-copy-button"
											data-copy-token
										>
											<?php
											echo esc_html__(
												'Copy',
												'dropkey-wp'
											);
											?>
										</button>
									</div>
								</div>

							</article>

						<?php endforeach; ?>

					</div>

				<?php endif; ?>

			</section>

		</div>

		<?php

		$this->render_scripts();
		$this->render_styles();

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
	 * @param string    $status   Status.
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
	 * Determine whether a license is currently within its entitlement period.
	 *
	 * @param string $expires_at Expiration timestamp.
	 * @return bool
	 */
	private function license_is_current( $expires_at ) {
		if ( '' === $expires_at ) {
			return true;
		}

		$timestamp = strtotime(
			$expires_at . ' UTC'
		);

		if ( false === $timestamp ) {
			return true;
		}

		return $timestamp >= current_time(
			'timestamp',
			true
		);
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

		if (
			$user
			&& '' !== trim( $user->display_name )
		) {
			return $user->display_name;
		}

		return $customer->get_email();
	}

	/**
	 * Render a status badge.
	 *
	 * @param string $status Status.
	 * @param string $type   Entity type.
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
				array(
					'past_due',
					'suspended',
				),
				true
			)
		) {
			$class .= ' is-warning';
		} elseif (
			in_array(
				$status,
				array(
					'cancelled',
					'expired',
					'revoked',
					'deactivated',
				),
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
				array(
					'_',
					'-',
				),
				' ',
				$gateway
			)
		);
	}

	/**
	 * Format date range.
	 *
	 * @param string $start Start datetime.
	 * @param string $end   End datetime.
	 * @return string
	 */
	private function format_date_range( $start, $end ) {
		$formatted_start = $this->format_datetime( $start );
		$formatted_end   = $this->format_datetime( $end );

		if (
			'Not set' === $formatted_start
			&& 'Not set' === $formatted_end
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
	 * @param string $type    Message type.
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
	 * Render activation JavaScript.
	 *
	 * @return void
	 */
	private function render_scripts() {
		?>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				var forms = document.querySelectorAll(
					'.dropkey-account-activation-form'
				);

				forms.forEach(function (form) {
					form.addEventListener('submit', function (event) {
						event.preventDefault();

						var button = form.querySelector(
							'button[type="submit"]'
						);

						var input = form.querySelector(
							'input[name="site_url"]'
						);

						var message = form.querySelector(
							'.dropkey-account-form-message'
						);

						var endpoint = form.getAttribute(
							'data-endpoint'
						);

						var licenseKey = form.getAttribute(
							'data-license-key'
						);

						var product = form.getAttribute(
							'data-product'
						);

						var licenseId = form.getAttribute(
							'data-license-id'
						);

						var card = form.closest(
							'[data-license-card]'
						);

						var tokenResult = card
							? card.querySelector(
								'[data-token-result="' +
								licenseId +
								'"]'
							)
							: null;

						if (
							! input
							|| ''
								=== input.value.trim()
						) {
							showMessage(
								message,
								'Please enter a site URL.',
								true
							);

							return;
						}

						button.disabled = true;
						button.classList.add('is-loading');

						showMessage(
							message,
							'Activating site…',
							false
						);

						fetch(
							endpoint,
							{
								method: 'POST',
								headers: {
									'Content-Type':
										'application/json'
								},
								body: JSON.stringify(
									{
										license_key:
											licenseKey,
										product:
											product,
										site_url:
											input.value.trim()
									}
								)
							}
						)
							.then(function (response) {
								return response
									.json()
									.then(function (data) {
										return {
											ok:
												response.ok,
											data:
												data
										};
									});
							})
							.then(function (result) {
								if (! result.ok) {
									throw new Error(
										getErrorMessage(
											result.data
										)
									);
								}

								var data = result.data;

								if (
									tokenResult
									&& data.api_token
								) {
									var token =
										tokenResult.querySelector(
											'[data-api-token]'
										);

									if (token) {
										token.textContent =
											data.api_token;
									}

									tokenResult.hidden = false;
								}

								showMessage(
									message,
									'Site activated successfully.',
									false
								);

								input.value = '';

								updateActivationCount(
									card
								);

								button.disabled = true;
								button.classList.remove(
									'is-loading'
								);

								if (
									data.activation
									&& data.activation.site_url
								) {
									appendActivation(
										card,
										data.activation
									);
								}
							})
							.catch(function (error) {
								showMessage(
									message,
									error.message ||
										'The activation could not be completed.',
									true
								);

								button.disabled = false;
								button.classList.remove(
									'is-loading'
								);
							});
					});
				});

				var copyButtons = document.querySelectorAll(
					'[data-copy-token]'
				);

				copyButtons.forEach(function (button) {
					button.addEventListener(
						'click',
						function () {
							var container =
								button.closest(
									'.dropkey-account-token-result'
								);

							var token =
								container
									? container.querySelector(
										'[data-api-token]'
									)
									: null;

							if (
								! token
								|| ''
									=== token.textContent
							) {
								return;
							}

							if (
								navigator.clipboard
								&& navigator.clipboard.writeText
							) {
								navigator.clipboard
									.writeText(
										token.textContent
									)
									.then(function () {
										button.textContent =
											'Copied';

										setTimeout(
											function () {
												button.textContent =
													'Copy';
											},
											1800
										);
									});
							}
						}
					);
				});

				function showMessage(
					element,
					text,
					isError
				) {
					if (! element) {
						return;
					}

					element.textContent = text;
					element.hidden = false;

					element.classList.toggle(
						'is-error',
						!! isError
					);

					element.classList.toggle(
						'is-success',
						! isError
					);
				}

				function getErrorMessage(data) {
					if (
						data
						&& data.message
					) {
						return data.message;
					}

					return 'The activation could not be completed.';
				}

				function updateActivationCount(card) {
					if (! card) {
						return;
					}

					var count =
						card.querySelector(
							'.dropkey-account-activation-count'
						);

					if (! count) {
						return;
					}

					var current =
						parseInt(
							count.textContent,
							10
						);

					if (
						Number.isNaN(current)
					) {
						return;
					}

					count.textContent =
						String(current + 1);
				}

				function appendActivation(
					card,
					activation
				) {
					if (! card) {
						return;
					}

					var container =
						card.querySelector(
							'.dropkey-account-activations'
						);

					if (! container) {
						container =
							document.createElement(
								'div'
							);

						container.className =
							'dropkey-account-activations';

						var heading =
							document.createElement(
								'h5'
							);

						heading.textContent =
							'Activations';

						container.appendChild(
							heading
						);

						var activateSection =
							card.querySelector(
								'.dropkey-account-activate'
							);

						if (activateSection) {
							card.insertBefore(
								container,
								activateSection
							);
						} else {
							card.appendChild(
								container
							);
						}
					}

					var row =
						document.createElement(
							'div'
						);

					row.className =
						'dropkey-account-activation';

					var details =
						document.createElement(
							'div'
						);

					var site =
						document.createElement(
							'strong'
						);

					site.textContent =
						activation.site_url || '';

					var date =
						document.createElement(
							'span'
						);

					date.textContent =
						'Just now';

					details.appendChild(site);
					details.appendChild(date);

					var status =
						document.createElement(
							'span'
						);

					status.className =
						'dropkey-account-status is-active';

					status.setAttribute(
						'data-type',
						'activation'
					);

					status.textContent =
						'Active';

					row.appendChild(details);
					row.appendChild(status);

					container.appendChild(row);
				}
			});
		</script>
		<?php
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

			.dropkey-account-section-heading p {
				margin: 6px 0 0;
				color: #646970;
				font-size: 14px;
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

			.dropkey-account-activations h5,
			.dropkey-account-activate-heading h5 {
				margin: 0 0 8px;
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

			.dropkey-account-activate {
				margin-top: 22px;
				padding-top: 20px;
				border-top: 1px solid #f0f0f1;
			}

			.dropkey-account-activate-heading p {
				margin: 0 0 14px;
				color: #646970;
				font-size: 13px;
				line-height: 1.5;
			}

			.dropkey-account-form-row {
				display: flex;
				align-items: flex-end;
				gap: 10px;
			}

			.dropkey-account-form-row label {
				flex: 1 1 auto;
			}

			.dropkey-account-form-row label > span {
				display: block;
				margin-bottom: 6px;
				color: #50575e;
				font-size: 12px;
				font-weight: 600;
			}

			.dropkey-account-form-row input {
				display: block;
				width: 100%;
				min-height: 42px;
				padding: 9px 11px;
				border: 1px solid #8c8f94;
				border-radius: 6px;
				background: #fff;
				color: #1d2327;
				font-size: 14px;
				box-shadow: inset 0 1px 2px rgba(0, 0, 0, .05);
			}

			.dropkey-account-form-row input:focus {
				border-color: #2271b1;
				outline: 2px solid rgba(34, 113, 177, .15);
				outline-offset: 0;
			}

			.dropkey-account-button,
			.dropkey-account-copy-button {
				min-height: 42px;
				padding: 9px 15px;
				border: 1px solid #2271b1;
				border-radius: 6px;
				background: #2271b1;
				color: #fff;
				font-size: 13px;
				font-weight: 600;
				line-height: 1.3;
				cursor: pointer;
				white-space: nowrap;
			}

			.dropkey-account-button:hover,
			.dropkey-account-copy-button:hover {
				background: #135e96;
				border-color: #135e96;
			}

			.dropkey-account-button:disabled {
				opacity: .65;
				cursor: wait;
			}

			.dropkey-account-form-message {
				margin-top: 10px;
				padding: 10px 12px;
				border-radius: 7px;
				font-size: 13px;
				line-height: 1.5;
			}

			.dropkey-account-form-message.is-success {
				background: #edfaef;
				color: #18752a;
			}

			.dropkey-account-form-message.is-error {
				background: #fcf0f1;
				color: #b32d2e;
			}

			.dropkey-account-token-result {
				margin-top: 18px;
				padding: 16px;
				border: 1px solid #c3e6cb;
				border-radius: 9px;
				background: #f0fff4;
			}

			.dropkey-account-token-result-heading strong,
			.dropkey-account-token-result-heading span {
				display: block;
			}

			.dropkey-account-token-result-heading strong {
				margin-bottom: 4px;
				color: #18752a;
				font-size: 14px;
			}

			.dropkey-account-token-result-heading span {
				color: #50575e;
				font-size: 12px;
			}

			.dropkey-account-token-row {
				display: flex;
				align-items: center;
				gap: 8px;
				margin-top: 12px;
			}

			.dropkey-account-token-row code {
				flex: 1 1 auto;
				min-width: 0;
				padding: 10px 12px;
				border: 1px solid #dcdcde;
				border-radius: 6px;
				background: #fff;
				color: #1d2327;
				font-size: 12px;
				overflow-wrap: anywhere;
			}

			.dropkey-account-copy-button {
				flex: 0 0 auto;
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

				.dropkey-account-form-row {
					align-items: stretch;
					flex-direction: column;
				}

				.dropkey-account-token-row {
					align-items: stretch;
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
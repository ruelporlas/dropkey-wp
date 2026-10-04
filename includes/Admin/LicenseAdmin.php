<?php
/**
 * DropKey WP license administration.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Admin;

use DropKeyWP\Database\Repositories\ActivationRepository;
use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Database\Repositories\LicenseRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Domain\License;

defined( 'ABSPATH' ) || exit;

final class LicenseAdmin {

	/**
	 * License repository.
	 *
	 * @var LicenseRepository
	 */
	private $licenses;

	/**
	 * Activation repository.
	 *
	 * @var ActivationRepository
	 */
	private $activations;

	/**
	 * Customer repository.
	 *
	 * @var CustomerRepository
	 */
	private $customers;

	/**
	 * Product repository.
	 *
	 * @var ProductRepository
	 */
	private $products;

	/**
	 * Constructor.
	 *
	 * @param LicenseRepository    $licenses    License repository.
	 * @param ActivationRepository $activations Activation repository.
	 * @param CustomerRepository  $customers   Customer repository.
	 * @param ProductRepository   $products    Product repository.
	 */
	public function __construct(
		LicenseRepository $licenses,
		ActivationRepository $activations,
		CustomerRepository $customers,
		ProductRepository $products
	) {
		$this->licenses    = $licenses;
		$this->activations = $activations;
		$this->customers   = $customers;
		$this->products    = $products;
	}

	/**
	 * Register administration hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action(
			'admin_menu',
			array( $this, 'register_menu' )
		);

		add_action(
			'admin_post_dropkey_wp_revoke_license',
			array( $this, 'handle_revoke_license' )
		);
	}

	/**
	 * Register license admin menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'dropkey-wp-products',
			__( 'Licenses', 'dropkey-wp' ),
			__( 'Licenses', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-licenses',
			array( $this, 'render_licenses_page' )
		);
	}

	/**
	 * Render licenses page.
	 *
	 * @return void
	 */
	public function render_licenses_page() {
		$this->ensure_capability();

		$status = isset( $_GET['status'] )
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: '';

		if ( '' !== $status && ! $this->is_valid_status( $status ) ) {
			$status = '';
		}

		$licenses = $this->get_licenses( $status );

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php echo esc_html__( 'Licenses', 'dropkey-wp' ); ?>
			</h1>

			<hr class="wp-header-end" />

			<?php $this->render_notices(); ?>

			<?php $this->render_status_filters( $status ); ?>

			<?php if ( empty( $licenses ) ) : ?>

				<div class="notice notice-info inline">
					<p>
						<?php
						if ( '' === $status ) {
							echo esc_html__(
								'No licenses have been created yet. Licenses are created automatically when a subscription becomes active.',
								'dropkey-wp'
							);
						} else {
							echo esc_html__(
								'No licenses match this status filter.',
								'dropkey-wp'
							);
						}
						?>
					</p>
				</div>

			<?php else : ?>

				<div class="dropkey-wp-license-table">
					<table class="widefat fixed striped">
						<thead>
							<tr>
								<th scope="col" class="column-license">
									<?php echo esc_html__( 'License', 'dropkey-wp' ); ?>
								</th>

								<th scope="col" class="column-customer">
									<?php echo esc_html__( 'Customer', 'dropkey-wp' ); ?>
								</th>

								<th scope="col" class="column-product">
									<?php echo esc_html__( 'Product', 'dropkey-wp' ); ?>
								</th>

								<th scope="col" class="column-subscription">
									<?php echo esc_html__( 'Subscription', 'dropkey-wp' ); ?>
								</th>

								<th scope="col" class="column-status">
									<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
								</th>

								<th scope="col" class="column-activations">
									<?php echo esc_html__( 'Activations', 'dropkey-wp' ); ?>
								</th>

								<th scope="col" class="column-expires">
									<?php echo esc_html__( 'Expires', 'dropkey-wp' ); ?>
								</th>

								<th scope="col" class="column-actions">
									<?php echo esc_html__( 'Actions', 'dropkey-wp' ); ?>
								</th>
							</tr>
						</thead>

						<tbody>
							<?php foreach ( $licenses as $license ) : ?>

								<?php
								$customer = $this->customers->find(
									$license->get_customer_id()
								);

								$product = $this->products->find(
									$license->get_product_id()
								);

								$activation_count =
									$this->activations->count_active_by_license(
										$license->get_id()
									);
								?>

								<tr>
									<td>
										<strong>
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: license ID. */
													__( 'License #%d', 'dropkey-wp' ),
													$license->get_id()
												)
											);
											?>
										</strong>

										<code class="dropkey-wp-license-key">
											<?php
											echo esc_html(
												$license->get_license_key()
											);
											?>
										</code>
									</td>

									<td>
										<?php if ( $customer ) : ?>

											<strong>
												<?php
												echo esc_html(
													$this->get_customer_name(
														$customer
													)
												);
												?>
											</strong>

											<span class="dropkey-wp-secondary-text">
												<?php
												echo esc_html(
													$customer->get_email()
												);
												?>
											</span>

										<?php else : ?>

											<span class="dropkey-wp-secondary-text">
												<?php
												echo esc_html__(
													'Customer not found',
													'dropkey-wp'
												);
												?>
											</span>

										<?php endif; ?>
									</td>

									<td>
										<?php if ( $product ) : ?>

											<strong>
												<?php
												echo esc_html(
													$product->get_name()
												);
												?>
											</strong>

											<span class="dropkey-wp-secondary-text">
												<?php
												echo esc_html(
													$product->get_slug()
												);
												?>
											</span>

										<?php else : ?>

											<span class="dropkey-wp-secondary-text">
												<?php
												echo esc_html__(
													'Product not found',
													'dropkey-wp'
												);
												?>
											</span>

										<?php endif; ?>
									</td>

									<td>
										<strong>
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: subscription ID. */
													__( '#%d', 'dropkey-wp' ),
													$license->get_subscription_id()
												)
											);
											?>
										</strong>

										<span class="dropkey-wp-secondary-text">
											<?php
											echo esc_html__(
												'Subscription',
												'dropkey-wp'
											);
											?>
										</span>
									</td>

									<td>
										<span
											class="<?php echo esc_attr(
												$this->get_status_class(
													$license->get_status()
												)
											); ?>"
										>
											<?php
											echo esc_html(
												$this->get_status_label(
													$license->get_status()
												)
											);
											?>
										</span>
									</td>

									<td>
										<strong>
											<?php
											echo esc_html(
												sprintf(
													/* translators: 1: active activation count, 2: activation limit. */
													__( '%1$d / %2$d', 'dropkey-wp' ),
													$activation_count,
													$license->get_activation_limit()
												)
											);
											?>
										</strong>

										<span class="dropkey-wp-secondary-text">
											<?php
											echo esc_html__(
												'Active activations',
												'dropkey-wp'
											);
											?>
										</span>
									</td>

									<td>
										<?php
										echo esc_html(
											$this->format_datetime(
												$license->get_expires_at()
											)
										);
										?>
									</td>

									<td>
										<?php if ( License::STATUS_REVOKED !== $license->get_status() ) : ?>

											<a
												href="<?php echo esc_url(
													$this->get_revoke_url(
														$license->get_id()
													)
												); ?>"
												class="dropkey-wp-revoke-link"
												data-confirm="<?php echo esc_attr__(
													'Are you sure you want to revoke this license? This will permanently prevent the license from being used again.',
													'dropkey-wp'
												); ?>"
											>
												<?php echo esc_html__( 'Revoke', 'dropkey-wp' ); ?>
											</a>

										<?php else : ?>

											<span class="dropkey-wp-secondary-text">
												<?php echo esc_html__( 'Revoked', 'dropkey-wp' ); ?>
											</span>

										<?php endif; ?>
									</td>
								</tr>

							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

			<?php endif; ?>
		</div>

		<?php $this->render_styles(); ?>
		<?php
	}

	/**
	 * Get licenses.
	 *
	 * @param string $status Status filter.
	 * @return License[]
	 */
	private function get_licenses( $status ) {
		global $wpdb;

		if ( '' === $status ) {
			$rows = $wpdb->get_results(
				"SELECT * FROM {$wpdb->prefix}dropkey_licenses ORDER BY id DESC",
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}dropkey_licenses
					WHERE status = %s
					ORDER BY id DESC",
					$status
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$licenses = array();

		foreach ( $rows as $row ) {
			$licenses[] = new License( $row );
		}

		return $licenses;
	}

	/**
	 * Handle license revocation.
	 *
	 * @return void
	 */
	public function handle_revoke_license() {
		$this->ensure_capability();

		$license_id = isset( $_GET['license_id'] )
			? absint( $_GET['license_id'] )
			: 0;

		if ( $license_id < 1 ) {
			$this->redirect_with_notice( 'invalid' );
		}

		check_admin_referer(
			'dropkey_wp_revoke_license_' . $license_id
		);

		$license = $this->licenses->find( $license_id );

		if ( ! $license ) {
			$this->redirect_with_notice( 'not_found' );
		}

		if ( License::STATUS_REVOKED === $license->get_status() ) {
			$this->redirect_with_notice( 'already_revoked' );
		}

		$result = $this->licenses->update_status(
			$license_id,
			License::STATUS_REVOKED
		);

		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error' );
		}

		do_action(
			'dropkey_wp_license_revoked',
			$result,
			$license
		);

		$this->redirect_with_notice( 'revoked' );
	}

	/**
	 * Get revoke URL.
	 *
	 * @param int $license_id License ID.
	 * @return string
	 */
	private function get_revoke_url( $license_id ) {
		$url = add_query_arg(
			array(
				'action'     => 'dropkey_wp_revoke_license',
				'license_id' => absint( $license_id ),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url(
			$url,
			'dropkey_wp_revoke_license_' . absint( $license_id )
		);
	}

	/**
	 * Render status filters.
	 *
	 * @param string $current_status Current status.
	 * @return void
	 */
	private function render_status_filters( $current_status ) {
		$filters = array(
			''                       => __( 'All', 'dropkey-wp' ),
			License::STATUS_ACTIVE   => __( 'Active', 'dropkey-wp' ),
			License::STATUS_SUSPENDED => __( 'Suspended', 'dropkey-wp' ),
			License::STATUS_EXPIRED  => __( 'Expired', 'dropkey-wp' ),
			License::STATUS_REVOKED  => __( 'Revoked', 'dropkey-wp' ),
		);

		$links = array();
		$total = count( $filters );
		$index = 0;

		foreach ( $filters as $status => $label ) {
			$url = admin_url(
				'admin.php?page=dropkey-wp-licenses'
			);

			if ( '' !== $status ) {
				$url = add_query_arg(
					'status',
					$status,
					$url
				);
			}

			$links[] =
				'<li>' .
				'<a href="' . esc_url( $url ) . '"' .
				( $current_status === $status ? ' class="current"' : '' ) .
				'>' .
				esc_html( $label ) .
				'</a>' .
				'</li>';

			++$index;

			if ( $index < $total ) {
				$links[ count( $links ) - 1 ] .= ' |';
			}
		}

		?>
		<ul class="subsubsub">
			<?php echo implode( ' ', $links ); ?>
		</ul>

		<div class="clear"></div>
		<?php
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		$notice = isset( $_GET['license_notice'] )
			? sanitize_key( wp_unslash( $_GET['license_notice'] ) )
			: '';

		$messages = array(
			'revoked' => array(
				'class'   => 'notice-success',
				'message' => __(
					'License revoked successfully.',
					'dropkey-wp'
				),
			),
			'already_revoked' => array(
				'class'   => 'notice-info',
				'message' => __(
					'This license has already been revoked.',
					'dropkey-wp'
				),
			),
			'not_found' => array(
				'class'   => 'notice-error',
				'message' => __(
					'The requested license could not be found.',
					'dropkey-wp'
				),
			),
			'invalid' => array(
				'class'   => 'notice-error',
				'message' => __(
					'The license ID is invalid.',
					'dropkey-wp'
				),
			),
			'error' => array(
				'class'   => 'notice-error',
				'message' => __(
					'The license could not be revoked.',
					'dropkey-wp'
				),
			),
		);

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		?>
		<div class="notice <?php echo esc_attr( $messages[ $notice ]['class'] ); ?> is-dismissible">
			<p>
				<?php echo esc_html( $messages[ $notice ]['message'] ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Redirect to licenses page with notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'dropkey-wp-licenses',
					'license_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Get customer display name.
	 *
	 * @param object $customer Customer.
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

		return $customer->get_email();
	}

	/**
	 * Get license status label.
	 *
	 * @param string $status License status.
	 * @return string
	 */
	private function get_status_label( $status ) {
		$labels = array(
			License::STATUS_ACTIVE    => __( 'Active', 'dropkey-wp' ),
			License::STATUS_SUSPENDED => __( 'Suspended', 'dropkey-wp' ),
			License::STATUS_EXPIRED   => __( 'Expired', 'dropkey-wp' ),
			License::STATUS_REVOKED   => __( 'Revoked', 'dropkey-wp' ),
		);

		return isset( $labels[ $status ] )
			? $labels[ $status ]
			: $status;
	}

	/**
	 * Get status CSS class.
	 *
	 * @param string $status License status.
	 * @return string
	 */
	private function get_status_class( $status ) {
		switch ( $status ) {
			case License::STATUS_ACTIVE:
				return 'dropkey-wp-status dropkey-wp-status-active';

			case License::STATUS_SUSPENDED:
				return 'dropkey-wp-status dropkey-wp-status-suspended';

			case License::STATUS_EXPIRED:
				return 'dropkey-wp-status dropkey-wp-status-expired';

			case License::STATUS_REVOKED:
				return 'dropkey-wp-status dropkey-wp-status-revoked';

			default:
				return 'dropkey-wp-status';
		}
	}

	/**
	 * Check license status.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	private function is_valid_status( $status ) {
		return in_array(
			$status,
			array(
				License::STATUS_ACTIVE,
				License::STATUS_SUSPENDED,
				License::STATUS_EXPIRED,
				License::STATUS_REVOKED,
			),
			true
		);
	}

	/**
	 * Format UTC datetime for WordPress timezone.
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
			get_option( 'date_format' ) . ' ' .
			get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * Render page-specific styles and scripts.
	 *
	 * @return void
	 */
	private function render_styles() {
		?>
		<style>
			.dropkey-wp-license-table {
				margin-top: 12px;
				overflow-x: auto;
			}

			.dropkey-wp-license-table table {
				width: 100%;
				min-width: 1100px;
			}

			.dropkey-wp-license-table th,
			.dropkey-wp-license-table td {
				vertical-align: top;
			}

			.dropkey-wp-license-table td {
				padding-top: 12px;
				padding-bottom: 12px;
			}

			.dropkey-wp-license-table th {
				white-space: nowrap;
			}

			.dropkey-wp-license-table .column-license {
				width: 210px;
			}

			.dropkey-wp-license-table .column-customer {
				width: 18%;
				min-width: 180px;
			}

			.dropkey-wp-license-table .column-product {
				width: 17%;
				min-width: 150px;
			}

			.dropkey-wp-license-table .column-subscription {
				width: 110px;
			}

			.dropkey-wp-license-table .column-status {
				width: 100px;
			}

			.dropkey-wp-license-table .column-activations {
				width: 105px;
			}

			.dropkey-wp-license-table .column-expires {
				width: 145px;
			}

			.dropkey-wp-license-table .column-actions {
				width: 90px;
			}

			.dropkey-wp-license-key {
				display: block;
				margin-top: 5px;
				padding: 2px 4px;
				overflow-wrap: anywhere;
				word-break: break-word;
				white-space: normal;
			}

			.dropkey-wp-secondary-text {
				display: block;
				margin-top: 3px;
				color: #646970;
				font-size: 12px;
				line-height: 1.45;
			}

			.dropkey-wp-status {
				display: inline-block;
				padding: 3px 8px;
				border-radius: 3px;
				background: #f0f0f1;
				color: #1d2327;
				font-size: 12px;
				font-weight: 600;
				line-height: 1.4;
				white-space: nowrap;
			}

			.dropkey-wp-status-active {
				background: #edfaef;
				color: #18752a;
			}

			.dropkey-wp-status-suspended {
				background: #fff8e5;
				color: #8a5a00;
			}

			.dropkey-wp-status-expired {
				background: #f6eeee;
				color: #8a2424;
			}

			.dropkey-wp-status-revoked {
				background: #f0f0f1;
				color: #50575e;
			}

			.dropkey-wp-revoke-link {
				color: #b32d2e;
			}

			.dropkey-wp-revoke-link:hover,
			.dropkey-wp-revoke-link:focus {
				color: #8f2425;
			}
		</style>

		<script>
			document.addEventListener(
				'DOMContentLoaded',
				function () {
					var links = document.querySelectorAll(
						'.dropkey-wp-revoke-link'
					);

					links.forEach(
						function (link) {
							link.addEventListener(
								'click',
								function (event) {
									var message = link.getAttribute(
										'data-confirm'
									);

									if (
										message
										&& ! window.confirm( message )
									) {
										event.preventDefault();
									}
								}
							);
						}
					);
				}
			);
		</script>
		<?php
	}

	/**
	 * Ensure current user can manage licenses.
	 *
	 * @return void
	 */
	private function ensure_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__(
					'You do not have permission to manage licenses.',
					'dropkey-wp'
				)
			);
		}
	}
}
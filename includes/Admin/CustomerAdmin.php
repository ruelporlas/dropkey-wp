<?php
/**
 * DropKey WP customer administration.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Admin;

use DropKeyWP\Application\CreateCustomer;
use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Domain\Customer;

defined( 'ABSPATH' ) || exit;

final class CustomerAdmin {

	/**
	 * Customer repository.
	 *
	 * @var CustomerRepository
	 */
	private $customers;

	/**
	 * Constructor.
	 *
	 * @param CustomerRepository $customers Customer repository.
	 */
	public function __construct( CustomerRepository $customers ) {
		$this->customers = $customers;
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
			'admin_post_dropkey_wp_create_customer',
			array( $this, 'handle_create_customer' )
		);
	}

	/**
	 * Register customer admin pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'dropkey-wp-products',
			__( 'Customers', 'dropkey-wp' ),
			__( 'Customers', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-customers',
			array( $this, 'render_customers_page' )
		);

		add_submenu_page(
			'dropkey-wp-products',
			__( 'Add Customer', 'dropkey-wp' ),
			__( 'Add Customer', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-customer-add',
			array( $this, 'render_add_customer_page' )
		);
	}

	/**
	 * Render customers list page.
	 *
	 * @return void
	 */
	public function render_customers_page() {
		$this->ensure_capability();

		$status = isset( $_GET['status'] )
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: '';

		if (
			'' !== $status
			&& ! in_array(
				$status,
				array(
					Customer::STATUS_ACTIVE,
					Customer::STATUS_INACTIVE,
				),
				true
			)
		) {
			$status = '';
		}

		$customers = $this->customers->all( $status );

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php echo esc_html__( 'Customers', 'dropkey-wp' ); ?>
			</h1>

			<a
				href="<?php echo esc_url( admin_url( 'admin.php?page=dropkey-wp-customer-add' ) ); ?>"
				class="page-title-action"
			>
				<?php echo esc_html__( 'Add Customer', 'dropkey-wp' ); ?>
			</a>

			<hr class="wp-header-end" />

			<?php $this->render_notices(); ?>

			<?php $this->render_status_filters( $status ); ?>

			<?php if ( empty( $customers ) ) : ?>

				<div class="notice notice-info inline">
					<p>
						<?php
						if ( '' === $status ) {
							echo esc_html__(
								'No DropKey customers have been created yet.',
								'dropkey-wp'
							);
						} else {
							echo esc_html__(
								'No customers match this status filter.',
								'dropkey-wp'
							);
						}
						?>
					</p>
				</div>

				<?php if ( '' === $status ) : ?>

					<p>
						<a
							href="<?php echo esc_url( admin_url( 'admin.php?page=dropkey-wp-customer-add' ) ); ?>"
							class="button button-primary"
						>
							<?php echo esc_html__( 'Add Customer', 'dropkey-wp' ); ?>
						</a>
					</p>

				<?php endif; ?>

			<?php else : ?>

				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th scope="col">
								<?php echo esc_html__( 'Customer', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'WordPress User', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Email', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Created', 'dropkey-wp' ); ?>
							</th>
						</tr>
					</thead>

					<tbody>
						<?php foreach ( $customers as $customer ) : ?>

							<?php
							$user = get_userdata(
								$customer->get_user_id()
							);
							?>

							<tr>
								<td>
									<strong>
										<?php
										echo esc_html(
											$this->get_customer_name(
												$customer
											)
										);
										?>
									</strong>

									<div class="row-actions">
										<span>
											<?php
											echo esc_html(
												sprintf(
													/* translators: %d: customer ID. */
													__( 'Customer #%d', 'dropkey-wp' ),
													$customer->get_id()
												)
											);
											?>
										</span>
									</div>
								</td>

								<td>
									<?php if ( $user ) : ?>

										<strong>
											<?php
											echo esc_html(
												$user->display_name
											);
											?>
										</strong>

										<span class="dropkey-wp-secondary-text">
											<?php
											echo esc_html(
												$user->user_login
											);
											?>
										</span>

									<?php else : ?>

										<span class="dropkey-wp-status dropkey-wp-status-inactive">
											<?php
											echo esc_html__(
												'User not found',
												'dropkey-wp'
											);
											?>
										</span>

									<?php endif; ?>

									<span class="dropkey-wp-secondary-text">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %d: WordPress user ID. */
												__( 'User ID: %d', 'dropkey-wp' ),
												$customer->get_user_id()
											)
										);
										?>
									</span>
								</td>

								<td>
									<?php echo esc_html( $customer->get_email() ); ?>
								</td>

								<td>
									<span
										class="<?php echo esc_attr(
											$this->get_status_class(
												$customer->get_status()
											)
										); ?>"
									>
										<?php
										echo esc_html(
											$this->get_status_label(
												$customer->get_status()
											)
										);
										?>
									</span>
								</td>

								<td>
									<?php
									echo esc_html(
										$this->format_datetime(
											$customer->get_created_at()
										)
									);
									?>
								</td>
							</tr>

						<?php endforeach; ?>
					</tbody>
				</table>

			<?php endif; ?>
		</div>

		<?php $this->render_styles(); ?>
		<?php
	}

	/**
	 * Render add customer page.
	 *
	 * @return void
	 */
	public function render_add_customer_page() {
		$this->ensure_capability();

		$users = $this->get_available_users();

		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html__( 'Add Customer', 'dropkey-wp' ); ?>
			</h1>

			<p>
				<?php
				echo esc_html__(
					'Customers are linked to existing WordPress user accounts. Select a user to create their DropKey customer record.',
					'dropkey-wp'
				);
				?>
			</p>

			<?php $this->render_notices(); ?>

			<?php if ( empty( $users ) ) : ?>

				<div class="notice notice-info inline">
					<p>
						<?php
						echo esc_html__(
							'There are no WordPress users available to add as DropKey customers. Users who already have customer records are excluded.',
							'dropkey-wp'
						);
						?>
					</p>
				</div>

				<p>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=dropkey-wp-customers' ) ); ?>"
						class="button"
					>
						<?php echo esc_html__( 'Back to Customers', 'dropkey-wp' ); ?>
					</a>
				</p>

			<?php else : ?>

				<form
					method="post"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				>
					<input
						type="hidden"
						name="action"
						value="dropkey_wp_create_customer"
					/>

					<?php
					wp_nonce_field(
						'dropkey_wp_create_customer',
						'dropkey_wp_customer_nonce'
					);
					?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="dropkey_wp_customer_user">
										<?php
										echo esc_html__(
											'WordPress User',
											'dropkey-wp'
										);
										?>
									</label>
								</th>

								<td>
									<select
										name="user_id"
										id="dropkey_wp_customer_user"
										class="regular-text"
										required
									>
										<option value="">
											<?php
											echo esc_html__(
												'Select a user',
												'dropkey-wp'
											);
											?>
										</option>

										<?php foreach ( $users as $user ) : ?>

											<option
												value="<?php echo esc_attr( $user->ID ); ?>"
											>
												<?php
												echo esc_html(
													$this->format_user_option(
														$user
													)
												);
												?>
											</option>

										<?php endforeach; ?>
									</select>

									<p class="description">
										<?php
										echo esc_html__(
											'The customer name and email will be taken from the selected WordPress account.',
											'dropkey-wp'
										);
										?>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<p class="submit">
						<button
							type="submit"
							class="button button-primary"
						>
							<?php
							echo esc_html__(
								'Create Customer',
								'dropkey-wp'
							);
							?>
						</button>

						<a
							href="<?php echo esc_url( admin_url( 'admin.php?page=dropkey-wp-customers' ) ); ?>"
							class="button"
						>
							<?php echo esc_html__( 'Cancel', 'dropkey-wp' ); ?>
						</a>
					</p>
				</form>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle customer creation.
	 *
	 * @return void
	 */
	public function handle_create_customer() {
		$this->ensure_capability();

		check_admin_referer(
			'dropkey_wp_create_customer',
			'dropkey_wp_customer_nonce'
		);

		$user_id = isset( $_POST['user_id'] )
			? absint( $_POST['user_id'] )
			: 0;

		if ( $user_id <= 0 ) {
			$this->redirect_with_notice( 'invalid_user' );
		}

		$service = new CreateCustomer(
			$this->customers
		);

		$result = $service->execute( $user_id );

		if ( is_wp_error( $result ) ) {
			if (
				'dropkey_customer_user_not_found' ===
				$result->get_error_code()
			) {
				$this->redirect_with_notice( 'user_not_found' );
			}

			if (
				$result->get_error_code() ===
				'dropkey_customer_create_failed'
			) {
				/*
				 * A concurrent request may have created the customer after
				 * the initial lookup. Recover the existing record where
				 * possible instead of presenting a misleading failure.
				 */
				$existing = $this->customers->find_by_user_id(
					$user_id
				);

				if ( $existing ) {
					$this->redirect_with_notice( 'created' );
				}
			}

			$this->redirect_with_notice( 'error' );
		}

		$this->redirect_with_notice( 'created' );
	}

	/**
	 * Get WordPress users that are not yet DropKey customers.
	 *
	 * @return \WP_User[]
	 */
	private function get_available_users() {
		$users = get_users(
			array(
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => 'all',
			)
		);

		$available = array();

		foreach ( $users as $user ) {
			if (
				$this->customers->find_by_user_id(
					$user->ID
				)
			) {
				continue;
			}

			$available[] = $user;
		}

		return $available;
	}

	/**
	 * Render customer status filters.
	 *
	 * @param string $current_status Current status.
	 * @return void
	 */
	private function render_status_filters( $current_status ) {
		$filters = array(
			''                       => __( 'All', 'dropkey-wp' ),
			Customer::STATUS_ACTIVE   => __( 'Active', 'dropkey-wp' ),
			Customer::STATUS_INACTIVE => __( 'Inactive', 'dropkey-wp' ),
		);

		$links = array();
		$total = count( $filters );
		$index = 0;

		?>
		<ul class="subsubsub">
			<?php foreach ( $filters as $status => $label ) : ?>

				<?php
				$url = admin_url(
					'admin.php?page=dropkey-wp-customers'
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
					( $current_status === $status
						? ' class="current"'
						: '' ) .
					'>' .
					esc_html( $label ) .
					'</a>' .
					'</li>';

				++$index;

				if ( $index < $total ) {
					$links[ count( $links ) - 1 ] .= ' |';
				}
				?>

			<?php endforeach; ?>

			<?php echo implode( ' ', $links ); ?>
		</ul>

		<div class="clear"></div>
		<?php
	}

	/**
	 * Render URL-based admin notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		$notice = isset( $_GET['customer_notice'] )
			? sanitize_key(
				wp_unslash( $_GET['customer_notice'] )
			)
			: '';

		$messages = array(
			'created' => array(
				'class'   => 'notice-success',
				'message' => __(
					'Customer created successfully.',
					'dropkey-wp'
				),
			),
			'invalid_user' => array(
				'class'   => 'notice-error',
				'message' => __(
					'Please select a valid WordPress user.',
					'dropkey-wp'
				),
			),
			'user_not_found' => array(
				'class'   => 'notice-error',
				'message' => __(
					'The selected WordPress user could not be found.',
					'dropkey-wp'
				),
			),
			'error' => array(
				'class'   => 'notice-error',
				'message' => __(
					'The customer could not be created.',
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
	 * Redirect back to the customers page with a notice.
	 *
	 * @param string $notice Notice key.
	 * @return void
	 */
	private function redirect_with_notice( $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'dropkey-wp-customers',
					'customer_notice' => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Get customer display name.
	 *
	 * @param Customer $customer Customer.
	 * @return string
	 */
	private function get_customer_name( Customer $customer ) {
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
	 * Format WordPress user select option.
	 *
	 * @param \WP_User $user WordPress user.
	 * @return string
	 */
	private function format_user_option( $user ) {
		$name = trim(
			$user->first_name . ' ' .
			$user->last_name
		);

		if ( '' === $name ) {
			$name = $user->display_name;
		}

		if ( '' === $name ) {
			$name = $user->user_login;
		}

		return sprintf(
			'%1$s — %2$s',
			$name,
			$user->user_email
		);
	}

	/**
	 * Get customer status label.
	 *
	 * @param string $status Customer status.
	 * @return string
	 */
	private function get_status_label( $status ) {
		$labels = array(
			Customer::STATUS_ACTIVE   => __( 'Active', 'dropkey-wp' ),
			Customer::STATUS_INACTIVE => __( 'Inactive', 'dropkey-wp' ),
		);

		return isset( $labels[ $status ] )
			? $labels[ $status ]
			: $status;
	}

	/**
	 * Get customer status CSS class.
	 *
	 * @param string $status Customer status.
	 * @return string
	 */
	private function get_status_class( $status ) {
		switch ( $status ) {
			case Customer::STATUS_ACTIVE:
				return 'dropkey-wp-status dropkey-wp-status-active';

			case Customer::STATUS_INACTIVE:
				return 'dropkey-wp-status dropkey-wp-status-inactive';

			default:
				return 'dropkey-wp-status';
		}
	}

	/**
	 * Format UTC datetime for the WordPress timezone.
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
	 * Render page-specific styles.
	 *
	 * @return void
	 */
	private function render_styles() {
		?>
		<style>
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

			.dropkey-wp-status-inactive {
				background: #f0f0f1;
				color: #50575e;
			}
		</style>
		<?php
	}

	/**
	 * Ensure current user can manage customers.
	 *
	 * @return void
	 */
	private function ensure_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__(
					'You do not have permission to manage customers.',
					'dropkey-wp'
				)
			);
		}
	}
}
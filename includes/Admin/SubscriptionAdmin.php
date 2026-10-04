<?php
/**
 * DropKey WP subscription administration.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Admin;

use DropKeyWP\Application\ChangeSubscriptionStatus;
use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

class SubscriptionAdmin {

	private $subscriptions;
	private $customers;
	private $products;
	private $plans;
	private $change_status;

	public function __construct(
		SubscriptionRepository $subscriptions,
		CustomerRepository $customers,
		ProductRepository $products,
		PlanRepository $plans,
		ChangeSubscriptionStatus $change_status
	) {
		$this->subscriptions = $subscriptions;
		$this->customers     = $customers;
		$this->products      = $products;
		$this->plans         = $plans;
		$this->change_status = $change_status;
	}

	public function register() {
		add_action(
			'admin_menu',
			array( $this, 'register_menu' )
		);

		add_action(
			'admin_post_dropkey_wp_change_subscription_status',
			array( $this, 'handle_change_status' )
		);
	}

	public function register_menu() {
		add_menu_page(
			__( 'Subscriptions', 'dropkey-wp' ),
			__( 'Subscriptions', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-subscriptions',
			array( $this, 'render_page' ),
			'dashicons-update-alt',
			30
		);
	}

	public function render_page() {
		$subscription_id = isset( $_GET['subscription_id'] )
			? absint( $_GET['subscription_id'] )
			: 0;

		if ( $subscription_id > 0 ) {
			$this->render_detail( $subscription_id );
			return;
		}

		$this->render_list();
	}

	private function render_list() {
		$status  = isset( $_GET['status'] )
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: '';

		$gateway = isset( $_GET['gateway'] )
			? sanitize_key( wp_unslash( $_GET['gateway'] ) )
			: '';

		$subscriptions = $this->subscriptions->all(
			array(
				'status'  => $status,
				'gateway' => $gateway,
			)
		);
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'Subscriptions', 'dropkey-wp' ); ?>
			</h1>

			<hr class="wp-header-end">

			<form method="get">
				<input type="hidden" name="post_type" value="dropkey_subscription">
				<input type="hidden" name="page" value="dropkey-wp-subscriptions">

				<select name="status">
					<option value="">
						<?php esc_html_e( 'All statuses', 'dropkey-wp' ); ?>
					</option>

					<?php foreach ( $this->get_statuses() as $value => $label ) : ?>
						<option
							value="<?php echo esc_attr( $value ); ?>"
							<?php selected( $status, $value ); ?>
						>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="gateway">
					<option value="">
						<?php esc_html_e( 'All gateways', 'dropkey-wp' ); ?>
					</option>

					<option value="paypal" <?php selected( $gateway, 'paypal' ); ?>>
						PayPal
					</option>
				</select>

				<?php submit_button( __( 'Filter', 'dropkey-wp' ), 'secondary', '', false ); ?>
			</form>

			<table class="widefat striped" style="margin-top:20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'dropkey-wp' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'dropkey-wp' ); ?></th>
						<th><?php esc_html_e( 'Plan', 'dropkey-wp' ); ?></th>
						<th><?php esc_html_e( 'Gateway', 'dropkey-wp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'dropkey-wp' ); ?></th>
						<th><?php esc_html_e( 'Period End', 'dropkey-wp' ); ?></th>
					</tr>
				</thead>

				<tbody>
					<?php if ( empty( $subscriptions ) ) : ?>
						<tr>
							<td colspan="6">
								<?php esc_html_e( 'No subscriptions found.', 'dropkey-wp' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $subscriptions as $subscription ) : ?>
							<?php
							$customer = $this->customers->find(
								$subscription->get_customer_id()
							);

							$plan = $this->plans->find(
								$subscription->get_plan_id()
							);

							$detail_url = add_query_arg(
								array(
									'page'            => 'dropkey-wp-subscriptions',
									'subscription_id' => $subscription->get_id(),
								),
								admin_url( 'admin.php' )
							);
							?>
							<tr>
								<td>
									<a href="<?php echo esc_url( $detail_url ); ?>">
										#<?php echo esc_html( $subscription->get_id() ); ?>
									</a>
								</td>

								<td>
									<?php echo esc_html( $customer ? $customer->get_name() : '—' ); ?>
								</td>

								<td>
									<?php echo esc_html( $plan ? $plan->get_name() : '—' ); ?>
								</td>

								<td>
									<?php echo esc_html( ucfirst( $subscription->get_gateway() ) ); ?>
								</td>

								<td>
									<?php echo esc_html( ucfirst( $subscription->get_status() ) ); ?>
								</td>

								<td>
									<?php echo esc_html( $subscription->get_current_period_end() ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_detail( $subscription_id ) {
		$subscription = $this->subscriptions->find( $subscription_id );

		if ( ! $subscription instanceof Subscription ) {
			wp_die(
				esc_html__( 'Subscription not found.', 'dropkey-wp' ),
				esc_html__( 'Subscription not found', 'dropkey-wp' ),
				array( 'response' => 404 )
			);
		}

		$customer = $this->customers->find(
			$subscription->get_customer_id()
		);

		$plan = $this->plans->find(
			$subscription->get_plan_id()
		);

		$product = $plan
			? $this->products->find( $plan->get_product_id() )
			: null;

		$status = $subscription->get_status();

		$back_url = admin_url(
			'admin.php?page=dropkey-wp-subscriptions'
		);

		?>
		<div class="wrap dropkey-wp-subscription-detail">
			<h1 class="wp-heading-inline">
				<?php
				printf(
					/* translators: %d: subscription ID */
					esc_html__( 'Subscription #%d', 'dropkey-wp' ),
					absint( $subscription->get_id() )
				);
				?>
			</h1>

			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
				<?php esc_html_e( 'Back to Subscriptions', 'dropkey-wp' ); ?>
			</a>

			<hr class="wp-header-end">

			<div class="dropkey-wp-subscription-grid">
				<div class="dropkey-wp-subscription-main">

					<div class="postbox">
						<div class="postbox-header">
							<h2 class="hndle">
								<?php esc_html_e( 'Subscription', 'dropkey-wp' ); ?>
							</h2>
						</div>

						<div class="inside">
							<table class="form-table" role="presentation">
								<tbody>
									<tr>
										<th scope="row"><?php esc_html_e( 'ID', 'dropkey-wp' ); ?></th>
										<td>#<?php echo esc_html( $subscription->get_id() ); ?></td>
									</tr>

									<tr>
										<th scope="row"><?php esc_html_e( 'Customer', 'dropkey-wp' ); ?></th>
										<td><?php echo esc_html( $customer ? $customer->get_name() : '—' ); ?></td>
									</tr>

									<tr>
										<th scope="row"><?php esc_html_e( 'Product', 'dropkey-wp' ); ?></th>
										<td><?php echo esc_html( $product ? $product->get_name() : '—' ); ?></td>
									</tr>

									<tr>
										<th scope="row"><?php esc_html_e( 'Plan', 'dropkey-wp' ); ?></th>
										<td><?php echo esc_html( $plan ? $plan->get_name() : '—' ); ?></td>
									</tr>

									<tr>
										<th scope="row"><?php esc_html_e( 'Gateway', 'dropkey-wp' ); ?></th>
										<td><?php echo esc_html( ucfirst( $subscription->get_gateway() ) ); ?></td>
									</tr>

									<tr>
										<th scope="row"><?php esc_html_e( 'Gateway Subscription ID', 'dropkey-wp' ); ?></th>
										<td><code><?php echo esc_html( $subscription->get_gateway_subscription_id() ); ?></code></td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<div class="postbox">
						<div class="postbox-header">
							<h2 class="hndle">
								<?php esc_html_e( 'Billing', 'dropkey-wp' ); ?>
							</h2>
						</div>

						<div class="inside">
							<div class="dropkey-wp-meta-row">
								<span><?php esc_html_e( 'Current period start', 'dropkey-wp' ); ?></span>
								<strong><?php echo esc_html( $subscription->get_current_period_start() ); ?></strong>
							</div>

							<div class="dropkey-wp-meta-row">
								<span><?php esc_html_e( 'Current period end', 'dropkey-wp' ); ?></span>
								<strong><?php echo esc_html( $subscription->get_current_period_end() ); ?></strong>
							</div>

							<div class="dropkey-wp-meta-row">
								<span><?php esc_html_e( 'Past due at', 'dropkey-wp' ); ?></span>
								<strong><?php echo esc_html( $subscription->get_past_due_at() ?: '—' ); ?></strong>
							</div>

							<div class="dropkey-wp-meta-row">
								<span><?php esc_html_e( 'Ended at', 'dropkey-wp' ); ?></span>
								<strong><?php echo esc_html( $subscription->get_ended_at() ?: '—' ); ?></strong>
							</div>
						</div>
					</div>

				</div>

				<div class="dropkey-wp-subscription-sidebar">

					<div class="postbox">
						<div class="postbox-header">
							<h2 class="hndle">
								<?php esc_html_e( 'Status', 'dropkey-wp' ); ?>
							</h2>
						</div>

						<div class="inside">
							<p>
								<strong>
									<?php echo esc_html( ucfirst( $status ) ); ?>
								</strong>
							</p>

							<form
								method="post"
								action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
							>
								<input
									type="hidden"
									name="action"
									value="dropkey_wp_change_subscription_status"
								>

								<input
									type="hidden"
									name="subscription_id"
									value="<?php echo esc_attr( $subscription->get_id() ); ?>"
								>

								<?php wp_nonce_field( 'dropkey_wp_change_subscription_status' ); ?>

								<select name="status">
									<?php foreach ( $this->get_statuses() as $value => $label ) : ?>
										<option
											value="<?php echo esc_attr( $value ); ?>"
											<?php selected( $status, $value ); ?>
										>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<?php
								submit_button(
									__( 'Update Status', 'dropkey-wp' ),
									'primary',
									'submit',
									false
								);
								?>
							</form>
						</div>
					</div>

					<div class="postbox">
						<div class="postbox-header">
							<h2 class="hndle">
								<?php esc_html_e( 'Lifecycle', 'dropkey-wp' ); ?>
							</h2>
						</div>

						<div class="inside">
							<div class="dropkey-wp-meta-row">
								<span><?php esc_html_e( 'Created', 'dropkey-wp' ); ?></span>
								<strong><?php echo esc_html( $subscription->get_created_at() ); ?></strong>
							</div>

							<div class="dropkey-wp-meta-row">
								<span><?php esc_html_e( 'Updated', 'dropkey-wp' ); ?></span>
								<strong><?php echo esc_html( $subscription->get_updated_at() ); ?></strong>
							</div>
						</div>
					</div>

					<div class="postbox">
						<div class="postbox-header">
							<h2 class="hndle">
								<?php esc_html_e( 'References', 'dropkey-wp' ); ?>
							</h2>
						</div>

						<div class="inside">
							<div class="dropkey-wp-meta-row">
								<span><?php esc_html_e( 'Customer ID', 'dropkey-wp' ); ?></span>
								<strong><?php echo esc_html( $subscription->get_customer_id() ); ?></strong>
							</div>

							<div class="dropkey-wp-meta-row">
								<span><?php esc_html_e( 'Plan ID', 'dropkey-wp' ); ?></span>
								<strong><?php echo esc_html( $subscription->get_plan_id() ); ?></strong>
							</div>

							<div class="dropkey-wp-meta-row">
								<span><?php esc_html_e( 'Product ID', 'dropkey-wp' ); ?></span>
								<strong><?php echo esc_html( $plan ? $plan->get_product_id() : '—' ); ?></strong>
							</div>
						</div>
					</div>

				</div>
			</div>
		</div>

		<style>
			.dropkey-wp-subscription-grid {
				display: grid;
				grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
				gap: 20px;
				margin-top: 20px;
			}

			.dropkey-wp-subscription-grid .postbox {
				margin-bottom: 20px;
			}

			.dropkey-wp-subscription-grid .postbox-header {
				padding: 0 12px;
			}

			.dropkey-wp-subscription-grid .postbox-header h2.hndle {
				padding: 10px 0;
				margin: 0;
			}

			.dropkey-wp-subscription-grid .inside {
				margin: 0;
				padding: 12px;
			}

			.dropkey-wp-subscription-grid .form-table {
				margin: 0;
			}

			.dropkey-wp-subscription-grid .form-table th {
				width: 220px;
			}

			.dropkey-wp-meta-row {
				display: flex;
				align-items: flex-start;
				justify-content: space-between;
				gap: 20px;
				padding: 9px 0;
				border-bottom: 1px solid #f0f0f1;
			}

			.dropkey-wp-meta-row:last-child {
				border-bottom: 0;
			}

			.dropkey-wp-meta-row span {
				color: #50575e;
			}

			.dropkey-wp-meta-row strong {
				text-align: right;
			}

			@media screen and (max-width: 782px) {
				.dropkey-wp-subscription-grid {
					grid-template-columns: 1fr;
				}

				.dropkey-wp-subscription-grid .form-table th {
					width: auto;
				}

				.dropkey-wp-meta-row {
					flex-direction: column;
					gap: 4px;
				}

				.dropkey-wp-meta-row strong {
					text-align: left;
				}
			}
		</style>
		<?php
	}

	public function handle_change_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dropkey-wp' ),
				esc_html__( 'Permission denied', 'dropkey-wp' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( 'dropkey_wp_change_subscription_status' );

		$subscription_id = isset( $_POST['subscription_id'] )
			? absint( $_POST['subscription_id'] )
			: 0;

		$status = isset( $_POST['status'] )
			? sanitize_key( wp_unslash( $_POST['status'] ) )
			: '';

		$this->change_status->execute(
			$subscription_id,
			$status
		);

		$url = add_query_arg(
			array(
				'page'            => 'dropkey-wp-subscriptions',
				'subscription_id' => $subscription_id,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	private function get_statuses() {
		return array(
			'pending'   => __( 'Pending', 'dropkey-wp' ),
			'active'    => __( 'Active', 'dropkey-wp' ),
			'past_due'  => __( 'Past Due', 'dropkey-wp' ),
			'suspended' => __( 'Suspended', 'dropkey-wp' ),
			'cancelled' => __( 'Cancelled', 'dropkey-wp' ),
			'expired'   => __( 'Expired', 'dropkey-wp' ),
		);
	}
}


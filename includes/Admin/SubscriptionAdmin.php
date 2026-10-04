<?php
/**
 * DropKey WP subscription administration.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Admin;

use DropKeyWP\Database\Repositories\CustomerRepository;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Database\Repositories\SubscriptionRepository;
use DropKeyWP\Domain\Subscription;

defined( 'ABSPATH' ) || exit;

final class SubscriptionAdmin {

	/**
	 * Subscription repository.
	 *
	 * @var SubscriptionRepository
	 */
	private $subscriptions;

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
	 * Plan repository.
	 *
	 * @var PlanRepository
	 */
	private $plans;

	/**
	 * Constructor.
	 *
	 * @param SubscriptionRepository $subscriptions Subscription repository.
	 * @param CustomerRepository     $customers     Customer repository.
	 * @param ProductRepository      $products      Product repository.
	 * @param PlanRepository         $plans         Plan repository.
	 */
	public function __construct(
		SubscriptionRepository $subscriptions,
		CustomerRepository $customers,
		ProductRepository $products,
		PlanRepository $plans
	) {
		$this->subscriptions = $subscriptions;
		$this->customers     = $customers;
		$this->products      = $products;
		$this->plans         = $plans;
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
	}

	/**
	 * Register admin menu page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'dropkey-wp-products',
			__( 'Subscriptions', 'dropkey-wp' ),
			__( 'Subscriptions', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-subscriptions',
			array( $this, 'render_subscriptions_page' )
		);
	}

	/**
	 * Render subscriptions list page.
	 *
	 * @return void
	 */
	public function render_subscriptions_page() {
		$this->ensure_capability();

		$status = isset( $_GET['status'] )
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: '';

		$gateway = isset( $_GET['gateway'] )
			? sanitize_key( wp_unslash( $_GET['gateway'] ) )
			: '';

		if ( '' !== $status && ! $this->is_valid_status( $status ) ) {
			$status = '';
		}

		$gateways = $this->subscriptions->all_gateways();

		if ( '' !== $gateway && ! in_array( $gateway, $gateways, true ) ) {
			$gateway = '';
		}

		$subscriptions = $this->subscriptions->all(
			$status,
			$gateway
		);

		$customers = array();
		$products  = array();
		$plans     = array();

		foreach ( $subscriptions as $subscription ) {
			$customer_id = $subscription->get_customer_id();
			$product_id  = $subscription->get_product_id();
			$plan_id     = $subscription->get_plan_id();

			if ( ! isset( $customers[ $customer_id ] ) ) {
				$customers[ $customer_id ] = $this->customers->find( $customer_id );
			}

			if ( ! isset( $products[ $product_id ] ) ) {
				$products[ $product_id ] = $this->products->find( $product_id );
			}

			if ( ! isset( $plans[ $plan_id ] ) ) {
				$plans[ $plan_id ] = $this->plans->find( $plan_id );
			}
		}

		?>
		<div class="wrap">

			<h1 class="wp-heading-inline">
				<?php echo esc_html__( 'Subscriptions', 'dropkey-wp' ); ?>
			</h1>

			<hr class="wp-header-end" />

			<?php $this->render_filters( $status, $gateway, $gateways ); ?>

			<?php if ( empty( $subscriptions ) ) : ?>

				<p>
					<?php
					echo esc_html__(
						'No subscriptions found.',
						'dropkey-wp'
					);
					?>
				</p>

			<?php else : ?>

				<table class="widefat fixed striped">

					<thead>
						<tr>

							<th scope="col">
								<?php echo esc_html__( 'ID', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Customer', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Product', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Plan', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Gateway', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Gateway Subscription ID', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Current Period', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Created', 'dropkey-wp' ); ?>
							</th>

						</tr>
					</thead>

					<tbody>

						<?php foreach ( $subscriptions as $subscription ) : ?>

							<?php
							$customer = isset(
								$customers[ $subscription->get_customer_id() ]
							)
								? $customers[ $subscription->get_customer_id() ]
								: null;

							$product = isset(
								$products[ $subscription->get_product_id() ]
							)
								? $products[ $subscription->get_product_id() ]
								: null;

							$plan = isset(
								$plans[ $subscription->get_plan_id() ]
							)
								? $plans[ $subscription->get_plan_id() ]
								: null;
							?>

							<tr>

								<td>
									<strong>
										<?php echo esc_html( $subscription->get_id() ); ?>
									</strong>
								</td>

								<td>
									<?php if ( $customer ) : ?>

										<strong>
											<?php
											echo esc_html(
												trim(
													$customer->get_first_name() . ' ' .
													$customer->get_last_name()
												)
											);
											?>
										</strong>

										<br />

										<small>
											<?php echo esc_html( $customer->get_email() ); ?>
										</small>

									<?php else : ?>

										<em>
											<?php echo esc_html__( 'Customer not found', 'dropkey-wp' ); ?>
										</em>

									<?php endif; ?>
								</td>

								<td>
									<?php if ( $product ) : ?>

										<strong>
											<?php echo esc_html( $product->get_name() ); ?>
										</strong>

										<br />

										<code>
											<?php echo esc_html( $product->get_slug() ); ?>
										</code>

									<?php else : ?>

										<em>
											<?php echo esc_html__( 'Product not found', 'dropkey-wp' ); ?>
										</em>

									<?php endif; ?>
								</td>

								<td>
									<?php if ( $plan ) : ?>

										<strong>
											<?php echo esc_html( $plan->get_name() ); ?>
										</strong>

										<br />

										<small>
											<?php
											echo esc_html(
												$this->format_plan_price(
													$plan->get_price(),
													$plan->get_currency()
												)
											);
											?>
											/
											<?php
											echo esc_html(
												$this->format_billing(
													$plan->get_billing_interval(),
													$plan->get_billing_interval_count()
												)
											);
											?>
										</small>

									<?php else : ?>

										<em>
											<?php echo esc_html__( 'Plan not found', 'dropkey-wp' ); ?>
										</em>

									<?php endif; ?>
								</td>

								<td>
									<?php
									echo esc_html(
										$this->get_gateway_label(
											$subscription->get_gateway()
										)
									);
									?>
								</td>

								<td>
									<code>
										<?php
										echo esc_html(
											$subscription->get_gateway_subscription_id()
										);
										?>
									</code>
								</td>

								<td>
									<?php
									echo esc_html(
										$this->get_status_label(
											$subscription->get_status()
										)
									);
									?>

									<?php if ( $subscription->get_cancel_at_period_end() ) : ?>

										<br />

										<small>
											<?php
											echo esc_html__(
												'Cancels at period end',
												'dropkey-wp'
											);
											?>
										</small>

									<?php endif; ?>
								</td>

								<td>
									<?php if ( $subscription->get_current_period_start() ) : ?>

										<?php
										echo esc_html(
											$this->format_datetime(
												$subscription->get_current_period_start()
											)
										);
										?>

										<br />

										<span aria-hidden="true">→</span>

										<br />

										<?php
										echo esc_html(
											$this->format_datetime(
												$subscription->get_current_period_end()
											)
										);
										?>

									<?php else : ?>

										<em>
											<?php echo esc_html__( 'Not set', 'dropkey-wp' ); ?>
										</em>

									<?php endif; ?>
								</td>

								<td>
									<?php
									echo esc_html(
										$this->format_datetime(
											$subscription->get_created_at()
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
		<?php
	}

	/**
	 * Render subscription filters.
	 *
	 * @param string   $status   Current status.
	 * @param string   $gateway  Current gateway.
	 * @param string[] $gateways Available gateways.
	 * @return void
	 */
	private function render_filters( $status, $gateway, array $gateways ) {
		?>
		<form method="get">

			<input
				type="hidden"
				name="page"
				value="dropkey-wp-subscriptions"
			/>

			<div
				style="
					display:flex;
					gap:8px;
					align-items:center;
					margin:12px 0;
				"
			>

				<label
					for="dropkey_wp_subscription_status"
					class="screen-reader-text"
				>
					<?php echo esc_html__( 'Filter by status', 'dropkey-wp' ); ?>
				</label>

				<select
					name="status"
					id="dropkey_wp_subscription_status"
				>
					<option value="">
						<?php echo esc_html__( 'All statuses', 'dropkey-wp' ); ?>
					</option>

					<?php foreach ( $this->get_statuses() as $subscription_status ) : ?>

						<option
							value="<?php echo esc_attr( $subscription_status ); ?>"
							<?php selected( $status, $subscription_status ); ?>
						>
							<?php
							echo esc_html(
								$this->get_status_label(
									$subscription_status
								)
							);
							?>
						</option>

					<?php endforeach; ?>

				</select>

				<label
					for="dropkey_wp_subscription_gateway"
					class="screen-reader-text"
				>
					<?php echo esc_html__( 'Filter by gateway', 'dropkey-wp' ); ?>
				</label>

				<select
					name="gateway"
					id="dropkey_wp_subscription_gateway"
				>
					<option value="">
						<?php echo esc_html__( 'All gateways', 'dropkey-wp' ); ?>
					</option>

					<?php foreach ( $gateways as $available_gateway ) : ?>

						<option
							value="<?php echo esc_attr( $available_gateway ); ?>"
							<?php selected( $gateway, $available_gateway ); ?>
						>
							<?php
							echo esc_html(
								$this->get_gateway_label(
									$available_gateway
								)
							);
							?>
						</option>

					<?php endforeach; ?>

				</select>

				<button
					type="submit"
					class="button"
				>
					<?php echo esc_html__( 'Filter', 'dropkey-wp' ); ?>
				</button>

				<?php if ( '' !== $status || '' !== $gateway ) : ?>

					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=dropkey-wp-subscriptions' ) ); ?>"
						class="button"
					>
						<?php echo esc_html__( 'Clear', 'dropkey-wp' ); ?>
					</a>

				<?php endif; ?>

			</div>

		</form>
		<?php
	}

	/**
	 * Get all subscription statuses.
	 *
	 * @return string[]
	 */
	private function get_statuses() {
		return array(
			Subscription::STATUS_PENDING,
			Subscription::STATUS_ACTIVE,
			Subscription::STATUS_PAST_DUE,
			Subscription::STATUS_SUSPENDED,
			Subscription::STATUS_CANCELLED,
			Subscription::STATUS_EXPIRED,
		);
	}

	/**
	 * Check whether a subscription status is valid.
	 *
	 * @param string $status Subscription status.
	 * @return bool
	 */
	private function is_valid_status( $status ) {
		return in_array(
			$status,
			$this->get_statuses(),
			true
		);
	}

	/**
	 * Get human-readable status label.
	 *
	 * @param string $status Subscription status.
	 * @return string
	 */
	private function get_status_label( $status ) {
		$labels = array(
			Subscription::STATUS_PENDING   => __( 'Pending', 'dropkey-wp' ),
			Subscription::STATUS_ACTIVE    => __( 'Active', 'dropkey-wp' ),
			Subscription::STATUS_PAST_DUE  => __( 'Past Due', 'dropkey-wp' ),
			Subscription::STATUS_SUSPENDED => __( 'Suspended', 'dropkey-wp' ),
			Subscription::STATUS_CANCELLED => __( 'Cancelled', 'dropkey-wp' ),
			Subscription::STATUS_EXPIRED   => __( 'Expired', 'dropkey-wp' ),
		);

		return isset( $labels[ $status ] )
			? $labels[ $status ]
			: $status;
	}

	/**
	 * Get human-readable gateway label.
	 *
	 * @param string $gateway Gateway name.
	 * @return string
	 */
	private function get_gateway_label( $gateway ) {
		$labels = array(
			'paypal' => __( 'PayPal', 'dropkey-wp' ),
			'test'   => __( 'Test', 'dropkey-wp' ),
		);

		return isset( $labels[ $gateway ] )
			? $labels[ $gateway ]
			: ucwords(
				str_replace(
					array( '-', '_' ),
					' ',
					$gateway
				)
			);
	}

	/**
	 * Format plan price.
	 *
	 * @param string $price    Plan price.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function format_plan_price( $price, $currency ) {
		return sprintf(
			'%1$s %2$s',
			$currency,
			number_format_i18n(
				(float) $price,
				2
			)
		);
	}

	/**
	 * Format billing interval.
	 *
	 * @param string $interval Billing interval.
	 * @param int    $count    Billing interval count.
	 * @return string
	 */
	private function format_billing( $interval, $count ) {
		$labels = array(
			'day'   => _n( 'day', 'days', $count, 'dropkey-wp' ),
			'week'  => _n( 'week', 'weeks', $count, 'dropkey-wp' ),
			'month' => _n( 'month', 'months', $count, 'dropkey-wp' ),
			'year'  => _n( 'year', 'years', $count, 'dropkey-wp' ),
		);

		$label = isset( $labels[ $interval ] )
			? $labels[ $interval ]
			: $interval;

		return sprintf(
			/* translators: 1: interval count, 2: interval label. */
			__( 'Every %1$d %2$s', 'dropkey-wp' ),
			$count,
			$label
		);
	}

	/**
	 * Format UTC datetime for WordPress admin display.
	 *
	 * @param string $datetime UTC datetime.
	 * @return string
	 */
	private function format_datetime( $datetime ) {
		if ( empty( $datetime ) ) {
			return __( 'Not set', 'dropkey-wp' );
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		if ( false === $timestamp ) {
			return $datetime;
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp,
			wp_timezone()
		);
	}

	/**
	 * Ensure current user has required capability.
	 *
	 * @return void
	 */
	private function ensure_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__(
					'You are not allowed to manage subscriptions.',
					'dropkey-wp'
				)
			);
		}
	}
}
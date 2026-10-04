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

		if (
			'' !== $gateway
			&& ! in_array( $gateway, $gateways, true )
		) {
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
				$customers[ $customer_id ] = $this->customers->find(
					$customer_id
				);
			}

			if ( ! isset( $products[ $product_id ] ) ) {
				$products[ $product_id ] = $this->products->find(
					$product_id
				);
			}

			if ( ! isset( $plans[ $plan_id ] ) ) {
				$plans[ $plan_id ] = $this->plans->find(
					$plan_id
				);
			}
		}

		?>
		<div class="wrap">

			<h1 class="wp-heading-inline">
				<?php echo esc_html__( 'Subscriptions', 'dropkey-wp' ); ?>
			</h1>

			<span class="title-count">
				<?php echo esc_html( number_format_i18n( count( $subscriptions ) ) ); ?>
			</span>

			<hr class="wp-header-end" />

			<?php $this->render_status_filters( $status, $gateway ); ?>

			<?php $this->render_gateway_filter( $status, $gateway, $gateways ); ?>

			<?php if ( empty( $subscriptions ) ) : ?>

				<div class="notice notice-info inline">
					<p>
						<?php
						echo esc_html__(
							'No subscriptions found.',
							'dropkey-wp'
						);
						?>
					</p>
				</div>

			<?php else : ?>

				<div class="dropkey-wp-subscriptions-table">

					<table class="widefat striped">

						<thead>
							<tr>

								<th scope="col" class="column-id">
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
											#<?php echo esc_html( $subscription->get_id() ); ?>
										</strong>
									</td>

									<td>

										<?php if ( $customer ) : ?>

											<strong class="row-title">
												<?php
												echo esc_html(
													$this->get_customer_name(
														$customer
													)
												);
												?>
											</strong>

											<br />

											<span class="description">
												<?php echo esc_html( $customer->get_email() ); ?>
											</span>

										<?php else : ?>

											<strong>
												<?php
												echo esc_html__(
													'Customer not found',
													'dropkey-wp'
												);
												?>
											</strong>

											<br />

											<span class="description">
												<?php
												printf(
													/* translators: %d: customer ID. */
													esc_html__(
														'Customer #%d',
														'dropkey-wp'
													),
													(int) $subscription->get_customer_id()
												);
												?>
											</span>

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

											<strong>
												<?php
												echo esc_html__(
													'Product not found',
													'dropkey-wp'
												);
												?>
											</strong>

											<br />

											<span class="description">
												<?php
												printf(
													/* translators: %d: product ID. */
													esc_html__(
														'Product #%d',
														'dropkey-wp'
													),
													(int) $subscription->get_product_id()
												);
												?>
											</span>

										<?php endif; ?>

									</td>

									<td>

										<?php if ( $plan ) : ?>

											<strong>
												<?php echo esc_html( $plan->get_name() ); ?>
											</strong>

											<br />

											<span class="description">
												<?php
												echo esc_html(
													$this->format_plan_price(
														$plan->get_price(),
														$plan->get_currency()
													)
												);
												?>

												&nbsp;·&nbsp;

												<?php
												echo esc_html(
													$this->format_billing(
														$plan->get_billing_interval(),
														$plan->get_billing_interval_count()
													)
												);
												?>
											</span>

										<?php else : ?>

											<strong>
												<?php
												echo esc_html__(
													'Plan not found',
													'dropkey-wp'
												);
												?>
											</strong>

											<br />

											<span class="description">
												<?php
												printf(
													/* translators: %d: plan ID. */
													esc_html__(
														'Plan #%d',
														'dropkey-wp'
													),
													(int) $subscription->get_plan_id()
												);
												?>
											</span>

										<?php endif; ?>

									</td>

									<td>
										<span class="dropkey-wp-gateway">
											<?php
											echo esc_html(
												$this->get_gateway_label(
													$subscription->get_gateway()
												)
											);
											?>
										</span>
									</td>

									<td>

										<?php
										$gateway_subscription_id =
											$subscription->get_gateway_subscription_id();
										?>

										<?php if ( $gateway_subscription_id ) : ?>

											<code class="dropkey-wp-gateway-id">
												<?php
												echo esc_html(
													$gateway_subscription_id
												);
												?>
											</code>

										<?php else : ?>

											<span class="description">
												<?php
												echo esc_html__(
													'Not set',
													'dropkey-wp'
												);
												?>
											</span>

										<?php endif; ?>

									</td>

									<td>

										<span
											class="dropkey-wp-status dropkey-wp-status-<?php echo esc_attr( $subscription->get_status() ); ?>"
										>
											<?php
											echo esc_html(
												$this->get_status_label(
													$subscription->get_status()
												)
											);
											?>
										</span>

										<?php if ( $subscription->get_cancel_at_period_end() ) : ?>

											<br />

											<span class="description">
												<?php
												echo esc_html__(
													'Cancels at period end',
													'dropkey-wp'
												);
												?>
											</span>

										<?php endif; ?>

									</td>

									<td>

										<?php
										$period_start =
											$subscription->get_current_period_start();

										$period_end =
											$subscription->get_current_period_end();
										?>

										<?php if ( $period_start || $period_end ) : ?>

											<div class="dropkey-wp-period">

												<?php if ( $period_start ) : ?>

													<div>
														<span class="description">
															<?php
															echo esc_html__(
																'Starts',
																'dropkey-wp'
															);
															?>
														</span>

														<strong>
															<?php
															echo esc_html(
																$this->format_datetime(
																	$period_start
																)
															);
															?>
														</strong>
													</div>

												<?php endif; ?>

												<?php if ( $period_end ) : ?>

													<div>
														<span class="description">
															<?php
															echo esc_html__(
																'Ends',
																'dropkey-wp'
															);
															?>
														</span>

														<strong>
															<?php
															echo esc_html(
																$this->format_datetime(
																	$period_end
																)
															);
															?>
														</strong>
													</div>

												<?php endif; ?>

											</div>

										<?php else : ?>

											<span class="description">
												<?php
												echo esc_html__(
													'Not set',
													'dropkey-wp'
												);
												?>
											</span>

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

				</div>

			<?php endif; ?>

			<?php $this->render_admin_styles(); ?>

		</div>
		<?php
	}

	/**
	 * Render subscription status filters.
	 *
	 * @param string $current_status Current status filter.
	 * @param string $current_gateway Current gateway filter.
	 * @return void
	 */
	private function render_status_filters(
		$current_status,
		$current_gateway
	) {
		$filters = array(
			''                         => __( 'All', 'dropkey-wp' ),
			Subscription::STATUS_ACTIVE    => __( 'Active', 'dropkey-wp' ),
			Subscription::STATUS_PENDING   => __( 'Pending', 'dropkey-wp' ),
			Subscription::STATUS_PAST_DUE  => __( 'Past Due', 'dropkey-wp' ),
			Subscription::STATUS_SUSPENDED => __( 'Suspended', 'dropkey-wp' ),
			Subscription::STATUS_CANCELLED => __( 'Cancelled', 'dropkey-wp' ),
			Subscription::STATUS_EXPIRED   => __( 'Expired', 'dropkey-wp' ),
		);

		?>
		<ul class="subsubsub">

			<?php
			$filter_links = array();
			$index        = 0;
			$total        = count( $filters );

			foreach ( $filters as $status => $label ) {
				$url = admin_url(
					'admin.php?page=dropkey-wp-subscriptions'
				);

				if ( '' !== $status ) {
					$url = add_query_arg(
						'status',
						$status,
						$url
					);
				}

				if ( '' !== $current_gateway ) {
					$url = add_query_arg(
						'gateway',
						$current_gateway,
						$url
					);
				}

				$is_current = $current_status === $status;

				$filter_links[] =
					'<li>' .
					'<a href="' . esc_url( $url ) . '"' .
					( $is_current ? ' class="current"' : '' ) .
					'>' .
					esc_html( $label ) .
					'</a>' .
					'</li>';

				++$index;

				if ( $index < $total ) {
					$filter_links[ count( $filter_links ) - 1 ] .= ' |';
				}
			}

			echo implode( ' ', $filter_links );
			?>

		</ul>

		<div class="clear"></div>
		<?php
	}

	/**
	 * Render gateway filter.
	 *
	 * @param string   $status   Current status.
	 * @param string   $gateway  Current gateway.
	 * @param string[] $gateways Available gateways.
	 * @return void
	 */
	private function render_gateway_filter(
		$status,
		$gateway,
		array $gateways
	) {
		?>
		<form
			method="get"
			class="dropkey-wp-subscription-filter"
		>

			<input
				type="hidden"
				name="page"
				value="dropkey-wp-subscriptions"
			/>

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

			<?php if ( '' !== $status ) : ?>

				<input
					type="hidden"
					name="status"
					value="<?php echo esc_attr( $status ); ?>"
				/>

			<?php endif; ?>

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

		</form>
		<?php
	}

	/**
	 * Render small amount of page-specific admin styles.
	 *
	 * @return void
	 */
	private function render_admin_styles() {
		?>
		<style>
			.dropkey-wp-subscriptions-table {
				overflow-x: auto;
				margin-top: 12px;
			}

			.dropkey-wp-subscriptions-table table {
				min-width: 1280px;
			}

			.dropkey-wp-subscriptions-table th,
			.dropkey-wp-subscriptions-table td {
				vertical-align: top;
			}

			.dropkey-wp-subscriptions-table .column-id {
				width: 60px;
			}

			.dropkey-wp-subscriptions-table .row-title {
				display: inline-block;
				margin-bottom: 2px;
			}

			.dropkey-wp-subscriptions-table .description {
				color: #646970;
			}

			.dropkey-wp-subscriptions-table code {
				word-break: break-word;
			}

			.dropkey-wp-gateway {
				font-weight: 600;
			}

			.dropkey-wp-gateway-id {
				display: inline-block;
				max-width: 220px;
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

			.dropkey-wp-status-pending {
				background: #f0f0f1;
				color: #50575e;
			}

			.dropkey-wp-status-past_due {
				background: #fff8e5;
				color: #8a5a00;
			}

			.dropkey-wp-status-suspended {
				background: #fff0f0;
				color: #b32d2e;
			}

			.dropkey-wp-status-cancelled {
				background: #f0f0f1;
				color: #50575e;
			}

			.dropkey-wp-status-expired {
				background: #f6eeee;
				color: #8a2424;
			}

			.dropkey-wp-period {
				display: flex;
				flex-direction: column;
				gap: 6px;
			}

			.dropkey-wp-period div {
				display: flex;
				flex-direction: column;
				gap: 1px;
			}

			.dropkey-wp-subscription-filter {
				display: flex;
				align-items: center;
				gap: 6px;
				margin: 4px 0 12px;
			}

			.title-count {
				display: inline-block;
				margin-left: 4px;
				color: #646970;
				font-size: 13px;
				font-weight: 400;
			}
		</style>
		<?php
	}

	/**
	 * Get customer display name.
	 *
	 * @param object $customer Customer object.
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


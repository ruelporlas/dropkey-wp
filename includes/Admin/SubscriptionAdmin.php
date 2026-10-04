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
	 * Subscription status service.
	 *
	 * @var ChangeSubscriptionStatus
	 */
	private $change_status;

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

		$this->change_status = new ChangeSubscriptionStatus(
			$subscriptions
		);
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
			'admin_post_dropkey_wp_change_subscription_status',
			array( $this, 'handle_change_status' )
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
	 * Render subscriptions page.
	 *
	 * @return void
	 */
	public function render_subscriptions_page() {
		$this->ensure_capability();

		$view = isset( $_GET['view'] )
			? sanitize_key( wp_unslash( $_GET['view'] ) )
			: '';

		if ( 'detail' === $view ) {
			$this->render_subscription_detail_page();
			return;
		}

		$this->render_subscription_list_page();
	}

	/**
	 * Render subscriptions list page.
	 *
	 * @return void
	 */
	private function render_subscription_list_page() {
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

			<span class="title-count">
				<?php echo esc_html( number_format_i18n( count( $subscriptions ) ) ); ?>
			</span>

			<hr class="wp-header-end" />

			<?php $this->render_status_filters( $status, $gateway ); ?>
			<?php $this->render_gateway_filter( $status, $gateway, $gateways ); ?>
			<?php $this->render_admin_notice(); ?>

			<?php if ( empty( $subscriptions ) ) : ?>

				<div class="notice notice-info inline">
					<p>
						<?php echo esc_html__( 'No subscriptions found.', 'dropkey-wp' ); ?>
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
								$customer = isset( $customers[ $subscription->get_customer_id() ] )
									? $customers[ $subscription->get_customer_id() ]
									: null;

								$product = isset( $products[ $subscription->get_product_id() ] )
									? $products[ $subscription->get_product_id() ]
									: null;

								$plan = isset( $plans[ $subscription->get_plan_id() ] )
									? $plans[ $subscription->get_plan_id() ]
									: null;

								$detail_url = add_query_arg(
									array(
										'page' => 'dropkey-wp-subscriptions',
										'view' => 'detail',
										'id'   => $subscription->get_id(),
									),
									admin_url( 'admin.php' )
								);
								?>

								<tr>

									<td>
										<strong>
											<a href="<?php echo esc_url( $detail_url ); ?>">
												#<?php echo esc_html( $subscription->get_id() ); ?>
											</a>
										</strong>
									</td>

									<td>
										<?php if ( $customer ) : ?>
											<strong class="row-title">
												<?php echo esc_html( $this->get_customer_name( $customer ) ); ?>
											</strong>
											<br />
											<span class="description">
												<?php echo esc_html( $customer->get_email() ); ?>
											</span>
										<?php else : ?>
											<strong><?php echo esc_html__( 'Customer not found', 'dropkey-wp' ); ?></strong>
											<br />
											<span class="description">
												<?php
												printf(
													esc_html__( 'Customer #%d', 'dropkey-wp' ),
													(int) $subscription->get_customer_id()
												);
												?>
											</span>
										<?php endif; ?>
									</td>

									<td>
										<?php if ( $product ) : ?>
											<strong><?php echo esc_html( $product->get_name() ); ?></strong>
											<br />
											<code><?php echo esc_html( $product->get_slug() ); ?></code>
										<?php else : ?>
											<strong><?php echo esc_html__( 'Product not found', 'dropkey-wp' ); ?></strong>
											<br />
											<span class="description">
												<?php
												printf(
													esc_html__( 'Product #%d', 'dropkey-wp' ),
													(int) $subscription->get_product_id()
												);
												?>
											</span>
										<?php endif; ?>
									</td>

									<td>
										<?php if ( $plan ) : ?>
											<strong><?php echo esc_html( $plan->get_name() ); ?></strong>
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
											<strong><?php echo esc_html__( 'Plan not found', 'dropkey-wp' ); ?></strong>
											<br />
											<span class="description">
												<?php
												printf(
													esc_html__( 'Plan #%d', 'dropkey-wp' ),
													(int) $subscription->get_plan_id()
												);
												?>
											</span>
										<?php endif; ?>
									</td>

									<td>
										<strong>
											<?php
											echo esc_html(
												$this->get_gateway_label(
													$subscription->get_gateway()
												)
											);
											?>
										</strong>
									</td>

									<td>
										<?php if ( $subscription->get_gateway_subscription_id() ) : ?>
											<code>
												<?php echo esc_html( $subscription->get_gateway_subscription_id() ); ?>
											</code>
										<?php else : ?>
											<span class="description">
												<?php echo esc_html__( 'Not set', 'dropkey-wp' ); ?>
											</span>
										<?php endif; ?>
									</td>

									<td>
										<span class="dropkey-wp-status dropkey-wp-status-<?php echo esc_attr( $subscription->get_status() ); ?>">
											<?php echo esc_html( $this->get_status_label( $subscription->get_status() ) ); ?>
										</span>

										<?php if ( $subscription->get_cancel_at_period_end() ) : ?>
											<br />
											<span class="description">
												<?php echo esc_html__( 'Cancels at period end', 'dropkey-wp' ); ?>
											</span>
										<?php endif; ?>
									</td>

									<td>
										<?php
										$period_start = $subscription->get_current_period_start();
										$period_end   = $subscription->get_current_period_end();
										?>

										<?php if ( $period_start || $period_end ) : ?>
											<div class="dropkey-wp-period">
												<?php if ( $period_start ) : ?>
													<div>
														<span class="description">
															<?php echo esc_html__( 'Starts', 'dropkey-wp' ); ?>
														</span>
														<strong><?php echo esc_html( $this->format_datetime( $period_start ) ); ?></strong>
													</div>
												<?php endif; ?>

												<?php if ( $period_end ) : ?>
													<div>
														<span class="description">
															<?php echo esc_html__( 'Ends', 'dropkey-wp' ); ?>
														</span>
														<strong><?php echo esc_html( $this->format_datetime( $period_end ) ); ?></strong>
													</div>
												<?php endif; ?>
											</div>
										<?php else : ?>
											<span class="description">
												<?php echo esc_html__( 'Not set', 'dropkey-wp' ); ?>
											</span>
										<?php endif; ?>
									</td>

									<td>
										<?php echo esc_html( $this->format_datetime( $subscription->get_created_at() ) ); ?>
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
	 * Render subscription detail page.
	 *
	 * @return void
	 */
	private function render_subscription_detail_page() {
		$subscription_id = isset( $_GET['id'] )
			? absint( $_GET['id'] )
			: 0;

		$subscription = $this->subscriptions->find( $subscription_id );

		if ( ! $subscription ) {
			$this->render_detail_not_found();
			return;
		}

		$customer = $this->customers->find(
			$subscription->get_customer_id()
		);

		$product = $this->products->find(
			$subscription->get_product_id()
		);

		$plan = $this->plans->find(
			$subscription->get_plan_id()
		);

		$allowed_transitions = $this->get_allowed_transitions(
			$subscription->get_status()
		);

		$back_url = admin_url(
			'admin.php?page=dropkey-wp-subscriptions'
		);

		?>
		<div class="wrap">

			<div class="dropkey-wp-detail-header">

				<div>
					<p class="dropkey-wp-breadcrumb">
						<a href="<?php echo esc_url( $back_url ); ?>">
							<?php echo esc_html__( 'Subscriptions', 'dropkey-wp' ); ?>
						</a>
						<span aria-hidden="true">›</span>
						<?php
						printf(
							esc_html__( 'Subscription #%d', 'dropkey-wp' ),
							(int) $subscription->get_id()
						);
						?>
					</p>

					<h1>
						<?php
						printf(
							esc_html__( 'Subscription #%d', 'dropkey-wp' ),
							(int) $subscription->get_id()
						);
						?>

						<span class="dropkey-wp-status dropkey-wp-status-<?php echo esc_attr( $subscription->get_status() ); ?>">
							<?php echo esc_html( $this->get_status_label( $subscription->get_status() ) ); ?>
						</span>
					</h1>
				</div>

				<div class="dropkey-wp-detail-header-actions">
					<a
						href="<?php echo esc_url( $back_url ); ?>"
						class="button"
					>
						<?php echo esc_html__( 'Back to Subscriptions', 'dropkey-wp' ); ?>
					</a>
				</div>

			</div>

			<hr class="wp-header-end" />

			<?php $this->render_admin_notice(); ?>

			<div class="metabox-holder dropkey-wp-subscription-detail">

				<div class="dropkey-wp-detail-main">

					<div class="postbox">

						<div class="postbox-header">
							<h2>
								<?php echo esc_html__( 'Subscription Details', 'dropkey-wp' ); ?>
							</h2>
						</div>

						<div class="inside">

							<table class="form-table" role="presentation">
								<tbody>

									<tr>
										<th scope="row">
											<?php echo esc_html__( 'Customer', 'dropkey-wp' ); ?>
										</th>
										<td>
											<?php if ( $customer ) : ?>
												<strong>
													<?php echo esc_html( $this->get_customer_name( $customer ) ); ?>
												</strong>
												<br />
												<span class="description">
													<?php echo esc_html( $customer->get_email() ); ?>
												</span>
											<?php else : ?>
												<span class="description">
													<?php
													printf(
														esc_html__( 'Customer #%d not found.', 'dropkey-wp' ),
														(int) $subscription->get_customer_id()
													);
													?>
												</span>
											<?php endif; ?>
										</td>
									</tr>

									<tr>
										<th scope="row">
											<?php echo esc_html__( 'Product', 'dropkey-wp' ); ?>
										</th>
										<td>
											<?php if ( $product ) : ?>
												<strong><?php echo esc_html( $product->get_name() ); ?></strong>
												<br />
												<code><?php echo esc_html( $product->get_slug() ); ?></code>
											<?php else : ?>
												<span class="description">
													<?php
													printf(
														esc_html__( 'Product #%d not found.', 'dropkey-wp' ),
														(int) $subscription->get_product_id()
													);
													?>
												</span>
											<?php endif; ?>
										</td>
									</tr>

									<tr>
										<th scope="row">
											<?php echo esc_html__( 'Plan', 'dropkey-wp' ); ?>
										</th>
										<td>
											<?php if ( $plan ) : ?>
												<strong><?php echo esc_html( $plan->get_name() ); ?></strong>
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
												<span class="description">
													<?php
													printf(
														esc_html__( 'Plan #%d not found.', 'dropkey-wp' ),
														(int) $subscription->get_plan_id()
													);
													?>
												</span>
											<?php endif; ?>
										</td>
									</tr>

									<tr>
										<th scope="row">
											<?php echo esc_html__( 'Gateway', 'dropkey-wp' ); ?>
										</th>
										<td>
											<strong>
												<?php
												echo esc_html(
													$this->get_gateway_label(
														$subscription->get_gateway()
													)
												);
												?>
											</strong>
										</td>
									</tr>

									<tr>
										<th scope="row">
											<?php echo esc_html__( 'Gateway Subscription ID', 'dropkey-wp' ); ?>
										</th>
										<td>
											<?php if ( $subscription->get_gateway_subscription_id() ) : ?>
												<code class="dropkey-wp-copy-value">
													<?php echo esc_html( $subscription->get_gateway_subscription_id() ); ?>
												</code>
											<?php else : ?>
												<span class="description">
													<?php echo esc_html__( 'Not set', 'dropkey-wp' ); ?>
												</span>
											<?php endif; ?>
										</td>
									</tr>

								</tbody>
							</table>

						</div>
					</div>

					<div class="postbox">

						<div class="postbox-header">
							<h2>
								<?php echo esc_html__( 'Billing', 'dropkey-wp' ); ?>
							</h2>
						</div>

						<div class="inside">

							<table class="form-table" role="presentation">
								<tbody>

									<tr>
										<th scope="row">
											<?php echo esc_html__( 'Current Period', 'dropkey-wp' ); ?>
										</th>
										<td>
											<?php
											$period_start = $subscription->get_current_period_start();
											$period_end   = $subscription->get_current_period_end();
											?>

											<strong>
												<?php echo esc_html( $this->format_datetime( $period_start ) ); ?>
											</strong>

											<span class="dropkey-wp-period-arrow" aria-hidden="true">→</span>

											<strong>
												<?php echo esc_html( $this->format_datetime( $period_end ) ); ?>
											</strong>
										</td>
									</tr>

									<tr>
										<th scope="row">
											<?php echo esc_html__( 'Price', 'dropkey-wp' ); ?>
										</th>
										<td>
											<?php if ( $plan ) : ?>
												<strong class="dropkey-wp-price">
													<?php
													echo esc_html(
														$this->format_plan_price(
															$plan->get_price(),
															$plan->get_currency()
														)
													);
													?>
												</strong>
											<?php else : ?>
												<span class="description">
													<?php echo esc_html__( 'Plan unavailable.', 'dropkey-wp' ); ?>
												</span>
											<?php endif; ?>
										</td>
									</tr>

									<tr>
										<th scope="row">
											<?php echo esc_html__( 'Billing Interval', 'dropkey-wp' ); ?>
										</th>
										<td>
											<?php if ( $plan ) : ?>
												<?php
												echo esc_html(
													$this->format_billing(
														$plan->get_billing_interval(),
														$plan->get_billing_interval_count()
													)
												);
												?>
											<?php else : ?>
												<span class="description">
													<?php echo esc_html__( 'Plan unavailable.', 'dropkey-wp' ); ?>
												</span>
											<?php endif; ?>
										</td>
									</tr>

									<tr>
										<th scope="row">
											<?php echo esc_html__( 'Cancel at Period End', 'dropkey-wp' ); ?>
										</th>
										<td>
											<?php if ( $subscription->get_cancel_at_period_end() ) : ?>
												<strong>
													<?php echo esc_html__( 'Yes', 'dropkey-wp' ); ?>
												</strong>
												<span class="description">
													<?php echo esc_html__( 'The subscription will remain active until the current period ends.', 'dropkey-wp' ); ?>
												</span>
											<?php else : ?>
												<?php echo esc_html__( 'No', 'dropkey-wp' ); ?>
											<?php endif; ?>
										</td>
									</tr>

								</tbody>
							</table>

						</div>
					</div>

				</div>

				<div class="dropkey-wp-detail-sidebar">

					<div class="postbox">

						<div class="postbox-header">
							<h2>
								<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
							</h2>
						</div>

						<div class="inside">

							<p class="dropkey-wp-current-status">
								<span class="dropkey-wp-status dropkey-wp-status-<?php echo esc_attr( $subscription->get_status() ); ?>">
									<?php echo esc_html( $this->get_status_label( $subscription->get_status() ) ); ?>
								</span>
							</p>

							<?php if ( ! empty( $allowed_transitions ) ) : ?>

								<form
									method="post"
									action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								>

									<input
										type="hidden"
										name="action"
										value="dropkey_wp_change_subscription_status"
									/>

									<input
										type="hidden"
										name="subscription_id"
										value="<?php echo esc_attr( $subscription->get_id() ); ?>"
									/>

									<?php
									wp_nonce_field(
										'dropkey_wp_change_subscription_status_' . $subscription->get_id()
									);
									?>

									<p>
										<label
											for="dropkey_wp_subscription_new_status"
											class="screen-reader-text"
										>
											<?php echo esc_html__( 'New status', 'dropkey-wp' ); ?>
										</label>

										<select
											name="new_status"
											id="dropkey_wp_subscription_new_status"
											class="widefat"
										>
											<?php foreach ( $allowed_transitions as $new_status ) : ?>
												<option value="<?php echo esc_attr( $new_status ); ?>">
													<?php echo esc_html( $this->get_status_label( $new_status ) ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</p>

									<p>
										<button
											type="submit"
											class="button button-primary"
										>
											<?php echo esc_html__( 'Update Status', 'dropkey-wp' ); ?>
										</button>
									</p>

								</form>

							<?php else : ?>

								<p class="description">
									<?php echo esc_html__( 'No status transitions are available from the current state.', 'dropkey-wp' ); ?>
								</p>

							<?php endif; ?>

						</div>
					</div>

					<div class="postbox">

						<div class="postbox-header">
							<h2>
								<?php echo esc_html__( 'Lifecycle', 'dropkey-wp' ); ?>
							</h2>
						</div>

						<div class="inside">

							<table class="widefat striped dropkey-wp-lifecycle-table">
								<tbody>

									<tr>
										<td>
											<?php echo esc_html__( 'Created', 'dropkey-wp' ); ?>
										</td>
										<td>
											<?php echo esc_html( $this->format_datetime( $subscription->get_created_at() ) ); ?>
										</td>
									</tr>

									<tr>
										<td>
											<?php echo esc_html__( 'Updated', 'dropkey-wp' ); ?>
										</td>
										<td>
											<?php echo esc_html( $this->format_datetime( $subscription->get_updated_at() ) ); ?>
										</td>
									</tr>

									<tr>
										<td>
											<?php echo esc_html__( 'Past Due', 'dropkey-wp' ); ?>
										</td>
										<td>
											<?php echo esc_html( $this->format_optional_datetime( $subscription->get_past_due_at() ) ); ?>
										</td>
									</tr>

									<tr>
										<td>
											<?php echo esc_html__( 'Cancelled', 'dropkey-wp' ); ?>
										</td>
										<td>
											<?php echo esc_html( $this->format_optional_datetime( $subscription->get_cancelled_at() ) ); ?>
										</td>
									</tr>

									<tr>
										<td>
											<?php echo esc_html__( 'Ended', 'dropkey-wp' ); ?>
										</td>
										<td>
											<?php echo esc_html( $this->format_optional_datetime( $subscription->get_ended_at() ) ); ?>
										</td>
									</tr>

								</tbody>
							</table>

						</div>
					</div>

					<div class="postbox">

						<div class="postbox-header">
							<h2>
								<?php echo esc_html__( 'IDs', 'dropkey-wp' ); ?>
							</h2>
						</div>

						<div class="inside">

							<table class="widefat striped dropkey-wp-lifecycle-table">
								<tbody>

									<tr>
										<td>
											<?php echo esc_html__( 'Subscription', 'dropkey-wp' ); ?>
										</td>
										<td>
											<strong>#<?php echo esc_html( $subscription->get_id() ); ?></strong>
										</td>
									</tr>

									<tr>
										<td>
											<?php echo esc_html__( 'Customer', 'dropkey-wp' ); ?>
										</td>
										<td>
											#<?php echo esc_html( $subscription->get_customer_id() ); ?>
										</td>
									</tr>

									<tr>
										<td>
											<?php echo esc_html__( 'Product', 'dropkey-wp' ); ?>
										</td>
										<td>
											#<?php echo esc_html( $subscription->get_product_id() ); ?>
										</td>
									</tr>

									<tr>
										<td>
											<?php echo esc_html__( 'Plan', 'dropkey-wp' ); ?>
										</td>
										<td>
											#<?php echo esc_html( $subscription->get_plan_id() ); ?>
										</td>
									</tr>

								</tbody>
							</table>

						</div>
					</div>

				</div>

			</div>

			<?php $this->render_admin_styles(); ?>

		</div>
		<?php
	}

	/**
	 * Handle subscription status change.
	 *
	 * @return void
	 */
	public function handle_change_status() {
		$this->ensure_capability();

		$subscription_id = isset( $_POST['subscription_id'] )
			? absint( $_POST['subscription_id'] )
			: 0;

		$nonce = isset( $_POST['_wpnonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) )
			: '';

		if (
			$subscription_id <= 0
			|| ! wp_verify_nonce(
				$nonce,
				'dropkey_wp_change_subscription_status_' . $subscription_id
			)
		) {
			wp_die(
				esc_html__(
					'Security check failed.',
					'dropkey-wp'
				)
			);
		}

		$new_status = isset( $_POST['new_status'] )
			? sanitize_key( wp_unslash( $_POST['new_status'] ) )
			: '';

		$result = $this->change_status->execute(
			$subscription_id,
			$new_status
		);

		$redirect_url = add_query_arg(
			array(
				'page' => 'dropkey-wp-subscriptions',
				'view' => 'detail',
				'id'   => $subscription_id,
			),
			admin_url( 'admin.php' )
		);

		if ( is_wp_error( $result ) ) {
			$redirect_url = add_query_arg(
				'dropkey_status_error',
				rawurlencode( $result->get_error_message() ),
				$redirect_url
			);

			wp_safe_redirect( $redirect_url );
			exit;
		}

		$redirect_url = add_query_arg(
			'dropkey_status_updated',
			'1',
			$redirect_url
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Render detail not-found state.
	 *
	 * @return void
	 */
	private function render_detail_not_found() {
		$back_url = admin_url(
			'admin.php?page=dropkey-wp-subscriptions'
		);

		?>
		<div class="wrap">

			<h1>
				<?php echo esc_html__( 'Subscription Not Found', 'dropkey-wp' ); ?>
			</h1>

			<div class="notice notice-error inline">
				<p>
					<?php echo esc_html__( 'The requested subscription could not be found.', 'dropkey-wp' ); ?>
				</p>
			</div>

			<p>
				<a
					href="<?php echo esc_url( $back_url ); ?>"
					class="button"
				>
					<?php echo esc_html__( 'Back to Subscriptions', 'dropkey-wp' ); ?>
				</a>
			</p>

		</div>
		<?php
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_admin_notice() {
		if (
			isset( $_GET['dropkey_status_updated'] )
			&& '1' === sanitize_text_field(
				wp_unslash( $_GET['dropkey_status_updated'] )
			)
		) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php echo esc_html__( 'Subscription status updated successfully.', 'dropkey-wp' ); ?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['dropkey_status_error'] ) ) {
			$error = sanitize_text_field(
				wp_unslash( $_GET['dropkey_status_error'] )
			);

			if ( '' !== $error ) {
				?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $error ); ?></p>
				</div>
				<?php
			}
		}
	}

	/**
	 * Get allowed lifecycle transitions.
	 *
	 * @param string $current_status Current status.
	 * @return string[]
	 */
	private function get_allowed_transitions( $current_status ) {
		$transitions = array(
			Subscription::STATUS_PENDING => array(
				Subscription::STATUS_ACTIVE,
				Subscription::STATUS_CANCELLED,
			),
			Subscription::STATUS_ACTIVE => array(
				Subscription::STATUS_PAST_DUE,
				Subscription::STATUS_SUSPENDED,
				Subscription::STATUS_CANCELLED,
				Subscription::STATUS_EXPIRED,
			),
			Subscription::STATUS_PAST_DUE => array(
				Subscription::STATUS_ACTIVE,
				Subscription::STATUS_SUSPENDED,
				Subscription::STATUS_CANCELLED,
				Subscription::STATUS_EXPIRED,
			),
			Subscription::STATUS_SUSPENDED => array(
				Subscription::STATUS_ACTIVE,
				Subscription::STATUS_CANCELLED,
				Subscription::STATUS_EXPIRED,
			),
			Subscription::STATUS_CANCELLED => array(
				Subscription::STATUS_EXPIRED,
			),
			Subscription::STATUS_EXPIRED => array(),
		);

		return isset( $transitions[ $current_status ] )
			? $transitions[ $current_status ]
			: array();
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
			''                             => __( 'All', 'dropkey-wp' ),
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

				$filter_links[] =
					'<li>' .
					'<a href="' . esc_url( $url ) . '"' .
					( $current_status === $status ? ' class="current"' : '' ) .
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
						<?php echo esc_html( $this->get_gateway_label( $available_gateway ) ); ?>
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

			<button type="submit" class="button">
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
	 * Render page-specific admin styles.
	 *
	 * These styles only provide layout and spacing where WordPress's
	 * standard admin classes do not provide the required arrangement.
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

			.dropkey-wp-period {
				display: flex;
				flex-direction: column;
				gap: 5px;
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

			.dropkey-wp-detail-header {
				display: flex;
				align-items: flex-start;
				justify-content: space-between;
				gap: 20px;
				margin: 16px 0 4px;
			}

			.dropkey-wp-detail-header h1 {
				margin: 0;
				display: flex;
				align-items: center;
				gap: 10px;
				flex-wrap: wrap;
			}

			.dropkey-wp-breadcrumb {
				margin: 0 0 5px;
				color: #646970;
				font-size: 13px;
			}

			.dropkey-wp-breadcrumb span {
				padding: 0 4px;
			}

			.dropkey-wp-detail-header-actions {
				padding-top: 25px;
			}

			.dropkey-wp-subscription-detail {
				display: grid;
				grid-template-columns: minmax(0, 2.15fr) minmax(280px, 1fr);
				gap: 20px;
				max-width: 1280px;
			}

			.dropkey-wp-subscription-detail .postbox {
				margin-bottom: 20px;
			}

			.dropkey-wp-subscription-detail .postbox-header {
				padding: 0 12px;
			}

			.dropkey-wp-subscription-detail .postbox-header h2 {
				padding: 10px 0;
				margin: 0;
			}

			.dropkey-wp-subscription-detail .inside {
				margin: 0;
			}

			.dropkey-wp-subscription-detail .form-table {
				margin: 0;
			}

			.dropkey-wp-subscription-detail .form-table th {
				width: 190px;
				padding-left: 12px;
				white-space: nowrap;
			}

			.dropkey-wp-subscription-detail .form-table td {
				padding-right: 12px;
			}

			.dropkey-wp-subscription-detail .form-table tr:first-child th,
			.dropkey-wp-subscription-detail .form-table tr:first-child td {
				padding-top: 10px;
			}

			.dropkey-wp-subscription-detail .form-table tr:last-child th,
			.dropkey-wp-subscription-detail .form-table tr:last-child td {
				padding-bottom: 10px;
			}

			.dropkey-wp-period-arrow {
				display: inline-block;
				margin: 0 8px;
				color: #646970;
			}

			.dropkey-wp-price {
				font-size: 14px;
			}

			.dropkey-wp-copy-value {
				word-break: break-all;
			}

			.dropkey-wp-current-status {
				margin: 0 0 14px;
			}

			.dropkey-wp-lifecycle-table {
				border: 0;
				box-shadow: none;
			}

			.dropkey-wp-lifecycle-table td {
				padding: 8px 10px;
				vertical-align: top;
			}

			.dropkey-wp-lifecycle-table td:first-child {
				width: 38%;
				font-weight: 600;
				white-space: nowrap;
			}

			.dropkey-wp-lifecycle-table td:last-child {
				text-align: right;
				color: #50575e;
			}

			@media screen and (max-width: 960px) {
				.dropkey-wp-subscription-detail {
					grid-template-columns: 1fr;
				}

				.dropkey-wp-detail-header {
					display: block;
				}

				.dropkey-wp-detail-header-actions {
					padding-top: 10px;
				}
			}

			@media screen and (max-width: 782px) {
				.dropkey-wp-subscription-detail .form-table th,
				.dropkey-wp-subscription-detail .form-table td {
					width: auto;
					white-space: normal;
				}

				.dropkey-wp-period-arrow {
					margin: 0 4px;
				}
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
	 * @param string $price    Price.
	 * @param string $currency Currency.
	 * @return string
	 */
	private function format_plan_price( $price, $currency ) {
		return strtoupper( $currency ) . ' ' . $price;
	}

	/**
	 * Format billing interval.
	 *
	 * @param string $interval Billing interval.
	 * @param int    $count    Billing interval count.
	 * @return string
	 */
	private function format_billing( $interval, $count ) {
		$count = (int) $count;

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
			__( 'Every %1$d %2$s', 'dropkey-wp' ),
			$count,
			$label
		);
	}

	/**
	 * Format UTC datetime for WordPress timezone.
	 *
	 * @param string $datetime UTC datetime.
	 * @return string
	 */
	private function format_datetime( $datetime ) {
		if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
			return __( 'Not set', 'dropkey-wp' );
		}

		$timestamp = strtotime(
			$datetime . ' UTC'
		);

		if ( false === $timestamp ) {
			return $datetime;
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * Format an optional UTC datetime.
	 *
	 * @param string $datetime UTC datetime.
	 * @return string
	 */
	private function format_optional_datetime( $datetime ) {
		if ( empty( $datetime ) || '0000-00-00 00:00:00' === $datetime ) {
			return __( 'Not set', 'dropkey-wp' );
		}

		return $this->format_datetime( $datetime );
	}

	/**
	 * Ensure current user can manage subscriptions.
	 *
	 * @return void
	 */
	private function ensure_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__(
					'You do not have permission to manage subscriptions.',
					'dropkey-wp'
				)
			);
		}
	}
}


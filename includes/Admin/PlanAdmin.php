<?php
/**
 * DropKey WP plan administration.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Admin;

use DropKeyWP\Application\ChangePlanStatus;
use DropKeyWP\Application\CreatePlan;
use DropKeyWP\Application\UpdatePlan;
use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Domain\BillingInterval;
use DropKeyWP\Domain\PlanStatus;

defined( 'ABSPATH' ) || exit;

final class PlanAdmin {

	/**
	 * Plan repository.
	 *
	 * @var PlanRepository
	 */
	private $plans;

	/**
	 * Product repository.
	 *
	 * @var ProductRepository
	 */
	private $products;

	/**
	 * Constructor.
	 *
	 * @param PlanRepository    $plans    Plan repository.
	 * @param ProductRepository $products Product repository.
	 */
	public function __construct(
		PlanRepository $plans,
		ProductRepository $products
	) {
		$this->plans    = $plans;
		$this->products = $products;
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
			'admin_head',
			array( $this, 'hide_edit_plan_menu_item' )
		);

		add_action(
			'admin_post_dropkey_wp_create_plan',
			array( $this, 'handle_create_plan' )
		);

		add_action(
			'admin_post_dropkey_wp_update_plan',
			array( $this, 'handle_update_plan' )
		);

		add_action(
			'admin_post_dropkey_wp_change_plan_status',
			array( $this, 'handle_change_plan_status' )
		);
	}

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'dropkey-wp-products',
			__( 'Plans', 'dropkey-wp' ),
			__( 'Plans', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-plans',
			array( $this, 'render_plans_page' )
		);

		add_submenu_page(
			'dropkey-wp-products',
			__( 'Add Plan', 'dropkey-wp' ),
			__( 'Add Plan', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-plan-add',
			array( $this, 'render_add_plan_page' )
		);

		add_submenu_page(
			'dropkey-wp-products',
			__( 'Edit Plan', 'dropkey-wp' ),
			__( 'Edit Plan', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-plan-edit',
			array( $this, 'render_edit_plan_page' )
		);
	}

	/**
	 * Hide the Edit Plan submenu item from the sidebar.
	 *
	 * The page remains registered and accessible through direct URLs.
	 *
	 * @return void
	 */
	public function hide_edit_plan_menu_item() {
		?>
		<style>
			#toplevel_page_dropkey-wp-products .wp-submenu a[href*="page=dropkey-wp-plan-edit"] {
				display: none;
			}
		</style>
		<?php
	}

	/**
	 * Render plans list page.
	 *
	 * @return void
	 */
	public function render_plans_page() {
		$this->ensure_capability();

		$product_id = isset( $_GET['product_id'] )
			? absint( $_GET['product_id'] )
			: 0;

		if ( $product_id < 1 ) {
			$product_id = $this->get_first_product_id();
		}

		if ( $product_id < 1 ) {
			?>
			<div class="wrap">
				<h1>
					<?php echo esc_html__( 'Plans', 'dropkey-wp' ); ?>
				</h1>

				<p>
					<?php
					echo esc_html__(
						'Create a product before creating plans.',
						'dropkey-wp'
					);
					?>
				</p>

				<p>
					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=dropkey-wp-product-add' ) ); ?>"
						class="button button-primary"
					>
						<?php echo esc_html__( 'Add Product', 'dropkey-wp' ); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		}

		$product = $this->products->find( $product_id );

		if ( ! $product ) {
			wp_die(
				esc_html__(
					'The selected product could not be found.',
					'dropkey-wp'
				)
			);
		}

		$status = isset( $_GET['status'] )
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: '';

		if (
			'' !== $status
			&& ! PlanStatus::is_valid( $status )
		) {
			$status = '';
		}

		$plans = $this->plans->all_by_product(
			$product_id,
			$status
		);

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php echo esc_html__( 'Plans', 'dropkey-wp' ); ?>
			</h1>

			<a
				href="<?php echo esc_url( $this->get_add_url( $product_id ) ); ?>"
				class="page-title-action"
			>
				<?php echo esc_html__( 'Add Plan', 'dropkey-wp' ); ?>
			</a>

			<hr class="wp-header-end" />

			<?php $this->render_admin_notices(); ?>

			<h2>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: product name. */
						__( 'Product: %s', 'dropkey-wp' ),
						$product->get_name()
					)
				);
				?>
			</h2>

			<?php $this->render_product_selector( $product_id ); ?>

			<?php $this->render_status_filters( $product_id, $status ); ?>

			<?php if ( empty( $plans ) ) : ?>

				<p>
					<?php
					echo esc_html__(
						'No plans have been created for this product yet.',
						'dropkey-wp'
					);
					?>
				</p>

			<?php else : ?>

				<table class="widefat fixed striped">
					<thead>
						<tr>
							<th scope="col">
								<?php echo esc_html__( 'Name', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Slug', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Price', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Billing', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Activations', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Actions', 'dropkey-wp' ); ?>
							</th>
						</tr>
					</thead>

					<tbody>
						<?php foreach ( $plans as $plan ) : ?>

							<tr>
								<td>
									<strong>
										<?php echo esc_html( $plan->get_name() ); ?>
									</strong>
								</td>

								<td>
									<code>
										<?php echo esc_html( $plan->get_slug() ); ?>
									</code>
								</td>

								<td>
									<?php
									echo esc_html(
										$this->format_price(
											$plan->get_price(),
											$plan->get_currency()
										)
									);
									?>
								</td>

								<td>
									<?php
									echo esc_html(
										$this->format_billing(
											$plan->get_billing_interval(),
											$plan->get_billing_interval_count()
										)
									);
									?>
								</td>

								<td>
									<?php
									echo esc_html(
										$plan->get_activation_limit()
									);
									?>
								</td>

								<td>
									<?php
									echo esc_html(
										$this->get_status_label(
											$plan->get_status()
										)
									);
									?>
								</td>

								<td>
									<a
										href="<?php echo esc_url( $this->get_edit_url( $plan->get_id() ) ); ?>"
									>
										<?php echo esc_html__( 'Edit', 'dropkey-wp' ); ?>
									</a>

									<?php if ( PlanStatus::ACTIVE === $plan->get_status() ) : ?>

										<span aria-hidden="true"> | </span>

										<a
											href="<?php echo esc_url( $this->get_status_action_url( $plan->get_id(), PlanStatus::ARCHIVED ) ); ?>"
										>
											<?php echo esc_html__( 'Archive', 'dropkey-wp' ); ?>
										</a>

									<?php elseif ( PlanStatus::ARCHIVED === $plan->get_status() ) : ?>

										<span aria-hidden="true"> | </span>

										<a
											href="<?php echo esc_url( $this->get_status_action_url( $plan->get_id(), PlanStatus::ACTIVE ) ); ?>"
										>
											<?php echo esc_html__( 'Restore', 'dropkey-wp' ); ?>
										</a>

									<?php endif; ?>
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
	 * Render product selector.
	 *
	 * @param int $current_product_id Current product ID.
	 * @return void
	 */
	private function render_product_selector( $current_product_id ) {
		$products = $this->products->all();

		if ( empty( $products ) ) {
			return;
		}

		?>
		<form method="get">
			<input
				type="hidden"
				name="page"
				value="dropkey-wp-plans"
			/>

			<label for="dropkey_wp_plan_product">
				<strong>
					<?php echo esc_html__( 'Product:', 'dropkey-wp' ); ?>
				</strong>
			</label>

			<select
				name="product_id"
				id="dropkey_wp_plan_product"
			>
				<?php foreach ( $products as $product ) : ?>

					<option
						value="<?php echo esc_attr( $product->get_id() ); ?>"
						<?php selected( $current_product_id, $product->get_id() ); ?>
					>
						<?php echo esc_html( $product->get_name() ); ?>
					</option>

				<?php endforeach; ?>
			</select>

			<?php
			submit_button(
				__( 'View Plans', 'dropkey-wp' ),
				'secondary',
				'submit',
				false
			);
			?>

		</form>

		<br />
		<?php
	}

	/**
	 * Render plan status filters.
	 *
	 * @param int    $product_id     Product ID.
	 * @param string $current_status Current status.
	 * @return void
	 */
	private function render_status_filters(
		$product_id,
		$current_status
	) {
		$filters = array(
			''                   => __( 'All', 'dropkey-wp' ),
			PlanStatus::ACTIVE   => __( 'Active', 'dropkey-wp' ),
			PlanStatus::ARCHIVED => __( 'Archived', 'dropkey-wp' ),
		);

		?>
		<ul class="subsubsub">
			<?php
			$filter_links = array();
			$total        = count( $filters );
			$index        = 0;

			foreach ( $filters as $status => $label ) {
				$url = add_query_arg(
					array(
						'page'       => 'dropkey-wp-plans',
						'product_id' => $product_id,
					),
					admin_url( 'admin.php' )
				);

				if ( '' !== $status ) {
					$url = add_query_arg(
						'status',
						$status,
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
	 * Render add plan page.
	 *
	 * @return void
	 */
	public function render_add_plan_page() {
		$this->ensure_capability();

		$product_id = isset( $_GET['product_id'] )
			? absint( $_GET['product_id'] )
			: 0;

		if ( $product_id < 1 ) {
			$product_id = $this->get_first_product_id();
		}

		if ( $product_id < 1 ) {
			wp_die(
				esc_html__(
					'Create a product before creating a plan.',
					'dropkey-wp'
				)
			);
		}

		$product = $this->products->find( $product_id );

		if ( ! $product ) {
			wp_die(
				esc_html__(
					'The selected product could not be found.',
					'dropkey-wp'
				)
			);
		}

		$form_data = array(
			'product_id'             => $product_id,
			'name'                   => '',
			'slug'                   => '',
			'price'                  => '0.0000',
			'currency'               => 'USD',
			'billing_interval'       => BillingInterval::MONTH,
			'billing_interval_count' => 1,
			'activation_limit'       => 1,
			'status'                 => PlanStatus::ACTIVE,
		);

		$form_errors = array();

		$error_token = isset( $_GET['error'] )
			? sanitize_key( wp_unslash( $_GET['error'] ) )
			: '';

		if ( '' !== $error_token ) {
			$error_data = $this->get_form_error_data(
				$error_token,
				'create'
			);

			if ( is_array( $error_data ) ) {
				$form_data = array_merge(
					$form_data,
					$error_data['data']
				);

				$form_errors = $error_data['errors'];
			}
		}

		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html__( 'Add Plan', 'dropkey-wp' ); ?>
			</h1>

			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: product name. */
						__( 'Product: %s', 'dropkey-wp' ),
						$product->get_name()
					)
				);
				?>
			</p>

			<?php $this->render_form_errors( $form_errors ); ?>

			<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			>
				<?php
				$this->render_plan_form(
					$form_data,
					'create'
				);
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render edit plan page.
	 *
	 * @return void
	 */
	public function render_edit_plan_page() {
		$this->ensure_capability();

		$plan_id = isset( $_GET['plan_id'] )
			? absint( $_GET['plan_id'] )
			: 0;

		if ( $plan_id < 1 ) {
			wp_die(
				esc_html__(
					'The plan ID is invalid.',
					'dropkey-wp'
				)
			);
		}

		$plan = $this->plans->find( $plan_id );

		if ( ! $plan ) {
			wp_die(
				esc_html__(
					'The plan could not be found.',
					'dropkey-wp'
				)
			);
		}

		$product = $this->products->find(
			$plan->get_product_id()
		);

		if ( ! $product ) {
			wp_die(
				esc_html__(
					'The product associated with this plan could not be found.',
					'dropkey-wp'
				)
			);
		}

		$form_data = array(
			'product_id'             => $plan->get_product_id(),
			'name'                   => $plan->get_name(),
			'slug'                   => $plan->get_slug(),
			'price'                  => $plan->get_price(),
			'currency'               => $plan->get_currency(),
			'billing_interval'       => $plan->get_billing_interval(),
			'billing_interval_count' => $plan->get_billing_interval_count(),
			'activation_limit'       => $plan->get_activation_limit(),
			'status'                 => $plan->get_status(),
		);

		$form_errors = array();

		$error_token = isset( $_GET['error'] )
			? sanitize_key( wp_unslash( $_GET['error'] ) )
			: '';

		if ( '' !== $error_token ) {
			$error_data = $this->get_form_error_data(
				$error_token,
				'update'
			);

			if (
				is_array( $error_data )
				&& isset( $error_data['plan_id'] )
				&& (int) $error_data['plan_id'] === $plan_id
			) {
				$form_data = array_merge(
					$form_data,
					$error_data['data']
				);

				$form_errors = $error_data['errors'];
			}
		}

		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html__( 'Edit Plan', 'dropkey-wp' ); ?>
			</h1>

			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: product name. */
						__( 'Product: %s', 'dropkey-wp' ),
						$product->get_name()
					)
				);
				?>
			</p>

			<?php $this->render_admin_notices(); ?>

			<?php $this->render_form_errors( $form_errors ); ?>

			<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			>
				<?php
				$this->render_plan_form(
					$form_data,
					'update',
					$plan_id
				);
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render plan form.
	 *
	 * @param array  $data    Form data.
	 * @param string $mode    Form mode.
	 * @param int    $plan_id Plan ID.
	 * @return void
	 */
	private function render_plan_form(
		array $data,
		$mode,
		$plan_id = 0
	) {
		$is_edit = 'update' === $mode;

		?>
		<input
			type="hidden"
			name="action"
			value="<?php echo esc_attr( $is_edit ? 'dropkey_wp_update_plan' : 'dropkey_wp_create_plan' ); ?>"
		/>

		<input
			type="hidden"
			name="product_id"
			value="<?php echo esc_attr( $data['product_id'] ); ?>"
		/>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="dropkey_wp_plan_name">
							<?php echo esc_html__( 'Name', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<input
							type="text"
							name="name"
							id="dropkey_wp_plan_name"
							class="regular-text"
							value="<?php echo esc_attr( $data['name'] ); ?>"
							required
						/>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_plan_slug">
							<?php echo esc_html__( 'Slug', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<input
							type="text"
							name="slug"
							id="dropkey_wp_plan_slug"
							class="regular-text"
							value="<?php echo esc_attr( $data['slug'] ); ?>"
							required
						/>

						<p class="description">
							<?php
							echo esc_html__(
								'A unique URL-friendly identifier for this plan within the product.',
								'dropkey-wp'
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_plan_price">
							<?php echo esc_html__( 'Price', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<input
							type="text"
							name="price"
							id="dropkey_wp_plan_price"
							class="regular-text"
							inputmode="decimal"
							value="<?php echo esc_attr( $data['price'] ); ?>"
							required
						/>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_plan_currency">
							<?php echo esc_html__( 'Currency', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<input
							type="text"
							name="currency"
							id="dropkey_wp_plan_currency"
							class="small-text"
							maxlength="3"
							value="<?php echo esc_attr( $data['currency'] ); ?>"
							required
						/>

						<p class="description">
							<?php
							echo esc_html__(
								'Use a three-letter currency code such as USD.',
								'dropkey-wp'
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_plan_billing_interval">
							<?php echo esc_html__( 'Billing Interval', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<select
							name="billing_interval"
							id="dropkey_wp_plan_billing_interval"
						>
							<?php foreach ( BillingInterval::all() as $interval ) : ?>

								<option
									value="<?php echo esc_attr( $interval ); ?>"
									<?php selected( $data['billing_interval'], $interval ); ?>
								>
									<?php
									echo esc_html(
										$this->get_interval_label( $interval )
									);
									?>
								</option>

							<?php endforeach; ?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_plan_billing_interval_count">
							<?php echo esc_html__( 'Interval Count', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<input
							type="number"
							name="billing_interval_count"
							id="dropkey_wp_plan_billing_interval_count"
							class="small-text"
							min="1"
							step="1"
							value="<?php echo esc_attr( $data['billing_interval_count'] ); ?>"
							required
						/>

						<p class="description">
							<?php
							echo esc_html__(
								'For example, 1 month or 3 months.',
								'dropkey-wp'
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_plan_activation_limit">
							<?php echo esc_html__( 'Activation Limit', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<input
							type="number"
							name="activation_limit"
							id="dropkey_wp_plan_activation_limit"
							class="small-text"
							min="1"
							step="1"
							value="<?php echo esc_attr( $data['activation_limit'] ); ?>"
							required
						/>

						<p class="description">
							<?php
							echo esc_html__(
								'Maximum number of active installations allowed for a license using this plan.',
								'dropkey-wp'
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_plan_status">
							<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<select
							name="status"
							id="dropkey_wp_plan_status"
						>
							<?php foreach ( PlanStatus::all() as $status ) : ?>

								<option
									value="<?php echo esc_attr( $status ); ?>"
									<?php selected( $data['status'], $status ); ?>
								>
									<?php
									echo esc_html(
										$this->get_status_label( $status )
									);
									?>
								</option>

							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</tbody>
		</table>

		<?php
		wp_nonce_field(
			'dropkey_wp_plan_' . $mode,
			'dropkey_wp_plan_nonce'
		);
		?>

		<input
			type="hidden"
			name="plan_id"
			value="<?php echo esc_attr( $plan_id ); ?>"
		/>

		<p class="submit">
			<button
				type="submit"
				class="button button-primary"
			>
				<?php
				echo esc_html(
					$is_edit
						? __( 'Update Plan', 'dropkey-wp' )
						: __( 'Create Plan', 'dropkey-wp' )
				);
				?>
			</button>

			<a
				href="<?php echo esc_url( $this->get_plans_url( $data['product_id'] ) ); ?>"
				class="button"
			>
				<?php echo esc_html__( 'Cancel', 'dropkey-wp' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Handle plan creation.
	 *
	 * @return void
	 */
	public function handle_create_plan() {
		$this->ensure_capability();

		check_admin_referer(
			'dropkey_wp_plan_create',
			'dropkey_wp_plan_nonce'
		);

		$data = $this->get_post_form_data();

		$service = new CreatePlan(
			$this->plans,
			$this->products
		);

		$result = $service->execute( $data );

		if ( is_wp_error( $result ) ) {
			$error_token = $this->store_form_error_data(
				'create',
				array(
					'data'   => $data,
					'errors' => $result->get_error_messages(),
				)
			);

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'dropkey-wp-plan-add',
						'product_id' => absint( $data['product_id'] ),
						'error'      => $error_token,
					),
					admin_url( 'admin.php' )
				)
			);

			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'dropkey-wp-plans',
					'product_id' => $result->get_product_id(),
					'created'    => '1',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Handle plan update.
	 *
	 * @return void
	 */
	public function handle_update_plan() {
		$this->ensure_capability();

		check_admin_referer(
			'dropkey_wp_plan_update',
			'dropkey_wp_plan_nonce'
		);

		$plan_id = isset( $_POST['plan_id'] )
			? absint( $_POST['plan_id'] )
			: 0;

		if ( $plan_id < 1 ) {
			wp_die(
				esc_html__(
					'The plan ID is invalid.',
					'dropkey-wp'
				)
			);
		}

		$data = $this->get_post_form_data();

		$service = new UpdatePlan(
			$this->plans,
			$this->products
		);

		$result = $service->execute(
			$plan_id,
			$data
		);

		if ( is_wp_error( $result ) ) {
			$error_token = $this->store_form_error_data(
				'update',
				array(
					'plan_id' => $plan_id,
					'data'    => $data,
					'errors'  => $result->get_error_messages(),
				)
			);

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'dropkey-wp-plan-edit',
						'plan_id' => $plan_id,
						'error'   => $error_token,
					),
					admin_url( 'admin.php' )
				)
			);

			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'dropkey-wp-plan-edit',
					'plan_id' => $plan_id,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Handle plan status change.
	 *
	 * @return void
	 */
	public function handle_change_plan_status() {
		$this->ensure_capability();

		$plan_id = isset( $_GET['plan_id'] )
			? absint( $_GET['plan_id'] )
			: 0;

		$status = isset( $_GET['status'] )
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: '';

		check_admin_referer(
			'dropkey_wp_change_plan_status_' . $plan_id . '_' . $status
		);

		$service = new ChangePlanStatus(
			$this->plans
		);

		$result = $service->execute(
			$plan_id,
			$status
		);

		if ( is_wp_error( $result ) ) {
			$product_id = 0;
			$plan       = $this->plans->find( $plan_id );

			if ( $plan ) {
				$product_id = $plan->get_product_id();
			}

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'dropkey-wp-plans',
						'product_id' => $product_id,
						'error'      => 'status_change',
					),
					admin_url( 'admin.php' )
				)
			);

			exit;
		}

		$notice = PlanStatus::ARCHIVED === $status
			? 'archived'
			: 'restored';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'dropkey-wp-plans',
					'product_id'    => $result->get_product_id(),
					'status_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Get submitted plan form data.
	 *
	 * @return array
	 */
	private function get_post_form_data() {
		return array(
			'product_id' => isset( $_POST['product_id'] )
				? absint(
					$_POST['product_id']
				)
				: 0,

			'name' => isset( $_POST['name'] )
				? sanitize_text_field(
					wp_unslash( $_POST['name'] )
				)
				: '',

			'slug' => isset( $_POST['slug'] )
				? sanitize_title(
					wp_unslash( $_POST['slug'] )
				)
				: '',

			'price' => isset( $_POST['price'] )
				? trim(
					wp_unslash( $_POST['price'] )
				)
				: '',

			'currency' => isset( $_POST['currency'] )
				? strtoupper(
					sanitize_text_field(
						wp_unslash( $_POST['currency'] )
					)
				)
				: '',

			'billing_interval' => isset( $_POST['billing_interval'] )
				? sanitize_key(
					wp_unslash( $_POST['billing_interval'] )
				)
				: '',

			'billing_interval_count' => isset( $_POST['billing_interval_count'] )
				? absint(
					$_POST['billing_interval_count']
				)
				: 0,

			'activation_limit' => isset( $_POST['activation_limit'] )
				? absint(
					$_POST['activation_limit']
				)
				: 0,

			'status' => isset( $_POST['status'] )
				? sanitize_key(
					wp_unslash( $_POST['status'] )
				)
				: '',
		);
	}

	/**
	 * Store form error data temporarily.
	 *
	 * @param string $context Form context.
	 * @param array  $data    Error and form data.
	 * @return string
	 */
	private function store_form_error_data( $context, array $data ) {
		$user_id = get_current_user_id();
		$token   = wp_generate_uuid4();

		$key = $this->get_form_error_transient_key(
			$user_id,
			$context,
			$token
		);

		set_transient(
			$key,
			$data,
			MINUTE_IN_SECONDS
		);

		return $token;
	}

	/**
	 * Retrieve and delete temporary form error data.
	 *
	 * @param string $token   Error token.
	 * @param string $context Form context.
	 * @return array|null
	 */
	private function get_form_error_data( $token, $context ) {
		$user_id = get_current_user_id();

		$key = $this->get_form_error_transient_key(
			$user_id,
			$context,
			$token
		);

		$data = get_transient( $key );

		if ( false !== $data ) {
			delete_transient( $key );
		}

		return is_array( $data )
			? $data
			: null;
	}

	/**
	 * Build form error transient key.
	 *
	 * @param int    $user_id User ID.
	 * @param string $context Form context.
	 * @param string $token   Error token.
	 * @return string
	 */
	private function get_form_error_transient_key(
		$user_id,
		$context,
		$token
	) {
		return 'dropkey_wp_plan_' .
			absint( $user_id ) .
			'_' .
			sanitize_key( $context ) .
			'_' .
			sanitize_key( $token );
	}

	/**
	 * Render validation errors.
	 *
	 * @param array $errors Error messages.
	 * @return void
	 */
	private function render_form_errors( array $errors ) {
		if ( empty( $errors ) ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<?php foreach ( $errors as $error ) : ?>
				<p><?php echo esc_html( $error ); ?></p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render URL-based admin notices.
	 *
	 * @return void
	 */
	private function render_admin_notices() {
		if ( isset( $_GET['created'] ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					echo esc_html__(
						'Plan created successfully.',
						'dropkey-wp'
					);
					?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['updated'] ) ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p>
					<?php
					echo esc_html__(
						'Plan updated successfully.',
						'dropkey-wp'
					);
					?>
				</p>
			</div>
			<?php
		}

		if ( isset( $_GET['status_notice'] ) ) {
			$notice = sanitize_key(
				wp_unslash( $_GET['status_notice'] )
			);

			if ( 'archived' === $notice ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html__(
							'Plan archived successfully.',
							'dropkey-wp'
						);
						?>
					</p>
				</div>
				<?php
			}

			if ( 'restored' === $notice ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html__(
							'Plan restored successfully.',
							'dropkey-wp'
						);
						?>
					</p>
				</div>
				<?php
			}
		}

		if ( isset( $_GET['error'] ) ) {
			$error = sanitize_key(
				wp_unslash( $_GET['error'] )
			);

			if ( 'status_change' === $error ) {
				?>
				<div class="notice notice-error is-dismissible">
					<p>
						<?php
						echo esc_html__(
							'The plan status could not be changed.',
							'dropkey-wp'
						);
						?>
					</p>
				</div>
				<?php
			}
		}
	}

	/**
	 * Get plans URL.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private function get_plans_url( $product_id ) {
		return add_query_arg(
			array(
				'page'       => 'dropkey-wp-plans',
				'product_id' => absint( $product_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Get add plan URL.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private function get_add_url( $product_id ) {
		return add_query_arg(
			array(
				'page'       => 'dropkey-wp-plan-add',
				'product_id' => absint( $product_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Get plan edit URL.
	 *
	 * @param int $plan_id Plan ID.
	 * @return string
	 */
	private function get_edit_url( $plan_id ) {
		return add_query_arg(
			array(
				'page'    => 'dropkey-wp-plan-edit',
				'plan_id' => absint( $plan_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Get plan status action URL.
	 *
	 * @param int    $plan_id Plan ID.
	 * @param string $status  Target status.
	 * @return string
	 */
	private function get_status_action_url( $plan_id, $status ) {
		$plan_id = absint( $plan_id );
		$status  = sanitize_key( $status );

		$url = add_query_arg(
			array(
				'action'  => 'dropkey_wp_change_plan_status',
				'plan_id' => $plan_id,
				'status'  => $status,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url(
			$url,
			'dropkey_wp_change_plan_status_' . $plan_id . '_' . $status
		);
	}

	/**
	 * Get the first product ID.
	 *
	 * @return int
	 */
	private function get_first_product_id() {
		$products = $this->products->all();

		if ( empty( $products ) ) {
			return 0;
		}

		return (int) $products[0]->get_id();
	}

	/**
	 * Ensure the current user has the required capability.
	 *
	 * @return void
	 */
	private function ensure_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__(
					'You are not allowed to manage plans.',
					'dropkey-wp'
				)
			);
		}
	}

	/**
	 * Get human-readable status label.
	 *
	 * @param string $status Plan status.
	 * @return string
	 */
	private function get_status_label( $status ) {
		$labels = array(
			PlanStatus::ACTIVE   => __( 'Active', 'dropkey-wp' ),
			PlanStatus::ARCHIVED => __( 'Archived', 'dropkey-wp' ),
		);

		return isset( $labels[ $status ] )
			? $labels[ $status ]
			: $status;
	}

	/**
	 * Get human-readable billing interval label.
	 *
	 * @param string $interval Billing interval.
	 * @return string
	 */
	private function get_interval_label( $interval ) {
		$labels = array(
			BillingInterval::DAY   => __( 'Day', 'dropkey-wp' ),
			BillingInterval::WEEK  => __( 'Week', 'dropkey-wp' ),
			BillingInterval::MONTH => __( 'Month', 'dropkey-wp' ),
			BillingInterval::YEAR  => __( 'Year', 'dropkey-wp' ),
		);

		return isset( $labels[ $interval ] )
			? $labels[ $interval ]
			: $interval;
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
			BillingInterval::DAY   => _n( 'day', 'days', $count, 'dropkey-wp' ),
			BillingInterval::WEEK  => _n( 'week', 'weeks', $count, 'dropkey-wp' ),
			BillingInterval::MONTH => _n( 'month', 'months', $count, 'dropkey-wp' ),
			BillingInterval::YEAR  => _n( 'year', 'years', $count, 'dropkey-wp' ),
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
	 * Format plan price.
	 *
	 * @param string $price    Plan price.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function format_price( $price, $currency ) {
		return sprintf(
			'%1$s %2$s',
			$currency,
			number_format_i18n(
				(float) $price,
				2
			)
		);
	}
}
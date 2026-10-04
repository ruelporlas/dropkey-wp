<?php
/**
 * DropKey WP product administration.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Admin;

use DropKeyWP\Application\ChangeProductStatus;
use DropKeyWP\Application\CreateProduct;
use DropKeyWP\Application\UpdateProduct;
use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Domain\ProductStatus;
use DropKeyWP\Domain\ProductType;

defined( 'ABSPATH' ) || exit;

final class ProductAdmin {

	/**
	 * Product repository.
	 *
	 * @var ProductRepository
	 */
	private $products;

	/**
	 * Constructor.
	 *
	 * @param ProductRepository $products Product repository.
	 */
	public function __construct( ProductRepository $products ) {
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
			'admin_post_dropkey_wp_create_product',
			array( $this, 'handle_create_product' )
		);

		add_action(
			'admin_post_dropkey_wp_update_product',
			array( $this, 'handle_update_product' )
		);

		add_action(
			'admin_post_dropkey_wp_change_product_status',
			array( $this, 'handle_change_product_status' )
		);
	}

	/**
	 * Register admin menu pages.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'DropKey WP', 'dropkey-wp' ),
			__( 'DropKey WP', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-products',
			array( $this, 'render_products_page' ),
			'dashicons-admin-network',
			56
		);

		add_submenu_page(
			'dropkey-wp-products',
			__( 'Products', 'dropkey-wp' ),
			__( 'Products', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-products',
			array( $this, 'render_products_page' )
		);

		add_submenu_page(
			'dropkey-wp-products',
			__( 'Add Product', 'dropkey-wp' ),
			__( 'Add Product', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-product-add',
			array( $this, 'render_add_product_page' )
		);

		add_submenu_page(
			'dropkey-wp-products',
			__( 'Edit Product', 'dropkey-wp' ),
			__( 'Edit Product', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-product-edit',
			array( $this, 'render_edit_product_page' )
		);
	}

	/**
	 * Render products list page.
	 *
	 * @return void
	 */
	public function render_products_page() {
		$this->ensure_capability();

		$status = isset( $_GET['status'] )
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: '';

		if (
			'' !== $status
			&& ! ProductStatus::is_valid( $status )
		) {
			$status = '';
		}

		$products = $this->products->all( $status );

		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php echo esc_html__( 'Products', 'dropkey-wp' ); ?>
			</h1>

			<a
				href="<?php echo esc_url( admin_url( 'admin.php?page=dropkey-wp-product-add' ) ); ?>"
				class="page-title-action"
			>
				<?php echo esc_html__( 'Add Product', 'dropkey-wp' ); ?>
			</a>

			<hr class="wp-header-end" />

			<?php $this->render_admin_notices(); ?>

			<?php $this->render_status_filters( $status ); ?>

			<?php if ( empty( $products ) ) : ?>

				<p>
					<?php
					echo esc_html__(
						'No products have been created yet.',
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
								<?php echo esc_html__( 'Type', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Created', 'dropkey-wp' ); ?>
							</th>

							<th scope="col">
								<?php echo esc_html__( 'Actions', 'dropkey-wp' ); ?>
							</th>
						</tr>
					</thead>

					<tbody>
						<?php foreach ( $products as $product ) : ?>

							<tr>
								<td>
									<strong>
										<?php echo esc_html( $product->get_name() ); ?>
									</strong>
								</td>

								<td>
									<code>
										<?php echo esc_html( $product->get_slug() ); ?>
									</code>
								</td>

								<td>
									<?php
									echo esc_html(
										$this->get_type_label(
											$product->get_type()
										)
									);
									?>
								</td>

								<td>
									<?php
									echo esc_html(
										$this->get_status_label(
											$product->get_status()
										)
									);
									?>
								</td>

								<td>
									<?php
									echo esc_html(
										$this->format_datetime(
											$product->get_created_at()
										)
									);
									?>
								</td>

								<td>
									<a
										href="<?php echo esc_url( $this->get_edit_url( $product->get_id() ) ); ?>"
									>
										<?php echo esc_html__( 'Edit', 'dropkey-wp' ); ?>
									</a>

									<?php if ( ProductStatus::ACTIVE === $product->get_status() ) : ?>

										<span aria-hidden="true"> | </span>

										<a
											href="<?php echo esc_url( $this->get_status_action_url( $product->get_id(), ProductStatus::ARCHIVED ) ); ?>"
										>
											<?php echo esc_html__( 'Archive', 'dropkey-wp' ); ?>
										</a>

									<?php elseif ( ProductStatus::ARCHIVED === $product->get_status() ) : ?>

										<span aria-hidden="true"> | </span>

										<a
											href="<?php echo esc_url( $this->get_status_action_url( $product->get_id(), ProductStatus::ACTIVE ) ); ?>"
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
	 * Render product status filters.
	 *
	 * @param string $current_status Current status filter.
	 * @return void
	 */
	private function render_status_filters( $current_status ) {
		$filters = array(
			''                      => __( 'All', 'dropkey-wp' ),
			ProductStatus::ACTIVE   => __( 'Active', 'dropkey-wp' ),
			ProductStatus::ARCHIVED => __( 'Archived', 'dropkey-wp' ),
		);

		?>
		<ul class="subsubsub">
			<?php
			$filter_links = array();
			$total        = count( $filters );
			$index        = 0;

			foreach ( $filters as $status => $label ) {
				$url = admin_url( 'admin.php?page=dropkey-wp-products' );

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
	 * Render add product page.
	 *
	 * @return void
	 */
	public function render_add_product_page() {
		$this->ensure_capability();

		$form_data = array(
			'name'        => '',
			'slug'        => '',
			'type'        => ProductType::WORDPRESS_PLUGIN,
			'description' => '',
			'status'      => ProductStatus::ACTIVE,
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
				<?php echo esc_html__( 'Add Product', 'dropkey-wp' ); ?>
			</h1>

			<?php $this->render_form_errors( $form_errors ); ?>

			<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			>
				<?php
				$this->render_product_form(
					$form_data,
					'create'
				);
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render edit product page.
	 *
	 * @return void
	 */
	public function render_edit_product_page() {
		$this->ensure_capability();

		$product_id = isset( $_GET['product_id'] )
			? absint( $_GET['product_id'] )
			: 0;

		if ( $product_id < 1 ) {
			wp_die(
				esc_html__(
					'The product ID is invalid.',
					'dropkey-wp'
				)
			);
		}

		$product = $this->products->find( $product_id );

		if ( ! $product ) {
			wp_die(
				esc_html__(
					'The product could not be found.',
					'dropkey-wp'
				)
			);
		}

		$form_data = array(
			'name'        => $product->get_name(),
			'slug'        => $product->get_slug(),
			'type'        => $product->get_type(),
			'description' => $product->get_description(),
			'status'      => $product->get_status(),
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
				&& isset( $error_data['product_id'] )
				&& (int) $error_data['product_id'] === $product_id
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
				<?php echo esc_html__( 'Edit Product', 'dropkey-wp' ); ?>
			</h1>

			<?php $this->render_admin_notices(); ?>

			<?php $this->render_form_errors( $form_errors ); ?>

			<form
				method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			>
				<?php
				$this->render_product_form(
					$form_data,
					'update',
					$product_id
				);
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render product form.
	 *
	 * @param array  $data       Form data.
	 * @param string $mode       Form mode.
	 * @param int    $product_id Product ID.
	 * @return void
	 */
	private function render_product_form(
		array $data,
		$mode,
		$product_id = 0
	) {
		$is_edit = 'update' === $mode;

		?>
		<input
			type="hidden"
			name="action"
			value="<?php echo esc_attr( $is_edit ? 'dropkey_wp_update_product' : 'dropkey_wp_create_product' ); ?>"
		/>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">
						<label for="dropkey_wp_product_name">
							<?php echo esc_html__( 'Name', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<input
							type="text"
							name="name"
							id="dropkey_wp_product_name"
							class="regular-text"
							value="<?php echo esc_attr( $data['name'] ); ?>"
							required
						/>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_product_slug">
							<?php echo esc_html__( 'Slug', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<input
							type="text"
							name="slug"
							id="dropkey_wp_product_slug"
							class="regular-text"
							value="<?php echo esc_attr( $data['slug'] ); ?>"
							required
						/>

						<p class="description">
							<?php
							echo esc_html__(
								'A unique URL-friendly identifier for the product.',
								'dropkey-wp'
							);
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_product_type">
							<?php echo esc_html__( 'Type', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<select
							name="type"
							id="dropkey_wp_product_type"
						>
							<?php foreach ( ProductType::all() as $type ) : ?>

								<option
									value="<?php echo esc_attr( $type ); ?>"
									<?php selected( $data['type'], $type ); ?>
								>
									<?php
									echo esc_html(
										$this->get_type_label( $type )
									);
									?>
								</option>

							<?php endforeach; ?>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_product_description">
							<?php echo esc_html__( 'Description', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<textarea
							name="description"
							id="dropkey_wp_product_description"
							class="large-text"
							rows="6"
						><?php echo esc_textarea( $data['description'] ); ?></textarea>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="dropkey_wp_product_status">
							<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
						</label>
					</th>

					<td>
						<select
							name="status"
							id="dropkey_wp_product_status"
						>
							<?php foreach ( ProductStatus::all() as $status ) : ?>

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
			'dropkey_wp_product_' . $mode,
			'dropkey_wp_product_nonce'
		);
		?>

		<input
			type="hidden"
			name="product_id"
			value="<?php echo esc_attr( $product_id ); ?>"
		/>

		<p class="submit">
			<button
				type="submit"
				class="button button-primary"
			>
				<?php
				echo esc_html(
					$is_edit
						? __( 'Update Product', 'dropkey-wp' )
						: __( 'Create Product', 'dropkey-wp' )
				);
				?>
			</button>

			<a
				href="<?php echo esc_url( admin_url( 'admin.php?page=dropkey-wp-products' ) ); ?>"
				class="button"
			>
				<?php echo esc_html__( 'Cancel', 'dropkey-wp' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Handle product creation.
	 *
	 * @return void
	 */
	public function handle_create_product() {
		$this->ensure_capability();

		check_admin_referer(
			'dropkey_wp_product_create',
			'dropkey_wp_product_nonce'
		);

		$data = $this->get_post_form_data();

		$service = new CreateProduct(
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
						'page'  => 'dropkey-wp-product-add',
						'error' => $error_token,
					),
					admin_url( 'admin.php' )
				)
			);

			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'dropkey-wp-products',
					'created' => '1',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Handle product update.
	 *
	 * @return void
	 */
	public function handle_update_product() {
		$this->ensure_capability();

		check_admin_referer(
			'dropkey_wp_product_update',
			'dropkey_wp_product_nonce'
		);

		$product_id = isset( $_POST['product_id'] )
			? absint( $_POST['product_id'] )
			: 0;

		if ( $product_id < 1 ) {
			wp_die(
				esc_html__(
					'The product ID is invalid.',
					'dropkey-wp'
				)
			);
		}

		$data = $this->get_post_form_data();

		$service = new UpdateProduct(
			$this->products
		);

		$result = $service->execute(
			$product_id,
			$data
		);

		if ( is_wp_error( $result ) ) {
			$error_token = $this->store_form_error_data(
				'update',
				array(
					'product_id' => $product_id,
					'data'       => $data,
					'errors'     => $result->get_error_messages(),
				)
			);

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'dropkey-wp-product-edit',
						'product_id' => $product_id,
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
					'page'       => 'dropkey-wp-product-edit',
					'product_id' => $product_id,
					'updated'    => '1',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Handle product status change.
	 *
	 * @return void
	 */
	public function handle_change_product_status() {
		$this->ensure_capability();

		$product_id = isset( $_GET['product_id'] )
			? absint( $_GET['product_id'] )
			: 0;

		$status = isset( $_GET['status'] )
			? sanitize_key( wp_unslash( $_GET['status'] ) )
			: '';

		check_admin_referer(
			'dropkey_wp_change_product_status_' . $product_id . '_' . $status
		);

		$service = new ChangeProductStatus(
			$this->products
		);

		$result = $service->execute(
			$product_id,
			$status
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'  => 'dropkey-wp-products',
						'error' => 'status_change',
					),
					admin_url( 'admin.php' )
				)
			);

			exit;
		}

		$notice = ProductStatus::ARCHIVED === $status
			? 'archived'
			: 'restored';

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'dropkey-wp-products',
					'status_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Get submitted product form data.
	 *
	 * @return array
	 */
	private function get_post_form_data() {
		return array(
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

			'type' => isset( $_POST['type'] )
				? sanitize_key(
					wp_unslash( $_POST['type'] )
				)
				: '',

			'description' => isset( $_POST['description'] )
				? wp_kses_post(
					wp_unslash( $_POST['description'] )
				)
				: '',

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
		return 'dropkey_wp_product_' .
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
						'Product created successfully.',
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
						'Product updated successfully.',
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
							'Product archived successfully.',
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
							'Product restored successfully.',
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
							'The product status could not be changed.',
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
	 * Get product edit URL.
	 *
	 * @param int $product_id Product ID.
	 * @return string
	 */
	private function get_edit_url( $product_id ) {
		return add_query_arg(
			array(
				'page'       => 'dropkey-wp-product-edit',
				'product_id' => absint( $product_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Get product status action URL.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $status     Target status.
	 * @return string
	 */
	private function get_status_action_url( $product_id, $status ) {
		$product_id = absint( $product_id );
		$status     = sanitize_key( $status );

		$url = add_query_arg(
			array(
				'action'     => 'dropkey_wp_change_product_status',
				'product_id' => $product_id,
				'status'     => $status,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url(
			$url,
			'dropkey_wp_change_product_status_' . $product_id . '_' . $status
		);
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
					'You are not allowed to manage products.',
					'dropkey-wp'
				)
			);
		}
	}

	/**
	 * Get human-readable product type label.
	 *
	 * @param string $type Product type.
	 * @return string
	 */
	private function get_type_label( $type ) {
		$labels = array(
			ProductType::WORDPRESS_PLUGIN => __( 'WordPress Plugin', 'dropkey-wp' ),
			ProductType::WORDPRESS_THEME  => __( 'WordPress Theme', 'dropkey-wp' ),
			ProductType::DIGITAL_DOWNLOAD => __( 'Digital Download', 'dropkey-wp' ),
			ProductType::OTHER            => __( 'Other', 'dropkey-wp' ),
		);

		return isset( $labels[ $type ] )
			? $labels[ $type ]
			: $type;
	}

	/**
	 * Get human-readable product status label.
	 *
	 * @param string $status Product status.
	 * @return string
	 */
	private function get_status_label( $status ) {
		$labels = array(
			ProductStatus::ACTIVE   => __( 'Active', 'dropkey-wp' ),
			ProductStatus::ARCHIVED => __( 'Archived', 'dropkey-wp' ),
		);

		return isset( $labels[ $status ] )
			? $labels[ $status ]
			: $status;
	}

	/**
	 * Format a database datetime.
	 *
	 * @param string $datetime Database datetime.
	 * @return string
	 */
	private function format_datetime( $datetime ) {
		$timestamp = strtotime( $datetime );

		if ( false === $timestamp ) {
			return $datetime;
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}
}
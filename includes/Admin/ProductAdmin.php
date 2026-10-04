<?php
/**
 * DropKey WP product administration.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Admin;

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
	 * Register product admin pages and actions.
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
	 * Register product admin menu.
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
			null,
			__( 'Edit Product', 'dropkey-wp' ),
			__( 'Edit Product', 'dropkey-wp' ),
			'manage_options',
			'dropkey-wp-product-edit',
			array( $this, 'render_edit_product_page' )
		);
	}

	/**
	 * Render the products list page.
	 *
	 * @return void
	 */
	public function render_products_page() {
		$this->ensure_capability();

		$products = $this->products->all();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php echo esc_html__( 'Products', 'dropkey-wp' ); ?>
			</h1>

			<a
				href="<?php echo esc_url( admin_url( 'admin.php?page=dropkey-wp-product-add' ) ); ?>"
				class="page-title-action"
			>
				<?php echo esc_html__( 'Add New', 'dropkey-wp' ); ?>
			</a>

			<hr class="wp-header-end" />

			<?php $this->render_admin_notices(); ?>

			<?php if ( empty( $products ) ) : ?>

				<div class="notice notice-info">
					<p>
						<?php
						echo esc_html__(
							'No products have been created yet.',
							'dropkey-wp'
						);
						?>
					</p>
				</div>

			<?php else : ?>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col">
								<?php echo esc_html__( 'Product', 'dropkey-wp' ); ?>
							</th>
							<th scope="col">
								<?php echo esc_html__( 'Type', 'dropkey-wp' ); ?>
							</th>
							<th scope="col">
								<?php echo esc_html__( 'Slug', 'dropkey-wp' ); ?>
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
						<?php foreach ( $products as $product ) : ?>
							<tr>
								<td>
									<strong>
										<a
											href="<?php echo esc_url( $this->get_edit_url( $product->get_id() ) ); ?>"
										>
											<?php echo esc_html( $product->get_name() ); ?>
										</a>
									</strong>

									<div class="row-actions">
										<span class="edit">
											<a
												href="<?php echo esc_url( $this->get_edit_url( $product->get_id() ) ); ?>"
											>
												<?php echo esc_html__( 'Edit', 'dropkey-wp' ); ?>
											</a>
										</span>
									</div>
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
									<code>
										<?php echo esc_html( $product->get_slug() ); ?>
									</code>
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
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the add product page.
	 *
	 * @return void
	 */
	public function render_add_product_page() {
		$this->ensure_capability();

		$errors = array();

		$form_data = array(
			'name'        => '',
			'slug'        => '',
			'type'        => ProductType::OTHER,
			'description' => '',
			'status'      => ProductStatus::ACTIVE,
		);

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$result = $this->handle_create_product();

			if ( is_wp_error( $result ) ) {
				$errors   = $result->get_error_messages();
				$form_data = $this->get_post_form_data();
			}
		}

		$this->render_product_form(
			__( 'Add Product', 'dropkey-wp' ),
			__( 'Create Product', 'dropkey-wp' ),
			'dropkey_wp_create_product',
			'dropkey_wp_product_nonce',
			$form_data,
			$errors
		);
	}

	/**
	 * Render the edit product page.
	 *
	 * @return void
	 */
	public function render_edit_product_page() {
		$this->ensure_capability();

		$product_id = isset( $_GET['product_id'] )
			? absint( $_GET['product_id'] )
			: 0;

		$product = $this->products->find( $product_id );

		if ( ! $product ) {
			wp_die(
				esc_html__(
					'The requested product could not be found.',
					'dropkey-wp'
				)
			);
		}

		$errors = array();

		$form_data = array(
			'name'        => $product->get_name(),
			'slug'        => $product->get_slug(),
			'type'        => $product->get_type(),
			'description' => $product->get_description(),
			'status'      => $product->get_status(),
		);

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$result = $this->handle_update_product( $product_id );

			if ( is_wp_error( $result ) ) {
				$errors    = $result->get_error_messages();
				$form_data = $this->get_post_form_data();
			} else {
				return;
			}
		}

		$this->render_product_form(
			__( 'Edit Product', 'dropkey-wp' ),
			__( 'Save Product', 'dropkey-wp' ),
			'dropkey_wp_update_product',
			'dropkey_wp_update_product_nonce',
			$form_data,
			$errors,
			$product_id
		);
	}

	/**
	 * Render a product form.
	 *
	 * @param string     $title Page title.
	 * @param string     $submit_label Submit button label.
	 * @param string     $nonce_action Nonce action.
	 * @param string     $nonce_name Nonce field name.
	 * @param array      $form_data Form data.
	 * @param array      $errors Errors.
	 * @param int        $product_id Product ID.
	 * @return void
	 */
	private function render_product_form(
		$title,
		$submit_label,
		$nonce_action,
		$nonce_name,
		array $form_data,
		array $errors,
		$product_id = 0
	) {
		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html( $title ); ?>
			</h1>

			<?php if ( ! empty( $errors ) ) : ?>
				<div class="notice notice-error">
					<p>
						<?php
						echo esc_html__(
							'The product could not be saved.',
							'dropkey-wp'
						);
						?>
					</p>

					<ul>
						<?php foreach ( $errors as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php
				wp_nonce_field(
					$nonce_action,
					$nonce_name
				);

				if ( $product_id > 0 ) {
					?>
					<input
						type="hidden"
						name="product_id"
						value="<?php echo esc_attr( $product_id ); ?>"
					/>
					<?php
				}
				?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="dropkey-wp-product-name">
								<?php echo esc_html__( 'Product Name', 'dropkey-wp' ); ?>
							</label>
						</th>

						<td>
							<input
								type="text"
								name="name"
								id="dropkey-wp-product-name"
								class="regular-text"
								value="<?php echo esc_attr( $form_data['name'] ); ?>"
								required
							/>

							<p class="description">
								<?php
								echo esc_html__(
									'Enter the name customers will recognize for this product.',
									'dropkey-wp'
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="dropkey-wp-product-slug">
								<?php echo esc_html__( 'Slug', 'dropkey-wp' ); ?>
							</label>
						</th>

						<td>
							<input
								type="text"
								name="slug"
								id="dropkey-wp-product-slug"
								class="regular-text"
								value="<?php echo esc_attr( $form_data['slug'] ); ?>"
							/>

							<p class="description">
								<?php
								echo esc_html__(
									'The slug is used as the product identifier. Leave blank to generate it from the product name.',
									'dropkey-wp'
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="dropkey-wp-product-type">
								<?php echo esc_html__( 'Product Type', 'dropkey-wp' ); ?>
							</label>
						</th>

						<td>
							<select
								name="type"
								id="dropkey-wp-product-type"
							>
								<?php foreach ( ProductType::all() as $type ) : ?>
									<option
										value="<?php echo esc_attr( $type ); ?>"
										<?php selected( $form_data['type'], $type ); ?>
									>
										<?php
										echo esc_html(
											$this->get_type_label( $type )
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>

							<p class="description">
								<?php
								echo esc_html__(
									'Choose the type that best describes the product.',
									'dropkey-wp'
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="dropkey-wp-product-description">
								<?php echo esc_html__( 'Description', 'dropkey-wp' ); ?>
							</label>
						</th>

						<td>
							<textarea
								name="description"
								id="dropkey-wp-product-description"
								class="large-text"
								rows="8"
							><?php echo esc_textarea( $form_data['description'] ); ?></textarea>

							<p class="description">
								<?php
								echo esc_html__(
									'Add a description of the product. Basic WordPress HTML is supported.',
									'dropkey-wp'
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="dropkey-wp-product-status">
								<?php echo esc_html__( 'Status', 'dropkey-wp' ); ?>
							</label>
						</th>

						<td>
							<select
								name="status"
								id="dropkey-wp-product-status"
							>
								<?php foreach ( ProductStatus::all() as $status ) : ?>
									<option
										value="<?php echo esc_attr( $status ); ?>"
										<?php selected( $form_data['status'], $status ); ?>
									>
										<?php
										echo esc_html(
											$this->get_status_label( $status )
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>

							<p class="description">
								<?php
								echo esc_html__(
									'Archived products are retained and are not deleted.',
									'dropkey-wp'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button
						type="submit"
						class="button button-primary"
					>
						<?php echo esc_html( $submit_label ); ?>
					</button>

					<a
						href="<?php echo esc_url( admin_url( 'admin.php?page=dropkey-wp-products' ) ); ?>"
						class="button"
					>
						<?php echo esc_html__( 'Cancel', 'dropkey-wp' ); ?>
					</a>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle product creation.
	 *
	 * @return \DropKeyWP\Domain\Product|\WP_Error
	 */
	private function handle_create_product() {
		if (
			! isset( $_POST['dropkey_wp_product_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field(
					wp_unslash( $_POST['dropkey_wp_product_nonce'] )
				),
				'dropkey_wp_create_product'
			)
		) {
			return new \WP_Error(
				'dropkey_wp_invalid_product_nonce',
				__(
					'The security check failed. Please try again.',
					'dropkey-wp'
				)
			);
		}

		$data = $this->get_post_form_data();

		$service = new CreateProduct( $this->products );

		$product = $service->execute( $data );

		if ( is_wp_error( $product ) ) {
			return $product;
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
	 * @param int $product_id Product ID.
	 * @return \DropKeyWP\Domain\Product|\WP_Error|null
	 */
	private function handle_update_product( $product_id ) {
		if (
			! isset( $_POST['dropkey_wp_update_product_nonce'] )
			|| ! wp_verify_nonce(
				sanitize_text_field(
					wp_unslash( $_POST['dropkey_wp_update_product_nonce'] )
				),
				'dropkey_wp_update_product'
			)
		) {
			return new \WP_Error(
				'dropkey_wp_invalid_product_update_nonce',
				__(
					'The security check failed. Please try again.',
					'dropkey-wp'
				)
			);
		}

		$service = new UpdateProduct( $this->products );

		$product = $service->execute(
			$product_id,
			$this->get_post_form_data()
		);

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'dropkey-wp-products',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Get product form data from POST.
	 *
	 * @return array<string,string>
	 */
	private function get_post_form_data() {
		return array(
			'name'        => isset( $_POST['name'] )
				? sanitize_text_field(
					wp_unslash( $_POST['name'] )
				)
				: '',
			'slug'        => isset( $_POST['slug'] )
				? sanitize_title(
					wp_unslash( $_POST['slug'] )
				)
				: '',
			'type'        => isset( $_POST['type'] )
				? sanitize_key(
					wp_unslash( $_POST['type'] )
				)
				: ProductType::OTHER,
			'description' => isset( $_POST['description'] )
				? wp_kses_post(
					wp_unslash( $_POST['description'] )
				)
				: '',
			'status'      => isset( $_POST['status'] )
				? sanitize_key(
					wp_unslash( $_POST['status'] )
				)
				: ProductStatus::ACTIVE,
		);
	}

	/**
	 * Render admin notices.
	 *
	 * @return void
	 */
	private function render_admin_notices() {
		if (
			isset( $_GET['created'] )
			&& '1' === sanitize_text_field(
				wp_unslash( $_GET['created'] )
			)
		) {
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

		if (
			isset( $_GET['updated'] )
			&& '1' === sanitize_text_field(
				wp_unslash( $_GET['updated'] )
			)
		) {
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
	 * Ensure the current user has the required capability.
	 *
	 * @return void
	 */
	private function ensure_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__(
					'You do not have permission to access this page.',
					'dropkey-wp'
				)
			);
		}
	}

	/**
	 * Get a human-readable product type label.
	 *
	 * @param string $type Product type.
	 * @return string
	 */
	private function get_type_label( $type ) {
		$labels = array(
			ProductType::WORDPRESS_PLUGIN => __(
				'WordPress Plugin',
				'dropkey-wp'
			),
			ProductType::WORDPRESS_THEME => __(
				'WordPress Theme',
				'dropkey-wp'
			),
			ProductType::DIGITAL_DOWNLOAD => __(
				'Digital Download',
				'dropkey-wp'
			),
			ProductType::OTHER => __(
				'Other',
				'dropkey-wp'
			),
		);

		return isset( $labels[ $type ] )
			? $labels[ $type ]
			: $type;
	}

	/**
	 * Get a human-readable product status label.
	 *
	 * @param string $status Product status.
	 * @return string
	 */
	private function get_status_label( $status ) {
		$labels = array(
			ProductStatus::ACTIVE => __(
				'Active',
				'dropkey-wp'
			),
			ProductStatus::ARCHIVED => __(
				'Archived',
				'dropkey-wp'
			),
		);

		return isset( $labels[ $status ] )
			? $labels[ $status ]
			: $status;
	}

	/**
	 * Format a database datetime for display.
	 *
	 * @param string $datetime UTC database datetime.
	 * @return string
	 */
	private function format_datetime( $datetime ) {
		if ( '' === $datetime ) {
			return '';
		}

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
<?php
/**
 * Update product application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Domain\Product;
use DropKeyWP\Domain\ProductStatus;
use DropKeyWP\Domain\ProductType;

defined( 'ABSPATH' ) || exit;

final class UpdateProduct {

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
	 * Update a product.
	 *
	 * @param int                  $product_id Product ID.
	 * @param array<string,mixed> $data Product data.
	 * @return Product|\WP_Error
	 */
	public function execute( $product_id, array $data ) {
		$product_id = absint( $product_id );

		if ( $product_id < 1 ) {
			return new \WP_Error(
				'dropkey_product_invalid_id',
				__( 'The product ID is invalid.', 'dropkey-wp' )
			);
		}

		$existing_product = $this->products->find( $product_id );

		if ( ! $existing_product ) {
			return new \WP_Error(
				'dropkey_product_not_found',
				__( 'The product could not be found.', 'dropkey-wp' )
			);
		}

		$name = isset( $data['name'] )
			? sanitize_text_field( $data['name'] )
			: $existing_product->get_name();

		$slug = isset( $data['slug'] )
			? sanitize_title( $data['slug'] )
			: sanitize_title( $name );

		$type = isset( $data['type'] )
			? sanitize_key( $data['type'] )
			: $existing_product->get_type();

		$description = isset( $data['description'] )
			? wp_kses_post( $data['description'] )
			: $existing_product->get_description();

		$status = isset( $data['status'] )
			? sanitize_key( $data['status'] )
			: $existing_product->get_status();

		$data = apply_filters(
			'dropkey_wp_product_update_data',
			array(
				'id'          => $product_id,
				'name'        => $name,
				'slug'        => $slug,
				'type'        => $type,
				'description' => $description,
				'status'      => $status,
			),
			$existing_product
		);

		$name = isset( $data['name'] )
			? sanitize_text_field( $data['name'] )
			: '';

		$slug = isset( $data['slug'] )
			? sanitize_title( $data['slug'] )
			: sanitize_title( $name );

		$type = isset( $data['type'] )
			? sanitize_key( $data['type'] )
			: ProductType::OTHER;

		$description = isset( $data['description'] )
			? wp_kses_post( $data['description'] )
			: '';

		$status = isset( $data['status'] )
			? sanitize_key( $data['status'] )
			: ProductStatus::ACTIVE;

		if ( '' === $name ) {
			return new \WP_Error(
				'dropkey_product_name_required',
				__( 'Product name is required.', 'dropkey-wp' )
			);
		}

		if ( '' === $slug ) {
			return new \WP_Error(
				'dropkey_product_slug_required',
				__( 'Product slug is required.', 'dropkey-wp' )
			);
		}

		$slug_product = $this->products->find_by_slug( $slug );

		if (
			$slug_product
			&& $slug_product->get_id() !== $product_id
		) {
			return new \WP_Error(
				'dropkey_product_slug_exists',
				__( 'A product with this slug already exists.', 'dropkey-wp' )
			);
		}

		$product = new Product(
			array(
				'id'          => $product_id,
				'name'        => $name,
				'slug'        => $slug,
				'type'        => $type,
				'description' => $description,
				'status'      => $status,
				'created_at'  => $existing_product->get_created_at(),
				'updated_at'  => $existing_product->get_updated_at(),
			)
		);

		$validation = $product->validate();

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$updated = $this->products->update(
			$product_id,
			array(
				'name'        => $product->get_name(),
				'slug'        => $product->get_slug(),
				'type'        => $product->get_type(),
				'description' => $product->get_description(),
				'status'      => $product->get_status(),
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		/**
		 * Fires after a product has been updated.
		 *
		 * @param Product $updated_product Updated product.
		 * @param Product $previous_product Previous product state.
		 */
		do_action(
			'dropkey_wp_product_updated',
			$updated,
			$existing_product
		);

		return $updated;
	}
}
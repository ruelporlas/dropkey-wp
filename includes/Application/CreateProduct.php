<?php
/**
 * Create product application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Domain\Product;
use DropKeyWP\Domain\ProductStatus;
use DropKeyWP\Domain\ProductType;

defined( 'ABSPATH' ) || exit;

final class CreateProduct {

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
	 * Create a product.
	 *
	 * @param array<string,mixed> $data Product data.
	 * @return Product|\WP_Error
	 */
	public function execute( array $data ) {
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

		$data = apply_filters(
			'dropkey_wp_product_data',
			array(
				'name'        => $name,
				'slug'        => $slug,
				'type'        => $type,
				'description' => $description,
				'status'      => $status,
			)
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

		if ( '' === $slug ) {
			return new \WP_Error(
				'dropkey_product_slug_required',
				__( 'Product slug is required.', 'dropkey-wp' )
			);
		}

		if ( $this->products->find_by_slug( $slug ) ) {
			return new \WP_Error(
				'dropkey_product_slug_exists',
				__( 'A product with this slug already exists.', 'dropkey-wp' )
			);
		}

		$product = new Product(
			array(
				'name'        => $name,
				'slug'        => $slug,
				'type'        => $type,
				'description' => $description,
				'status'      => $status,
			)
		);

		$validation = $product->validate();

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$product = $this->products->create(
			array(
				'name'        => $product->get_name(),
				'slug'        => $product->get_slug(),
				'type'        => $product->get_type(),
				'description' => $product->get_description(),
				'status'      => $product->get_status(),
			)
		);

		if ( is_wp_error( $product ) ) {
			return $product;
		}

		/**
		 * Fires after a product has been created.
		 *
		 * @param Product $product Created product. 
		 */
		do_action( 'dropkey_wp_product_created', $product );

		return $product;
	}
}
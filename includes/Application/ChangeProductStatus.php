<?php
/**
 * Change product status application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\ProductRepository;
use DropKeyWP\Domain\ProductStatus;

defined( 'ABSPATH' ) || exit;

final class ChangeProductStatus {

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
	 * Change a product's status.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $status     New product status.
	 * @return \DropKeyWP\Domain\Product|\WP_Error
	 */
	public function execute( $product_id, $status ) {
		$product_id = absint( $product_id );
		$status     = sanitize_key( $status );

		if ( $product_id < 1 ) {
			return new \WP_Error(
				'dropkey_product_invalid_id',
				__( 'The product ID is invalid.', 'dropkey-wp' )
			);
		}

		if ( ! ProductStatus::is_valid( $status ) ) {
			return new \WP_Error(
				'dropkey_product_invalid_status',
				__( 'The product status is invalid.', 'dropkey-wp' )
			);
		}

		$product = $this->products->find( $product_id );

		if ( ! $product ) {
			return new \WP_Error(
				'dropkey_product_not_found',
				__( 'The product could not be found.', 'dropkey-wp' )
			);
		}

		if ( $product->get_status() === $status ) {
			return $product;
		}

		$updated = $this->products->update(
			$product_id,
			array(
				'name'        => $product->get_name(),
				'slug'        => $product->get_slug(),
				'type'        => $product->get_type(),
				'description' => $product->get_description(),
				'status'      => $status,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( ProductStatus::ARCHIVED === $status ) {
			do_action(
				'dropkey_wp_product_archived',
				$updated,
				$product
			);
		}

		if ( ProductStatus::ACTIVE === $status ) {
			do_action(
				'dropkey_wp_product_restored',
				$updated,
				$product
			);
		}

		return $updated;
	}
}
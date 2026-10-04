<?php
/**
 * Product domain entity.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Domain;

defined( 'ABSPATH' ) || exit;

final class Product {

	/**
	 * Product ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Product name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Product slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Product type.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Product description.
	 *
	 * @var string
	 */
	private $description;

	/**
	 * Product status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Created timestamp.
	 *
	 * @var string
	 */
	private $created_at;

	/**
	 * Updated timestamp.
	 *
	 * @var string
	 */
	private $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Product data.
	 */
	public function __construct( array $data ) {
		$this->id          = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$this->name        = isset( $data['name'] ) ? (string) $data['name'] : '';
		$this->slug        = isset( $data['slug'] ) ? (string) $data['slug'] : '';
		$this->type        = isset( $data['type'] ) ? (string) $data['type'] : '';
		$this->description = isset( $data['description'] ) ? (string) $data['description'] : '';
		$this->status      = isset( $data['status'] ) ? (string) $data['status'] : ProductStatus::ACTIVE;
		$this->created_at  = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
		$this->updated_at  = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : '';
	}

	/**
	 * Validate product data.
	 *
	 * @return \WP_Error|true
	 */
	public function validate() {
		$errors = new \WP_Error();

		if ( '' === trim( $this->name ) ) {
			$errors->add(
				'dropkey_product_name_required',
				__( 'Product name is required.', 'dropkey-wp' )
			);
		}

		if ( '' === trim( $this->slug ) ) {
			$errors->add(
				'dropkey_product_slug_required',
				__( 'Product slug is required.', 'dropkey-wp' )
			);
		}

		if ( ! ProductType::is_valid( $this->type ) ) {
			$errors->add(
				'dropkey_product_type_invalid',
				__( 'Product type is invalid.', 'dropkey-wp' )
			);
		}

		if ( ! ProductStatus::is_valid( $this->status ) ) {
			$errors->add(
				'dropkey_product_status_invalid',
				__( 'Product status is invalid.', 'dropkey-wp' )
			);
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		return true;
	}

	/**
	 * Get product ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	/**
	 * Get product name.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Get product slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return $this->slug;
	}

	/**
	 * Get product type.
	 *
	 * @return string
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * Get product description.
	 *
	 * @return string
	 */
	public function get_description() {
		return $this->description;
	}

	/**
	 * Get product status.
	 *
	 * @return string
	 */
	public function get_status() {
		return $this->status;
	}

	/**
	 * Get created timestamp.
	 *
	 * @return string
	 */
	public function get_created_at() {
		return $this->created_at;
	}

	/**
	 * Get updated timestamp.
	 *
	 * @return string
	 */
	public function get_updated_at() {
		return $this->updated_at;
	}
}
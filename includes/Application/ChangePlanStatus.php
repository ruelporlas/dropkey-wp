<?php
/**
 * Change plan status application service.
 *
 * @package DropKeyWP
 */

namespace DropKeyWP\Application;

use DropKeyWP\Database\Repositories\PlanRepository;
use DropKeyWP\Domain\PlanStatus;

defined( 'ABSPATH' ) || exit;

final class ChangePlanStatus {

	/**
	 * Plan repository.
	 *
	 * @var PlanRepository
	 */
	private $plans;

	/**
	 * Constructor.
	 *
	 * @param PlanRepository $plans Plan repository.
	 */
	public function __construct( PlanRepository $plans ) {
		$this->plans = $plans;
	}

	/**
	 * Change a plan's status.
	 *
	 * @param int    $plan_id Plan ID.
	 * @param string $status  New plan status.
	 * @return \DropKeyWP\Domain\Plan|\WP_Error
	 */
	public function execute( $plan_id, $status ) {
		$plan_id = absint( $plan_id );
		$status  = sanitize_key( $status );

		if ( $plan_id < 1 ) {
			return new \WP_Error(
				'dropkey_plan_invalid_id',
				__( 'The plan ID is invalid.', 'dropkey-wp' )
			);
		}

		if ( ! PlanStatus::is_valid( $status ) ) {
			return new \WP_Error(
				'dropkey_plan_invalid_status',
				__( 'The plan status is invalid.', 'dropkey-wp' )
			);
		}

		$plan = $this->plans->find( $plan_id );

		if ( ! $plan ) {
			return new \WP_Error(
				'dropkey_plan_not_found',
				__( 'The plan could not be found.', 'dropkey-wp' )
			);
		}

		/*
		 * Keep repeated status changes idempotent.
		 */
		if ( $plan->get_status() === $status ) {
			return $plan;
		}

		$updated = $this->plans->update(
			$plan_id,
			array(
				'product_id'             => $plan->get_product_id(),
				'name'                   => $plan->get_name(),
				'slug'                   => $plan->get_slug(),
				'price'                  => $plan->get_price(),
				'currency'               => $plan->get_currency(),
				'billing_interval'       => $plan->get_billing_interval(),
				'billing_interval_count' => $plan->get_billing_interval_count(),
				'activation_limit'       => $plan->get_activation_limit(),
				'status'                 => $status,
			)
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		if ( PlanStatus::ARCHIVED === $status ) {
			/**
			 * Fires after a plan has been archived.
			 *
			 * @param \DropKeyWP\Domain\Plan $updated Updated plan.
			 * @param \DropKeyWP\Domain\Plan $previous Previous plan state.
			 */
			do_action(
				'dropkey_wp_plan_archived',
				$updated,
				$plan
			);
		}

		if ( PlanStatus::ACTIVE === $status ) {
			/**
			 * Fires after a plan has been restored.
			 *
			 * @param \DropKeyWP\Domain\Plan $updated Updated plan.
			 * @param \DropKeyWP\Domain\Plan $previous Previous plan state.
			 */
			do_action(
				'dropkey_wp_plan_restored',
				$updated,
				$plan
			);
		}

		return $updated;
	}
}

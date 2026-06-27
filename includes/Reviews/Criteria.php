<?php
/**
 * Criterion value object.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Reviews;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable rating criterion (e.g. "Quality").
 */
class Criteria {

	/**
	 * Criterion id.
	 *
	 * @var int
	 */
	public $id;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Slug.
	 *
	 * @var string
	 */
	public $slug;

	/**
	 * Scope: global|category|product.
	 *
	 * @var string
	 */
	public $scope;

	/**
	 * Scope id (term/product) or null for global.
	 *
	 * @var int|null
	 */
	public $scope_id;

	/**
	 * Sort position.
	 *
	 * @var int
	 */
	public $position;

	/**
	 * Status: active|inactive.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Build from a DB row.
	 *
	 * @param array<string,mixed>|object $row Row data.
	 * @return Criteria
	 */
	public static function from_row( $row ) {
		$row              = (array) $row;
		$c                = new self();
		$c->id            = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$c->name          = isset( $row['name'] ) ? (string) $row['name'] : '';
		$c->slug          = isset( $row['slug'] ) ? (string) $row['slug'] : '';
		$c->scope         = isset( $row['scope'] ) ? (string) $row['scope'] : 'global';
		$c->scope_id      = isset( $row['scope_id'] ) && null !== $row['scope_id'] ? (int) $row['scope_id'] : null;
		$c->position      = isset( $row['position'] ) ? (int) $row['position'] : 0;
		$c->status        = isset( $row['status'] ) ? (string) $row['status'] : 'active';

		return $c;
	}
}

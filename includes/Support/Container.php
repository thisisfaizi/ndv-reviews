<?php
/**
 * Minimal service container.
 *
 * @package NdvReviews
 */

namespace NdvReviews\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tiny PSR-11-flavoured container with lazy singletons.
 *
 * Pro hooks the same container via the `ndv-reviews/loaded` action to register
 * and resolve its own services without editing free files.
 */
class Container {

	/**
	 * Service factories keyed by id.
	 *
	 * @var array<string,callable>
	 */
	private $factories = array();

	/**
	 * Resolved singleton instances keyed by id.
	 *
	 * @var array<string,mixed>
	 */
	private $instances = array();

	/**
	 * Register a lazy factory for a service id.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Factory receiving the container, returning the service.
	 * @return void
	 */
	public function set( $id, callable $factory ) {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Whether a service id is registered.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( $id ) {
		return isset( $this->factories[ $id ] ) || isset( $this->instances[ $id ] );
	}

	/**
	 * Resolve a service (instantiating once and caching).
	 *
	 * @param string $id Service id.
	 * @return mixed|null The service, or null if not registered.
	 */
	public function get( $id ) {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			return null;
		}

		$this->instances[ $id ] = call_user_func( $this->factories[ $id ], $this );

		return $this->instances[ $id ];
	}
}

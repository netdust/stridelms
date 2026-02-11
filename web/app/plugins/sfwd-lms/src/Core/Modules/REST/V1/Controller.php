<?php
/**
 * Controller for the REST API.
 *
 * @since 4.25.0
 *
 * @package LearnDash\Core
 */

namespace LearnDash\Core\Modules\REST\V1;

use LearnDash\Core\Modules\REST\V1\Contracts\Endpoint;
use LearnDash\Core\App;
use StellarWP\Learndash\StellarWP\Arrays\Arr;

/**
 * Controller for the REST API.
 *
 * @since 4.25.0
 */
class Controller {
	/**
	 * The registered endpoints.
	 *
	 * @since 4.25.0
	 *
	 * @var Endpoint[]
	 */
	private array $endpoints = [];

	/**
	 * Loads and instantiates all endpoint classes.
	 *
	 * @since 4.25.0
	 *
	 * @return Endpoint[]
	 */
	protected function load_endpoints(): array {
		/**
		 * Filters the list of REST endpoint classes to load.
		 *
		 * @since 4.25.0
		 *
		 * @param string[] $endpoint_classes Array of endpoint class names.
		 *
		 * @return string[]
		 */
		$endpoint_classes = apply_filters(
			'learndash_rest_endpoints',
			[
				Endpoints\Documentation::class,
				Endpoints\Profile\Remove_Card::class,
			]
		);

		$container = App::container();

		/**
		 * The registered endpoints.
		 *
		 * @var Endpoint[]
		 */
		$endpoints = array_map(
			static function ( $class_name ) use ( $container ) {
				return class_exists( $class_name )
					? $container->make( $class_name )
					: null;
			},
			$endpoint_classes
		);

		// Filter out null values (classes that don't exist).
		return array_filter( $endpoints );
	}

	/**
	 * Registers all REST API routes.
	 *
	 * @since 4.25.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		foreach ( $this->get_endpoints() as $endpoint ) {
			$endpoint->register_routes();
		}
	}

	/**
	 * Returns all registered endpoints.
	 *
	 * @since 4.25.0
	 *
	 * @return Endpoint[]
	 */
	public function get_endpoints(): array {
		if ( empty( $this->endpoints ) ) {
			$this->endpoints = $this->load_endpoints();
		}

		return $this->endpoints;
	}

	/**
	 * Returns OpenAPI documentation for all endpoints.
	 *
	 * @since 4.25.0
	 *
	 * @return array<string,mixed>
	 */
	public function get_openapi_documentation(): array {
		$documentation = OpenAPI::get_base_spec();

		foreach ( $this->get_endpoints() as $endpoint ) {
			if ( method_exists( $endpoint, 'get_openapi_schema' ) ) {
				$schema = $endpoint->get_openapi_schema();

				$documentation['paths'] = array_merge(
					Arr::wrap( $documentation['paths'] ),
					$schema
				);
			}
		}

		/**
		 * Filters the OpenAPI documentation.
		 *
		 * @since 4.25.0
		 *
		 * @param array<string,mixed> $documentation The OpenAPI documentation.
		 *
		 * @return array<string,mixed>
		 */
		return apply_filters(
			'learndash_rest_openapi_documentation',
			$documentation
		);
	}
}

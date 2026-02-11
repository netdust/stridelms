<?php
/**
 * Abstract REST endpoint.
 *
 * @since 4.25.0
 *
 * @package LearnDash\Core
 */

namespace LearnDash\Core\Modules\REST\V1\Contracts;

use LearnDash\Core\Modules\REST\V1\Controller;
use LearnDash\Core\Modules\REST\V1\OpenAPI;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use LearnDash\Core\Utilities\Cast;
use StellarWP\Learndash\StellarWP\Arrays\Arr;

/**
 * Abstract REST endpoint class.
 *
 * @since 4.25.0
 */
abstract class Endpoint implements Interface_Endpoint {
	/**
	 * The namespace for this endpoint.
	 *
	 * @since 4.25.0
	 *
	 * @var string
	 */
	protected string $namespace = 'learndash/v1';

	/**
	 * The base route for this endpoint.
	 *
	 * @since 4.25.0
	 *
	 * @var string
	 */
	protected string $base_route = '';

	/**
	 * The permission required to access this endpoint, if not set, the endpoint is public.
	 *
	 * @since 4.25.0
	 *
	 * @var string
	 */
	protected string $permission_required = 'manage_options';

	/**
	 * Whether the endpoint is experimental.
	 *
	 * @since 4.25.0
	 *
	 * @var bool
	 */
	protected bool $experimental = true;

	/**
	 * Returns the routes configuration for this endpoint.
	 *
	 * @since 4.25.0
	 *
	 * @return array<string,array<string,string|callable>>
	 */
	abstract protected function get_routes(): array;

	/**
	 * Returns the endpoint arguments.
	 *
	 * @since 4.25.0
	 *
	 * @return array<string,array<string,mixed>>
	 */
	abstract protected function get_endpoint_args(): array;

	/**
	 * Validates the experimental header.
	 *
	 * @since 4.25.0
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request The REST request object.
	 *
	 * @return bool|WP_Error
	 */
	protected function validate_experimental_header( $request ) {
		if ( ! $this->experimental ) {
			return true;
		}

		if ( 'allow' !== strtolower( Cast::to_string( $request->get_header( 'Learndash-Experimental-Rest-Api' ) ) ) ) {
			return new WP_Error(
				'rest_not_allowed',
				__( 'The Learndash-Experimental-Rest-Api header is required to access this endpoint.', 'learndash' ),
				[
					'status' => 403,
				]
			);
		}

		return true;
	}

	/**
	 * Validates a parameter type.
	 *
	 * @since 4.25.0
	 *
	 * @param mixed  $value The parameter value.
	 * @param string $type  The expected type.
	 *
	 * @return bool
	 */
	protected function validate_parameter_type( $value, $type ): bool {
		switch ( $type ) {
			case 'integer':
				return is_numeric( $value );
			case 'boolean':
				return is_bool( $value ) || in_array( $value, [ '1', '0', 'true', 'false' ], true );
			case 'array':
				return is_array( $value );
			case 'object':
				return is_object( $value ) || is_array( $value );
			case 'string':
			default:
				return is_string( $value ) || is_numeric( $value );
		}
	}

	/**
	 * Sanitizes a parameter value.
	 *
	 * Sanitizes values based on their type:
	 * - integer: Casts to integer.
	 * - number: Casts to float or integer.
	 * - boolean: Converts to true/false using rest_sanitize_boolean().
	 * - array: Recursively sanitizes array values.
	 * - object: Recursively sanitizes object properties.
	 * - string: Sanitizes using sanitize_text_field().
	 *
	 * @since 4.25.0
	 *
	 * @param mixed   $value      The parameter value to sanitize.
	 * @param string  $type       The parameter type (integer, boolean, array, object, string).
	 * @param ?string $param_name Optional. The parameter name for context-specific sanitization.
	 *
	 * @return mixed The sanitized value.
	 */
	protected function sanitize_parameter( $value, string $type, ?string $param_name = null ) {
		switch ( $type ) {
			case 'integer':
				return Cast::to_int( $value );
			case 'number':
				return is_float( $value ) ? Cast::to_float( $value ) : Cast::to_int( $value );
			case 'boolean':
				return rest_sanitize_boolean( Cast::to_string( $value ) );
			case 'array':
				return $this->sanitize_array_parameter( $value, Cast::to_string( $param_name ) );
			case 'object':
				return $this->sanitize_object_parameter( $value, Cast::to_string( $param_name ) );
			case 'string':
			default:
				return sanitize_text_field( Cast::to_string( $value ) );
		}
	}

	/**
	 * Sanitizes an array parameter with proper chaining.
	 *
	 * @since 4.25.0
	 *
	 * @param mixed  $value      The array value to sanitize.
	 * @param string $param_name The parameter name for context.
	 *
	 * @return array<string,mixed>
	 */
	protected function sanitize_array_parameter( $value, $param_name ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		// For non-meta arrays, we need schema to know how to sanitize.
		// Without schema, we cannot safely sanitize, so return empty array.
		return [];
	}

	/**
	 * Sanitizes an object parameter with proper chaining.
	 *
	 * @since 4.25.0
	 *
	 * @param mixed  $value      The object value to sanitize.
	 * @param string $param_name The parameter name for context.
	 *
	 * @return array<string,mixed>|object
	 */
	protected function sanitize_object_parameter( $value, $param_name ) {
		if (
			! is_array( $value )
			&& ! is_object( $value )
		) {
			return (object) [];
		}

		// For non-meta objects, we need schema to know how to sanitize.
		// Without schema, we cannot safely sanitize, so return empty object.
		return (object) [];
	}

	/**
	 * Returns the meta type for this endpoint.
	 *
	 * Override this method in child classes to specify the correct meta type.
	 *
	 * @since 4.25.0
	 *
	 * @return string
	 */
	protected function get_meta_type(): string {
		return 'post';
	}

	/**
	 * Returns the meta schema for this endpoint.
	 *
	 * Override this method in child classes to provide specific meta field schemas.
	 *
	 * @since 4.25.0
	 *
	 * @return array<string,mixed>
	 */
	protected function get_meta_schema(): array {
		return [];
	}

	/**
	 * Converts endpoint args to request schema format.
	 *
	 * This method transforms WordPress REST API endpoint args format
	 * to OpenAPI/JSON Schema format for request body validation.
	 *
	 * @since 4.25.0
	 *
	 * @param array<string,array<string,mixed>> $endpoint_args The endpoint arguments. Defaults to an empty array, which will be overridden by the `get_endpoint_args` method if it exists.
	 *
	 * @return array{
	 *     type: string,
	 *     properties: array<string,array<string,mixed>>,
	 *     required?: string[],
	 * }
	 */
	protected function convert_endpoint_args_to_schema( array $endpoint_args = [] ): array {
		if ( empty( $endpoint_args ) ) {
			if ( ! method_exists( $this, 'get_endpoint_args' ) ) {
				return [
					'type'       => 'object',
					'properties' => [],
				];
			}

			$endpoint_args = $this->get_endpoint_args();
		}

		$properties = [];
		$required   = [];

		foreach ( $endpoint_args as $param => $config ) {
			$property = [];

			// Map type.
			if ( isset( $config['type'] ) ) {
				$property['type'] = $config['type'];
			}

			// Map description.
			if ( isset( $config['description'] ) ) {
				$property['description'] = $config['description'];
			}

			// Map default value.
			if ( isset( $config['default'] ) ) {
				$property['default'] = $config['default'];
			}

			// Map enum values.
			if ( isset( $config['enum'] ) ) {
				$property['enum'] = $config['enum'];
			}

			// Map minimum/maximum for numbers.
			if ( isset( $config['minimum'] ) ) {
				$property['minimum'] = $config['minimum'];
			}
			if ( isset( $config['maximum'] ) ) {
				$property['maximum'] = $config['maximum'];
			}

			// Map array items.
			if ( isset( $config['items'] ) ) {
				$property['items'] = $config['items'];
			}

			// Map object properties.
			if ( isset( $config['properties'] ) ) {
				$property['properties'] = $config['properties'];
			}

			// Check if parameter is required.
			if ( isset( $config['required'] ) && $config['required'] ) {
				$required[] = $param;
			}

			// Add example if not present but default is available.
			if ( ! Arr::has( $property, 'example' ) && Arr::has( $property, 'default' ) ) {
				$property['example'] = Arr::get( $property, 'default' );
			}

			$properties[ $param ] = $property;
		}

		$schema = [
			'type'       => 'object',
			'properties' => $properties,
		];

		if ( ! empty( $required ) ) {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * Creates a standardized success response.
	 *
	 * @since 4.25.0
	 *
	 * @param mixed  $data    The response data.
	 * @param string $message Optional success message.
	 * @param int    $status  HTTP status code.
	 *
	 * @return WP_REST_Response
	 */
	protected function success_response( $data = null, $message = '', $status = 200 ): WP_REST_Response {
		$response = [
			'success' => true,
			'data'    => $data,
		];

		if ( ! empty( $message ) ) {
			$response['message'] = $message;
		}

		return new WP_REST_Response( $response, $status );
	}

	/**
	 * Creates a standardized error response.
	 *
	 * @since 4.25.0
	 *
	 * @param string $message Error message.
	 * @param string $code    Error code.
	 * @param int    $status  HTTP status code.
	 * @param mixed  $data    Optional error data.
	 *
	 * @return WP_REST_Response
	 */
	protected function error_response( $message, $code = 'rest_error', $status = 400, $data = null ): WP_REST_Response {
		$response = [
			'success' => false,
			'code'    => $code,
			'message' => $message,
		];

		if ( null !== $data ) {
			$response['data'] = $data;
		}

		return new WP_REST_Response( $response, $status );
	}

	/**
	 * Builds the OpenAPI path schema for a route configuration.
	 *
	 * @since 4.25.0
	 *
	 * @param string                        $path   The path of the route.
	 * @param array<string,string|callable> $config Route configuration.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function build_openapi_path_schema( string $path, array $config ): array {
		$path_schema = [];

		if ( is_array( $config ) && isset( $config['methods'] ) ) {
			$config = [ $config ];
		}

		foreach ( $config as $method_config ) {
			$methods = Arr::get( $method_config, 'methods', [ WP_REST_Server::READABLE ] );

			if ( is_string( $methods ) ) {
				$methods = [ $methods ];
			}

			if ( ! is_array( $methods ) ) {
				continue;
			}

			foreach ( $methods as $method ) {
				$method       = Cast::to_string( $method );
				$method_lower = strtolower( $method );

				if ( 'GET' === $method ) {
					$method_lower = 'get';
				} elseif ( 'POST' === $method ) {
					$method_lower = 'post';
				} elseif ( 'PUT' === $method ) {
					$method_lower = 'put';
				} elseif ( 'DELETE' === $method ) {
					$method_lower = 'delete';
				}

				$path_schema[ $method_lower ] = [
					'summary'     => Arr::get( $method_config, 'summary', '' ),
					'description' => Arr::get( $method_config, 'description', '' ),
					'security'    => $this->get_security_schemes( $path, $method ),
					'parameters'  => $this->build_openapi_parameters( $path, $method ),
					'responses'   => $this->build_openapi_responses( $path, $method ),
					'tags'        => $this->get_tags(),
					'servers'     => [
						[
							'url' => rest_url( $this->get_namespace() ),
						],
					],
				];

				if ( in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
					$path_schema[ $method_lower ]['requestBody'] = [
						'content' => [
							'application/json' => [
								'schema' => $this->convert_endpoint_args_to_schema(),
							],
						],
					];
				}
			}
		}

		return $path_schema;
	}

	/**
	 * Returns the tags for this endpoint.
	 *
	 * @since 4.25.0
	 *
	 * @return string[]
	 */
	protected function get_tags(): array {
		return [ trim( $this->get_base_route(), '/' ) ];
	}

	/**
	 * Returns the security schemes for this endpoint.
	 *
	 * @since 4.25.0
	 *
	 * @param string $path   The path of the route.
	 * @param string $method The HTTP method.
	 *
	 * @return array<string,string[]>
	 */
	protected function get_security_schemes( string $path, string $method ): array {
		$security_schemes = [];

		if ( $this->experimental ) {
			$security_schemes[ OpenAPI::$security_scheme_experimental ] = [];
		}

		return $security_schemes;
	}

	/**
	 * Builds the OpenAPI parameters schema.
	 *
	 * @since 4.25.0
	 *
	 * @param string $path   The path of the route.
	 * @param string $method The HTTP method.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function build_openapi_parameters( string $path, string $method ): array {
		$schema     = $this->get_request_schema( $path, $method );
		$properties = $schema['properties'] ?? [];
		$parameters = [];

		foreach ( $properties as $name => $config ) {
			$parameter = [
				'name'        => $name,
				'in'          => 'query',
				'description' => Arr::get( $config, 'description', '' ),
				'required'    => Arr::get( $config, 'required', false ),
				'schema'      => [
					'type' => Arr::get( $config, 'type', 'string' ),
				],
			];

			// Add enum values if present.
			if ( Arr::has( $config, 'enum' ) ) {
				$parameter['schema']['enum'] = $config['enum'];
			}

			// Add minimum/maximum for numbers.
			if ( Arr::has( $config, 'minimum' ) ) {
				$parameter['schema']['minimum'] = $config['minimum'];
			}
			if ( Arr::has( $config, 'maximum' ) ) {
				$parameter['schema']['maximum'] = $config['maximum'];
			}

			// Add items for array types.
			if ( Arr::has( $config, 'items' ) ) {
				$parameter['schema']['items'] = $config['items'];
			}

			// Add properties for object types.
			if ( Arr::has( $config, 'properties' ) ) {
				$parameter['schema']['properties'] = $config['properties'];
			}

			// Add example if present.
			if ( Arr::has( $config, 'example' ) ) {
				$parameter['schema']['example'] = $config['example'];
			}

			$parameters[] = $parameter;
		}

		return $parameters;
	}

	/**
	 * Builds the OpenAPI responses schema.
	 *
	 * @since 4.25.0
	 *
	 * @param string $path   The path of the route.
	 * @param string $method The HTTP method.
	 *
	 * @return array<int, array<string, array<string, array<string, array<string, array<string, mixed>|string>|string>>|string>>
	 */
	protected function build_openapi_responses( string $path, string $method ): array {
		return [
			'200' => [
				'description' => 'Success',
				'content'     => [
					'application/json' => [
						'schema' => $this->get_response_schema( $path, $method ),
					],
				],
			],
			'400' => [
				'description' => 'Bad Request',
				'content'     => [
					'application/json' => [
						'schema' => [
							'type'       => 'object',
							'properties' => [
								'success' => [
									'type'    => 'boolean',
									'example' => false,
								],
								'code'    => [
									'type'    => 'string',
									'example' => 'rest_error',
								],
								'message' => [
									'type'    => 'string',
									'example' => 'Error message',
								],
							],
						],
					],
				],
			],
		];
	}

	/**
	 * Returns the schema for request parameters.
	 *
	 * @since 4.25.0
	 *
	 * @param string $path   The path of the route.
	 * @param string $method The HTTP method.
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	abstract public function get_request_schema( string $path, string $method ): array;

	/**
	 * Returns the schema for response data.
	 *
	 * @since 4.25.0
	 *
	 * @param string $path   The path of the route.
	 * @param string $method The HTTP method.
	 *
	 * @return array<string,array<string,mixed>|string>
	 */
	abstract public function get_response_schema( string $path, string $method ): array;

	/**
	 * Returns the namespace for this endpoint.
	 *
	 * @since 4.25.0
	 *
	 * @return string
	 */
	public function get_namespace(): string {
		return $this->namespace;
	}

	/**
	 * Returns the base route for this endpoint.
	 *
	 * @since 4.25.0
	 *
	 * @return string
	 */
	public function get_base_route(): string {
		return $this->base_route;
	}

	/**
	 * Returns the permission required to access this endpoint.
	 *
	 * @since 4.25.0
	 *
	 * @return string
	 */
	public function get_permission_required(): string {
		return $this->permission_required;
	}

	/**
	 * Checks if the current user has permission to access this endpoint.
	 *
	 * @since 4.25.0
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request The REST request object.
	 *
	 * @return bool|WP_Error
	 */
	public function check_permission( $request ) {
		$header_validation = $this->validate_experimental_header( $request );

		if ( is_wp_error( $header_validation ) ) {
			return $header_validation;
		}

		$permission = $this->get_permission_required();

		if ( '' === $permission ) {
			return true;
		}

		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to access this endpoint.', 'learndash' ),
				[
					'status' => 401,
				]
			);
		}

		return current_user_can( $permission );
	}

	/**
	 * Validates the request parameters.
	 *
	 * @since 4.25.0
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request The REST request object.
	 *
	 * @return bool|WP_Error
	 */
	public function validate_request( $request ) {
		$schema = $this->get_request_schema( $request->get_route(), $request->get_method() );
		$errors = [];

		if ( empty( $schema['properties'] ) ) {
			return true;
		}

		$required_params = (array) Arr::get( $schema, 'required', [] );

		foreach ( $schema['properties'] as $param => $config ) {
			if ( ! $request->has_param( $param ) ) {
				// Check if parameter is required.
				if ( in_array( $param, $required_params, true ) ) {
					$errors[] = sprintf(
						/* translators: %s: parameter name */
						__( 'Missing required parameter: %s', 'learndash' ),
						$param
					);
				}
				continue;
			}

			$value = $request->get_param( $param );
			$type  = Cast::to_string( Arr::get( $config, 'type', 'string' ) );

			// Validate parameter type.
			if ( ! $this->validate_parameter_type( $value, $type ) ) {
				$errors[] = sprintf(
					/* translators: 1: parameter name, 2: expected type */
					__( 'Parameter %1$s must be of type %2$s.', 'learndash' ),
					$param,
					$type
				);
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error(
				'rest_invalid_param',
				implode( ', ', $errors ),
				[
					'status' => 422,
				]
			);
		}

		return true;
	}

	/**
	 * Sanitizes the request parameters.
	 *
	 * @since 4.25.0
	 *
	 * @param WP_REST_Request<array<string,mixed>> $request The REST request object.
	 *
	 * @return array<string,mixed>
	 */
	public function sanitize_request( $request ): array {
		$schema    = $this->get_request_schema( $request->get_route(), $request->get_method() );
		$sanitized = [];

		if ( empty( $schema['properties'] ) ) {
			return $request->get_params();
		}

		foreach ( $schema['properties'] as $param => $config ) {
			if ( ! $request->has_param( $param ) ) {
				continue;
			}

			$value = $request->get_param( $param );
			$type  = Cast::to_string( Arr::get( $config, 'type', 'string' ) );

			$sanitized[ $param ] = $this->sanitize_parameter( $value, $type, $param );
		}

		return $sanitized;
	}

	/**
	 * Returns the OpenAPI schema for this endpoint.
	 *
	 * @since 4.25.0
	 *
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public function get_openapi_schema(): array {
		$routes = $this->get_routes();
		$schema = [];

		foreach ( $routes as $route => $config ) {
			$path = '/' . $this->get_base_route() . $route;
			$path = str_replace( '//', '/', $path );

			$schema[ $path ] = $this->build_openapi_path_schema( $path, $config );
		}

		return $schema;
	}

	/**
	 * Registers routes for this endpoint.
	 *
	 * @since 4.25.0
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$routes = $this->get_routes();

		foreach ( $routes as $route => $config ) {
			register_rest_route(
				$this->get_namespace(),
				$this->get_base_route() . $route,
				$config
			);
		}
	}
}

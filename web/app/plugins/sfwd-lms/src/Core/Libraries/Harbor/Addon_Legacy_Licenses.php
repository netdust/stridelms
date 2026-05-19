<?php
/**
 * LearnDash Harbor Addon Legacy Licenses class.
 *
 * @since 5.1.0
 *
 * @package LearnDash\Core
 */

namespace LearnDash\Core\Libraries\Harbor;

use LearnDash\Core\Licensing\Status_Checker;
use LearnDash\Core\Utilities\Cast;

/**
 * Registers legacy license keys for premium addons that store their auth token
 * in an auth-token.php file at the plugin root.
 *
 * @since 5.1.0
 *
 * @phpstan-type AddonEntry array{
 *   name: string,
 *   harbor_slug: string,
 *   status_slug: string,
 * }
 */
class Addon_Legacy_Licenses {
	/**
	 * Map of plugin root path constants to their slug configuration.
	 *
	 * Each entry should be:
	 *   'CONSTANT_NAME' => [
	 *     'name'        => 'Plugin Name',   // human-readable name
	 *     'harbor_slug' => 'harbor-slug',   // slug used in the lw-harbor/legacy_licenses filter
	 *     'status_slug' => 'status-slug',   // slug used for Status_Checker::get_status()
	 *   ]
	 *
	 * @since 5.1.0
	 *
	 * @var array<string,AddonEntry>
	 */
	private array $addon_map;

	/**
	 * Constructor.
	 *
	 * @since 5.1.0
	 *
	 * @param array<string,AddonEntry> $addon_map Map of ABSPATH constant names to slug configuration.
	 */
	public function __construct( array $addon_map ) {
		$this->addon_map = $addon_map;
	}

	/**
	 * Registers legacy license keys for each installed addon.
	 *
	 * Hooked into `lw-harbor/legacy_licenses`.
	 *
	 * @since 5.1.0
	 *
	 * @param array<int,array<string,mixed>> $licenses The existing legacy licenses.
	 *
	 * @return array<int,array<string,mixed>> The legacy licenses with addon entries appended.
	 */
	public function register( array $licenses ): array {
		foreach ( $this->addon_map as $constant => $entry ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$auth_token_file = trailingslashit( Cast::to_string( constant( $constant ) ) ) . 'auth-token.php';

			if ( ! file_exists( $auth_token_file ) ) {
				continue;
			}

			$license_key = require $auth_token_file;

			if (
				empty( $license_key )
				|| ! is_string( $license_key )
			) {
				continue;
			}

			$status_data = Status_Checker::get_status( $entry['status_slug'], $license_key );

			$licenses[] = [
				'key'        => $license_key,
				'slug'       => $entry['harbor_slug'],
				'name'       => $entry['name'],
				'is_active'  => isset( $status_data['status'] ) && Status_Checker::does_status_allow_access( $status_data['status'] ),
				'product'    => 'learndash',
				'expires_at' => $status_data['expiry'] ?? '',
			];
		}

		return $licenses;
	}
}

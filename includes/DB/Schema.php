<?php
/**
 * Manages the plugin's custom database tables via dbDelta.
 *
 * @package Plume
 */

declare( strict_types=1 );
namespace Plume\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the plugin's custom database tables.
 *
 * All table names are prefixed with $wpdb->prefix and the plugin-specific
 * 'plume_' prefix to avoid collisions with other plugins.
 */
class Schema {

	private const PREFIX = 'plume_';

	private const TABLES = [
		'conversations' => 'conversations',
		'messages'      => 'messages',
	];

	/**
	 * Resolve a logical table name to its fully-qualified database table name.
	 *
	 * @since 1.0.0
	 * @param string $name Logical name: 'conversations' or 'messages'.
	 * @throws \InvalidArgumentException If $name is not a known table identifier.
	 * @return string Fully-qualified table name including WordPress and plugin prefixes.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		if ( ! isset( self::TABLES[ $name ] ) ) {
			throw new \InvalidArgumentException( "Unknown table: {$name}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $wpdb->prefix . self::PREFIX . self::TABLES[ $name ];
	}

	/**
	 * Rename wpaim_* tables to plume_* on first activation after the plugin is renamed.
	 *
	 * Skips tables that do not exist under the old name and never overwrites a new table
	 * that already exists. Guarded by the plume_tables_migrated option so it only runs once.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public static function maybe_migrate_tables(): void {
		global $wpdb;

		if ( get_option( 'plume_tables_migrated', false ) ) {
			return;
		}

		foreach ( array_keys( self::TABLES ) as $name ) {
			$old_table = $wpdb->prefix . 'wpaim_' . self::TABLES[ $name ];
			$new_table = self::table( $name );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$old_exists = (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$old_table
				)
			);
			if ( ! $old_exists ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$new_exists = (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$new_table
				)
			);
			if ( $new_exists ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
		}

		update_option( 'plume_tables_migrated', true, false );
	}

	/**
	 * Run on plugin activation via dbDelta().
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$conversations = self::table( 'conversations' );
		dbDelta(
			"CREATE TABLE {$conversations} (
            id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            user_id    BIGINT UNSIGNED NOT NULL,
            title      VARCHAR(255)             DEFAULT NULL,
            post_id    BIGINT UNSIGNED          DEFAULT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) {$charset};"
		);

		$messages = self::table( 'messages' );
		dbDelta(
			"CREATE TABLE {$messages} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            role            ENUM('user','assistant') NOT NULL,
            content         LONGTEXT        NOT NULL,
            model           VARCHAR(100)             DEFAULT NULL,
            tokens          INT UNSIGNED             DEFAULT NULL,
            PRIMARY KEY (id),
            KEY conversation_id (conversation_id)
        ) {$charset};"
		);
	}

	/**
	 * Drop all plugin tables.
	 *
	 * Called on plugin uninstall from uninstall.php.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;
		foreach ( array_keys( self::TABLES ) as $name ) {
			$table = self::table( $name );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}
	}
}

<?php

namespace Plume\Tests\Unit\Tiers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use Plume\Tests\Helpers\WpdbStubFactory;
use Plume\Tiers\UsageTracker;

class UsageTrackerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Default site-tier lookup returns the supplied default so tests focused on
		// per-user meta resolution do not need to stub the new SITE_OPTION read.
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $default );
	}

	protected function tearDown(): void {
		// Restore a valid $wpdb baseline so log_usage() does not crash in later test classes.
		global $wpdb;
		$wpdb = WpdbStubFactory::create(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_get_usage_returns_correct_structure_for_free_user(): void {
		$month_key = 'plume_usage_' . gmdate( 'Y_m' );

		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'get_user_meta' )
			->once()->with( 1, $month_key, true )->andReturn( '25000' );
		// get_user_tier() resolves to 'free' (site option default); get_cached_credit_limit()
		// hits its transient cache miss and falls back to FALLBACK_LIMIT.
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$usage = UsageTracker::get_usage();

		$this->assertSame( 'free', $usage['tier'] );
		$this->assertSame( 25000, $usage['used'] );
		$this->assertSame( UsageTracker::FALLBACK_LIMIT, $usage['limit'] );
		$this->assertTrue( $usage['can_use'] );
	}

	public function test_get_usage_can_use_is_always_true(): void {
		// can_use is hardcoded true — the Worker's KV ledger is the sole enforcement
		// point now; the local mirror is for dashboard display only.
		$month_key = 'plume_usage_' . gmdate( 'Y_m' );

		Functions\expect( 'get_user_meta' )
			->once()->with( 2, $month_key, true )->andReturn( '999999999' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		$usage = UsageTracker::get_usage( 2 );
		$this->assertTrue( $usage['can_use'] );
	}

	public function test_get_usage_pro_byok_is_always_unlimited(): void {
		$month_key = 'plume_usage_' . gmdate( 'Y_m' );

		// pro_byok lives on the site option.
		Functions\when( 'get_option' )->alias(
			fn( $key, $default = false ) =>
				'plume_site_tier' === $key ? 'pro_byok' : $default
		);
		Functions\expect( 'get_user_meta' )
			->once()->with( 3, $month_key, true )->andReturn( '999999' );

		$usage = UsageTracker::get_usage( 3 );
		$this->assertTrue( $usage['can_use'] );
		$this->assertNull( $usage['limit'] );
		$this->assertNull( $usage['remaining'] );
	}

	public function test_log_usage_performs_atomic_sql_increment(): void {
		global $wpdb;
		$month_key = 'plume_usage_' . gmdate( 'Y_m' );

		$wpdb                = \Mockery::mock( 'wpdb' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb->usermeta      = 'wp_usermeta';
		$wpdb->rows_affected = 1;
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 1 );

		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );

		UsageTracker::log_usage( 500 );
		$this->addToAssertionCount( 1 );
	}

	public function test_log_usage_falls_back_to_insert_when_no_row_exists(): void {
		global $wpdb;
		$month_key = 'plume_usage_' . gmdate( 'Y_m' );

		$wpdb                = \Mockery::mock( 'wpdb' ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$wpdb->usermeta      = 'wp_usermeta';
		$wpdb->rows_affected = 0; // No existing row.
		$wpdb->shouldReceive( 'prepare' )
			->once()
			->andReturnUsing( fn( $sql ) => $sql );
		$wpdb->shouldReceive( 'query' )->once()->andReturn( 0 );

		Functions\expect( 'get_current_user_id' )->once()->andReturn( 1 );
		Functions\expect( 'add_user_meta' )
			->once()
			->with( 1, $month_key, 500, true )
			->andReturn( true );

		UsageTracker::log_usage( 500 );
		$this->addToAssertionCount( 1 );
	}

	public function test_get_current_month_key_returns_expected_format(): void {
		$key = UsageTracker::get_current_month_key();

		$this->assertMatchesRegularExpression( '/^plume_usage_\d{4}_\d{2}$/', $key );
		$this->assertSame( 'plume_usage_' . gmdate( 'Y_m' ), $key );
	}

	// ── check_limit() removed entirely — no replacement method, no stub ───────

	public function test_check_limit_method_does_not_exist(): void {
		$this->assertFalse(
			method_exists( UsageTracker::class, 'check_limit' ),
			'check_limit() must be removed — the Worker KV ledger is the sole enforcement point now.'
		);
	}

	// ── get_cached_credit_limit() ──────────────────────────────────────────────
	//
	// Interim limitation (tracked as a follow-up GitHub issue — see the method's
	// own PHPDoc): the Worker's /register and /rotate-secret responses do not yet
	// expose a credit-limit field, so there is nothing to fetch on a cache miss.
	// The fetch path is stubbed to always fall through to FALLBACK_LIMIT until a
	// small Worker-side follow-up PR adds a config endpoint. The transient cache
	// plumbing (read-through, TTL, pro_byok null-for-unlimited) is built now so
	// swapping in the real fetch later is a one-line change inside this method,
	// not a call-site migration.

	public function test_get_cached_credit_limit_returns_cached_transient_value_on_hit(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'plume_credit_limit_free' )
			->andReturn( 100 );
		Functions\expect( 'set_transient' )->never();

		$this->assertSame( 100, UsageTracker::get_cached_credit_limit( 'free' ) );
	}

	public function test_get_cached_credit_limit_caches_fallback_limit_on_cache_miss(): void {
		Functions\expect( 'get_transient' )
			->once()
			->with( 'plume_credit_limit_pro_managed' )
			->andReturn( false );
		Functions\expect( 'set_transient' )
			->once()
			->with( 'plume_credit_limit_pro_managed', UsageTracker::FALLBACK_LIMIT, \DAY_IN_SECONDS )
			->andReturn( true );

		$this->assertSame( UsageTracker::FALLBACK_LIMIT, UsageTracker::get_cached_credit_limit( 'pro_managed' ) );
	}

	public function test_get_cached_credit_limit_returns_null_for_pro_byok(): void {
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->assertNull( UsageTracker::get_cached_credit_limit( 'pro_byok' ) );
	}
}

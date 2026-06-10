<?php
declare( strict_types=1 );

namespace Plume\Tests\Unit\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Plume\Core\Plugin;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Plugin::reset_instance(); // prevent static state leaking between tests
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_instance_returns_same_object(): void {
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'add_action' )->justReturn( true );
        Functions\when( 'add_filter' )->justReturn( true );

        $a = Plugin::instance();
        $b = Plugin::instance();
        $this->assertSame( $a, $b );
    }

    /**
     * Verify that stilus_* options are migrated to plume_* equivalents when
     * wp_ai_mind_* keys are absent (i.e. the site previously ran the WP AI Mind → Stilus hop).
     */
    public function test_migrate_stilus_options_to_plume(): void {
        $updated = [];

        Functions\when( 'wp_clear_scheduled_hook' )->justReturn();
        Functions\when( 'update_option' )->alias(
            static function ( string $key, mixed $val, mixed $autoload = null ) use ( &$updated ): bool {
                $updated[ $key ] = $val;
                return true;
            }
        );
        Functions\when( 'get_option' )->alias(
            static function ( string $key, mixed $default = false ): mixed {
                return match ( $key ) {
                    'plume_options_migrated'  => false,
                    'stilus_provider_keys'    => [ 'openai' => 'sk-abc' ],
                    'stilus_default_provider' => 'openai',
                    default                   => $default,
                };
            }
        );

        $ref = new \ReflectionMethod( Plugin::class, 'maybe_migrate_from_wp_ai_mind' );
        $ref->setAccessible( true );
        $ref->invoke( null );

        $this->assertSame( [ 'openai' => 'sk-abc' ], $updated['plume_provider_keys'] ?? null );
        $this->assertSame( 'openai', $updated['plume_default_provider'] ?? null );
    }

    /**
     * Verify that deactivate() clears the stilus_trial_check cron hook left behind
     * on sites that previously ran under the Stilus plugin name.
     */
    public function test_deactivate_clears_stilus_trial_check(): void {
        $cleared = [];
        Functions\when( 'wp_clear_scheduled_hook' )->alias(
            static function ( string $hook ) use ( &$cleared ): void {
                $cleared[] = $hook;
            }
        );
        Functions\when( 'flush_rewrite_rules' )->justReturn();

        Plugin::deactivate();

        $this->assertContains( 'stilus_trial_check', $cleared );
    }
}

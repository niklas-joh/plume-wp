<?php
namespace WP_AI_Mind\Tests\Unit\DB;

use Brain\Monkey;
use Brain\Monkey\Functions;
use WP_AI_Mind\DB\ConversationStore;
use PHPUnit\Framework\TestCase;

/**
 * Minimal wpdb stub that supports insert() and update().
 */
class FakeWpdb {
    public string $prefix    = 'wp_';
    public int    $insert_id = 0;

    public function insert( string $table, array $data, array $format = [] ): int {
        return 1;
    }

    public function update( string $table, array $data, array $where, array $formats = [], array $where_formats = [] ): int|false {
        return 1;
    }
}

class ConversationStoreTest extends TestCase {

    protected function setUp(): void    { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_create_returns_integer_id(): void {
        global $wpdb;
        $fake            = new FakeWpdb();
        $fake->insert_id = 42;
        $wpdb            = $fake;

        Functions\when( 'get_current_user_id' )->justReturn( 1 );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

        $store = new ConversationStore();
        $id    = $store->create( 'Test conversation', 5 );
        $this->assertSame( 42, $id );
    }

    public function test_add_message_returns_integer(): void {
        global $wpdb;
        $fake            = new FakeWpdb();
        $fake->insert_id = 99;
        $wpdb            = $fake;

        Functions\when( 'wp_kses_post' )->alias( fn( $v ) => $v );
        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

        $store = new ConversationStore();
        $id    = $store->add_message( 42, 'user', 'Hello', 'gpt-4o', 10 );
        $this->assertSame( 99, $id );
    }

    /**
     * @covers \WP_AI_Mind\DB\ConversationStore::update_title
     */
    public function test_update_title_returns_true_on_success(): void {
        global $wpdb;
        $wpdb = new FakeWpdb();

        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

        $store  = new ConversationStore();
        $result = $store->update_title( 1, 'New title' );
        $this->assertTrue( $result );
    }

    /**
     * @covers \WP_AI_Mind\DB\ConversationStore::update_title
     */
    public function test_update_title_returns_false_when_wpdb_update_fails(): void {
        global $wpdb;
        $wpdb = new class extends FakeWpdb {
            public function update( string $table, array $data, array $where, array $formats = [], array $where_formats = [] ): int|false {
                return false;
            }
        };

        Functions\when( 'sanitize_text_field' )->alias( fn( $v ) => $v );

        $store  = new ConversationStore();
        $result = $store->update_title( 1, 'New title' );
        $this->assertFalse( $result );
    }
}

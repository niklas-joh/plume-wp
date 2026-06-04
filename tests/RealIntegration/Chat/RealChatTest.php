<?php
/**
 * Real integration tests for the chat feature.
 *
 * Makes live Anthropic API calls via the Pro-BYOK path.
 * SKIPPED when CLAUDE_API_KEY is absent.
 *
 * Cost: ~$0.0003/run (claude-haiku-4-5-20251001, minimal prompts).
 *
 * @package Stilus\Tests\RealIntegration\Chat
 */

declare( strict_types=1 );

namespace Stilus\Tests\RealIntegration\Chat;

use Stilus\Tests\RealIntegration\RealIntegrationTestCase;

/**
 * @since 1.8.0
 */
class RealChatTest extends RealIntegrationTestCase {

	/** @since 1.8.0 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::skip_without_api_key();
	}

	/** @since 1.8.0 */
	public function setUp(): void {
		parent::setUp();
		$this->activate_byok_tier( self::$editor_user_id );
	}

	/**
	 * Chat feature returns a real non-empty AI response via Pro-BYOK.
	 *
	 * @since 1.8.0
	 */
	public function test_send_message_returns_real_ai_response(): void {
		wp_set_current_user( self::$editor_user_id );

		$create = $this->rest_do( 'POST', '/stilus/v1/conversations', [ 'title' => 'Real Chat Test' ] );
		$this->assertSame( 201, $create->get_status() );
		$conv_id = $create->get_data()['id'];

		// No HTTP mock — live Anthropic API call.
		$response = $this->rest_do(
			'POST',
			"/stilus/v1/conversations/{$conv_id}/messages",
			[
				'content'  => 'Reply with only the word "pong". Nothing else.',
				'provider' => 'claude',
				'model'    => 'claude-haiku-4-5-20251001',
			]
		);

		$data = $response->get_data();
		$this->assertSame(
			200,
			$response->get_status(),
			sprintf( 'Expected 200, got %d. Body: %s', $response->get_status(), wp_json_encode( $data ) )
		);
		$this->assertNotEmpty( $data['content'] ?? '' );
		$this->assertGreaterThan( 0, (int) ( $data['tokens'] ?? 0 ) );
	}

	/**
	 * Conversation history is persisted after a real AI turn.
	 *
	 * @since 1.8.0
	 */
	public function test_message_history_persisted_after_real_turn(): void {
		wp_set_current_user( self::$editor_user_id );

		$create  = $this->rest_do( 'POST', '/stilus/v1/conversations', [ 'title' => 'History Test' ] );
		$conv_id = $create->get_data()['id'];

		$this->rest_do(
			'POST',
			"/stilus/v1/conversations/{$conv_id}/messages",
			[
				'content'  => 'Say "ok".',
				'provider' => 'claude',
				'model'    => 'claude-haiku-4-5-20251001',
			]
		);

		$history  = $this->rest_do( 'GET', "/stilus/v1/conversations/{$conv_id}/messages" );
		$messages = $history->get_data();

		$this->assertGreaterThanOrEqual( 2, count( $messages ), 'Must have user + assistant turns.' );
		$this->assertContains( 'user', array_column( $messages, 'role' ) );
		$this->assertContains( 'assistant', array_column( $messages, 'role' ) );
	}

	/**
	 * Multi-turn conversation context is preserved across real API turns.
	 *
	 * @since 1.8.0
	 */
	public function test_multi_turn_conversation_maintains_context(): void {
		wp_set_current_user( self::$editor_user_id );

		$create  = $this->rest_do( 'POST', '/stilus/v1/conversations', [ 'title' => 'Context Test' ] );
		$conv_id = $create->get_data()['id'];

		// First turn: tell Claude a number.
		$this->rest_do(
			'POST',
			"/stilus/v1/conversations/{$conv_id}/messages",
			[
				'content'  => 'My secret number is 77. Acknowledge with just "ack".',
				'provider' => 'claude',
				'model'    => 'claude-haiku-4-5-20251001',
			]
		);

		// Second turn: ask Claude to recall it.
		$recall = $this->rest_do(
			'POST',
			"/stilus/v1/conversations/{$conv_id}/messages",
			[
				'content'  => 'What was my secret number? Reply with just the number.',
				'provider' => 'claude',
				'model'    => 'claude-haiku-4-5-20251001',
			]
		);

		$this->assertStringContainsString( '77', (string) ( $recall->get_data()['content'] ?? '' ) );
	}
}

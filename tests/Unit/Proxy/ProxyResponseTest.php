<?php
/**
 * Unit tests for the ProxyResponse value object.
 *
 * @package WP_AI_Mind\Tests\Unit\Proxy
 */

declare( strict_types=1 );

namespace WP_AI_Mind\Tests\Unit\Proxy;

use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Proxy\ProxyResponse;

/**
 * Covers ProxyResponse::from_array() and ProxyResponse::to_claude_format().
 *
 * @since 1.2.0
 */
class ProxyResponseTest extends TestCase {

	/**
	 * Verify that from_array() correctly maps all three fields from a complete payload.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_from_array_maps_fields_correctly(): void {
		$data = [
			'content' => 'Hello from the proxy.',
			'usage'   => [
				'input_tokens'  => 42,
				'output_tokens' => 17,
			],
		];

		$dto = ProxyResponse::from_array( $data );

		$this->assertSame( 'Hello from the proxy.', $dto->content );
		$this->assertSame( 42, $dto->input_tokens );
		$this->assertSame( 17, $dto->output_tokens );
	}

	/**
	 * Verify that to_claude_format() wraps the plain string in the block-array structure
	 * that ClaudeProvider::parse_response() expects.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_to_claude_format_wraps_content_in_block_array(): void {
		$dto    = new ProxyResponse( 'AI answer', 10, 5 );
		$result = $dto->to_claude_format();

		$this->assertArrayHasKey( 'content', $result );
		$this->assertIsArray( $result['content'] );
		$this->assertCount( 1, $result['content'] );

		$block = $result['content'][0];
		$this->assertSame( 'text', $block['type'] );
		$this->assertSame( 'AI answer', $block['text'] );

		$this->assertArrayHasKey( 'usage', $result );
		$this->assertSame( 10, $result['usage']['input_tokens'] );
		$this->assertSame( 5, $result['usage']['output_tokens'] );
	}

	/**
	 * Verify that from_array() falls back to an empty string when content is not a string.
	 *
	 * The proxy always returns a string, but this guards against accidentally
	 * passing a raw Claude wire-format body (which has an array in content).
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_from_array_handles_non_string_content_gracefully(): void {
		$data = [
			'content' => [ [ 'type' => 'text', 'text' => 'should be ignored' ] ],
			'usage'   => [
				'input_tokens'  => 1,
				'output_tokens' => 1,
			],
		];

		$dto = ProxyResponse::from_array( $data );

		$this->assertSame( '', $dto->content, 'Non-string content must fall back to empty string.' );
	}

	/**
	 * Verify that from_array() defaults token counts to zero when usage is absent.
	 *
	 * @since 1.2.0
	 * @return void
	 */
	public function test_from_array_defaults_token_counts_to_zero_when_absent(): void {
		$dto = ProxyResponse::from_array( [ 'content' => 'No usage data.' ] );

		$this->assertSame( 0, $dto->input_tokens );
		$this->assertSame( 0, $dto->output_tokens );
	}
}

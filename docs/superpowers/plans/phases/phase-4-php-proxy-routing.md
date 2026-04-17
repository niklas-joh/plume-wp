# Phase 4: PHP — ProxyProvider + ProviderFactory Routing

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Free/trial users transparently route through a new `ProxyProvider` class (which calls `NJ_Proxy_Client` → Cloudflare Worker). Pro users continue using their own API keys through existing providers. `nj_resolve_provider()` is the single routing decision point. UsageLogger is disabled (logging has moved to Worker KV).

**Architecture:** `ProxyProvider` implements the existing `ProviderInterface` so `ChatRestController` requires zero changes. `nj_resolve_provider()` is a global function (same pattern as `wp_ai_mind_is_pro()`) that inspects `NJ_Entitlement` to select the correct provider.

**Tech Stack:** PHP 8.1+, WordPress plugin, PHPUnit + Brain Monkey

**Depends on:** Phase 2 (NJ_Auth + NJ_Entitlement available) + Phase 3 (Worker `/v1/chat` endpoint live)

---

## Task 0: Pre-implementation Reuse Audit

> **Mandatory.** Complete before writing any new PHP classes.

- [ ] **Step 0.1: Read `ProviderInterface` and `AbstractProvider`**

```bash
cat includes/Providers/ProviderInterface.php
cat includes/Providers/AbstractProvider.php
# ProxyProvider MUST extend AbstractProvider and implement ProviderInterface.
# Do not create a parallel hierarchy.
```

- [ ] **Step 0.2: Read existing Provider implementations**

```bash
ls includes/Providers/
# Review ClaudeProvider.php (or similar) to understand the pattern ProxyProvider must follow.
# ProxyProvider.php should follow the same structure — no bespoke patterns.
```

- [ ] **Step 0.3: Read `NJ_Entitlement::feature()` and `NJ_Entitlement::get()`**

```bash
grep -n "feature\|get\|allowed_models" includes/Entitlement/NJ_Entitlement.php
# Understand what data is available (including allowed_models from Phase 2) before
# implementing ProxyProvider's model-selection logic.
```

- [ ] **Step 0.4: Confirm `nj_resolve_provider()` does not already exist**

```bash
grep -rn "nj_resolve_provider" . --include="*.php"
# If it exists, read it before modifying — do not duplicate.
```

---

## Design Decisions

| Decision | Choice | Reason |
|----------|--------|--------|
| ProxyProvider slug | `'proxy'` | Distinguishable from real provider slugs; logged in any debug output |
| Free/trial model | `claude-haiku-4-5` (forced) | Cheapest capable model; qualitative incentive to upgrade |
| `pro_managed` model | Requested model forwarded to Worker | Worker validates against allowlist from `tier-config.ts`; plugin UI uses `allowed_models` from entitlement |
| Max output tokens (free/trial) | 1,000 (from Worker tier-config) | Hard cap enforced by Worker; plugin honours it locally too |
| Max output tokens (pro_managed) | 8,000 (from Worker tier-config) | Worker enforces final cap |
| Simulated streaming | Word-by-word token split | Real SSE passthrough is Phase 7; this gives users the streaming UX immediately |
| Tool calling | `supports_tools() = false` on ProxyProvider | Tool calls require own API key (`pro` BYOK); prevents misconfigured requests |
| UsageLogger removal | Remove `maybe_log()` call from AbstractProvider | Worker KV is the authoritative logger; plugin-side logging is redundant |

---

## File Map

**New files:**
- `includes/Proxy/NJ_Proxy_Client.php`
- `includes/Providers/ProxyProvider.php`
- `tests/Unit/Proxy/NJProxyClientTest.php`
- `tests/Unit/Providers/ProxyProviderTest.php`

**Modified files:**
- `wp-ai-mind.php` — add eager-load of `nj_resolve_provider()` global helper
- `includes/Providers/ProviderFactory.php` — `make_default()` delegates to `nj_resolve_provider()`
- `includes/Modules/Chat/ChatRestController.php` — use `nj_resolve_provider()` for provider selection
- `includes/Providers/AbstractProvider.php` — remove `maybe_log()` call and `UsageLogger` import

---

## Task 1: NJ_Proxy_Client

**Files:** Create `includes/Proxy/NJ_Proxy_Client.php`, `tests/Unit/Proxy/NJProxyClientTest.php`

- [ ] **Step 1.1: Write failing tests** (`tests/Unit/Proxy/NJProxyClientTest.php`)

```php
<?php
namespace WP_AI_Mind\Tests\Unit\Proxy;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Proxy\NJ_Proxy_Client;
use WP_AI_Mind\Providers\ProviderException;

class NJProxyClientTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'get_option' )->justReturn( 'valid-jwt-token' );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_json_encode' )->returnArg();
        Functions\when( 'wp_remote_post' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"content":[{"type":"text","text":"hello"}],"usage":{"input_tokens":10,"output_tokens":5}}' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_chat_returns_raw_body_on_success(): void {
        $client = new NJ_Proxy_Client();
        $result = $client->chat( [ 'model' => 'claude-haiku-4-5', 'messages' => [] ] );
        $this->assertIsString( $result );
        $decoded = json_decode( $result, true );
        $this->assertArrayHasKey( 'content', $decoded );
    }

    public function test_chat_throws_provider_exception_on_429(): void {
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"token_limit_exceeded"}' );

        $this->expectException( ProviderException::class );

        $client = new NJ_Proxy_Client();
        $client->chat( [ 'model' => 'claude-haiku-4-5', 'messages' => [] ] );
    }

    public function test_chat_throws_provider_exception_on_server_error(): void {
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 500 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '{"error":"internal error"}' );

        $this->expectException( ProviderException::class );

        $client = new NJ_Proxy_Client();
        $client->chat( [ 'model' => 'claude-haiku-4-5', 'messages' => [] ] );
    }
}
```

Run: `./vendor/bin/phpunit tests/Unit/Proxy/NJProxyClientTest.php --colors=always`
Expected: FAIL (class not found)

- [ ] **Step 1.2: Implement `includes/Proxy/NJ_Proxy_Client.php`**

```php
<?php
namespace WP_AI_Mind\Proxy;

use WP_AI_Mind\Auth\NJ_Auth;
use WP_AI_Mind\Providers\ProviderException;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * HTTP client for the Cloudflare Worker proxy.
 * Used by ProxyProvider for free/trial user chat requests.
 */
class NJ_Proxy_Client {

    /**
     * Sends a chat completion request to the Worker.
     * $body must be a valid Anthropic messages API payload (model, max_tokens, messages).
     *
     * @throws ProviderException on 429 (limit exceeded) or non-2xx responses.
     * @return string Raw JSON response body from the Worker.
     */
    public function chat( array $body ): string {
        $token = NJ_Auth::get_valid_access_token();
        if ( '' === $token ) {
            throw new ProviderException(
                __( 'Not authenticated. Please log in to use AI features.', 'wp-ai-mind' ),
                'proxy', 401, false
            );
        }

        $response = wp_remote_post( NJ_Auth::PROXY_BASE . '/v1/chat', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => WP_AI_MIND_HTTP_TIMEOUT,
        ] );

        if ( is_wp_error( $response ) ) {
            throw new ProviderException(
                $response->get_error_message(),
                'proxy', 502, true
            );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $raw    = wp_remote_retrieve_body( $response );

        if ( 429 === $status ) {
            throw new ProviderException(
                __( 'Monthly AI credit limit reached. Upgrade to Pro for unlimited access.', 'wp-ai-mind' ),
                'proxy', 429, false
            );
        }

        if ( $status < 200 || $status >= 300 ) {
            $data = json_decode( $raw, true );
            throw new ProviderException(
                is_array( $data ) && ! empty( $data['error'] )
                    ? (string) $data['error']
                    : __( 'Proxy error. Please try again.', 'wp-ai-mind' ),
                'proxy',
                (int) $status,
                in_array( (int) $status, [ 500, 502, 503 ], true )
            );
        }

        return $raw;
    }
}
```

- [ ] **Step 1.3: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Proxy/NJProxyClientTest.php --colors=always
# Expected: 3 tests PASS
```

- [ ] **Step 1.4: Commit**

```bash
git add includes/Proxy/NJ_Proxy_Client.php tests/Unit/Proxy/NJProxyClientTest.php
git commit -m "feat(proxy): add NJ_Proxy_Client HTTP client for Worker chat endpoint"
```

---

## Task 2: ProxyProvider

**Files:** Create `includes/Providers/ProxyProvider.php`, `tests/Unit/Providers/ProxyProviderTest.php`

- [ ] **Step 2.1: Write failing tests** (`tests/Unit/Providers/ProxyProviderTest.php`)

```php
<?php
namespace WP_AI_Mind\Tests\Unit\Providers;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WP_AI_Mind\Providers\ProxyProvider;
use WP_AI_Mind\Providers\ProviderException;
use WP_AI_Mind\Providers\CompletionRequest;

class ProxyProviderTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_slug_returns_proxy(): void {
        $provider = new ProxyProvider();
        $this->assertSame( 'proxy', $provider->get_slug() );
    }

    public function test_supports_tools_returns_false(): void {
        $provider = new ProxyProvider();
        $this->assertFalse( $provider->supports_tools() );
    }

    public function test_is_available_returns_false_when_not_authenticated(): void {
        Functions\when( 'get_option' )->justReturn( '' );
        $provider = new ProxyProvider();
        $this->assertFalse( $provider->is_available() );
    }

    public function test_is_available_returns_true_when_authenticated(): void {
        Functions\when( 'get_option' )->justReturn( 'some-token' );
        $provider = new ProxyProvider();
        $this->assertTrue( $provider->is_available() );
    }

    public function test_generate_image_throws_403_exception(): void {
        $this->expectException( ProviderException::class );
        $provider = new ProxyProvider();
        $provider->generate_image( 'a sunset' );
    }

    public function test_get_default_model_returns_haiku(): void {
        $provider = new ProxyProvider();
        $this->assertSame( 'claude-haiku-4-5', $provider->get_default_model() );
    }
}
```

Run: `./vendor/bin/phpunit tests/Unit/Providers/ProxyProviderTest.php --colors=always`
Expected: FAIL (class not found)

- [ ] **Step 2.2: Implement `includes/Providers/ProxyProvider.php`**

```php
<?php
namespace WP_AI_Mind\Providers;

use WP_AI_Mind\Auth\NJ_Auth;
use WP_AI_Mind\Proxy\NJ_Proxy_Client;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Routes free/trial user chat requests through the NJ Cloudflare Worker proxy.
 * The Worker uses the platform's Anthropic API key — the plugin never sees it.
 *
 * This provider implements ProviderInterface identically to ClaudeProvider,
 * so ChatRestController requires no changes to support it.
 */
class ProxyProvider extends AbstractProvider {

    private NJ_Proxy_Client $client;

    public function __construct() {
        $this->client = new NJ_Proxy_Client();
    }

    public function get_slug(): string          { return 'proxy'; }
    public function get_default_model(): string  { return 'claude-haiku-4-5'; }
    public function is_available(): bool         { return NJ_Auth::is_authenticated(); }
    public function supports_tools(): bool       { return false; } // Tool calls require Pro plan.

    public function get_models(): array {
        // Return available models from the entitlement payload (populated by Worker tier-config).
        // For free/trial this is just Haiku. For pro_managed it includes Sonnet, Opus, etc.
        $entitlement    = \WP_AI_Mind\Entitlement\NJ_Entitlement::get();
        $allowed_models = (array) ( $entitlement['allowed_models'] ?? [ 'claude-haiku-4-5' ] );

        $models = [];
        foreach ( $allowed_models as $model_id ) {
            $models[ $model_id ] = [
                'name'            => $model_id,
                'input_per_mtok'  => 0, // Cost absorbed by platform.
                'output_per_mtok' => 0,
            ];
        }
        return $models;
    }

    protected function do_complete( CompletionRequest $request ): CompletionResponse {
        $entitlement    = \WP_AI_Mind\Entitlement\NJ_Entitlement::get();
        $can_select     = (bool) ( $entitlement['features']['model_selection'] ?? false );
        $allowed_models = (array) ( $entitlement['allowed_models'] ?? [] );

        // Respect model selection for pro_managed; force Haiku for free/trial.
        if ( $can_select && ! empty( $request->model ) && in_array( $request->model, $allowed_models, true ) ) {
            $model = $request->model;
        } else {
            $model = $this->get_default_model(); // 'claude-haiku-4-5' for free/trial
        }

        $body = [
            'model'      => $model,
            'max_tokens' => (int) $request->max_tokens, // Worker enforces the per-tier cap
            'messages'   => $request->messages,
        ];

        if ( '' !== $request->system ) {
            $body['system'] = $request->system;
        }

        $raw  = $this->client->chat( $body );
        $data = json_decode( $raw, true );

        return $this->parse_claude_response( $data ?? [] );
    }

    protected function do_stream( CompletionRequest $request, callable $on_chunk ): CompletionResponse {
        // Phase 4: simulate streaming from buffered response.
        // Real SSE passthrough is implemented in Phase 7.
        $response = $this->do_complete( $request );
        $words    = explode( ' ', $response->content );

        foreach ( $words as $i => $word ) {
            $on_chunk( ( $i > 0 ? ' ' : '' ) . $word );
        }

        return $response;
    }

    public function generate_image( string $prompt, array $options = [] ): int {
        throw new ProviderException(
            __( 'Image generation requires a Pro plan with your own API key.', 'wp-ai-mind' ),
            'proxy', 403, false
        );
    }

    private function parse_claude_response( array $data ): CompletionResponse {
        $content = '';
        foreach ( $data['content'] ?? [] as $block ) {
            if ( 'text' === ( $block['type'] ?? '' ) ) {
                $content .= $block['text'] ?? '';
            }
        }

        return new CompletionResponse(
            content:           $content,
            model:             $data['model']                       ?? 'claude-haiku-4-5',
            prompt_tokens:     (int) ( $data['usage']['input_tokens']  ?? 0 ),
            completion_tokens: (int) ( $data['usage']['output_tokens'] ?? 0 ),
            cost_usd:          0.0, // Cost absorbed by the platform.
            raw:               $data,
        );
    }
}
```

- [ ] **Step 2.3: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Providers/ProxyProviderTest.php --colors=always
# Expected: 6 tests PASS
```

- [ ] **Step 2.4: Commit**

```bash
git add includes/Providers/ProxyProvider.php tests/Unit/Providers/ProxyProviderTest.php
git commit -m "feat(providers): add ProxyProvider routing free/trial requests through Worker"
```

---

## Task 3: nj_resolve_provider() + ProviderFactory Wiring

**Files:** Modify `wp-ai-mind.php`, `includes/Providers/ProviderFactory.php`, `includes/Modules/Chat/ChatRestController.php`

- [ ] **Step 3.1: Add `nj_resolve_provider()` to `wp-ai-mind.php` eager block**

After the `nj_feature()` function definition (added in Phase 2), add:

```php
if ( ! function_exists( 'nj_resolve_provider' ) ) {
    /**
     * Returns the correct ProviderInterface for the current authenticated user.
     *
     * Routing rules:
     * - own_key feature (Pro BYOK): returns a direct provider using the user's own API key.
     *   Worker is bypassed — no platform cost for BYOK requests.
     * - pro_managed / trial / free (authenticated): returns ProxyProvider.
     *   ProxyProvider routes via the Worker, which enforces per-tier limits and model allowlists.
     * - Not authenticated: returns ProxyProvider (is_available() = false; caller must check this).
     *
     * @param string $provider_slug Optional provider slug for Pro BYOK users ('claude', 'openai', etc.)
     */
    function nj_resolve_provider( string $provider_slug = '' ): \WP_AI_Mind\Providers\ProviderInterface {
        if ( \nj_feature( 'own_key' ) ) {
            // Pro BYOK path: direct provider with user's own API key; Worker not involved.
            $factory = new \WP_AI_Mind\Providers\ProviderFactory(
                new \WP_AI_Mind\Settings\ProviderSettings()
            );
            return '' !== $provider_slug
                ? $factory->make( $provider_slug )
                : $factory->make_default();
        }

        // Pro Managed / trial / free path: always route via Worker.
        // ProxyProvider respects model_selection feature flag internally.
        return new \WP_AI_Mind\Providers\ProxyProvider();
    }
}
```

- [ ] **Step 3.2: Update `includes/Providers/ProviderFactory.php::make_default()`**

```php
public function make_default(): ProviderInterface {
    // When NJ routing is active, delegate to entitlement-aware resolver.
    if ( function_exists( 'nj_resolve_provider' ) ) {
        return \nj_resolve_provider();
    }

    // Legacy fallback (removed in Phase 5 when Freemius is stripped).
    $slug = get_option( 'wp_ai_mind_default_provider', 'claude' );
    return $this->make( ! empty( $slug ) ? $slug : 'claude' );
}
```

- [ ] **Step 3.3: Update `includes/Modules/Chat/ChatRestController.php::send_message()`**

Find the line that calls `$factory->make( $provider_slug )` and replace with:

```php
// Use entitlement-aware routing when available; fall back to direct factory for Pro users.
$provider = function_exists( 'nj_resolve_provider' )
    ? \nj_resolve_provider( $provider_slug )
    : $factory->make( $provider_slug );
```

- [ ] **Step 3.4: Run full test suite**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
# Expected: All tests PASS
```

- [ ] **Step 3.5: Commit**

```bash
git add wp-ai-mind.php includes/Providers/ProviderFactory.php includes/Modules/Chat/ChatRestController.php
git commit -m "feat(routing): add nj_resolve_provider() and wire into ProviderFactory and ChatRestController"
```

---

## Task 4: Remove UsageLogger from AbstractProvider

**Files:** Modify `includes/Providers/AbstractProvider.php`

- [ ] **Step 4.1: Remove `maybe_log()` from `AbstractProvider`**

In `includes/Providers/AbstractProvider.php`:

1. Remove the `use WP_AI_Mind\DB\UsageLogger;` import at the top
2. Remove the call to `$this->maybe_log( $request, $response )` in the `complete()` method
3. Remove the call to `$this->maybe_log( $request, $response )` in the `stream()` method
4. Remove the entire `private function maybe_log(...)` method

The logging has moved to the Worker's KV store. The `UsageLogger` class itself is deleted in Phase 5.

- [ ] **Step 4.2: Run full test suite**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
# Expected: All tests PASS (AbstractProviderTest must not reference maybe_log)
```

- [ ] **Step 4.3: Commit**

```bash
git add includes/Providers/AbstractProvider.php
git commit -m "refactor(providers): remove maybe_log from AbstractProvider (logging moved to Worker KV)"
```

---

## Task 5: Post-implementation Code Reuse Verification

> **Mandatory.** Run before marking Phase 4 complete.

- [ ] **Step 5.1: No hardcoded plan names in ProxyProvider**

```bash
grep -n "pro_managed\|free\|trial\|plan ===" includes/Providers/ProxyProvider.php
# Expected: 0 matches — ProxyProvider uses nj_feature() and entitlement data, not plan name strings
```

- [ ] **Step 5.2: No hardcoded model names in ProxyProvider**

```bash
grep -n "claude-haiku\|claude-sonnet\|claude-opus" includes/Providers/ProxyProvider.php
# Expected: only in get_default_model() return value; model lists come from entitlement payload
```

- [ ] **Step 5.3: AbstractProvider contract still honoured**

```bash
grep -n "do_complete\|do_stream\|get_slug\|is_available\|get_models" includes/Providers/ProxyProvider.php
# Expected: all required ProviderInterface methods are implemented
```

- [ ] **Step 5.4: Full test suite passes**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
# Expected: all tests pass including new ProxyProvider tests
```

---

## Phase 4 Acceptance Criteria

- [ ] Free/trial user chat request routes via `ProxyProvider` → Worker → Anthropic (verify via network/debug log), model forced to Haiku
- [ ] `pro_managed` user chat request routes via `ProxyProvider` → Worker, requested model forwarded; Worker validates against allowlist
- [ ] Pro BYOK user chat request routes via direct `ClaudeProvider` (Worker not involved)
- [ ] `ProxyProvider::get_models()` returns Haiku only for free/trial; returns full allowed_models list for pro_managed
- [ ] `ProxyProvider::is_available()` returns `false` when not authenticated
- [ ] `ProxyProvider::generate_image()` throws 403 `ProviderException`
- [ ] `ProxyProvider::supports_tools()` returns `false`
- [ ] Token usage is no longer written to `wpaim_usage_log` table
- [ ] All PHPUnit tests pass: `./vendor/bin/phpunit tests/Unit/ --colors=always`

---

## Phase 4 Risk Notes

- **`nj_resolve_provider()` calls `NJ_Entitlement::get()`** which may trigger one HTTP call per hour on transient expiry. First-request-after-expiry latency: up to 300ms. Mitigated by the 1-hour cache.
- **ProxyProvider silently ignores `$provider_slug` for free users.** If a free user selected Gemini as their default, they will receive Claude Haiku responses. This is intentional — document it in the UI (Phase 6): "Free and trial plans use Claude Haiku via shared infrastructure."
- **`wp_remote_post()` has a configurable timeout.** The `WP_AI_MIND_HTTP_TIMEOUT` constant (60s) is used. Anthropic Haiku responses at 1,000 tokens are typically <10s — this timeout is safe.

# Phase 2: PHP — Auth + Entitlement Layer

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The WordPress plugin can authenticate against the Cloudflare Worker, store JWTs, fetch entitlement data, and expose it to all PHP callers via `nj_feature()`. This replaces ProGate as the source of truth. ProGate is NOT removed yet — both coexist during this phase.

**Architecture:** Three new PHP classes (`NJ_Auth`, `NJ_Entitlement`, `NJAuthRestController`) plus a global helper function `nj_feature()`. Auth tokens stored in `wp_options`. Entitlement cached as a 1-hour WP transient. New WP REST endpoints proxy credentials to the Worker.

**Tech Stack:** PHP 8.1+, WordPress wp_options, WP transients, WP REST API, wp_remote_post/get, PHPUnit + Brain Monkey

**Depends on:** Phase 1 deployed (Worker endpoints live at `https://wp-ai-mind-proxy.YOUR.workers.dev`)

---

## Task 0: Pre-implementation Reuse Audit

> **Mandatory.** Complete before writing any new PHP classes.

- [ ] **Step 0.1: Audit existing Auth-related PHP files**

```bash
find includes/ -name "*.php" | xargs grep -l "token\|auth\|jwt\|session" -i 2>/dev/null
# Review each match. Do not create a new class for logic that already exists.
```

- [ ] **Step 0.2: Audit existing REST controller pattern**

```bash
ls includes/Admin/*RestController.php includes/Modules/**/*RestController.php 2>/dev/null
# Follow the same structure/conventions as existing REST controllers.
```

- [ ] **Step 0.3: Audit `wp_options` usage pattern in the plugin**

```bash
grep -rn "get_option\|update_option" includes/ --include="*.php" | head -20
# Understand naming conventions (prefix `wpaim_`) before choosing option keys.
```

- [ ] **Step 0.4: Confirm NJ_Auth and NJ_Entitlement do not already exist**

```bash
ls includes/Auth/NJ_Auth.php includes/Entitlement/NJ_Entitlement.php 2>/dev/null \
  || echo "Confirmed: classes not yet created"
# If either exists, read it fully before proceeding — do not overwrite in-progress work.
```

---

## Design Decisions

| Decision | Choice | Reason |
|----------|--------|--------|
| Token storage | `wp_options` (access token plain; refresh token AES-256-CBC encrypted) | Access token is short-lived (1 h) — same risk as any API key. Refresh token has a 30-day lifetime and is encrypted with `openssl_encrypt( $refresh, 'aes-256-cbc', AUTH_KEY, 0, AUTH_SALT )` before writing to `wp_options`, and decrypted on read. |
| Entitlement cache | 1-hour WP transient | Matches JWT expiry window; balances freshness vs HTTP overhead |
| Null-object pattern | `empty_doc()` returns a fully-shaped array | Callers never need to null-check; feature flags default to `false` |
| Proactive refresh | Refresh when token has < 5 minutes remaining | Prevents mid-request token expiry without adding a second HTTP round-trip |
| Login/register REST endpoints | `permission_callback => __return_true` | Auth endpoints exist before WP session; nonce not available on first load |
| **Multi-user model** | **One NJ account per WordPress site (site-wide `wp_options`)** | **Design constraint:** All WordPress users on a site share a single NJ account and entitlement tier. A WP Editor using the plugin authenticates as the same NJ account as the WP Admin. `POST /nj/logout` invalidates the token for every WP user simultaneously. This is intentional for single-owner installs (the primary use-case) and avoids the complexity of per-WP-user NJ account mapping. Sites with multiple WP users who each need independent NJ accounts are out of scope for this phase — see Risk Notes for migration path. |

---

## File Map

**New files:**
- `includes/Auth/NJ_Auth.php`
- `includes/Entitlement/NJ_Entitlement.php`
- `includes/Admin/NJAuthRestController.php`
- `tests/Unit/Auth/NJAuthTest.php`
- `tests/Unit/Entitlement/NJEntitlementTest.php`

**Modified files:**
- `wp-ai-mind.php` — add eager-loads for NJ_Auth, NJ_Entitlement, `nj_feature()` helper
- `includes/Core/Plugin.php` — register NJAuthRestController REST routes
- `includes/Admin/SettingsPage.php` — add `isAuthenticated` + `entitlement` to localized data
- `includes/Admin/ChatPage.php` — same
- `includes/Admin/DashboardPage.php` — same
- `includes/Admin/GeneratorPage.php` — same
- `includes/Modules/Editor/EditorModule.php` — same
- `includes/Modules/Frontend/FrontendWidgetModule.php` — same
- `includes/Modules/Generator/GeneratorModule.php` — same
- `includes/Modules/Images/ImagesModule.php` — same
- `includes/Modules/Seo/SeoModule.php` — same
- `includes/Modules/Usage/UsageModule.php` — same

---

## Task 1: NJ_Auth Class

**Files:** Create `includes/Auth/NJ_Auth.php`, `tests/Unit/Auth/NJAuthTest.php`

- [ ] **Step 1.1: Write failing tests** (`tests/Unit/Auth/NJAuthTest.php`)

```php
<?php
namespace WP_AI_Mind\Tests\Unit\Auth;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class NJAuthTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // Mock all WP functions used by NJ_Auth.
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'delete_option' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_is_authenticated_returns_false_when_no_token(): void {
        Functions\when( 'get_option' )->justReturn( '' );
        $this->assertFalse( \WP_AI_Mind\Auth\NJ_Auth::is_authenticated() );
    }

    public function test_is_authenticated_returns_true_when_token_present(): void {
        Functions\when( 'get_option' )->justReturn( 'some-jwt-token' );
        $this->assertTrue( \WP_AI_Mind\Auth\NJ_Auth::is_authenticated() );
    }

    public function test_logout_clears_tokens(): void {
        Functions\expect( 'delete_option' )->twice();
        Functions\expect( 'delete_transient' )->once();
        \WP_AI_Mind\Auth\NJ_Auth::logout();
    }

    public function test_decode_payload_returns_null_for_invalid_jwt(): void {
        $method = new \ReflectionMethod( \WP_AI_Mind\Auth\NJ_Auth::class, 'decode_payload' );
        $method->setAccessible( true );
        $this->assertNull( $method->invoke( null, 'not.a.jwt' ) );
        $this->assertNull( $method->invoke( null, '' ) );
    }

    public function test_stored_refresh_token_is_encrypted(): void {
        if ( ! defined( 'AUTH_KEY' ) ) {
            define( 'AUTH_KEY', 'test-auth-key-32-bytes-long-value' );
        }
        if ( ! defined( 'AUTH_SALT' ) ) {
            define( 'AUTH_SALT', 'test-auth-salt-16b' );
        }

        $raw_refresh = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJ1c2VyMSJ9.sig';
        $stored_value = null;

        Functions\when( 'update_option' )->alias(
            function ( string $key, string $value ) use ( &$stored_value, $raw_refresh ) {
                if ( 'wpaim_nj_refresh_token' === $key ) {
                    $stored_value = $value;
                }
                return true;
            }
        );

        $encrypt_method = new \ReflectionMethod( \WP_AI_Mind\Auth\NJ_Auth::class, 'encrypt_refresh' );
        $encrypt_method->setAccessible( true );
        $encrypted = $encrypt_method->invoke( null, $raw_refresh );

        $this->assertNotSame( $raw_refresh, $encrypted, 'Stored refresh token must not be the raw JWT.' );
        $this->assertNotEmpty( $encrypted );

        $decrypt_method = new \ReflectionMethod( \WP_AI_Mind\Auth\NJ_Auth::class, 'decrypt_refresh' );
        $decrypt_method->setAccessible( true );
        $this->assertSame( $raw_refresh, $decrypt_method->invoke( null, $encrypted ) );
    }
}
```

Run: `./vendor/bin/phpunit tests/Unit/Auth/NJAuthTest.php --colors=always`
Expected: FAIL (class not found)

- [ ] **Step 1.2: Implement `includes/Auth/NJ_Auth.php`**

```php
<?php
namespace WP_AI_Mind\Auth;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class NJ_Auth {

    private const ACCESS_KEY  = 'wpaim_nj_access_token';
    private const REFRESH_KEY = 'wpaim_nj_refresh_token';

    /**
     * Base URL of the Cloudflare Worker proxy.
     * Update this after wrangler deploy outputs the workers.dev URL.
     */
    public const PROXY_BASE = 'https://wp-ai-mind-proxy.YOUR_SUBDOMAIN.workers.dev';

    public static function store( string $access, string $refresh ): void {
        update_option( self::ACCESS_KEY,  $access,                          false );
        update_option( self::REFRESH_KEY, self::encrypt_refresh( $refresh ), false );
        delete_transient( 'wpaim_entitlement' );
    }

    public static function get_access_token(): string {
        return (string) get_option( self::ACCESS_KEY, '' );
    }

    /**
     * Returns a valid (non-expired) access token, refreshing silently if needed.
     * Returns '' if not authenticated or refresh fails.
     */
    public static function get_valid_access_token(): string {
        $token = self::get_access_token();
        if ( '' === $token ) return '';

        $payload = self::decode_payload( $token );
        if ( ! $payload ) return self::do_refresh();

        // Refresh proactively when less than 5 minutes remain.
        if ( ( (int) ( $payload['exp'] ?? 0 ) ) - time() < 300 ) {
            return self::do_refresh();
        }

        return $token;
    }

    public static function logout(): void {
        delete_option( self::ACCESS_KEY );
        delete_option( self::REFRESH_KEY );
        delete_transient( 'wpaim_entitlement' );
    }

    public static function is_authenticated(): bool {
        return '' !== self::get_access_token();
    }

    private static function do_refresh(): string {
        $stored  = (string) get_option( self::REFRESH_KEY, '' );
        $refresh = '' !== $stored ? self::decrypt_refresh( $stored ) : '';
        if ( '' === $refresh ) return '';

        $response = wp_remote_post( self::PROXY_BASE . '/v1/auth/refresh', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'refresh_token' => $refresh ] ),
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) return '';

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) {
            self::logout(); // Refresh invalid — force re-login.
            return '';
        }

        update_option( self::ACCESS_KEY, $body['access_token'], false );
        delete_transient( 'wpaim_entitlement' );
        return $body['access_token'];
    }

    /**
     * Encrypts the refresh token with AES-256-CBC before writing to wp_options.
     * Uses AUTH_KEY as the key and AUTH_SALT (truncated/padded to 16 bytes) as the IV.
     */
    private static function encrypt_refresh( string $refresh ): string {
        if ( '' === $refresh ) return '';
        $iv        = substr( hash( 'sha256', AUTH_SALT ), 0, 16 );
        $encrypted = openssl_encrypt( $refresh, 'aes-256-cbc', AUTH_KEY, 0, $iv );
        return false !== $encrypted ? $encrypted : '';
    }

    /**
     * Decrypts a refresh token previously encrypted by encrypt_refresh().
     * Returns '' on failure so the caller can treat it as "not authenticated".
     */
    private static function decrypt_refresh( string $stored ): string {
        if ( '' === $stored ) return '';
        $iv        = substr( hash( 'sha256', AUTH_SALT ), 0, 16 );
        $decrypted = openssl_decrypt( $stored, 'aes-256-cbc', AUTH_KEY, 0, $iv );
        return false !== $decrypted ? $decrypted : '';
    }

    private static function decode_payload( string $token ): ?array {
        $parts = explode( '.', $token );
        if ( 3 !== count( $parts ) ) return null;

        $decoded = base64_decode( strtr( $parts[1], '-_', '+/' ) );
        if ( false === $decoded ) return null;

        $payload = json_decode( $decoded, true );
        return is_array( $payload ) ? $payload : null;
    }
}
```

- [ ] **Step 1.3: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Auth/NJAuthTest.php --colors=always
# Expected: 4 tests PASS
```

- [ ] **Step 1.4: Commit**

```bash
git add includes/Auth/NJ_Auth.php tests/Unit/Auth/NJAuthTest.php
git commit -m "feat(auth): add NJ_Auth class for JWT token storage and refresh"
```

---

## Task 2: NJ_Entitlement Class

**Files:** Create `includes/Entitlement/NJ_Entitlement.php`, `tests/Unit/Entitlement/NJEntitlementTest.php`

- [ ] **Step 2.1: Write failing tests** (`tests/Unit/Entitlement/NJEntitlementTest.php`)

```php
<?php
namespace WP_AI_Mind\Tests\Unit\Entitlement;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class NJEntitlementTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'get_transient' )->justReturn( false );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_get' )->justReturn( [] );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 200 );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_returns_empty_doc_when_not_authenticated(): void {
        Functions\when( 'get_option' )->justReturn( '' ); // No token stored.
        $doc = \WP_AI_Mind\Entitlement\NJ_Entitlement::get();
        $this->assertSame( 'none', $doc['plan'] );
        $this->assertFalse( $doc['features']['chat'] );
        $this->assertFalse( $doc['features']['own_key'] );
    }

    public function test_get_returns_cached_transient_when_available(): void {
        Functions\when( 'get_option' )->justReturn( 'some-token' );
        $cached = [
            'plan'     => 'trial',
            'features' => [ 'chat' => true, 'generator' => true, 'seo' => true, 'images' => true, 'own_key' => false ],
            'tokens_used' => 5000, 'tokens_limit' => 300000, 'tokens_remaining' => 295000, 'resets_at' => null,
        ];
        Functions\when( 'get_transient' )->justReturn( $cached );

        // wp_remote_get should NOT be called (cache hit).
        Functions\expect( 'wp_remote_get' )->never();

        $doc = \WP_AI_Mind\Entitlement\NJ_Entitlement::get();
        $this->assertSame( 'trial', $doc['plan'] );
    }

    public function test_feature_returns_false_when_not_authenticated(): void {
        Functions\when( 'get_option' )->justReturn( '' );
        $this->assertFalse( \WP_AI_Mind\Entitlement\NJ_Entitlement::feature( 'chat' ) );
    }

    public function test_bust_deletes_transient(): void {
        Functions\expect( 'delete_transient' )->once()->with( 'wpaim_entitlement' );
        \WP_AI_Mind\Entitlement\NJ_Entitlement::bust();
    }

    public function test_get_returns_empty_doc_on_http_error(): void {
        Functions\when( 'get_option' )->justReturn( 'some-token' );
        Functions\when( 'is_wp_error' )->justReturn( true );

        $doc = \WP_AI_Mind\Entitlement\NJ_Entitlement::get();
        $this->assertSame( 'none', $doc['plan'] );
    }
}
```

Run: `./vendor/bin/phpunit tests/Unit/Entitlement/NJEntitlementTest.php --colors=always`
Expected: FAIL (class not found)

- [ ] **Step 2.2: Implement `includes/Entitlement/NJ_Entitlement.php`**

```php
<?php
namespace WP_AI_Mind\Entitlement;

use WP_AI_Mind\Auth\NJ_Auth;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class NJ_Entitlement {

    private const TRANSIENT_KEY = 'wpaim_entitlement';
    private const TTL           = HOUR_IN_SECONDS;

    /** Null-object shape returned when unauthenticated or fetch fails. */
    private static function empty_doc(): array {
        return [
            'plan'             => 'none',
            'tokens_used'      => 0,
            'tokens_limit'     => 0,
            'tokens_remaining' => 0,
            'resets_at'        => null,
            'features'         => [
                'chat'            => false,
                'generator'       => false,
                'seo'             => false,
                'images'          => false,
                'own_key'         => false,
                'model_selection' => false,
            ],
            'allowed_models'   => [],  // Populated from Worker entitlement response for pro_managed
        ];
    }

    public static function get(): array {
        if ( ! NJ_Auth::is_authenticated() ) {
            return self::empty_doc();
        }

        $cached = get_transient( self::TRANSIENT_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        return self::fetch_and_cache();
    }

    public static function feature( string $key ): bool {
        return (bool) ( self::get()['features'][ $key ] ?? false );
    }

    public static function plan(): string {
        return (string) ( self::get()['plan'] ?? 'none' );
    }

    public static function bust(): void {
        delete_transient( self::TRANSIENT_KEY );
    }

    private static function fetch_and_cache(): array {
        $token = NJ_Auth::get_valid_access_token();
        if ( '' === $token ) return self::empty_doc();

        $response = wp_remote_get( NJ_Auth::PROXY_BASE . '/v1/entitlement', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) ) return self::empty_doc();
        if ( 200 !== wp_remote_retrieve_response_code( $response ) ) return self::empty_doc();

        $doc = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $doc ) || empty( $doc['plan'] ) ) return self::empty_doc();

        set_transient( self::TRANSIENT_KEY, $doc, self::TTL );
        return $doc;
    }
}
```

- [ ] **Step 2.3: Run tests to verify they pass**

```bash
./vendor/bin/phpunit tests/Unit/Entitlement/NJEntitlementTest.php --colors=always
# Expected: 5 tests PASS
```

- [ ] **Step 2.4: Commit**

```bash
git add includes/Entitlement/NJ_Entitlement.php tests/Unit/Entitlement/NJEntitlementTest.php
git commit -m "feat(entitlement): add NJ_Entitlement class with transient caching"
```

---

## Task 3: NJAuthRestController

**Files:** Create `includes/Admin/NJAuthRestController.php`

- [ ] **Step 3.1: Implement `includes/Admin/NJAuthRestController.php`**

```php
<?php
namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Auth\NJ_Auth;
use WP_AI_Mind\Entitlement\NJ_Entitlement;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class NJAuthRestController {

    public function register_routes(): void {
        $ns = 'wp-ai-mind/v1';

        register_rest_route( $ns, '/nj/login', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'login' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'email'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ],
                'password' => [ 'type' => 'string', 'required' => true ],
            ],
        ] );

        register_rest_route( $ns, '/nj/register', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'register' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'email'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ],
                'password' => [ 'type' => 'string', 'required' => true ],
            ],
        ] );

        register_rest_route( $ns, '/nj/logout', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'logout' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( $ns, '/nj/me', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'me' ],
            'permission_callback' => '__return_true',
        ] );
    }

    public function login( \WP_REST_Request $request ): \WP_REST_Response {
        $email    = sanitize_email( (string) $request->get_param( 'email' ) );
        $password = (string) $request->get_param( 'password' );

        if ( empty( $email ) || empty( $password ) ) {
            return new \WP_REST_Response( [ 'error' => __( 'Email and password are required.', 'wp-ai-mind' ) ], 400 );
        }

        $response = wp_remote_post( NJ_Auth::PROXY_BASE . '/v1/auth/token', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( compact( 'email', 'password' ) ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_REST_Response( [ 'error' => __( 'Could not reach the authentication server.', 'wp-ai-mind' ) ], 503 );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status ) {
            return new \WP_REST_Response(
                [ 'error' => $body['error'] ?? __( 'Authentication failed.', 'wp-ai-mind' ) ],
                $status
            );
        }

        NJ_Auth::store( $body['access_token'], $body['refresh_token'] );
        NJ_Entitlement::bust();

        return new \WP_REST_Response( [
            'success'     => true,
            'plan'        => $body['plan'],
            'entitlement' => NJ_Entitlement::get(),
        ] );
    }

    public function register( \WP_REST_Request $request ): \WP_REST_Response {
        $email    = sanitize_email( (string) $request->get_param( 'email' ) );
        $password = (string) $request->get_param( 'password' );

        $response = wp_remote_post( NJ_Auth::PROXY_BASE . '/v1/auth/register', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( compact( 'email', 'password' ) ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_REST_Response( [ 'error' => __( 'Could not reach the authentication server.', 'wp-ai-mind' ) ], 503 );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 201 !== $status ) {
            return new \WP_REST_Response(
                [ 'error' => $body['error'] ?? __( 'Registration failed.', 'wp-ai-mind' ) ],
                $status
            );
        }

        // Auto-login after successful registration.
        return $this->login( $request );
    }

    public function logout( \WP_REST_Request $request ): \WP_REST_Response {
        NJ_Auth::logout();
        return new \WP_REST_Response( [ 'success' => true ] );
    }

    public function me( \WP_REST_Request $request ): \WP_REST_Response {
        NJ_Entitlement::bust();
        return new \WP_REST_Response( NJ_Entitlement::get() );
    }
}
```

- [ ] **Step 3.2: Commit**

```bash
git add includes/Admin/NJAuthRestController.php
git commit -m "feat(auth): add NJAuthRestController REST endpoints"
```

---

## Task 4: Wire into Plugin

**Files:** Modify `wp-ai-mind.php`, `includes/Core/Plugin.php`, all Admin page classes

- [ ] **Step 4.1: Add eager-loads to `wp-ai-mind.php`**

After the existing `require_once WP_AI_MIND_DIR . 'includes/Core/ProGate.php';` line, add:

```php
// NJ Auth + Entitlement — loaded eagerly for global availability.
require_once WP_AI_MIND_DIR . 'includes/Auth/NJ_Auth.php';
require_once WP_AI_MIND_DIR . 'includes/Entitlement/NJ_Entitlement.php';

if ( ! function_exists( 'nj_feature' ) ) {
    /**
     * Returns whether the current authenticated user's plan includes a feature.
     * Returns false when not authenticated.
     *
     * @param string $key Feature key: 'chat', 'generator', 'seo', 'images', 'own_key'
     */
    function nj_feature( string $key ): bool {
        return \WP_AI_Mind\Entitlement\NJ_Entitlement::feature( $key );
    }
}
```

- [ ] **Step 4.2: Register routes in `includes/Core/Plugin.php`**

In the `register_rest_routes()` method, add:

```php
public function register_rest_routes(): void {
    do_action( 'wp_ai_mind_register_rest_routes' );

    // NJ Auth routes (registered directly, not via module action).
    $auth_controller = new \WP_AI_Mind\Admin\NJAuthRestController();
    $auth_controller->register_routes();
}
```

- [ ] **Step 4.3: Update `includes/Admin/SettingsPage.php` localized data**

In the `enqueue_assets()` method, find the `wp_localize_script()` call and add:

```php
'isAuthenticated' => \WP_AI_Mind\Auth\NJ_Auth::is_authenticated(),
'entitlement'     => \WP_AI_Mind\Entitlement\NJ_Entitlement::get(),
// Keep isPro as a derived alias for backward compatibility with any third-party code.
'isPro'           => \nj_feature( 'own_key' ),
```

Repeat this pattern for all other Admin pages and module files that call `wp_localize_script()`:
- `includes/Admin/ChatPage.php`
- `includes/Admin/DashboardPage.php`
- `includes/Admin/GeneratorPage.php`
- `includes/Modules/Editor/EditorModule.php`
- `includes/Modules/Frontend/FrontendWidgetModule.php`
- `includes/Modules/Generator/GeneratorModule.php`
- `includes/Modules/Images/ImagesModule.php`
- `includes/Modules/Seo/SeoModule.php`
- `includes/Modules/Usage/UsageModule.php`

- [ ] **Step 4.4: Run full test suite**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
# Expected: All existing tests PASS + new NJAuth + NJEntitlement tests PASS
```

- [ ] **Step 4.5: Commit**

```bash
git add wp-ai-mind.php includes/Core/Plugin.php includes/Admin/ includes/Modules/
git commit -m "feat(auth): wire NJ_Auth and NJ_Entitlement into plugin bootstrap and localized data"
```

---

## Task 5: Post-implementation Code Reuse Verification

> **Mandatory.** Run before marking Phase 2 complete.

- [ ] **Step 5.1: No duplicate WP option keys**

```bash
grep -rn "wpaim_nj_access_token\|wpaim_nj_refresh_token\|wpaim_entitlement" includes/ --include="*.php"
# Expected: only NJ_Auth.php and NJ_Entitlement.php reference these keys
```

- [ ] **Step 5.2: No plan-name checks in PHP (use nj_feature() instead)**

```bash
grep -rn "plan.*===\|=== .*plan\|pro_managed\|'free'\|'trial'" includes/ --include="*.php" \
  | grep -v "NJ_Entitlement\|NJ_Auth\|Test"
# Expected: no plan-name string comparisons outside the entitlement class
```

- [ ] **Step 5.3: Confirm `nj_feature('model_selection')` is available**

```bash
grep -n "model_selection" includes/Entitlement/NJ_Entitlement.php
# Expected: appears in empty_doc() features array
```

- [ ] **Step 5.4: Full test suite still passes**

```bash
./vendor/bin/phpunit tests/Unit/ --colors=always
# Expected: all tests pass
```

---

## Phase 2 Acceptance Criteria

- [ ] `POST /wp-ai-mind/v1/nj/login` with correct credentials → stores tokens in wp_options, returns entitlement doc
- [ ] `POST /wp-ai-mind/v1/nj/login` with wrong credentials → 401 error
- [ ] `POST /wp-ai-mind/v1/nj/register` → creates account, auto-logs in, returns entitlement
- [ ] `GET /wp-ai-mind/v1/nj/me` → returns fresh entitlement (busts cache first)
- [ ] `POST /wp-ai-mind/v1/nj/logout` → clears tokens and transient
- [ ] `nj_feature('chat')` returns `false` when not authenticated
- [ ] `nj_feature('chat')` returns `true` after login with trial/free/pro_managed/pro plan
- [ ] `nj_feature('model_selection')` returns `true` for `pro_managed` and `pro` plans; `false` for `free`/`trial`
- [ ] Entitlement doc includes `allowed_models` array (populated from Worker response)
- [ ] `wpAiMindData` includes `allowed_models` for use in React model selector (Phase 6)
- [ ] Entitlement transient is set after first fetch; second call does not make HTTP request
- [ ] Refresh token is stored encrypted in `wp_options`; raw JWT value is never written to the database
- [ ] `NJ_Auth::encrypt_refresh()` / `decrypt_refresh()` round-trip returns original value
- [ ] PHPUnit test confirms stored value differs from the raw JWT string
- [ ] All PHPUnit tests pass: `./vendor/bin/phpunit tests/Unit/ --colors=always`
- [ ] `wpAiMindData` JavaScript object includes `isAuthenticated` and `entitlement` keys on all pages

---

## Phase 2 Risk Notes

- **`wp_options` for tokens is readable by any code with database access.** The access JWT is short-lived (1 h) — same risk as any plugin storing an API key. The refresh token (30-day lifetime) is encrypted with AES-256-CBC using `AUTH_KEY`/`AUTH_SALT` before storage, so a raw database read cannot be used to silently obtain new access tokens. If `AUTH_KEY` is not defined (non-standard WP setup), encryption will produce an empty string and the token will not be stored — treat this as a startup-time misconfiguration.
- **First page load after transient expiry adds ~100–300ms latency** (one HTTP call to the Worker). This is a cold-cache penalty once per hour per WordPress request — acceptable.
- **`__return_true` on auth endpoints** means any WordPress visitor can attempt login/register. This is intentional — the rate limiting is enforced by the Worker (Phase 7). The WordPress REST endpoint is just a proxy.
- **Single NJ account per WordPress site (multi-user UX implications).** Because tokens are stored in `wp_options`, the plugin operates under a single shared NJ identity for the entire WordPress site. Concrete consequences:
  - Any WP user with plugin access (Admin, Editor, Author) uses the same NJ account and entitlement tier.
  - `POST /nj/logout` clears the shared token, ending the session for *all* WP users on the site at once — not just the user who triggered the logout.
  - If a lower-privileged WP user changes the NJ password or rotates credentials, it affects every other WP user immediately.
  - This model is safe and correct for the intended single-owner install. It becomes confusing on sites where multiple WP users independently expect their own NJ sessions.
  - **If per-WP-user isolation is needed in the future**, the migration path is to replace the `wp_options` keys with `user_meta` keyed by `get_current_user_id()` and scope the `wpaim_entitlement` transient to `wpaim_entitlement_{user_id}`. The `NJ_Auth` and `NJ_Entitlement` class interfaces would not need to change — only the storage keys. This migration is deferred until there is a concrete multi-user use-case.

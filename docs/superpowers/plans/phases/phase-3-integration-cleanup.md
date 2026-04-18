# Phase 3: Integration & Cleanup

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete integration of the three-tier system with full provider routing, remove all Freemius/ProGate legacy code, and polish the admin UI. All tiers work seamlessly with appropriate routing (proxy vs direct).

**Architecture:** Final routing logic that directs Free/Pro Managed through proxy, Pro BYOK through direct provider calls. Complete removal of Freemius SDK. Polished admin UI with usage meters and upgrade flows.

**Tech Stack:** PHP 8.1+, WordPress plugin patterns, existing provider architecture

**Depends on:** Phase 1 (WordPress foundation) + Phase 2 (Cloudflare proxy) both complete

---

## Design Decisions

| Decision | Choice | Reason |
|----------|--------|--------|
| Routing Logic | Single `nj_resolve_provider()` function | Central decision point, easy to debug |
| Legacy Removal | Complete Freemius removal | Clean codebase, no mixed patterns |
| Provider Integration | Extend existing `ProviderInterface` | Maintain existing architecture patterns |
| Admin UI | Enhance existing admin pages | WordPress-native, familiar UX |
| Error Handling | Graceful degradation | Never break existing functionality |

---

## Task 0: Pre-implementation Audit

> **Mandatory.** Understand current state before final integration.

- [ ] **Step 0.1: Verify Phase 1 & 2 completion**
```bash
# Check WordPress tier system
grep -n "nj_get_user_tier\|nj_can_user" wp-ai-mind.php

# Check proxy client exists
ls includes/Proxy/NJ_Proxy_Client.php

# Confirm proxy is deployed
curl -X POST "https://wp-ai-mind-proxy.YOUR-ACCOUNT.workers.dev/v1/chat"
```

- [ ] **Step 0.2: Audit existing provider architecture**
```bash
ls includes/Providers/
grep -rn "ProviderInterface" includes/Providers/ --include="*.php"
# Understand how to integrate proxy as a new provider
```

- [ ] **Step 0.3: Map remaining ProGate usage**
```bash
grep -rn "ProGate\|wam_fs()" includes/ --include="*.php" > remaining_progate.txt
# These are the remaining calls to replace
```

---

## Task 1: Provider Routing Integration

**Files:** Create `includes/Providers/ProxyProvider.php`, modify provider factory

- [ ] **Step 1.1: Create ProxyProvider class**

```php
<?php
namespace WP_AI_Mind\Providers;

use WP_Error;
use WP_AI_Mind\Proxy\NJ_Proxy_Client;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

class ProxyProvider extends AbstractProvider {

    public function get_name(): string {
        return 'proxy';
    }

    public function is_configured(): bool {
        // Always configured for free/pro_managed users
        $tier = NJ_Tier_Manager::get_user_tier();
        return in_array( $tier, [ 'free', 'pro_managed' ], true );
    }

    public function send_request( array $messages, array $options = [] ): array|WP_Error {
        // Route through Cloudflare proxy
        return NJ_Proxy_Client::chat( $messages, $options );
    }

    public function get_available_models(): array {
        $tier = NJ_Tier_Manager::get_user_tier();

        return match( $tier ) {
            'free' => [
                'claude-3-haiku-20240307' => 'Claude 3 Haiku',
            ],
            'pro_managed' => [
                'claude-3-haiku-20240307' => 'Claude 3 Haiku',
                'claude-3-sonnet-20240229' => 'Claude 3 Sonnet',
                'claude-3-opus-20240229' => 'Claude 3 Opus',
            ],
            default => [],
        };
    }

    public function get_max_tokens(): int {
        $tier = NJ_Tier_Manager::get_user_tier();
        return $tier === 'free' ? 1000 : 4000;
    }

    protected function validate_options( array $options ): bool {
        // Basic validation
        return isset( $options['messages'] ) && is_array( $options['messages'] );
    }
}
```

- [ ] **Step 1.2: Create provider routing function**

Add to `wp-ai-mind.php`:
```php
/**
 * Determine which provider to use based on user tier
 */
function nj_resolve_provider(): string {
    $tier = nj_get_user_tier();

    return match( $tier ) {
        'free', 'pro_managed' => 'proxy',
        'pro_byok' => nj_get_user_configured_provider(), // User's choice (Claude, OpenAI, etc.)
        default => '',
    };
}

/**
 * Get user's configured provider for Pro BYOK
 */
function nj_get_user_configured_provider(): string {
    // Check which API key they have configured
    $user_id = get_current_user_id();

    if ( get_user_meta( $user_id, 'wp_ai_mind_api_key_anthropic', true ) ) {
        return 'claude';
    }

    if ( get_user_meta( $user_id, 'wp_ai_mind_api_key_openai', true ) ) {
        return 'openai';
    }

    if ( get_user_meta( $user_id, 'wp_ai_mind_api_key_gemini', true ) ) {
        return 'gemini';
    }

    return 'claude'; // Default fallback
}
```

- [ ] **Step 1.3: Modify provider factory**

Update `includes/Core/ProviderFactory.php` (or similar):
```php
public static function get_provider( ?string $provider_name = null ): ?ProviderInterface {
    if ( ! $provider_name ) {
        $provider_name = nj_resolve_provider();
    }

    return match( $provider_name ) {
        'proxy' => new ProxyProvider(),
        'claude' => new ClaudeProvider(),
        'openai' => new OpenAIProvider(),
        'gemini' => new GeminiProvider(),
        'ollama' => new OllamaProvider(),
        default => null,
    };
}
```

---

## Task 2: Complete ProGate Removal

**Files:** All files containing ProGate/Freemius references

- [ ] **Step 2.1: Replace remaining ProGate calls**

```bash
# For each file in remaining_progate.txt, replace patterns:

# OLD:
if ( wp_ai_mind_is_pro() ) {
    // feature code
}

# NEW:
if ( nj_can_user( 'specific_feature' ) ) {
    // feature code
}

# OLD:
if ( ProGate::is_pro() ) {
    $provider = ProviderFactory::get_provider( 'claude' );
}

# NEW:
$provider = ProviderFactory::get_provider( nj_resolve_provider() );
if ( $provider && $provider->is_configured() ) {
    // use provider
}
```

- [ ] **Step 2.2: Remove Freemius integration**

```bash
# Remove Freemius files
rm -rf includes/vendor/freemius/
grep -rn "freemius\|wam_fs" . --include="*.php" | cut -d: -f1 | sort -u > freemius_files.txt

# For each file in freemius_files.txt:
# - Remove freemius includes
# - Remove wam_fs() calls
# - Remove freemius menu items
# - Remove freemius settings
```

- [ ] **Step 2.3: Update plugin header**

Remove Freemius references from `wp-ai-mind.php`:
```php
// Remove these lines:
// if ( ! function_exists( 'wam_fs' ) ) {
//     // Freemius integration code
// }

// Keep clean plugin header:
<?php
/**
 * Plugin Name: WP AI Mind
 * Description: AI-powered content generation with three-tier system
 * Version: 2.0.0
 * Author: Your Name
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load tier system
require_once plugin_dir_path( __FILE__ ) . 'includes/Core/Plugin.php';
```

---

## Task 3: Admin UI Polish

**Files:** Enhance existing admin pages with tier-aware UI

- [ ] **Step 3.1: Enhanced dashboard widget**

Update `includes/Admin/NJ_Usage_Widget.php`:
```php
<?php
namespace WP_AI_Mind\Admin;

use WP_AI_Mind\Tiers\NJ_Usage_Tracker;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;

class NJ_Usage_Widget {

    public static function register_hooks(): void {
        add_action( 'wp_dashboard_setup', [ self::class, 'add_dashboard_widget' ] );
    }

    public static function add_dashboard_widget(): void {
        wp_add_dashboard_widget(
            'wp_ai_mind_usage',
            __( 'AI Mind Usage', 'wp-ai-mind' ),
            [ self::class, 'dashboard_widget_content' ]
        );
    }

    public static function dashboard_widget_content(): void {
        $usage = NJ_Usage_Tracker::get_current_usage();
        $tier = NJ_Tier_Manager::get_user_tier();

        ?>
        <div class="wp-ai-mind-usage-widget">
            <div class="usage-header">
                <h4><?php echo esc_html( ucfirst( str_replace( '_', ' ', $tier ) ) ); ?> Plan</h4>
            </div>

            <?php if ( $usage['limit'] ): ?>
                <div class="usage-meter">
                    <?php
                    $percentage = ( $usage['used'] / $usage['limit'] ) * 100;
                    $bar_color = $percentage > 80 ? '#d63638' : ( $percentage > 60 ? '#dba617' : '#00a32a' );
                    ?>
                    <div class="usage-bar" style="background: #e0e0e0; height: 10px; border-radius: 5px;">
                        <div class="usage-fill" style="width: <?php echo min( $percentage, 100 ); ?>%; background: <?php echo $bar_color; ?>; height: 100%; border-radius: 5px;"></div>
                    </div>

                    <div class="usage-text">
                        <?php echo number_format( $usage['used'] ); ?> / <?php echo number_format( $usage['limit'] ); ?> tokens
                        (<?php echo number_format( $usage['remaining'] ); ?> remaining)
                    </div>
                </div>

                <?php if ( $percentage > 80 ): ?>
                    <div class="notice notice-warning inline">
                        <p><?php _e( 'You\'ve used over 80% of your monthly tokens. Consider upgrading for higher limits.', 'wp-ai-mind' ); ?></p>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="unlimited-usage">
                    <p><strong><?php _e( 'Unlimited Usage', 'wp-ai-mind' ); ?></strong></p>
                    <p><?php _e( 'You\'re using your own API key with no monthly limits.', 'wp-ai-mind' ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( in_array( $tier, [ 'free', 'pro_managed' ] ) ): ?>
                <div class="upgrade-section">
                    <p><a href="<?php echo admin_url( 'options-general.php?page=wp-ai-mind-tiers' ); ?>" class="button">
                        <?php _e( 'Manage Plan', 'wp-ai-mind' ); ?>
                    </a></p>
                </div>
            <?php endif; ?>
        </div>

        <style>
        .wp-ai-mind-usage-widget .usage-meter { margin: 10px 0; }
        .wp-ai-mind-usage-widget .usage-text { margin-top: 5px; font-size: 12px; color: #666; }
        .wp-ai-mind-usage-widget .upgrade-section { margin-top: 15px; text-align: center; }
        </style>
        <?php
    }
}
```

- [ ] **Step 3.2: Enhanced settings page**

Update `includes/Admin/NJ_Tier_Settings.php` with better upgrade flows:
```php
public static function settings_page(): void {
    $current_user_tier = nj_get_user_tier();
    $usage = \WP_AI_Mind\Tiers\NJ_Usage_Tracker::get_current_usage();

    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

        <!-- Current Status Card -->
        <div class="card">
            <h2>Current Plan: <?php echo esc_html( ucfirst( str_replace( '_', ' ', $current_user_tier ) ) ); ?></h2>

            <?php if ( $usage['limit'] ): ?>
                <div class="tier-usage">
                    <p><strong>Usage this month:</strong>
                        <?php echo number_format( $usage['used'] ); ?> / <?php echo number_format( $usage['limit'] ); ?> tokens
                    </p>
                    <p><strong>Resets:</strong> <?php echo date( 'F 1, Y', strtotime( 'first day of next month' ) ); ?></p>
                </div>
            <?php else: ?>
                <p><strong>Unlimited usage</strong> with your own API key.</p>
            <?php endif; ?>
        </div>

        <!-- Plan Comparison -->
        <div class="card">
            <h2>Available Plans</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Monthly Tokens</th>
                        <th>Models</th>
                        <th>Setup</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr<?php echo $current_user_tier === 'free' ? ' style="background: #f0f8ff;"' : ''; ?>>
                        <td><strong>Free</strong></td>
                        <td>50,000</td>
                        <td>Claude Haiku</td>
                        <td>None required</td>
                        <td><?php echo $current_user_tier === 'free' ? 'Current' : '—'; ?></td>
                    </tr>
                    <tr<?php echo $current_user_tier === 'pro_managed' ? ' style="background: #f0f8ff;"' : ''; ?>>
                        <td><strong>Pro Managed</strong></td>
                        <td>2,000,000</td>
                        <td>Haiku, Sonnet, Opus</td>
                        <td>Payment only</td>
                        <td>
                            <?php if ( $current_user_tier === 'pro_managed' ): ?>
                                Current
                            <?php else: ?>
                                <a href="https://wpaimind.lemonsqueezy.com/checkout" class="button button-primary" target="_blank">Upgrade</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr<?php echo $current_user_tier === 'pro_byok' ? ' style="background: #f0f8ff;"' : ''; ?>>
                        <td><strong>Pro BYOK</strong></td>
                        <td>Unlimited</td>
                        <td>Any (your key)</td>
                        <td>Payment + API key</td>
                        <td>
                            <?php if ( $current_user_tier === 'pro_byok' ): ?>
                                Current
                            <?php else: ?>
                                <a href="https://wpaimind.lemonsqueezy.com/checkout/byok" class="button" target="_blank">Upgrade</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ( $current_user_tier === 'pro_byok' ): ?>
            <!-- API Key Management for Pro BYOK -->
            <div class="card">
                <h2>API Key Configuration</h2>
                <form method="post" action="options.php">
                    <?php settings_fields( 'wp_ai_mind_api_keys' ); ?>
                    <table class="form-table">
                        <tr>
                            <th><label for="anthropic_api_key">Anthropic API Key</label></th>
                            <td>
                                <input type="password" id="anthropic_api_key" name="anthropic_api_key"
                                       value="<?php echo esc_attr( self::get_masked_api_key( 'anthropic' ) ); ?>"
                                       class="regular-text" />
                                <p class="description">Your API key is encrypted and stored securely.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
```

---

## Task 4: Error Handling & Edge Cases

**Files:** Improve error handling across all components

- [ ] **Step 4.1: Graceful provider fallbacks**

```php
// In provider factory or main chat handlers
function nj_handle_chat_request( array $messages, array $options = [] ) {
    $provider = ProviderFactory::get_provider();

    if ( ! $provider ) {
        return new WP_Error( 'no_provider', __( 'No AI provider available. Please check your plan and configuration.', 'wp-ai-mind' ) );
    }

    if ( ! $provider->is_configured() ) {
        $tier = nj_get_user_tier();

        return match( $tier ) {
            'pro_byok' => new WP_Error( 'api_key_required', __( 'Please configure your API key in Settings > AI Mind Tiers.', 'wp-ai-mind' ) ),
            default => new WP_Error( 'service_unavailable', __( 'AI service temporarily unavailable. Please try again later.', 'wp-ai-mind' ) ),
        };
    }

    return $provider->send_request( $messages, $options );
}
```

- [ ] **Step 4.2: Rate limit grace handling**

```php
// In usage tracker
public static function check_rate_limit_with_grace( $user_id = null ): array {
    $usage = self::get_current_usage( $user_id );

    // Allow small overage (5%) for grace
    $grace_limit = $usage['limit'] ? $usage['limit'] * 1.05 : null;
    $hard_blocked = $grace_limit && $usage['used'] > $grace_limit;

    return [
        'allowed' => $usage['can_use'] || ! $hard_blocked,
        'warning' => ! $usage['can_use'] && ! $hard_blocked,
        'blocked' => $hard_blocked,
        'usage' => $usage,
    ];
}
```

---

## Task 5: Production Testing

**Files:** Comprehensive testing across all tiers

- [ ] **Step 5.1: End-to-end tier testing**

Create test script `tests/manual-tier-test.php`:
```php
<?php
// Test script for manual verification

function test_tier_system() {
    $test_user_id = 1; // Replace with actual test user

    // Test Free tier
    NJ_Tier_Manager::set_user_tier( 'free', $test_user_id );
    $provider = ProviderFactory::get_provider();
    assert( $provider instanceof ProxyProvider );

    // Test Pro Managed tier
    NJ_Tier_Manager::set_user_tier( 'pro_managed', $test_user_id );
    $provider = ProviderFactory::get_provider();
    assert( $provider instanceof ProxyProvider );

    // Test Pro BYOK tier
    NJ_Tier_Manager::set_user_tier( 'pro_byok', $test_user_id );
    $provider_name = nj_resolve_provider();
    assert( in_array( $provider_name, [ 'claude', 'openai', 'gemini' ] ) );

    echo "All tier tests passed!\n";
}
```

- [ ] **Step 5.2: Verify proxy integration**

Test that Free/Pro Managed users route through proxy:
```bash
# Check WordPress logs for proxy requests
tail -f wp-content/debug.log | grep "proxy"

# Check Cloudflare Worker logs
wrangler tail wp-ai-mind-proxy
```

- [ ] **Step 5.3: Verify direct provider routing**

Test that Pro BYOK users bypass proxy and use direct provider calls.

---

## Phase 3 Acceptance Criteria

- [ ] All three tiers route correctly: Free/Pro Managed → Proxy, Pro BYOK → Direct
- [ ] ProxyProvider integrates with existing provider architecture
- [ ] All ProGate/Freemius code removed
- [ ] Global helpers `nj_get_user_tier()`, `nj_can_user()` work throughout codebase
- [ ] `nj_resolve_provider()` returns correct provider for each tier
- [ ] Admin UI shows tier status, usage meters, and upgrade options
- [ ] Dashboard widget displays current usage and warnings
- [ ] Error handling gracefully handles misconfigurations
- [ ] LemonSqueezy webhook properly upgrades/downgrades users
- [ ] Rate limiting works end-to-end (WordPress + proxy double-check)
- [ ] Pro BYOK users can configure and use encrypted API keys
- [ ] No mixed ProGate/new tier system code remains

**After Phase 3: Complete three-tier system with WordPress-native management, minimal proxy for API protection, and clean codebase.**
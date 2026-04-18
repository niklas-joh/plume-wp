# Phase 2: Minimal Cloudflare Proxy

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A minimal Cloudflare Worker (~200 lines) that protects API keys for Free/Pro Managed users. WordPress signs requests; proxy validates signatures and forwards to Anthropic. Enables Free/Pro Managed tiers to function.

**Architecture:** Simple TypeScript Worker with single endpoint `/v1/chat`. No auth, user management, or webhooks. WordPress handles all business logic and sends signed requests containing user_id, tier, and chat data.

**Tech Stack:** Cloudflare Workers (TypeScript), Cloudflare KV (rate limiting only), Wrangler CLI, Web Crypto API (HMAC verification)

**Depends on:** Phase 1 complete (WordPress tier system working)

---

## Design Decisions

| Decision | Choice | Reason |
|----------|--------|--------|
| Authentication | WordPress signs requests with HMAC | No external auth system needed |
| User Management | None - WordPress passes user_id in payload | Proxy is stateless |
| Rate Limiting | KV storage + WordPress fallback | Double-check at edge + local tracking |
| API Key Protection | Only proxy purpose | 70% simpler than full microservices |
| Error Handling | Pass through to WordPress | WordPress handles upgrade UX |

---

## Project Structure

```
wp-ai-mind-proxy/
├── src/
│   ├── index.ts              # Main proxy logic (~150 lines)
│   ├── types.ts              # Simple interfaces
│   └── signature.ts          # HMAC verification
├── wrangler.toml             # Minimal Cloudflare config
├── package.json
└── README.md
```

---

## Task 0: Pre-implementation Audit

> **Mandatory.** Understand what already exists before creating proxy.

- [ ] **Step 0.1: Check if proxy project exists**
```bash
ls -la wp-ai-mind-proxy/ 2>/dev/null || echo "No existing proxy project"
# If files exist, audit them before proceeding
```

- [ ] **Step 0.2: Verify Phase 1 WordPress integration**
```bash
grep -n "nj_get_user_tier\|nj_can_user" wp-ai-mind.php
# Confirm WordPress tier system is in place before building proxy
```

---

## Task 1: Cloudflare Project Setup

**Files:** Create basic Cloudflare Worker project

- [ ] **Step 1.1: Create project directory**
```bash
mkdir -p wp-ai-mind-proxy/src
cd wp-ai-mind-proxy
```

- [ ] **Step 1.2: Create package.json**
```bash
npm init -y
npm install --save-dev wrangler typescript
```

- [ ] **Step 1.3: Create wrangler.toml**
```toml
name = "wp-ai-mind-proxy"
main = "src/index.ts"
compatibility_date = "2024-01-01"

[[kv_namespaces]]
binding = "USAGE_KV"
id = "REPLACE_AFTER_KV_CREATE"
preview_id = "REPLACE_AFTER_KV_CREATE_PREVIEW"

# Secrets (set via CLI):
# wrangler secret put ANTHROPIC_API_KEY
# wrangler secret put PROXY_SIGNATURE_SECRET
```

- [ ] **Step 1.4: Create TypeScript config**
```json
{
  "compilerOptions": {
    "target": "ES2022",
    "module": "ES2022",
    "moduleResolution": "Bundler",
    "lib": ["ES2022"],
    "types": ["@cloudflare/workers-types"],
    "strict": true,
    "noEmit": true
  },
  "include": ["src/**/*.ts"]
}
```

---

## Task 2: Cloudflare Resources

**Prerequisites:** Cloudflare account, wrangler CLI installed

- [ ] **Step 2.1: Login to Cloudflare**
```bash
wrangler login
```

- [ ] **Step 2.2: Create KV namespace**
```bash
wrangler kv:namespace create USAGE_KV
# Copy ID to wrangler.toml [[kv_namespaces]] id

wrangler kv:namespace create USAGE_KV --preview
# Copy ID to wrangler.toml [[kv_namespaces]] preview_id
```

- [ ] **Step 2.3: Set secrets**
```bash
wrangler secret put ANTHROPIC_API_KEY
# Enter your Anthropic API key

wrangler secret put PROXY_SIGNATURE_SECRET
# Enter a random 64-character string for HMAC signing
```

---

## Task 3: Minimal Proxy Implementation

**Files:** Create `src/types.ts`, `src/signature.ts`, `src/index.ts`

- [ ] **Step 3.1: Create types interface**

```typescript
// src/types.ts
export interface Env {
  USAGE_KV: KVNamespace;
  ANTHROPIC_API_KEY: string;
  PROXY_SIGNATURE_SECRET: string;
}

export interface ProxyRequest {
  user_id: number;
  tier: 'free' | 'pro_managed';
  messages: any[];
  model?: string;
  max_tokens?: number;
  signature: string;
}

export interface UsageData {
  used: number;
  limit: number;
  month: string;
}
```

- [ ] **Step 3.2: Create signature verification**

```typescript
// src/signature.ts
import { Env, ProxyRequest } from './types';

export async function verifySignature(
  request: ProxyRequest,
  signature: string,
  env: Env
): Promise<boolean> {
  try {
    // Create payload without signature for verification
    const { signature: _, ...payload } = request;
    const message = JSON.stringify(payload);

    const key = await crypto.subtle.importKey(
      'raw',
      new TextEncoder().encode(env.PROXY_SIGNATURE_SECRET),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['verify']
    );

    const expectedSignature = await crypto.subtle.sign(
      'HMAC',
      key,
      new TextEncoder().encode(message)
    );

    const expectedHex = Array.from(new Uint8Array(expectedSignature))
      .map(b => b.toString(16).padStart(2, '0'))
      .join('');

    return signature === expectedHex;
  } catch (error) {
    console.error('Signature verification failed:', error);
    return false;
  }
}
```

- [ ] **Step 3.3: Create main proxy logic**

```typescript
// src/index.ts
import { Env, ProxyRequest } from './types';
import { verifySignature } from './signature';

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    // Handle CORS preflight
    if (request.method === 'OPTIONS') {
      return new Response(null, {
        status: 204,
        headers: {
          'Access-Control-Allow-Origin': '*',
          'Access-Control-Allow-Methods': 'POST',
          'Access-Control-Allow-Headers': 'Content-Type, X-WP-Signature',
        },
      });
    }

    if (request.method !== 'POST') {
      return jsonResponse({ error: 'Method not allowed' }, 405);
    }

    const url = new URL(request.url);
    if (url.pathname === '/v1/chat') {
      return handleChatProxy(request, env);
    }

    return jsonResponse({ error: 'Not found' }, 404);
  },
};

async function handleChatProxy(request: Request, env: Env): Promise<Response> {
  try {
    const body = await request.json() as ProxyRequest;
    const signature = request.headers.get('X-WP-Signature') || body.signature;

    if (!signature) {
      return jsonResponse({ error: 'Missing signature' }, 401);
    }

    // Verify request signature from WordPress
    if (!(await verifySignature(body, signature, env))) {
      return jsonResponse({ error: 'Invalid signature' }, 401);
    }

    const { user_id, tier, messages, model, max_tokens } = body;

    // Check rate limits in KV (double-check after WordPress)
    if (tier !== 'pro_byok') {
      const rateLimitCheck = await checkRateLimit(user_id, tier, env);
      if (!rateLimitCheck.allowed) {
        return jsonResponse({
          error: 'Rate limit exceeded',
          used: rateLimitCheck.used,
          limit: rateLimitCheck.limit,
        }, 429);
      }
    }

    // Determine model based on tier
    const selectedModel = getModelForTier(tier, model);

    // Forward to Anthropic
    const anthropicResponse = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: {
        'x-api-key': env.ANTHROPIC_API_KEY,
        'anthropic-version': '2023-06-01',
        'content-type': 'application/json',
      },
      body: JSON.stringify({
        model: selectedModel,
        max_tokens: max_tokens || (tier === 'free' ? 1000 : 4000),
        messages: messages,
      }),
    });

    const result = await anthropicResponse.json() as any;

    // Update usage in KV (for free/pro_managed only)
    if (tier !== 'pro_byok' && result.usage) {
      const tokensUsed = result.usage.input_tokens + result.usage.output_tokens;
      await updateUsage(user_id, tokensUsed, env);
    }

    return new Response(JSON.stringify(result), {
      status: anthropicResponse.status,
      headers: {
        'Content-Type': 'application/json',
        'Access-Control-Allow-Origin': '*',
      },
    });

  } catch (error) {
    console.error('Proxy error:', error);
    return jsonResponse({ error: 'Internal proxy error' }, 500);
  }
}

async function checkRateLimit(
  userId: number,
  tier: string,
  env: Env
): Promise<{ allowed: boolean; used: number; limit: number }> {
  const limits = { free: 50000, pro_managed: 2000000 };
  const limit = limits[tier as keyof typeof limits] || 0;

  const monthKey = `usage:${userId}:${getCurrentMonth()}`;
  const currentUsage = parseInt(await env.USAGE_KV.get(monthKey) || '0');

  return {
    allowed: currentUsage < limit,
    used: currentUsage,
    limit: limit,
  };
}

async function updateUsage(userId: number, tokens: number, env: Env): Promise<void> {
  const monthKey = `usage:${userId}:${getCurrentMonth()}`;
  const currentUsage = parseInt(await env.USAGE_KV.get(monthKey) || '0');

  await env.USAGE_KV.put(monthKey, String(currentUsage + tokens), {
    expirationTtl: getSecondsUntilNextMonth(),
  });
}

function getModelForTier(tier: string, requestedModel?: string): string {
  const tierModels = {
    free: ['claude-3-haiku-20240307'],
    pro_managed: [
      'claude-3-haiku-20240307',
      'claude-3-sonnet-20240229',
      'claude-3-opus-20240229',
    ],
  };

  const allowedModels = tierModels[tier as keyof typeof tierModels] || [];

  if (requestedModel && allowedModels.includes(requestedModel)) {
    return requestedModel;
  }

  return allowedModels[0] || 'claude-3-haiku-20240307';
}

function getCurrentMonth(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

function getSecondsUntilNextMonth(): number {
  const now = new Date();
  const nextMonth = new Date(now.getFullYear(), now.getMonth() + 1, 1);
  return Math.floor((nextMonth.getTime() - now.getTime()) / 1000);
}

function jsonResponse(data: any, status = 200): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      'Content-Type': 'application/json',
      'Access-Control-Allow-Origin': '*',
    },
  });
}
```

---

## Task 4: WordPress Integration

**Files:** Modify `includes/Proxy/NJ_Proxy_Client.php` (from Phase 1)

- [ ] **Step 4.1: Implement WordPress proxy client**

```php
<?php
namespace WP_AI_Mind\Proxy;

use WP_Error;
use WP_AI_Mind\Tiers\NJ_Tier_Manager;
use WP_AI_Mind\Tiers\NJ_Usage_Tracker;

class NJ_Proxy_Client {

    const PROXY_URL = 'https://wp-ai-mind-proxy.YOUR-ACCOUNT.workers.dev/v1/chat';

    public static function chat( array $messages, array $options = [] ): array|WP_Error {
        $user_id = get_current_user_id();
        $tier = NJ_Tier_Manager::get_user_tier( $user_id );

        // For Pro BYOK users, bypass proxy entirely (handled elsewhere)
        if ( $tier === 'pro_byok' ) {
            return new WP_Error( 'wrong_method', 'Pro BYOK users should use direct provider calls' );
        }

        // Check usage limit in WordPress first
        if ( ! NJ_Usage_Tracker::check_rate_limit( $user_id ) ) {
            return new WP_Error( 'rate_limit_exceeded', __( 'Monthly usage limit reached.', 'wp-ai-mind' ) );
        }

        // Prepare signed request
        $payload = [
            'user_id' => $user_id,
            'tier' => $tier,
            'messages' => $messages,
            'model' => $options['model'] ?? null,
            'max_tokens' => $options['max_tokens'] ?? null,
        ];

        $signature = self::sign_request( $payload );

        $response = wp_remote_post( self::PROXY_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WP-Signature' => $signature,
            ],
            'body' => wp_json_encode( array_merge( $payload, [ 'signature' => $signature ] ) ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 429 ) {
            return new WP_Error( 'rate_limit_exceeded', __( 'Monthly usage limit reached.', 'wp-ai-mind' ) );
        }

        if ( $code !== 200 ) {
            return new WP_Error( 'proxy_error', $body['error'] ?? 'Unknown proxy error' );
        }

        // Log usage locally in WordPress
        if ( isset( $body['usage'] ) ) {
            $tokens = $body['usage']['input_tokens'] + $body['usage']['output_tokens'];
            NJ_Usage_Tracker::log_usage( $tokens );
        }

        return $body;
    }

    private static function sign_request( array $payload ): string {
        $secret = defined( 'WP_AI_MIND_PROXY_SECRET' ) ? WP_AI_MIND_PROXY_SECRET : '';

        if ( empty( $secret ) ) {
            error_log( 'WP_AI_MIND_PROXY_SECRET not defined in wp-config.php' );
        }

        return hash_hmac( 'sha256', wp_json_encode( $payload ), $secret );
    }
}
```

- [ ] **Step 4.2: Add secret to wp-config.php documentation**

Create `wp-ai-mind-proxy/README.md`:
```markdown
# WP AI Mind Proxy Setup

## WordPress Configuration

Add to your `wp-config.php`:

```php
define( 'WP_AI_MIND_PROXY_SECRET', 'same-64-char-string-as-cloudflare-secret' );
```

This must match the `PROXY_SIGNATURE_SECRET` set in Cloudflare Workers.

## Deployment URL

After deployment, update `NJ_Proxy_Client::PROXY_URL` to your actual workers.dev URL.
```

---

## Task 5: Local Testing & Deployment

- [ ] **Step 5.1: Local development**
```bash
cd wp-ai-mind-proxy
wrangler dev
# Test at http://localhost:8787
```

- [ ] **Step 5.2: Test signature verification**
```bash
# Use a tool like curl to test signature verification
curl -X POST http://localhost:8787/v1/chat \
  -H "Content-Type: application/json" \
  -H "X-WP-Signature: test-signature" \
  -d '{"user_id":1,"tier":"free","messages":[]}'
# Should return 401 Invalid signature
```

- [ ] **Step 5.3: Deploy to Cloudflare**
```bash
wrangler deploy
# Note the deployed URL for WordPress configuration
```

- [ ] **Step 5.4: Update WordPress proxy URL**
Update `NJ_Proxy_Client::PROXY_URL` with the actual workers.dev URL.

---

## Task 6: Integration Testing

**Files:** WordPress plugin integration

- [ ] **Step 6.1: Test Free tier flow**
- Set a test user to `free` tier with `NJ_Tier_Manager::set_user_tier('free', $user_id)`
- Attempt chat request through `NJ_Proxy_Client::chat()`
- Verify rate limiting works at 50k tokens

- [ ] **Step 6.2: Test Pro Managed tier flow**
- Set a test user to `pro_managed` tier
- Verify higher rate limits (2M tokens) and model selection

- [ ] **Step 6.3: Test error handling**
- Test with invalid signature
- Test with exceeded rate limits
- Test Anthropic API errors

---

## Phase 2 Acceptance Criteria

- [ ] Cloudflare Worker deployed at `wp-ai-mind-proxy.YOUR.workers.dev`
- [ ] Single endpoint `/v1/chat` handles requests from WordPress
- [ ] HMAC signature verification prevents unauthorized access
- [ ] Rate limiting via KV works (double-check after WordPress)
- [ ] Free tier: 50k tokens/month, Haiku only
- [ ] Pro Managed tier: 2M tokens/month, Haiku/Sonnet/Opus
- [ ] Pro BYOK tier: bypasses proxy entirely (handled by WordPress)
- [ ] WordPress integration: signed requests via `NJ_Proxy_Client`
- [ ] Error handling returns appropriate HTTP status codes
- [ ] CORS headers allow WordPress admin requests

**After Phase 2: All three tiers work end-to-end. Free/Pro Managed use proxy, Pro BYOK uses direct API calls.**
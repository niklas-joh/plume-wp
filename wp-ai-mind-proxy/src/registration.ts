// src/registration.ts

import { Env, SiteRecord } from './types';
import { generateToken } from './auth';

export async function handleRegistration(
  request: Request,
  env: Env
): Promise<Response> {
  if (request.method !== 'POST') {
    return jsonResponse({ error: 'Method not allowed' }, 405);
  }

  let body: { site_url?: string };
  try {
    body = await request.json() as { site_url?: string };
  } catch {
    return jsonResponse({ error: 'Invalid JSON' }, 400);
  }

  const site_url = (body.site_url ?? '').trim();
  if (!site_url || !isValidUrl(site_url)) {
    return jsonResponse({ error: 'Invalid site_url' }, 400);
  }

  // Idempotent — return the existing token if already registered.
  const urlHash = await sha256(site_url);
  const existingToken = await env.USAGE_KV.get(`site_url:${urlHash}`);
  if (existingToken) {
    const record = await env.USAGE_KV.get<SiteRecord>(`site:${existingToken}`, 'json');
    if (record) {
      return jsonResponse({ token: existingToken, tier: record.tier });
    }
  }

  const token = generateToken();
  const record: SiteRecord = { site_url, tier: 'free', created_at: Date.now() };

  await env.USAGE_KV.put(`site:${token}`, JSON.stringify(record));
  await env.USAGE_KV.put(`site_url:${urlHash}`, token);

  return jsonResponse({ token, tier: 'free' }, 201);
}

async function sha256(input: string): Promise<string> {
  const bytes = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input));
  return Array.from(new Uint8Array(bytes))
    .map(b => b.toString(16).padStart(2, '0'))
    .join('');
}

function isValidUrl(url: string): boolean {
  try {
    const { protocol } = new URL(url);
    return protocol === 'http:' || protocol === 'https:';
  } catch {
    return false;
  }
}

function jsonResponse(data: unknown, status = 200): Response {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

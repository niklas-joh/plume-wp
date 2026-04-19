export interface Env {
	USAGE_KV: KVNamespace;
	ANTHROPIC_API_KEY: string;
	PROXY_SIGNATURE_SECRET: string;
}

// Tiers routed through proxy. Mirrors NJ_Tier_Config::TIERS (excludes pro_byok).
export type ProxyTier = 'free' | 'trial' | 'pro_managed';

export interface ProxyRequest {
	user_id: number;
	tier: ProxyTier;
	messages: MessageParam[];
	model?: string;
	max_tokens?: number;
}

export interface MessageParam {
	role: 'user' | 'assistant';
	content: string;
}

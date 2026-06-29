/**
 * Shared chat action definitions.
 *
 * Single source of truth for prompt strings used by both QuickActions
 * and the launch-view suggestion chips in ChatApp. Centralising here
 * means a prompt update only needs to happen in one place.
 *
 * Each action object has the shape:
 *   { id: string, label: string, prompt: string, icon: Component, requiresPost: boolean }
 *
 * When `requiresPost` is true the consumer must ensure a post is attached
 * before dispatching the prompt; otherwise the context picker should be opened first.
 *
 * Every action is available to every tier — credit exhaustion is enforced
 * by the Worker, not a tier-based action split, so there is no longer a
 * Free/Pro distinction here.
 */
import { FilePenLine, Search, Image } from 'lucide-react';

export const QUICK_ACTIONS = [
	{
		id: 'summarise',
		label: 'Summarise this post',
		prompt: 'Please summarise the current post in 2-3 sentences.',
		icon: FilePenLine,
		requiresPost: true,
	},
	{
		id: 'readability',
		label: 'Improve readability',
		prompt: 'Review this content and suggest readability improvements.',
		icon: FilePenLine,
		requiresPost: true,
	},
	{
		id: 'write-post',
		label: 'Write a post',
		prompt: 'Help me write a new blog post. What topic should we start with?',
		icon: FilePenLine,
		requiresPost: false,
	},
	{
		id: 'seo-title',
		label: 'Generate SEO title',
		prompt: 'Generate an optimised SEO title for this post.',
		icon: Search,
		requiresPost: true,
	},
	{
		id: 'meta-description',
		label: 'Write meta description',
		prompt: 'Write a compelling 155-character meta description for this post.',
		icon: Search,
		requiresPost: true,
	},
	{
		id: 'featured-image',
		label: 'Create featured image',
		prompt: 'Generate a featured image for this post.',
		icon: Image,
		requiresPost: true,
	},
];

/** Actions shown as suggestion chips on the centred launch screen. */
export const LAUNCH_ACTIONS = [
	QUICK_ACTIONS[ 0 ],
	QUICK_ACTIONS[ 1 ],
	QUICK_ACTIONS[ 2 ],
];

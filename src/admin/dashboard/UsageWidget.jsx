/**
 * Compact usage widget embedded in the main Dashboard page.
 *
 * Displays a tier badge, a percentage-based quota indicator, and a progress
 * bar. Visible only when the `usage` prop is truthy (i.e. the current user
 * has the `manage_options` capability, as gated by PHP before localisation).
 *
 * @param {Object}       props
 * @param {Object|null}  props.usage  Usage summary from `NJ_Usage_Tracker::get_usage()`:
 *                                    `{ tier, used, limit, remaining, can_use }`.
 *                                    When null or falsy the component renders nothing.
 * @returns {ReactElement|null}
 *
 * @example
 * <UsageWidget usage={ data.usage ?? null } />
 */
export default function UsageWidget( { usage } ) {
	if ( ! usage ) {
		return null;
	}

	const { tier, used, limit, can_use: canUse } = usage;

	const hasLimit = limit !== null && limit !== undefined;
	const usedPct = hasLimit ? Math.min( 100, Math.round( ( used / limit ) * 100 ) ) : 0;

	return (
		<div
			className="wpaim-usage-widget"
			style={ {
				background: '#f8f9fa',
				border: '1px solid #e0e1e6',
				borderRadius: 'var(--radius)',
				padding: 'var(--space-4)',
			} }
		>
			<div
				style={ {
					display: 'flex',
					alignItems: 'baseline',
					justifyContent: 'space-between',
					marginBottom: 'var(--space-3)',
				} }
			>
				<span
					className="wpaim-dash-section-title"
					style={ {
						fontSize: '0.625rem',
						fontWeight: 600,
						color: 'var(--color-text-muted)',
						textTransform: 'uppercase',
						letterSpacing: '0.1em',
						fontFamily: 'var(--font-mono, monospace)',
					} }
				>
					Usage
				</span>
				<span
					style={ {
						fontSize: '0.625rem',
						padding: '2px 6px',
						borderRadius: '3px',
						background: '#e0e1e6',
						color: '#787c82',
						fontWeight: 600,
						textTransform: 'uppercase',
						letterSpacing: '0.05em',
					} }
				>
					Tier: { tier }
				</span>
			</div>

			<div
				style={ {
					display: 'flex',
					alignItems: 'baseline',
					gap: 'var(--space-2)',
				} }
			>
				<span
					style={ {
						fontSize: '1.5rem',
						fontWeight: 700,
						color: canUse ? 'var(--color-text-primary, #1d2327)' : '#d63638',
						lineHeight: 1,
					} }
				>
					{ hasLimit ? usedPct + '%' : 'Unlimited' }
				</span>
				{ hasLimit && (
					<span
						style={ {
							fontSize: '0.6875rem',
							color: 'var(--color-text-secondary)',
						} }
					>
						of quota used
					</span>
				) }
			</div>

			{ hasLimit && (
				<div
					style={ {
						fontSize: '0.625rem',
						color: 'var(--color-text-muted)',
						marginTop: '2px',
						marginBottom: 'var(--space-2)',
					} }
				>
					{ used.toLocaleString() } / { limit.toLocaleString() } tokens
				</div>
			) }

			{ hasLimit && (
				<div
					style={ {
						background: 'var(--color-border, #ddd)',
						borderRadius: '4px',
						height: '6px',
						overflow: 'hidden',
						marginTop: 'var(--space-2)',
					} }
				>
					<div
						style={ {
							width: `${ usedPct }%`,
							height: '100%',
							background: canUse ? 'var(--wp-admin-theme-color)' : '#d63638',
							transition: 'width 0.3s ease',
						} }
					/>
				</div>
			) }

			{ hasLimit && ! canUse && (
				<p
					style={ {
						color: '#d63638',
						margin: 'var(--space-2) 0 0',
						fontSize: '0.6875rem',
					} }
				>
					Monthly limit reached. Upgrade your plan to continue.
				</p>
			) }
		</div>
	);
}

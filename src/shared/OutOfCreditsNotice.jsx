import { CircleSlash } from 'lucide-react';

/**
 * Banner shown when a Worker request fails with a credit-exhaustion error.
 *
 * Says "Get more credits" rather than "Upgrade to Pro" because BYOK sites
 * structurally never reach this state (they call their own API key
 * directly, bypassing the Worker's credit ledger entirely) — both Free and
 * Pro Managed tiers have a real monthly ceiling and can land here, so the
 * copy and CTA must make sense for an already-paying Pro Managed user too,
 * not just imply "upgrade from Free."
 *
 * @param {Object} props
 * @param {string} props.websiteUrl Base marketing-site URL (no trailing slash) used to build the pricing link.
 * @return {ReactElement}
 *
 * @example
 * <OutOfCreditsNotice websiteUrl="https://wpaimind.com" />
 */
export default function OutOfCreditsNotice( { websiteUrl } ) {
	return (
		<div className="plume-out-of-credits-banner">
			<CircleSlash size={ 18 } />
			<span>
				You&apos;ve used all your credits for this billing period.
			</span>
			<a
				href={ `${ websiteUrl }/pricing` }
				className="button button-primary"
			>
				Get more credits →
			</a>
		</div>
	);
}

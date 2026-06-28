import { useState } from '@wordpress/element';
import { ToggleControl, CheckboxControl } from '@wordpress/components';

const MODULES = [
	{
		slug: 'chat',
		label: 'Chat',
		description: 'AI chat assistant panel in the WordPress admin.',
	},
	{
		slug: 'text_rewrite',
		label: 'Text Rewrite',
		description: 'Rewrite and improve post content using AI.',
	},
	{
		slug: 'summaries',
		label: 'Summaries',
		description: 'Automatically generate post summaries and excerpts.',
	},
	{
		slug: 'seo',
		label: 'SEO',
		description:
			'AI-assisted meta titles, descriptions, and keyword suggestions.',
	},
	{
		slug: 'images',
		label: 'Images',
		description: 'Generate and insert AI images directly into posts.',
	},
	{
		slug: 'generator',
		label: 'Generator',
		description: 'Full AI-driven post and page generation workflow.',
	},
	{
		slug: 'usage',
		label: 'Usage',
		description: 'Track token consumption and API usage across providers.',
	},
];

/**
 * Settings tab for toggling AI modules and configuring post-type access.
 *
 * Every module is available on every tier — credit exhaustion is enforced
 * by the Worker, not a PHP/JS-side feature gate, so no card is ever locked.
 * Post-type changes and the write-tools toggle are persisted immediately
 * on change.
 *
 * @param {Object}   props
 * @param {Object}   props.settings      Full settings object from the REST API.
 * @param {Function} props.saveSettings  Persists a partial settings patch via POST.
 * @return {ReactElement}
 */
export default function FeaturesTab( { settings, saveSettings } ) {
	const enabledModules = settings?.enabled_modules ?? [];

	const [ allowedPostTypes, setAllowedPostTypes ] = useState(
		settings?.allowed_post_types ?? [ 'post', 'page' ]
	);
	const [ availablePostTypes ] = useState(
		settings?.available_post_types ?? []
	);
	const [ enableWriteTools, setEnableWriteTools ] = useState(
		settings?.enable_write_tools ?? false
	);

	function handleToggle( slug ) {
		const isEnabled = enabledModules.includes( slug );
		const updatedArray = isEnabled
			? enabledModules.filter( ( s ) => s !== slug )
			: [ ...enabledModules, slug ];

		saveSettings( { enabled_modules: updatedArray } );
	}

	function handlePostTypeChange( slug, checked ) {
		const updated = checked
			? [ ...allowedPostTypes, slug ]
			: allowedPostTypes.filter( ( s ) => s !== slug );
		setAllowedPostTypes( updated );
		saveSettings( { allowed_post_types: updated } );
	}

	function handleWriteToolsChange( val ) {
		setEnableWriteTools( val );
		saveSettings( { enable_write_tools: val } );
	}

	return (
		<div className="plume-features-tab">
			<section className="plume-settings-section">
				<div className="plume-settings-section-header">
					<h3 className="plume-settings-section-title">
						Enabled Modules
					</h3>
					<p className="plume-settings-section-desc">
						Enable or disable individual AI modules.
					</p>
				</div>

				<div className="plume-features-grid">
					{ MODULES.map( ( { slug, label, description } ) => {
						const isEnabled = enabledModules.includes( slug );

						return (
							<div
								key={ slug }
								className={ `plume-feature-card${
									isEnabled
										? ' plume-feature-card--enabled'
										: ''
								}` }
							>
								<div className="plume-feature-card-header">
									<div className="plume-feature-card-meta">
										<span className="plume-feature-card-label">
											{ label }
										</span>
									</div>

									<ToggleControl
										label={ label }
										checked={ isEnabled }
										onChange={ () => handleToggle( slug ) }
										hideLabelFromVision
										__nextHasNoMarginBottom
									/>
								</div>

								<p className="plume-feature-card-desc">
									{ description }
								</p>
							</div>
						);
					} ) }
				</div>
			</section>

			<section className="plume-settings-section">
				<div className="plume-settings-section-header">
					<h3 className="plume-settings-section-title">
						Post Type Access
					</h3>
					<p className="plume-settings-section-desc">
						Select which post types the AI assistant can read and
						write.
					</p>
				</div>

				<div className="plume-post-type-list">
					{ availablePostTypes.map( ( { slug, label } ) => (
						<CheckboxControl
							key={ slug }
							label={
								<>
									{ label } <code>{ slug }</code>
								</>
							}
							checked={ allowedPostTypes.includes( slug ) }
							onChange={ ( checked ) =>
								handlePostTypeChange( slug, checked )
							}
							__nextHasNoMarginBottom
						/>
					) ) }
				</div>

				<div style={ { marginTop: '1rem' } }>
					<ToggleControl
						label="Enable write tools — allow AI to create and update posts"
						help="When enabled, the AI can draft and publish content directly."
						checked={ enableWriteTools }
						onChange={ handleWriteToolsChange }
						__nextHasNoMarginBottom
					/>
				</div>
			</section>
		</div>
	);
}

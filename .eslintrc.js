module.exports = {
	root: true,
	extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
	rules: {
		// @wordpress/* packages are WordPress core externals provided at runtime.
		// They are not npm dependencies and cannot be resolved by the import plugin.
		'import/no-unresolved': [
			'error',
			{ ignore: [ '^@wordpress/' ] },
		],
		'import/no-extraneous-dependencies': [
			'error',
			{ devDependencies: true, optionalDependencies: false, peerDependencies: false },
		],
	},
	overrides: [
		{
			// Allow @wordpress/* imports without extraneous-dependency errors.
			// These are WordPress core externals (wp.element, wp.apiFetch, etc.)
			// injected at runtime via webpack externals — they are intentionally
			// absent from package.json.
			files: [ 'src/**/*.{js,jsx}' ],
			rules: {
				'import/no-extraneous-dependencies': 'off',
			},
		},
	],
};

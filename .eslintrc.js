module.exports = {
	extends: [
		'plugin:@wordpress/eslint-plugin/recommended',
		'plugin:jsdoc/recommended-error',
	],
	plugins: [ 'jsdoc' ],
	settings: {
		jsdoc: {
			mode: 'typescript',
			tagNamePreference: {
				// Enforce @return over @returns.
				returns: 'return',
			},
		},
	},
	rules: {
		// @wordpress/* packages are provided by WordPress core at runtime and
		// externalized by @wordpress/scripts webpack. They are not installed as
		// npm packages, so the module resolver cannot find them.
		'import/no-unresolved': [ 'error', { ignore: [ '^@wordpress/' ] } ],

		// Relax jsdoc rules that conflict with the project's documentation style.
		'jsdoc/require-jsdoc': [
			'error',
			{
				// Only exported (public) functions need a JSDoc block;
				// inner helpers and event handlers are exempt.
				publicOnly: true,
				require: {
					FunctionDeclaration: true,
					ArrowFunctionExpression: true,
					FunctionExpression: false,
					MethodDefinition: false,
				},
				checkConstructors: false,
			},
		],
		// Allow @return without a description (the type is sufficient).
		'jsdoc/require-returns-description': 'off',
		// Allow @param without a description when the name is self-documenting.
		'jsdoc/require-param-description': 'off',
		// JSDoc tags are checked by @wordpress/eslint-plugin already.
		'jsdoc/check-tag-names': [ 'error', { definedTags: [ 'throws' ] } ],
		// ReactElement, JSX.Element, etc. are valid types not resolvable in this config.
		'jsdoc/no-undefined-types': 'off',
		// Allow Function type — more specific types can be added case by case.
		'jsdoc/reject-function-type': 'off',
		// Allow nested prop paths (e.g. @param {string} props.message.role).
		'jsdoc/check-param-names': 'off',
	},
};

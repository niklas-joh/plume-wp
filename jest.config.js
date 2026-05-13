// Extend the @wordpress/scripts unit-test preset with project-specific overrides.
// The preset provides jsdom, babel-jest transforms, CSS mocking, and
// @wordpress/jest-console matchers.
const base = require( '@wordpress/scripts/config/jest-unit.config.js' );

module.exports = {
	...base,
	moduleNameMapper: {
		...base.moduleNameMapper,
		// @wordpress/api-fetch is a webpack external — it is not installed as a
		// standalone npm package. Provide a transparent Jest manual mock so
		// modules that import it can be resolved without webpack. Individual test
		// files replace the implementation via jest.mock().
		'^@wordpress/api-fetch$': '<rootDir>/tests/js/__mocks__/api-fetch.js',
	},
};

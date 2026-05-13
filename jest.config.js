// Re-export the @wordpress/scripts jest preset so `npm run test:unit-js` works
// without any additional configuration. The preset sets up jsdom, babel-jest,
// CSS mocking, and the @wordpress/jest-console matchers.
module.exports = require( '@wordpress/scripts/config/jest-unit.config.js' );

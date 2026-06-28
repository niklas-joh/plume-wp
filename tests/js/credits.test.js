/**
 * Unit tests for the shared out-of-credits detection helper.
 *
 * @see src/shared/credits.js
 */
import { isOutOfCreditsError } from '../../src/shared/credits';

describe( 'isOutOfCreditsError', () => {
	it( 'returns true when the error carries HTTP status 429', () => {
		expect( isOutOfCreditsError( { status: 429 } ) ).toBe( true );
	} );

	it( 'returns true when the error code is rate_limit_exceeded', () => {
		expect(
			isOutOfCreditsError( { code: 'rate_limit_exceeded' } )
		).toBe( true );
	} );

	it( 'returns true when both status and code are present', () => {
		expect(
			isOutOfCreditsError( { status: 429, code: 'rate_limit_exceeded' } )
		).toBe( true );
	} );

	it( 'returns false for an unrelated error', () => {
		expect(
			isOutOfCreditsError( { status: 500, code: 'internal_error' } )
		).toBe( false );
	} );

	it( 'returns false for null or undefined input', () => {
		expect( isOutOfCreditsError( null ) ).toBe( false );
		expect( isOutOfCreditsError( undefined ) ).toBe( false );
	} );

	it( 'returns false for a plain Error with neither field', () => {
		expect( isOutOfCreditsError( new Error( 'Generation failed.' ) ) ).toBe(
			false
		);
	} );
} );

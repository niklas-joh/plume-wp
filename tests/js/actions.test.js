/**
 * Unit tests for the shared chat quick-action definitions.
 *
 * @see src/admin/components/Chat/actions.js
 */
jest.mock( 'lucide-react', () => new Proxy( {}, { get: () => function Icon() {} } ) );

import { QUICK_ACTIONS, LAUNCH_ACTIONS } from '../../src/admin/components/Chat/actions';

describe( 'actions', () => {
	it( 'exports a single merged QUICK_ACTIONS list with no Free/Pro split', () => {
		expect( Array.isArray( QUICK_ACTIONS ) ).toBe( true );
		const ids = QUICK_ACTIONS.map( ( a ) => a.id );
		expect( ids ).toContain( 'summarise' );
		expect( ids ).toContain( 'readability' );
		expect( ids ).toContain( 'write-post' );
		expect( ids ).toContain( 'seo-title' );
		expect( ids ).toContain( 'meta-description' );
		expect( ids ).toContain( 'featured-image' );
	} );

	it( 'keeps write-post at LAUNCH_ACTIONS index 2 after the merge', () => {
		expect( LAUNCH_ACTIONS ).toHaveLength( 3 );
		expect( LAUNCH_ACTIONS[ 0 ].id ).toBe( 'summarise' );
		expect( LAUNCH_ACTIONS[ 1 ].id ).toBe( 'readability' );
		expect( LAUNCH_ACTIONS[ 2 ].id ).toBe( 'write-post' );
	} );
} );

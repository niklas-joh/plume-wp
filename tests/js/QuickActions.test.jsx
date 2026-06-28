/**
 * Unit tests for QuickActions — right-panel prompt shortcuts.
 *
 * @see src/admin/components/RightPanel/QuickActions.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import QuickActions from '../../src/admin/components/RightPanel/QuickActions';
import { QUICK_ACTIONS } from '../../src/admin/components/Chat/actions';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

describe( 'QuickActions', () => {
	let container;
	let root;

	beforeEach( () => {
		container = document.createElement( 'div' );
		document.body.appendChild( container );
		root = createRoot( container );
	} );

	afterEach( () => {
		act( () => {
			root.unmount();
		} );
		document.body.removeChild( container );
	} );

	it( 'renders the full merged action list unconditionally', async () => {
		await act( async () => {
			root.render(
				<QuickActions
					onAction={ jest.fn() }
					attachedPost={ null }
					onRequestAttach={ jest.fn() }
				/>
			);
		} );

		const buttons = container.querySelectorAll( '.plume-quick-action' );
		expect( buttons.length ).toBe( QUICK_ACTIONS.length );
	} );

	it( 'does not render the "More actions with Pro" teaser', async () => {
		await act( async () => {
			root.render(
				<QuickActions
					onAction={ jest.fn() }
					attachedPost={ null }
					onRequestAttach={ jest.fn() }
				/>
			);
		} );

		expect( container.querySelector( '.plume-pro-teaser' ) ).toBeNull();
		expect( container.textContent ).not.toContain(
			'More actions with Pro'
		);
	} );

	it( 'does not accept or read an isPro prop', async () => {
		await act( async () => {
			root.render(
				<QuickActions
					onAction={ jest.fn() }
					isPro={ false }
					attachedPost={ null }
					onRequestAttach={ jest.fn() }
				/>
			);
		} );

		// Regardless of isPro, the full list renders and no teaser appears.
		const buttons = container.querySelectorAll( '.plume-quick-action' );
		expect( buttons.length ).toBe( QUICK_ACTIONS.length );
		expect( container.querySelector( '.plume-pro-teaser' ) ).toBeNull();
	} );
} );

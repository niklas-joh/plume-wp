/**
 * Unit tests for SeoPanel — Block Editor sidebar SEO checklist.
 *
 * @see src/editor/components/SeoPanel.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import SeoPanel from '../../src/editor/components/SeoPanel';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

const mockSelect = jest.fn();
jest.mock(
	'@wordpress/data',
	() => ( {
		useSelect: ( callback ) => callback( mockSelect ),
	} ),
	{ virtual: true }
);

function setEditorState( { title = '', content = '', excerpt = '' } ) {
	mockSelect.mockImplementation( () => ( {
		getEditedPostAttribute: ( attr ) =>
			( { title, content, excerpt } )[ attr ],
	} ) );
}

describe( 'SeoPanel', () => {
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

	it( 'renders the checklist regardless of isPaid/isPro state', async () => {
		window.plumeData = { isPaid: false };
		setEditorState( { title: 'A reasonably long post title here', content: 'word '.repeat( 350 ), excerpt: 'An excerpt.' } );

		await act( async () => {
			root.render( <SeoPanel /> );
		} );

		expect( container.querySelector( '.plume-seo-panel' ) ).not.toBeNull();
		expect(
			container.querySelector( '.plume-seo-panel--locked' )
		).toBeNull();
		delete window.plumeData;
	} );

	it( 'does not render the locked placeholder', async () => {
		window.plumeData = { isPaid: false };
		setEditorState( {} );

		await act( async () => {
			root.render( <SeoPanel /> );
		} );

		expect( container.textContent ).not.toContain(
			'SEO analysis requires Plume Pro.'
		);
		delete window.plumeData;
	} );

	it( 'computes the checklist score from editor content', async () => {
		setEditorState( { title: 'A reasonably long post title here', content: 'word '.repeat( 350 ), excerpt: 'An excerpt.' } );

		await act( async () => {
			root.render( <SeoPanel /> );
		} );

		expect( container.querySelector( '.plume-seo-panel__score' ).textContent ).toBe(
			'3/3'
		);
	} );
} );

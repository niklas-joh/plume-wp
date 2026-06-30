/**
 * Unit tests for ModelSelector — provider/model dropdown in the right panel.
 *
 * @see src/admin/components/RightPanel/ModelSelector.jsx
 */
import React from 'react';
import { act } from 'react';
import { createRoot } from 'react-dom/client';
import ModelSelector from '../../src/admin/components/RightPanel/ModelSelector';

jest.mock( 'lucide-react', () =>
	new Proxy( {}, { get: () => () => <span /> } )
);

const PROVIDERS = [
	{ slug: 'claude', is_available: true, models: { 'claude-sonnet-4-6': 'Sonnet' } },
];

describe( 'ModelSelector', () => {
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

	it( 'disables the Advanced toggle when modelSelection is false', async () => {
		await act( async () => {
			root.render(
				<ModelSelector
					providers={ PROVIDERS }
					selectedProvider="claude"
					selectedModel=""
					onProviderChange={ jest.fn() }
					onModelChange={ jest.fn() }
					modelSelection={ false }
				/>
			);
		} );

		const toggle = container.querySelector(
			'.plume-model-advanced-toggle'
		);
		expect( toggle.disabled ).toBe( true );
	} );

	it( 'enables the Advanced toggle when modelSelection is true', async () => {
		await act( async () => {
			root.render(
				<ModelSelector
					providers={ PROVIDERS }
					selectedProvider="claude"
					selectedModel=""
					onProviderChange={ jest.fn() }
					onModelChange={ jest.fn() }
					modelSelection={ true }
				/>
			);
		} );

		const toggle = container.querySelector(
			'.plume-model-advanced-toggle'
		);
		expect( toggle.disabled ).toBe( false );
	} );

	it( 'defaults modelSelection to false when the prop is omitted', async () => {
		await act( async () => {
			root.render(
				<ModelSelector
					providers={ PROVIDERS }
					selectedProvider="claude"
					selectedModel=""
					onProviderChange={ jest.fn() }
					onModelChange={ jest.fn() }
				/>
			);
		} );

		const toggle = container.querySelector(
			'.plume-model-advanced-toggle'
		);
		expect( toggle.disabled ).toBe( true );
	} );
} );

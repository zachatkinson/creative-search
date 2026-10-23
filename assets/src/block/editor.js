/**
 * The editor side: the block in the inserter with its settings panel; the preview is the server's own
 * markup, so the editor shows exactly what the shop will.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import ServerSideRender from '@wordpress/server-side-render';

import './style.css';
import './editor.css';

const NAME = 'creative-search/search';

function Edit( { attributes, setAttributes } ) {
	const toggle = ( key, label ) => (
		<ToggleControl
			__nextHasNoMarginBottom
			label={ label }
			checked={ Boolean( attributes[ key ] ) }
			onChange={ ( value ) => setAttributes( { [ key ]: value } ) }
		/>
	);
	return (
		<div { ...useBlockProps() }>
			<InspectorControls>
				<PanelBody title={ __( 'Search bar', 'creative-search' ) }>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Show as', 'creative-search' ) }
						help={ __(
							'A button opens the search in a dialog, with the cursor already in the field.',
							'creative-search'
						) }
						value={ attributes.mode || 'field' }
						options={ [
							{
								value: 'field',
								label: __(
									'A search field',
									'creative-search'
								),
							},
							{
								value: 'button',
								label: __(
									'A search button (icon)',
									'creative-search'
								),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { mode: value } )
						}
					/>
					{ 'button' !== attributes.mode &&
						toggle(
							'dimPage',
							__(
								'Dim the page behind the suggestions',
								'creative-search'
							)
						) }
					{ toggle(
						'labelVisible',
						__( 'Show the label', 'creative-search' )
					) }
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Placeholder', 'creative-search' ) }
						value={ attributes.placeholder || '' }
						onChange={ ( value ) =>
							setAttributes( { placeholder: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Suggestions', 'creative-search' ) }>
					<RangeControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Products in the panel',
							'creative-search'
						) }
						min={ 1 }
						max={ 10 }
						value={ attributes.limit }
						onChange={ ( value ) =>
							setAttributes( { limit: value } )
						}
					/>
					{ toggle(
						'showPrices',
						__( 'Show prices', 'creative-search' )
					) }
					{ toggle(
						'showStock',
						__( 'Show stock', 'creative-search' )
					) }
					{ toggle(
						'showTerms',
						__(
							'Show categories, tags and brands',
							'creative-search'
						)
					) }
					{ toggle(
						'recentSearches',
						__(
							'Offer recent searches (stored in the shopper’s browser)',
							'creative-search'
						)
					) }
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Panel position', 'creative-search' ) }
						value={ attributes.panelPosition }
						options={ [
							{
								value: 'auto',
								label: __( 'Automatic', 'creative-search' ),
							},
							{
								value: 'below',
								label: __(
									'Below the field',
									'creative-search'
								),
							},
							{
								value: 'above',
								label: __(
									'Above the field',
									'creative-search'
								),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { panelPosition: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<ServerSideRender block={ NAME } attributes={ attributes } />
		</div>
	);
}

// Attributes and their defaults come from the server (the owner's settings), so none are repeated here.
registerBlockType( NAME, { edit: Edit, save: () => null } );

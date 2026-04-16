( function ( blocks, element, blockEditor, components ) {
	var el = element.createElement;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;

	blocks.registerBlockType( 'vtc-training-planner/week', {
		apiVersion: 2,
		title: 'Training week',
		icon: 'calendar-alt',
		category: 'widgets',
		description: 'Weekoverzicht trainingen + Nevobo-wedstrijden',
		attributes: {
			week: { type: 'string', default: '' },
		},
		edit: function ( props ) {
			return el(
				element.Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: 'Week', initialOpen: true },
						el( TextControl, {
							label: 'ISO-week (leeg = huidige week)',
							value: props.attributes.week,
							onChange: function ( v ) {
								return props.setAttributes( { week: v } );
							},
							placeholder: '2026-W12',
						} )
					)
				),
				el(
					'div',
					{ className: 'vtc-tp-block-editor-note' },
					'[Training week — frontend toont het rooster]'
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components );

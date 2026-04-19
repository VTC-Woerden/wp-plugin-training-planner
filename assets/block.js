( function ( blocks, element, blockEditor, components, serverSideRender ) {
	var el = element.createElement;
	var Fragment = element.Fragment;
	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var PanelBody = components.PanelBody;
	var TextControl = components.TextControl;
	var ServerSideRender = serverSideRender;

	blocks.registerBlockType( 'vtc-training-planner/week', {
		apiVersion: 2,
		title: 'Training week',
		icon: 'calendar-alt',
		category: 'widgets',
		description: 'Weekoverzicht trainingen + Nevobo-wedstrijden (visueel rooster)',
		attributes: {
			week: { type: 'string', default: '' },
		},
		edit: function ( props ) {
			var blockProps = useBlockProps( { className: 'vtc-tp-week-block-wrap' } );
			return el(
				Fragment,
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
					blockProps,
					el( ServerSideRender, {
						block: 'vtc-training-planner/week',
						attributes: { week: props.attributes.week || '' },
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.serverSideRender
);

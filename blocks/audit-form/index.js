( function ( blocks, element, blockEditor, components, i18n ) {
	var el = element.createElement;
	var __ = i18n.__;

	blocks.registerBlockType( 'site-audit-report/audit-form', {
		edit: function ( props ) {
			var attrs = props.attributes;
			var field = function ( key, label ) {
				return el( components.TextControl, {
					label: label,
					value: attrs[ key ],
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					onChange: function ( value ) {
						var change = {};
						change[ key ] = value;
						props.setAttributes( change );
					},
				} );
			};
			return el(
				'div',
				blockEditor.useBlockProps(),
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Form text', 'site-audit-report' ) },
						field( 'heading', __( 'Heading', 'site-audit-report' ) ),
						field( 'intro', __( 'Introduction', 'site-audit-report' ) ),
						field( 'button', __( 'Button text', 'site-audit-report' ) )
					)
				),
				el(
					components.Placeholder,
					{
						icon: 'chart-area',
						label: attrs.heading || __( 'Site Audit Form', 'site-audit-report' ),
						instructions: attrs.intro || __( 'Visitors enter their website address, business name and email here. Leave the text settings empty to use the plugin defaults.', 'site-audit-report' ),
					}
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n );

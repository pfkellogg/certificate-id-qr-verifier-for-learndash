( function ( blocks, element, blockEditor ) {
	var el = element.createElement;
	var useBlockProps = blockEditor.useBlockProps;

	blocks.registerBlockType( 'cp-ld/photo-upload', {
		title: 'Certificate Photo Upload',
		description: 'Displays a photo upload form so learners can add or change their certificate photo.',
		icon: 'camera',
		category: 'widgets',
		supports: {
			html: false,
			multiple: false,
		},
		edit: function () {
			var blockProps = useBlockProps();
			return el( 'div', blockProps,
				el( 'div', {
					style: {
						border: '2px dashed #cbd5e1',
						borderRadius: '10px',
						padding: '28px',
						textAlign: 'center',
						background: '#f8fafc',
					},
				},
					el( 'div', { style: { fontSize: '40px', marginBottom: '12px' } }, '\uD83D\uDCF7' ),
					el( 'p', { style: { fontWeight: '600', fontSize: '16px', margin: '0 0 8px' } }, 'Certificate Photo Upload' ),
					el( 'p', { style: { color: '#64748b', fontSize: '13px', margin: 0 } }, 'Learners will see a photo upload form here.' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
}( window.wp.blocks, window.wp.element, window.wp.blockEditor ) );

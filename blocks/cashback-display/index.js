( function ( wp ) {
	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor && wp.blockEditor.useBlockProps;
	var ServerSideRender = wp.serverSideRender;
	var el = wp.element.createElement;
	var __ = ( wp.i18n && wp.i18n.__ ) ? wp.i18n.__ : function ( s ) { return s; };

	registerBlockType( 'cashback/cashback-display', {
		edit: function ( props ) {
			var blockProps = useBlockProps ? useBlockProps() : {};
			var postId = props.context && props.context.postId
				? props.context.postId
				: null;

			if ( ! ServerSideRender ) {
				return el(
					'div',
					blockProps,
					el(
						'div',
						{ className: 'cashback-display-editor-fallback' },
						__( 'Кэшбэк отображается на фронте.', 'cashback-plugin' )
					)
				);
			}

			return el(
				'div',
				blockProps,
				el( ServerSideRender, {
					block: 'cashback/cashback-display',
					attributes: props.attributes,
					urlQueryArgs: postId ? { postId: postId } : {},
					EmptyResponsePlaceholder: function () {
						return el(
							'div',
							{ className: 'cashback-display-editor-empty' },
							__( 'Кэшбэк появится, когда у товара заполнено поле «Размер кэшбэка».', 'cashback-plugin' )
						);
					}
				} )
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );

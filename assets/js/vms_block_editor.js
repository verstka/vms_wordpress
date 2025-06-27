( function( wp ) {
    const { registerPlugin } = wp.plugins;
    const { PluginPostPublishPanel } = wp.editor;
    const { Button } = wp.components;
    const { __ } = wp.i18n;
    const { createElement } = wp.element;

    const VmsEditorButtons = () => {
        return createElement(
            PluginPostPublishPanel,
            { title: __( 'Verstka Editor', 'verstka-backend' ) },
            createElement(
                Button,
                {
                    isSecondary: true,
                    onClick: () => window.open( vmsBlockEditor.desktopUrl, '_blank' ),
                },
                __( 'Desktop', 'verstka-backend' )
            ),
            createElement(
                Button,
                {
                    isSecondary: true,
                    onClick: () => window.open( vmsBlockEditor.mobileUrl, '_blank' ),
                },
                __( 'Mobile', 'verstka-backend' )
            )
        );
    };

    registerPlugin( 'vms-editor-buttons', {
        render: VmsEditorButtons,
    } );
} )( window.wp ); 
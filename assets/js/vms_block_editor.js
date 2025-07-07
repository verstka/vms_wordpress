( function( wp ) {
    const { registerPlugin } = wp.plugins;
    const { PluginMoreMenuItem, PluginDocumentSettingPanel } = wp.editPost;
    const { Button, Panel, PanelBody, PanelRow } = wp.components;
    const { __ } = wp.i18n;
    const { createElement, Fragment } = wp.element;

    // Добавляем пункты в меню "Дополнительно" (три точки)
    const VmsMoreMenuItems = () => {
        return createElement(
            Fragment,
            null,
            createElement(
                PluginMoreMenuItem,
                {
                    icon: 'desktop',
                    onClick: () => window.open( vmsBlockEditor.desktopUrl, '_blank' ),
                },
                __( 'Verstka Desktop', 'verstka-backend' )
            ),
            createElement(
                PluginMoreMenuItem,
                {
                    icon: 'smartphone',
                    onClick: () => window.open( vmsBlockEditor.mobileUrl, '_blank' ),
                },
                __( 'Verstka Mobile', 'verstka-backend' )
            )
        );
    };

    // Добавляем панель в боковое меню документа
    const VmsDocumentSettingPanel = () => {
        return createElement(
            PluginDocumentSettingPanel,
            {
                name: 'vms-editor-panel',
                title: __( 'Verstka Editor', 'verstka-backend' ),
                className: 'vms-editor-panel',
            },
            createElement(
                PanelRow,
                null,
                createElement(
                    'div',
                    { style: { display: 'flex', gap: '8px', width: '100%' } },
                    createElement(
                        Button,
                        {
                            isPrimary: true,
                            onClick: () => window.open( vmsBlockEditor.desktopUrl, '_blank' ),
                            style: { flex: 1 }
                        },
                        __( 'Desktop', 'verstka-backend' )
                    ),
                    createElement(
                        Button,
                        {
                            isPrimary: true,
                            onClick: () => window.open( vmsBlockEditor.mobileUrl, '_blank' ),
                            style: { flex: 1 }
                        },
                        __( 'Mobile', 'verstka-backend' )
                    )
                )
            )
        );
    };

    // Регистрируем плагин с обеими функциями
    registerPlugin( 'vms-editor-buttons', {
        render: () => createElement(
            Fragment,
            null,
            createElement( VmsMoreMenuItems ),
            createElement( VmsDocumentSettingPanel )
        ),
    } );
} )( window.wp ); 
jQuery( function ( $ ) {

    // Settings page — Test Connection
    $( '#ctb-test-btn' ).on( 'click', function () {
        var $b = $( this ), $r = $( '#ctb-test-result' );
        $b.prop( 'disabled', true ).text( 'Testing...' );
        $r.text( '' );
        $.post( ctbAdmin.ajaxUrl, { action: 'ctb_test_connection', _ajax_nonce: ctbAdmin.nonce } )
            .done( function ( res ) { $r.css( 'color', res.success ? 'green' : '#b32d2e' ).text( res.data.message ); } )
            .fail( function () { $r.css( 'color', '#b32d2e' ).text( 'Request failed.' ); } )
            .always( function () { $b.prop( 'disabled', false ).text( 'Test Connection' ); } );
    } );

    // Debug tab — Clear Log
    $( '#ctb-clear-log' ).on( 'click', function () {
        if ( ! confirm( 'Clear the entire debug log?' ) ) return;
        var $b = $( this );
        $b.prop( 'disabled', true );
        $.post( ctbAdmin.ajaxUrl, { action: 'ctb_clear_log', _ajax_nonce: ctbAdmin.nonce } )
            .done( function ( res ) { if ( res.success ) location.reload(); } )
            .always( function () { $b.prop( 'disabled', false ); } );
    } );

    // Post editor — Manual Crosspost
    $( document ).on( 'click', '.ctb-manual-btn', function () {
        var $b = $( this ), $r = $b.siblings( '.ctb-manual-result' );
        $b.prop( 'disabled', true ).text( 'Posting...' );
        $.post( ajaxurl, {
            action:      'ctb_manual_crosspost',
            post_id:     $b.data( 'postid' ),
            _ajax_nonce: $b.data( 'nonce' )
        } )
        .done( function ( res ) {
            if ( res.success ) {
                $r.css( 'color', 'green' ).text( '\u2713 Posted!' );
                $b.text( 'Re-crosspost' ).prop( 'disabled', false );
            } else {
                $r.css( 'color', '#b32d2e' ).text( res.data.message );
                $b.prop( 'disabled', false );
            }
        } )
        .fail( function () {
            $r.css( 'color', '#b32d2e' ).text( 'Request failed.' );
            $b.prop( 'disabled', false );
        } );
    } );

} );

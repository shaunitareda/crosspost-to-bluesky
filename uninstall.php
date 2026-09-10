<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;
delete_option( 'ctb_settings' );
delete_option( 'ctb_debug_log' );
delete_post_meta_by_key( '_ctb_bluesky_uri' );
delete_post_meta_by_key( '_ctb_bluesky_cid' );
delete_post_meta_by_key( '_ctb_skip_crosspost' );
delete_post_meta_by_key( '_ctb_last_error' );
delete_post_meta_by_key( '_ctb_crosspost_in_progress' );

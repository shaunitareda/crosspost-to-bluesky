<?php
/**
 * Plugin Name:       Crosspost to Bluesky
 * Plugin URI:        https://github.com/evecodes/crosspost-to-bluesky
 * Description:       Crossposts WordPress posts and Social Notes (Tusky/Enable Mastodon Apps) to Bluesky with native image and video uploads.
 * Version:           1.2.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            evecodes
 * License:           GPL-2.0-or-later
 * Text Domain:       crosspost-to-bluesky
 * Domain Path:       /languages
 */
if ( ! defined( 'ABSPATH' ) ) exit;
define( 'CTB_VERSION',    '1.2.1' );
define( 'CTB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CTB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register( function ( $class ) {
    if ( strncmp( 'CTB\\', $class, 4 ) !== 0 ) return;
    $file = CTB_PLUGIN_DIR . 'includes/' . str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, 4 ) ) . '.php';
    if ( file_exists( $file ) ) require_once $file;
} );

register_activation_hook( __FILE__, [ 'CTB\\Core\\Installer', 'activate' ] );

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'crosspost-to-bluesky', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    $options   = new CTB\Core\Options();
    $logger    = new CTB\Core\Logger();
    $http      = new CTB\Api\HttpClient( $logger );
    $auth      = new CTB\Api\Auth( $options, $http, $logger );
    $uploader  = new CTB\Api\ImageUploader( $auth, $http, $options, $logger );
    $video     = new CTB\Api\VideoUploader( $auth, $http, $options, $logger );
    $creator   = new CTB\Api\PostCreator( $auth, $uploader, $video, $http, $options, $logger );
    $publisher = new CTB\Core\Publisher( $creator, $options, $logger );
    $publisher->register_hooks();
    if ( is_admin() ) {
        ( new CTB\Admin\SettingsPage( $options, $auth, $logger ) )->register_hooks();
        ( new CTB\Admin\PostMetaBox( $options, $creator ) )->register_hooks();
    }
} );

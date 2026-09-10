<?php
namespace CTB\Admin;
use CTB\Core\Options;
use CTB\Core\Logger;
use CTB\Api\Auth;
class SettingsPage {
    private const SLUG  = 'crosspost-to-bluesky';
    private const GROUP = 'ctb_settings_group';
    public function __construct( private Options $options, private Auth $auth, private Logger $logger ) {}
    public function register_hooks(): void {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_setting' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_ajax_ctb_test_connection', [ $this, 'ajax_test' ] );
        add_action( 'wp_ajax_ctb_clear_log',       [ $this, 'ajax_clear_log' ] );
    }
    public function add_menu(): void {
        add_options_page( 'Crosspost to Bluesky', 'Crosspost to Bluesky', 'manage_options', self::SLUG, [ $this, 'render' ] );
    }
    public function register_setting(): void {
        register_setting( self::GROUP, 'ctb_settings', [ $this, 'sanitize' ] );
    }
    public function sanitize( $raw ): array {
        if ( ! is_array( $raw ) ) $raw = [];
        $existing = $this->options->all();
        $password = sanitize_text_field( $raw['app_password'] ?? '' );
        return [
            'identifier'         => sanitize_text_field( $raw['identifier'] ?? '' ),
            'app_password'       => '' !== $password ? $password : (string) ( $existing['app_password'] ?? '' ),
            'pds_host'           => esc_url_raw( $raw['pds_host'] ?? 'https://bsky.social' ),
            'enabled_post_types' => array_map( 'sanitize_key', (array) ( $raw['enabled_post_types'] ?? [ 'post' ] ) ),
            'auto_crosspost'     => ! empty( $raw['auto_crosspost'] ) ? 1 : 0,
            'template'           => sanitize_textarea_field( $raw['template'] ?? "{title}\n\n{content}" ),
            'include_permalink'  => ! empty( $raw['include_permalink'] ) ? 1 : 0,
            'debug_enabled'      => ! empty( $raw['debug_enabled'] ) ? 1 : 0,
            'video_enabled'      => ! empty( $raw['video_enabled'] ) ? 1 : 0,
        ];
    }
    public function enqueue( string $hook ): void {
        if ( 'settings_page_' . self::SLUG !== $hook ) return;
        wp_enqueue_script( 'ctb-admin', CTB_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], CTB_VERSION, true );
        wp_localize_script( 'ctb-admin', 'ctbAdmin', [ 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'ctb_admin_nonce' ) ] );
    }
    public function ajax_test(): void {
        check_ajax_referer( 'ctb_admin_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        $this->logger->info( 'Connection test triggered.', [ 'user' => get_current_user_id() ] );
        $r = $this->auth->create_session();
        if ( is_wp_error( $r ) ) { $this->logger->error( 'Test failed: ' . $r->get_error_message() ); wp_send_json_error( [ 'message' => $r->get_error_message() ] ); }
        $this->logger->info( 'Connection test succeeded.', [ 'did' => $r['did'] ?? '', 'user' => get_current_user_id() ] );
        wp_send_json_success( [ 'message' => 'Connected! DID: ' . ( $r['did'] ?? 'unknown' ) ] );
    }
    public function ajax_clear_log(): void {
        check_ajax_referer( 'ctb_admin_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        $this->logger->clear();
        wp_send_json_success( [ 'message' => 'Log cleared.' ] );
    }
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $opts       = $this->options->all();
        $opts['app_password'] = '';
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        $log        = $this->logger->get_entries();
        $active_tab = sanitize_key( $_GET['tab'] ?? 'settings' );
        include CTB_PLUGIN_DIR . 'templates/settings-page.php';
    }
}

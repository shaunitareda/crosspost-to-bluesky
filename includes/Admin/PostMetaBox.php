<?php
namespace CTB\Admin;
use CTB\Core\Options;
use CTB\Api\PostCreator;
class PostMetaBox {
    public function __construct( private Options $options, private PostCreator $creator ) {}
    public function register_hooks(): void {
        add_action( 'add_meta_boxes',               [ $this, 'add_box' ] );
        add_action( 'save_post',                    [ $this, 'save_meta' ] );
        add_action( 'admin_enqueue_scripts',        [ $this, 'enqueue' ] );
        add_action( 'wp_ajax_ctb_manual_crosspost', [ $this, 'ajax_crosspost' ] );
    }
    public function add_box(): void {
        foreach ( (array) $this->options->get( 'enabled_post_types', [ 'post' ] ) as $type ) {
            add_meta_box( 'ctb_box', 'Crosspost to Bluesky', [ $this, 'render' ], $type, 'side' );
        }
    }
    public function enqueue( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
        wp_enqueue_script( 'ctb-meta', CTB_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], CTB_VERSION, true );
    }
    public function render( \WP_Post $post ): void {
        wp_nonce_field( 'ctb_meta_box', 'ctb_meta_nonce' );
        $skip  = get_post_meta( $post->ID, '_ctb_skip_crosspost', true );
        $uri   = get_post_meta( $post->ID, '_ctb_bluesky_uri',    true );
        $error = get_post_meta( $post->ID, '_ctb_last_error',     true );
        include CTB_PLUGIN_DIR . 'templates/meta-box.php';
    }
    public function save_meta( int $post_id ): void {
        if ( ! isset( $_POST['ctb_meta_nonce'] ) ) return;
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ctb_meta_nonce'] ) ), 'ctb_meta_box' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        update_post_meta( $post_id, '_ctb_skip_crosspost', ! empty( $_POST['ctb_skip_crosspost'] ) ? 1 : '' );
    }
    public function ajax_crosspost(): void {
        $post_id = absint( $_POST['post_id'] ?? 0 );
        check_ajax_referer( 'ctb_manual_' . $post_id );
        if ( ! current_user_can( 'edit_post', $post_id ) ) wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
        $post = get_post( $post_id );
        if ( ! $post ) wp_send_json_error( [ 'message' => 'Post not found.' ], 404 );
        $result = $this->creator->create_from_post( $post );
        if ( is_wp_error( $result ) ) wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        update_post_meta( $post_id, '_ctb_bluesky_uri', $result['uri'] ?? '' );
        update_post_meta( $post_id, '_ctb_bluesky_cid', $result['cid'] ?? '' );
        delete_post_meta( $post_id, '_ctb_last_error' );
        wp_send_json_success( [ 'uri' => $result['uri'] ?? '' ] );
    }
}

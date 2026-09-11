<?php
namespace CTB\Core;
use CTB\Api\PostCreator;
class Publisher {
    public function __construct( private PostCreator $creator, private Options $options, private Logger $logger ) {}
    public function register_hooks(): void {
        add_action( 'transition_post_status', [ $this, 'maybe_crosspost' ], 10, 3 );
        add_action( 'publish_post', [ $this, 'maybe_crosspost_publish_hook' ], 10, 3 );
        add_action( 'publish_page', [ $this, 'maybe_crosspost_publish_hook' ], 10, 3 );
        add_action( 'save_post', [ $this, 'maybe_crosspost_save_hook' ], 20, 3 );
    }


    public function maybe_crosspost_save_hook( int $post_id, \WP_Post $post, bool $update ): void {
        $this->logger->debug( 'save_post fired.', [ 'post_id' => $post_id, 'post_type' => $post->post_type, 'status' => $post->post_status, 'update' => $update ] );
        if ( $post->post_status !== 'publish' ) return;
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) return;
        $this->maybe_crosspost( 'publish', $update ? 'publish' : 'draft', $post );
    }

    public function maybe_crosspost_publish_hook( int $post_id, \WP_Post $post, string $old_status ): void {
        $this->logger->debug( 'publish_* hook fired.', [ 'post_id' => $post_id, 'post_type' => $post->post_type, 'old_status' => $old_status, 'new_status' => $post->post_status ] );
        $this->maybe_crosspost( 'publish', $old_status, $post );
    }

    public function maybe_crosspost( string $new_status, string $old_status, \WP_Post $post ): void {
        $this->logger->debug( 'transition_post_status fired.', [ 'post_id' => $post->ID, 'post_type' => $post->post_type, 'new_status' => $new_status, 'old_status' => $old_status ] );
        if ( 'publish' !== $new_status || 'publish' === $old_status ) {
            return;
        }
        if ( ! $this->options->get( 'auto_crosspost', 1 ) ) return;
        $enabled = (array) $this->options->get( 'enabled_post_types', [ 'post' ] );
        $this->logger->debug( 'Checking enabled post types.', [ 'post_type' => $post->post_type, 'enabled' => $enabled ] );
        if ( ! in_array( $post->post_type, $enabled, true ) ) {
            $this->logger->debug( 'Skipped: post type not enabled.', [ 'post_id' => $post->ID, 'post_type' => $post->post_type, 'enabled' => $enabled ] );
            return;
        }
        if ( get_post_meta( $post->ID, '_ctb_skip_crosspost', true ) ) { $this->logger->debug( 'Skipped: manual skip flag.', [ 'post_id' => $post->ID ] ); return; }

        $blacksky_only = (bool) get_post_meta( $post->ID, '_ctb_blacksky_only', true );
        $published_uri_meta = $blacksky_only ? '_ctb_blacksky_uri' : '_ctb_bluesky_uri';
        $published_cid_meta = $blacksky_only ? '_ctb_blacksky_cid' : '_ctb_bluesky_cid';

        if ( get_post_meta( $post->ID, $published_uri_meta, true ) ) {
            $this->logger->debug(
                $blacksky_only ? 'Skipped: already crossposted to Blacksky community.' : 'Skipped: already crossposted.',
                [ 'post_id' => $post->ID ]
            );
            return;
        }

        if ( get_post_meta( $post->ID, '_ctb_crosspost_in_progress', true ) ) { $this->logger->debug( 'Skipped: crosspost already in progress.', [ 'post_id' => $post->ID ] ); return; }
        update_post_meta( $post->ID, '_ctb_crosspost_in_progress', 1 );

        $this->logger->info(
            $blacksky_only ? 'Starting Blacksky-only crosspost.' : 'Starting crosspost.',
            [ 'post_id' => $post->ID, 'post_type' => $post->post_type ]
        );

        $result = $this->creator->create_from_post( $post, $blacksky_only );
        if ( is_wp_error( $result ) ) {
            update_post_meta( $post->ID, '_ctb_last_error', $result->get_error_message() );
            delete_post_meta( $post->ID, '_ctb_crosspost_in_progress' );
            $this->logger->error( 'Crosspost failed: ' . $result->get_error_message(), [ 'post_id' => $post->ID ] );
            return;
        }

        update_post_meta( $post->ID, $published_uri_meta, $result['uri'] ?? '' );
        update_post_meta( $post->ID, $published_cid_meta, $result['cid'] ?? '' );
        delete_post_meta( $post->ID, '_ctb_last_error' );
        delete_post_meta( $post->ID, '_ctb_crosspost_in_progress' );

        $this->logger->info(
            $blacksky_only ? 'Blacksky-only crosspost succeeded.' : 'Crosspost succeeded.',
            [ 'post_id' => $post->ID, 'uri' => $result['uri'] ?? '' ]
        );
    }
}

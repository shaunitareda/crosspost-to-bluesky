<?php
namespace CTB\Api;
use CTB\Core\Options;
use CTB\Core\Logger;

class PostCreator {
    private const MAX_IMAGES = 4;
    private const VIDEO_MIMES = [ 'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm' ];

    public function __construct(
        private Auth          $auth,
        private ImageUploader $uploader,
        private VideoUploader $video_uploader,
        private HttpClient    $http,
        private Options       $options,
        private Logger        $logger
    ) {}

    public function create_from_post( \WP_Post $post ) {
        $session = $this->auth->create_session();
        if ( is_wp_error( $session ) ) return $session;
        $jwt = $session['accessJwt'] ?? '';
        $did = $session['did']       ?? '';
        if ( ! $jwt || ! $did ) return new \WP_Error( 'ctb_bad_session', 'Invalid Bluesky session response.' );

        $text = $this->build_text( $post );
        if ( '' === $text ) return new \WP_Error( 'ctb_empty', 'Post text is empty after processing.' );

        $record = [
            '$type'     => 'app.bsky.feed.post',
            'text'      => $text,
            'createdAt' => gmdate( 'c' ),
            'langs'     => [ $this->locale_to_lang( get_locale() ) ],
        ];

        // ── Video takes priority over images (can't embed both) ───────────
        $video_embed = null;
        if ( $this->options->get( 'video_enabled', 1 ) ) {
            $video_embed = $this->collect_video( $post, $jwt, $did );
        }

        if ( $video_embed ) {
            $record['embed'] = $video_embed;
            $this->logger->info( 'Video embed attached to Bluesky post.', [ 'post_id' => $post->ID ] );
        } else {
            // No video — try images
            $images = $this->collect_images( $post, $jwt );
            if ( ! empty( $images ) ) {
                $record['embed'] = [ '$type' => 'app.bsky.embed.images', 'images' => $images ];
                $this->logger->info( count( $images ) . ' image(s) attached.', [ 'post_id' => $post->ID ] );
            } else {
                $this->logger->debug( 'No media found for this post.', [ 'post_id' => $post->ID ] );
            }
        }

        $host = rtrim( (string) $this->options->get( 'pds_host', 'https://bsky.social' ), '/' );
        return $this->http->post_json(
            $host . '/xrpc/com.atproto.repo.createRecord',
            [ 'repo' => $did, 'collection' => 'app.bsky.feed.post', 'record' => $record ],
            [ 'Authorization' => 'Bearer ' . $jwt ]
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VIDEO
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Look for a video attachment. Checks:
     *  1. get_attached_media('video') — Enable Mastodon Apps / Social Notes uploads
     *  2. Featured video (post_format video or video block in content)
     * Returns a fully-formed app.bsky.embed.video record or null if no video found.
     */
    private function collect_video( \WP_Post $post, string $jwt, string $did ): ?array {
        $video_attachments = get_attached_media( 'video', $post );
        $this->logger->debug( 'Video: attached media count.', [ 'count' => count( $video_attachments ) ] );

        if ( ! empty( $video_attachments ) ) {
            $att = reset( $video_attachments ); // first video only (Bluesky supports one)
            return $this->upload_video_attachment( $att->ID, $jwt, $did, $post );
        }

        // Check for a video attachment set as post thumbnail (rare but possible)
        $thumb_id = get_post_thumbnail_id( $post->ID );
        if ( $thumb_id ) {
            $mime = get_post_mime_type( $thumb_id );
            if ( $mime && strpos( $mime, 'video/' ) === 0 ) {
                return $this->upload_video_attachment( $thumb_id, $jwt, $did, $post );
            }
        }

        // Scrape <video src> or <source src> from post content
        if ( ! empty( $post->post_content ) ) {
            $url = $this->extract_video_url( $post->post_content );
            if ( $url ) {
                $this->logger->debug( 'Video: found URL in content.', [ 'url' => $url ] );
                // Check if it maps to a local attachment first
                $att_id = attachment_url_to_postid( $url );
                if ( $att_id ) return $this->upload_video_attachment( $att_id, $jwt, $did, $post );
                // External URL — not supported via simple upload; log and skip
                $this->logger->debug( 'Video: external URL found but not uploaded (local attachments only).', [ 'url' => $url ] );
            }
        }

        return null;
    }

    private function upload_video_attachment( int $attachment_id, string $jwt, string $did, \WP_Post $post ): ?array {
        $mime = get_post_mime_type( $attachment_id );
        if ( ! $mime || strpos( $mime, 'video/' ) !== 0 ) {
            $this->logger->debug( 'Skipping non-video attachment.', [ 'attachment_id' => $attachment_id, 'mime' => $mime ] );
            return null;
        }
        $this->logger->info( 'Uploading video attachment.', [ 'attachment_id' => $attachment_id, 'mime' => $mime ] );
        $result = $this->video_uploader->upload( $attachment_id, $jwt, $did );
        if ( is_wp_error( $result ) ) {
            $this->logger->error( 'Video upload failed: ' . $result->get_error_message(), [ 'attachment_id' => $attachment_id ] );
            return null;
        }
        $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true )
            ?: wp_strip_all_tags( get_the_title( $post ) );
        $embed = [
            '$type' => 'app.bsky.embed.video',
            'video' => $result['blob'],
        ];
        if ( ! empty( $result['aspectRatio'] ) ) {
            $embed['aspectRatio'] = $result['aspectRatio'];
        }
        if ( $alt ) {
            $embed['alt'] = mb_substr( sanitize_text_field( $alt ), 0, 10000 );
        }
        return $embed;
    }

    private function extract_video_url( string $content ): ?string {
        // <video src="...">
        if ( preg_match( '/<video[^>]+src=["\']([^"\']+\.mp4[^"\']*)["\'][^>]*>/i', $content, $m ) ) return $m[1];
        // <source src="..." type="video/...">
        if ( preg_match( '/<source[^>]+src=["\']([^"\']+\.mp4[^"\']*)["\'][^>]*>/i', $content, $m ) ) return $m[1];
        if ( preg_match( '/<source[^>]+src=["\']([^"\']+)["\'][^>]+type=["\']video\/[^"\']+["\'][^>]*>/i', $content, $m ) ) return $m[1];
        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // IMAGES  (unchanged three-tier logic)
    // ─────────────────────────────────────────────────────────────────────────

    private function collect_images( \WP_Post $post, string $jwt ): array {
        $blobs = []; $seen_ids = [];

        // Tier 1: Featured image (skip if it's a video)
        $thumb_id = get_post_thumbnail_id( $post->ID );
        if ( $thumb_id ) {
            $thumb_mime = get_post_mime_type( $thumb_id );
            if ( $thumb_mime && strpos( $thumb_mime, 'image/' ) === 0 ) {
                $this->logger->debug( 'Tier 1: featured image.', [ 'id' => $thumb_id ] );
                $blob = $this->uploader->upload( (int) $thumb_id, $jwt );
                if ( ! is_wp_error( $blob ) ) { $blobs[] = $this->make_item( $blob, $thumb_id, $post ); $seen_ids[] = $thumb_id; }
                else $this->logger->error( 'Tier 1 failed: ' . $blob->get_error_message(), [ 'id' => $thumb_id ] );
            }
        }

        // Tier 2: WP image attachments (Social Notes / Enable Mastodon Apps)
        if ( count( $blobs ) < self::MAX_IMAGES ) {
            $attached = get_attached_media( 'image', $post );
            $this->logger->debug( 'Tier 2: attached images.', [ 'count' => count( $attached ) ] );
            foreach ( $attached as $att ) {
                if ( count( $blobs ) >= self::MAX_IMAGES ) break;
                if ( in_array( $att->ID, $seen_ids, true ) ) continue;
                $blob = $this->uploader->upload( $att->ID, $jwt );
                if ( ! is_wp_error( $blob ) ) { $blobs[] = $this->make_item( $blob, $att->ID, $post ); $seen_ids[] = $att->ID; }
                else $this->logger->error( 'Tier 2 failed: ' . $blob->get_error_message(), [ 'id' => $att->ID ] );
            }
        }

        // Tier 3: <img> scrape from content
        if ( count( $blobs ) < self::MAX_IMAGES && ! empty( $post->post_content ) ) {
            $urls = $this->extract_img_urls( $post->post_content );
            $this->logger->debug( 'Tier 3: img URLs in content.', [ 'count' => count( $urls ) ] );
            foreach ( $urls as $url ) {
                if ( count( $blobs ) >= self::MAX_IMAGES ) break;
                $att_id = attachment_url_to_postid( $url );
                if ( $att_id && in_array( $att_id, $seen_ids, true ) ) continue;
                $blob = $this->uploader->upload_from_url( $url, $jwt );
                if ( ! is_wp_error( $blob ) ) {
                    $alt     = $this->extract_img_alt( $post->post_content, $url );
                    $blobs[] = [ 'alt' => mb_substr( sanitize_text_field( $alt ?: get_the_title( $post ) ), 0, 1000 ), 'image' => $blob ];
                } else $this->logger->error( 'Tier 3 failed: ' . $blob->get_error_message(), [ 'url' => $url ] );
            }
        }
        return $blobs;
    }

    private function make_item( array $blob, int $id, \WP_Post $post ): array {
        $alt = get_post_meta( $id, '_wp_attachment_image_alt', true ) ?: get_the_title( $post );
        return [ 'alt' => mb_substr( sanitize_text_field( $alt ), 0, 1000 ), 'image' => $blob ];
    }

    private function extract_img_urls( string $content ): array {
        preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $content, $m );
        $urls = array_unique( array_filter( $m[1] ?? [] ) );
        return array_values( array_filter( $urls, fn($u) => (bool) preg_match( '/\.(jpe?g|png|gif|webp|avif)(\?.*)?$/i', $u ) ) );
    }

    private function extract_img_alt( string $content, string $src ): string {
        $e = preg_quote( $src, '/' );
        if ( preg_match( '/<img[^>]+src=["\']' . $e . '["\'][^>]*alt=["\']([^"\']*)["\'][^>]*>/i', $content, $m ) ) return $m[1];
        if ( preg_match( '/<img[^>]+alt=["\']([^"\']*)["\'][^>]+src=["\']' . $e . '["\'][^>]*>/i', $content, $m ) ) return $m[1];
        return '';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TEXT
    // ─────────────────────────────────────────────────────────────────────────

    private function build_text( \WP_Post $post ): string {
        $template  = (string) $this->options->get( 'template', "{title}\n\n{content}" );
        $title     = wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
        $raw       = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
        $excerpt   = has_excerpt( $post )
            ? wp_strip_all_tags( html_entity_decode( $post->post_excerpt, ENT_QUOTES, 'UTF-8' ) )
            : wp_trim_words( $raw, 55, '...' );
        $content   = wp_trim_words( $raw, 100, '...' );
        $permalink = get_permalink( $post );
        $is_note   = ( ! $title || 'post-format-status' === get_post_format( $post ) || $post->post_type === 'indieblocks_note' );
        if ( $is_note ) $template = preg_replace( '/^\{title\}\s*/m', '', $template );
        $text = strtr( $template, [ '{title}' => $title, '{excerpt}' => $excerpt, '{content}' => $content, '{url}' => $permalink ] );
        if ( $this->options->get( 'include_permalink', 0 ) ) $text .= "\n\n" . $permalink;
        $text = trim( preg_replace( '/\n{3,}/', "\n\n", $text ) );
        if ( mb_strlen( $text ) > 300 ) $text = mb_substr( $text, 0, 297 ) . '...';
        $this->logger->debug( 'Built text.', [ 'text' => $text ] );
        return $text;
    }

    private function locale_to_lang( string $locale ): string {
        return strtolower( explode( '_', $locale )[0] ?? 'en' );
    }
}

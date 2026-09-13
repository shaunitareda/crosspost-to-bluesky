<?php
namespace CTB\Api;
use CTB\Core\Options;
use CTB\Core\Logger;

class PostCreator {
    private const MAX_IMAGES = 4;
    private const MAX_POST_CHARS = 300;
    private const VIDEO_MIMES = [ 'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm' ];
    private const BLACKSKY_APPVIEW_PROXY = 'did:web:api.blacksky.community#bsky_appview';

    public function __construct(
        private Auth          $auth,
        private ImageUploader $uploader,
        private VideoUploader $video_uploader,
        private HttpClient    $http,
        private Options       $options,
        private Logger        $logger
    ) {}

    public function create_from_post( \WP_Post $post, bool $blacksky_only = false ) {
        $session = $this->auth->create_session();
        if ( is_wp_error( $session ) ) return $session;
        $jwt = $session['accessJwt'] ?? '';
        $did = $session['did']       ?? '';
        if ( ! $jwt || ! $did ) return new \WP_Error( 'ctb_bad_session', 'Invalid Bluesky session response.' );

        $text = $this->build_text( $post );
        if ( $blacksky_only ) {
            $text = $this->strip_blacksky_route_command( $text );
        }
        if ( '' === $text ) return new \WP_Error( 'ctb_empty', 'Post text is empty after processing.' );

        $embed = null;

        $video_embed = null;
        if ( $this->options->get( 'video_enabled', 1 ) ) {
            $video_embed = $this->collect_video( $post, $jwt, $did );
        }

        if ( $video_embed ) {
            $embed = $video_embed;
            $this->logger->info( 'Video embed attached to Bluesky post.', [ 'post_id' => $post->ID ] );
        } else {
            $images = $this->collect_images( $post, $jwt );
            if ( ! empty( $images ) ) {
                $embed = [ '$type' => 'app.bsky.embed.images', 'images' => $images ];
                $this->logger->info( count( $images ) . ' image(s) attached.', [ 'post_id' => $post->ID ] );
            } else {
                $primary_url = $this->find_primary_url( $text );
                if ( $primary_url ) {
                    $card = $this->build_external_embed( $primary_url, $jwt );
                    if ( $card ) {
                        $text = $this->remove_primary_url_from_text( $text, $primary_url );
                        $embed = $card;
                        $this->logger->info( 'External link card attached to Bluesky post.', [ 'post_id' => $post->ID, 'url' => $primary_url ] );
                    }
                }
                if ( ! $embed ) {
                    $this->logger->debug( 'No media or external card found for this post.', [ 'post_id' => $post->ID ] );
                }
            }
        }

        $chunks = $this->split_text_for_thread( $text );
        if ( empty( $chunks ) ) return new \WP_Error( 'ctb_empty', 'Post text is empty after processing.' );

        $host = rtrim( (string) $this->options->get( 'pds_host', 'https://bsky.social' ), '/' );
        $endpoint = $host . '/xrpc/com.atproto.repo.createRecord';
        $root_ref = null;
        $parent_ref = null;
        $last_response = null;

        foreach ( $chunks as $index => $chunk ) {
            $record = [
                '$type'     => 'app.bsky.feed.post',
                'text'      => $chunk,
                'createdAt' => gmdate( 'c' ),
                'langs'     => [ $this->locale_to_lang( get_locale() ) ],
            ];

            if ( 0 === $index && $embed ) {
                $record['embed'] = $embed;
            }

            $facets = $this->build_facets( $chunk );
            if ( ! empty( $facets ) ) {
                $record['facets'] = $facets;
            }

            if ( $parent_ref ) {
                $record['reply'] = [
                    'root'   => $root_ref,
                    'parent' => $parent_ref,
                ];
            }

            if ( $blacksky_only ) {
                $response = $this->create_blacksky_record(
                    $host,
                    $did,
                    $jwt,
                    $record,
                    0 === $index ? $embed : null
                );
            } else {
                $response = $this->http->post_json(
                    $endpoint,
                    [ 'repo' => $did, 'collection' => 'app.bsky.feed.post', 'record' => $record ],
                    [ 'Authorization' => 'Bearer ' . $jwt ]
                );
            }
            if ( is_wp_error( $response ) ) return $response;

            $ref = $this->record_ref_from_response( $response );
            if ( ! $ref ) {
                return new \WP_Error( 'ctb_bad_record_response', 'Bluesky did not return a record URI/CID for thread chaining.' );
            }

            if ( null === $root_ref ) $root_ref = $ref;
            $parent_ref = $ref;
            $last_response = $response;
        }

        if ( count( $chunks ) > 1 ) {
            $this->logger->info(
                $blacksky_only ? 'Blacksky-only thread created.' : 'Bluesky thread created.',
                [ 'post_id' => $post->ID, 'chunks' => count( $chunks ) ]
            );
        }

        return $last_response;
    }

    private function create_blacksky_record(
        string $host,
        string $did,
        string $jwt,
        array $record,
        ?array $embed
    ) {
        $rkey = $this->generate_record_key();

        $submit = $record;
        unset( $submit['$type'] );
        $submit['rkey'] = $rkey;

        $submitted = $this->http->post_json(
            $host . '/xrpc/community.blacksky.feed.submitPost',
            $submit,
            [
                'Authorization' => 'Bearer ' . $jwt,
                'atproto-proxy' => self::BLACKSKY_APPVIEW_PROXY,
            ]
        );
        if ( is_wp_error( $submitted ) ) return $submitted;

        $cid = $submitted['cid'] ?? '';
        if ( ! is_string( $cid ) || '' === $cid ) {
            return new \WP_Error(
                'ctb_blacksky_bad_submit_response',
                'Blacksky did not return a CID for the submitted community post.'
            );
        }

        $stub = [
            '$type'     => 'community.blacksky.feed.post',
            'createdAt' => $record['createdAt'],
            'cid'       => $cid,
        ];

        if ( $embed ) {
            $stub['embed'] = $embed;
        }

        $stub_response = $this->http->post_json(
            $host . '/xrpc/com.atproto.repo.createRecord',
            [
                'repo'       => $did,
                'collection' => 'community.blacksky.feed.post',
                'rkey'       => $rkey,
                'record'     => $stub,
            ],
            [ 'Authorization' => 'Bearer ' . $jwt ]
        );

        if ( is_wp_error( $stub_response ) ) {
            $submitted_uri = $submitted['uri'] ?? '';
            if ( is_string( $submitted_uri ) && '' !== $submitted_uri ) {
                $cleanup = $this->http->post_json(
                    $host . '/xrpc/community.blacksky.feed.deletePost',
                    [ 'uri' => $submitted_uri ],
                    [
                        'Authorization' => 'Bearer ' . $jwt,
                        'atproto-proxy' => self::BLACKSKY_APPVIEW_PROXY,
                    ]
                );

                if ( is_wp_error( $cleanup ) ) {
                    $this->logger->error(
                        'Blacksky cleanup failed after PDS stub creation failed: ' . $cleanup->get_error_message(),
                        [ 'uri' => $submitted_uri ]
                    );
                }
            }

            return $stub_response;
        }

        $submitted_uri = $submitted['uri'] ?? '';
        if ( ! is_string( $submitted_uri ) || '' === $submitted_uri ) {
            return new \WP_Error(
                'ctb_blacksky_bad_submit_response',
                'Blacksky did not return a URI for the submitted community post.'
            );
        }

        return [
            'uri' => $submitted_uri,
            'cid' => $cid,
        ];
    }

    private function generate_record_key(): string {
        static $last = 0;
        $micros = (int) floor( microtime( true ) * 1000000 );
        $value = ( $micros * 1024 ) + random_int( 0, 1023 );
        if ( $value <= $last ) $value = $last + 1;
        $last = $value;

        $alphabet = '234567abcdefghijklmnopqrstuvwxyz';
        $encoded = '';
        for ( $i = 0; $i < 13; $i++ ) {
            $encoded = $alphabet[ $value % 32 ] . $encoded;
            $value = intdiv( $value, 32 );
        }
        return $encoded;
    }

    private function record_ref_from_response( array $response ): ?array {
        $uri = $response['uri'] ?? '';
        $cid = $response['cid'] ?? '';
        if ( ! is_string( $uri ) || ! is_string( $cid ) || '' === $uri || '' === $cid ) return null;
        return [ 'uri' => $uri, 'cid' => $cid ];
    }

    private function collect_video( \WP_Post $post, string $jwt, string $did ): ?array {
        $video_attachments = get_attached_media( 'video', $post );
        $this->logger->debug( 'Video: attached media count.', [ 'count' => count( $video_attachments ) ] );

        if ( ! empty( $video_attachments ) ) {
            $att = reset( $video_attachments );
            return $this->upload_video_attachment( $att->ID, $jwt, $did, $post );
        }

        $thumb_id = get_post_thumbnail_id( $post->ID );
        if ( $thumb_id ) {
            $mime = get_post_mime_type( $thumb_id );
            if ( $mime && strpos( $mime, 'video/' ) === 0 ) {
                return $this->upload_video_attachment( $thumb_id, $jwt, $did, $post );
            }
        }

        if ( ! empty( $post->post_content ) ) {
            $url = $this->extract_video_url( $post->post_content );
            if ( $url ) {
                $this->logger->debug( 'Video: found URL in content.', [ 'url' => $url ] );
                $att_id = attachment_url_to_postid( $url );
                if ( $att_id ) return $this->upload_video_attachment( $att_id, $jwt, $did, $post );
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
        if ( preg_match( '/<video[^>]+src=["\']([^"\']+\.mp4[^"\']*)["\'][^>]*>/i', $content, $m ) ) return $m[1];
        if ( preg_match( '/<source[^>]+src=["\']([^"\']+\.mp4[^"\']*)["\'][^>]*>/i', $content, $m ) ) return $m[1];
        if ( preg_match( '/<source[^>]+src=["\']([^"\']+)["\'][^>]+type=["\']video\/[^"\']+["\'][^>]*>/i', $content, $m ) ) return $m[1];
        return null;
    }

    private function collect_images( \WP_Post $post, string $jwt ): array {
        $blobs = []; $seen_ids = [];
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

    private function find_primary_url( string $text ): ?string {
        if ( ! preg_match( '~https?://[^\s<>"\']+~iu', $text, $match ) ) return null;
        $url = rtrim( $match[0], ".,!?;:)]}" );
        return $this->is_safe_external_url( $url ) ? $url : null;
    }

    private function remove_primary_url_from_text( string $text, string $url ): string {
        $pattern = '/' . preg_quote( $url, '/' ) . '/u';
        $text = preg_replace( $pattern, '', $text, 1 );
        $text = preg_replace( '/[ \t]{2,}/u', ' ', (string) $text );
        $text = preg_replace( '/[ \t]+\n/u', "\n", (string) $text );
        $text = preg_replace( '/\n[ \t]+/u', "\n", (string) $text );
        $text = trim( preg_replace( '/\n{3,}/u', "\n\n", (string) $text ) );
        return $text;
    }

    private function build_external_embed( string $url, string $jwt ): ?array {
        $metadata = $this->fetch_external_metadata( $url );
        if ( ! $metadata ) return null;
        $external = [ 'uri' => $url, 'title' => $metadata['title'], 'description' => $metadata['description'] ];
        if ( ! empty( $metadata['image'] ) ) {
            $thumb = $this->uploader->upload_from_url( $metadata['image'], $jwt );
            if ( ! is_wp_error( $thumb ) ) $external['thumb'] = $thumb;
            else $this->logger->debug( 'External card thumbnail upload failed; continuing without thumb.', [ 'url' => $metadata['image'] ] );
        }
        return [ '$type' => 'app.bsky.embed.external', 'external' => $external ];
    }

    private function fetch_external_metadata( string $url ): ?array {
        if ( ! $this->is_safe_external_url( $url ) ) return null;
        $response = wp_safe_remote_get( $url, [ 'timeout' => 12, 'redirection' => 3, 'limit_response_size' => 1048576, 'user-agent' => 'Crosspost to Bluesky/' . ( defined( 'CTB_VERSION' ) ? CTB_VERSION : 'unknown' ) . '; ' . home_url( '/' ) ] );
        if ( is_wp_error( $response ) ) { $this->logger->debug( 'External card metadata fetch failed.', [ 'url' => $url, 'error' => $response->get_error_message() ] ); return null; }
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) return null;
        $content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        if ( $content_type && false === strpos( $content_type, 'text/html' ) && false === strpos( $content_type, 'application/xhtml+xml' ) ) return null;
        $html = wp_remote_retrieve_body( $response );
        if ( '' === trim( $html ) ) return null;
        $title = $this->extract_meta_content( $html, 'property', 'og:title' ) ?: $this->extract_meta_content( $html, 'name', 'twitter:title' ) ?: $this->extract_html_title( $html );
        $description = $this->extract_meta_content( $html, 'property', 'og:description' ) ?: $this->extract_meta_content( $html, 'name', 'twitter:description' ) ?: $this->extract_meta_content( $html, 'name', 'description' );
        $image = $this->extract_meta_content( $html, 'property', 'og:image:secure_url' ) ?: $this->extract_meta_content( $html, 'property', 'og:image' ) ?: $this->extract_meta_content( $html, 'name', 'twitter:image' );
        $title = $this->clean_card_text( $title ?: wp_parse_url( $url, PHP_URL_HOST ) ?: $url, 300 );
        $description = $this->clean_card_text( $description ?: '', 1000 );
        $image = $image ? $this->resolve_url( $image, $url ) : '';
        if ( $image && ! $this->is_safe_external_url( $image ) ) $image = '';
        return [ 'title' => $title, 'description' => $description, 'image' => $image ];
    }

    private function extract_meta_content( string $html, string $attribute, string $value ): string {
        $attr = preg_quote( $attribute, '/' ); $val = preg_quote( $value, '/' );
        $patterns = [ '/<meta[^>]*\b' . $attr . '\s*=\s*(["\'])' . $val . '\1[^>]*\bcontent\s*=\s*(["\'])(.*?)\2[^>]*>/isu', '/<meta[^>]*\bcontent\s*=\s*(["\'])(.*?)\1[^>]*\b' . $attr . '\s*=\s*(["\'])' . $val . '\3[^>]*>/isu' ];
        foreach ( $patterns as $i => $pattern ) if ( preg_match( $pattern, $html, $m ) ) return html_entity_decode( $i === 0 ? $m[3] : $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return '';
    }

    private function extract_html_title( string $html ): string {
        if ( preg_match( '/<title[^>]*>(.*?)<\/title>/isu', $html, $m ) ) return html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        return '';
    }

    private function clean_card_text( string $text, int $max ): string {
        $text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = trim( preg_replace( '/\s+/u', ' ', $text ) );
        return mb_substr( $text, 0, $max );
    }

    private function is_safe_external_url( string $url ): bool {
        if ( ! wp_http_validate_url( $url ) ) return false;
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        return in_array( $scheme, [ 'http', 'https' ], true );
    }

    private function resolve_url( string $candidate, string $base ): string {
        $candidate = trim( html_entity_decode( $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        if ( preg_match( '~^https?://~i', $candidate ) ) return $candidate;
        $parts = wp_parse_url( $base );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) return '';
        if ( str_starts_with( $candidate, '//' ) ) return $parts['scheme'] . ':' . $candidate;
        $origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
        if ( str_starts_with( $candidate, '/' ) ) return $origin . $candidate;
        $path = $parts['path'] ?? '/';
        $dir = preg_replace( '~/[^/]*$~', '/', $path );
        return $origin . $dir . $candidate;
    }

    private function build_text( \WP_Post $post ): string {
        $template  = (string) $this->options->get( 'template', "{title}\n{content}" );
        $title     = wp_strip_all_tags( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) );
        $raw       = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
        $excerpt   = has_excerpt( $post ) ? wp_strip_all_tags( html_entity_decode( $post->post_excerpt, ENT_QUOTES, 'UTF-8' ) ) : wp_trim_words( $raw, 55, '...' );
        $content   = $raw;
        $permalink = get_permalink( $post );
        $is_social_note = $post->post_type === 'jetpack-social-note';
        $is_note   = ( ! $title || 'post-format-status' === get_post_format( $post ) || $post->post_type === 'indieblocks_note' || $is_social_note );
        if ( $is_note ) $template = preg_replace( '/^\{title\}\s*/m', '', $template );
        if ( false !== strpos( $template, '{title}' ) && false !== strpos( $template, '{content}' ) ) $template = preg_replace( '/\{title\}\s*\R+\s*\{content\}/u', '{title} {content}', $template );
        if ( false !== strpos( $template, '{title}' ) && false !== strpos( $template, '{content}' ) && '' !== $title ) $content = $this->strip_leading_duplicate_title( $title, $content );
        $text = strtr( $template, [ '{title}' => $title, '{excerpt}' => $excerpt, '{content}' => $content, '{url}' => $is_social_note ? '' : $permalink ] );
        if ( ! $is_social_note && $this->options->get( 'include_permalink', 0 ) ) $text .= "\n\n" . $permalink;
        $text = trim( preg_replace( '/\n{3,}/', "\n\n", $text ) );
        $this->logger->debug( 'Built text.', [ 'text' => $text ] );
        return $text;
    }

    private function strip_blacksky_route_command( string $text ): string {
        $text = preg_replace(
            '/(?<![\\p{L}\\p{M}\\p{N}_])#blacksky(?![\\p{L}\\p{M}\\p{N}_])/iu',
            '',
            $text
        );
        $text = preg_replace( '/[ \t]{2,}/u', ' ', (string) $text );
        $text = preg_replace( '/[ \t]+\n/u', "\n", (string) $text );
        $text = preg_replace( '/\n[ \t]+/u', "\n", (string) $text );
        return trim( preg_replace( '/\n{3,}/u', "\n\n", (string) $text ) );
    }

    private function split_text_for_thread( string $text ): array {
        $chunks = [];
        $remaining = trim( $text );
        while ( '' !== $remaining ) {
            if ( mb_strlen( $remaining ) <= self::MAX_POST_CHARS ) { $chunks[] = $remaining; break; }
            $candidate = mb_substr( $remaining, 0, self::MAX_POST_CHARS );
            $split = $this->find_split_position( $candidate, $remaining );
            if ( $split < 1 ) $split = self::MAX_POST_CHARS;
            $chunk = rtrim( mb_substr( $remaining, 0, $split ) );
            if ( '' === $chunk ) { $chunk = mb_substr( $remaining, 0, self::MAX_POST_CHARS ); $split = self::MAX_POST_CHARS; }
            $chunks[] = $chunk;
            $remaining = ltrim( mb_substr( $remaining, $split ) );
        }
        return $chunks;
    }

    private function find_split_position( string $candidate, string $remaining ): int {
        $limit = mb_strlen( $candidate );
        $url_ranges = $this->url_char_ranges( $remaining );
        $best = 0;
        if ( preg_match_all( '/\n{2,}|\n/u', $candidate, $m, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $m[0] as [ $match, $byte ] ) { $pos = mb_strlen( substr( $candidate, 0, $byte ) ) + mb_strlen( $match ); if ( $pos < $limit && ! $this->char_position_inside_ranges( $pos, $url_ranges ) ) $best = $pos; }
        }
        if ( $best > 0 ) return $best;
        if ( preg_match_all( '/[.!?。！？](?:["\'”’\)\]]*)\s+/u', $candidate, $m, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $m[0] as [ $match, $byte ] ) { $pos = mb_strlen( substr( $candidate, 0, $byte ) ) + mb_strlen( $match ); if ( $pos < $limit && ! $this->char_position_inside_ranges( $pos, $url_ranges ) ) $best = $pos; }
        }
        if ( $best > 0 ) return $best;
        if ( preg_match_all( '/\s+/u', $candidate, $m, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $m[0] as [ $match, $byte ] ) { $pos = mb_strlen( substr( $candidate, 0, $byte ) ); if ( $pos > 0 && $pos < $limit && ! $this->char_position_inside_ranges( $pos, $url_ranges ) ) $best = $pos; }
        }
        if ( $best > 0 ) return $best;
        foreach ( $url_ranges as [ $start, $end ] ) {
            if ( $start < $limit && $end > $limit && $start > 0 ) return $start;
        }
        return $limit;
    }

    private function url_char_ranges( string $text ): array {
        $ranges = [];
        if ( preg_match_all( '~https?://[^\s<>"\']+~iu', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[0] as [ $raw_url, $byte_start ] ) {
                $url = rtrim( $raw_url, ".,!?;:)]}" );
                if ( '' === $url ) continue;
                $char_start = mb_strlen( substr( $text, 0, $byte_start ) );
                $ranges[] = [ $char_start, $char_start + mb_strlen( $url ) ];
            }
        }
        return $ranges;
    }

    private function char_position_inside_ranges( int $position, array $ranges ): bool {
        foreach ( $ranges as [ $start, $end ] ) if ( $position > $start && $position < $end ) return true;
        return false;
    }

    private function normalize_for_compare( string $text ): string {
        $text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/\s+/u', ' ', trim( $text ) );
        $text = preg_replace( '/[.!?。！？]+$/u', '', $text );
        return mb_strtolower( trim( $text ), 'UTF-8' );
    }

    private function strip_leading_duplicate_title( string $title, string $content ): string {
        $normalized_title = $this->normalize_for_compare( $title );
        if ( '' === $normalized_title || '' === trim( $content ) ) return $content;
        $parts = preg_split( '/\s+/u', $normalized_title, -1, PREG_SPLIT_NO_EMPTY );
        if ( empty( $parts ) ) return $content;
        $pattern = implode( '\\s+', array_map( static fn( string $part ): string => preg_quote( $part, '/' ), $parts ) );
        $deduped = preg_replace( '/^\s*' . $pattern . '(?:[.!?。！？]+)?(?=\s|$)\s*/iu', '', $content, 1, $count );
        return $count > 0 ? trim( (string) $deduped ) : $content;
    }

    private function build_facets( string $text ): array {
        $facets = []; $link_ranges = [];
        if ( preg_match_all( '~https?://[^\s<>"\']+~iu', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[0] as [ $raw_url, $start ] ) {
                $url = rtrim( $raw_url, ".,!?;:)]}" );
                if ( '' === $url ) continue;
                $end = $start + strlen( $url );
                $link_ranges[] = [ $start, $end ];
                $facets[] = [ 'index' => [ 'byteStart' => $start, 'byteEnd' => $end ], 'features' => [ [ '$type' => 'app.bsky.richtext.facet#link', 'uri' => $url ] ] ];
            }
        }
        if ( preg_match_all( '/(?<![\p{L}\p{M}\p{N}_])#([\p{L}\p{M}\p{N}_]+)/u', $text, $matches, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $matches[0] as $i => [ $full, $start ] ) {
                $end = $start + strlen( $full );
                if ( $this->range_overlaps( $start, $end, $link_ranges ) ) continue;
                $tag = $matches[1][ $i ][0] ?? '';
                if ( '' === $tag ) continue;
                $facets[] = [ 'index' => [ 'byteStart' => $start, 'byteEnd' => $end ], 'features' => [ [ '$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tag ] ] ];
            }
        }
        usort( $facets, fn( array $a, array $b ): int => $a['index']['byteStart'] <=> $b['index']['byteStart'] );
        return $facets;
    }

    private function range_overlaps( int $start, int $end, array $ranges ): bool {
        foreach ( $ranges as [ $range_start, $range_end ] ) if ( $start < $range_end && $end > $range_start ) return true;
        return false;
    }

    private function locale_to_lang( string $locale ): string {
        return strtolower( explode( '_', $locale )[0] ?? 'en' );
    }
}

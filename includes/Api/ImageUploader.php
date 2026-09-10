<?php
namespace CTB\Api;
use CTB\Core\Options;
use CTB\Core\Logger;
class ImageUploader {
    public function __construct( private Auth $auth, private HttpClient $http, private Options $options, private Logger $logger ) {}

    public function upload( int $attachment_id, string $access_jwt ) {
        $file = get_attached_file( $attachment_id );
        $this->logger->debug( 'Uploading attachment.', [ 'attachment_id' => $attachment_id, 'file' => $file ] );
        if ( ! $file || ! file_exists( $file ) ) {
            $src = wp_get_attachment_url( $attachment_id );
            if ( $src ) { $file = $this->download_to_temp( $src ); }
            if ( ! $file ) return new \WP_Error( 'ctb_no_file', 'Image file not found.' );
        }
        $mime = wp_check_filetype( $file )['type'] ?: ( function_exists( 'mime_content_type' ) ? mime_content_type( $file ) : 'image/jpeg' );
        if ( ! $mime || strpos( $mime, 'image/' ) !== 0 ) return new \WP_Error( 'ctb_not_image', 'Not an image: ' . $mime );
        $raw = file_get_contents( $file );
        if ( ! $raw ) return new \WP_Error( 'ctb_read_fail', 'Could not read image file.' );
        return $this->upload_raw( $raw, $mime, $access_jwt, $attachment_id );
    }

    public function upload_from_url( string $url, string $access_jwt ) {
        $this->logger->debug( 'Uploading from URL.', [ 'url' => $url ] );
        $temp = $this->download_to_temp( $url );
        if ( ! $temp ) return new \WP_Error( 'ctb_download_fail', 'Could not download image: ' . $url );
        $mime = function_exists( 'mime_content_type' ) ? mime_content_type( $temp ) : 'image/jpeg';
        $raw  = file_get_contents( $temp );
        @unlink( $temp );
        if ( ! $raw ) return new \WP_Error( 'ctb_read_fail', 'Could not read downloaded image.' );
        return $this->upload_raw( $raw, $mime ?: 'image/jpeg', $access_jwt );
    }

    private function upload_raw( string $raw, string $mime, string $access_jwt, ?int $attachment_id = null ) {
        $host   = rtrim( (string) $this->options->get( 'pds_host', 'https://bsky.social' ), '/' );
        $result = $this->http->post_binary(
            $host . '/xrpc/com.atproto.repo.uploadBlob', $raw, $mime,
            [ 'Authorization' => 'Bearer ' . $access_jwt ]
        );
        if ( is_wp_error( $result ) ) return $result;
        if ( empty( $result['blob'] ) ) return new \WP_Error( 'ctb_blob_empty', 'Bluesky returned no blob.' );
        $this->logger->info( 'Image blob uploaded.', [ 'attachment_id' => $attachment_id ] );
        return $result['blob'];
    }

    public function download_to_temp( string $url ): ?string {
        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );
        if ( is_wp_error( $response ) ) return null;
        $body = wp_remote_retrieve_body( $response );
        if ( ! $body ) return null;
        $tmp = wp_tempnam();
        file_put_contents( $tmp, $body );
        return $tmp;
    }
}

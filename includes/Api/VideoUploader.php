<?php
namespace CTB\Api;
use CTB\Core\Options;
use CTB\Core\Logger;

/**
 * Uploads a video to Bluesky using the recommended video service method:
 *   1. Get a service auth token scoped for uploadBlob
 *   2. POST the video to https://video.bsky.app/xrpc/app.bsky.video.uploadVideo
 *   3. Poll getJobStatus until the blob is ready
 *   4. Return the blob + aspect ratio for use in app.bsky.embed.video
 *
 * Supported format: video/mp4, max 100 MB.
 * Falls back to the simple uploadBlob-to-PDS method if service auth fails.
 */
class VideoUploader {
    private const VIDEO_SERVICE    = 'https://video.bsky.app';
    private const MAX_SIZE_BYTES   = 100_000_000; // 100 MB
    private const POLL_MAX_SECONDS = 120;
    private const POLL_INTERVAL    = 3; // seconds between status checks
    private const SUPPORTED_MIMES  = [ 'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm' ];

    public function __construct(
        private Auth       $auth,
        private HttpClient $http,
        private Options    $options,
        private Logger     $logger
    ) {}

    /**
     * Upload a WP video attachment to Bluesky.
     *
     * @param int    $attachment_id WP attachment post ID
     * @param string $access_jwt   Session JWT from createSession
     * @param string $did          User DID from createSession
     * @return array|\WP_Error  [ 'blob' => [...], 'aspectRatio' => ['width'=>W,'height'=>H] ]
     */
    public function upload( int $attachment_id, string $access_jwt, string $did ) {
        $file = get_attached_file( $attachment_id );
        $this->logger->debug( 'VideoUploader: uploading attachment.', [ 'attachment_id' => $attachment_id, 'file' => $file ] );

        if ( ! $file || ! file_exists( $file ) ) {
            // Try downloading from URL (e.g., remote storage / CDN)
            $src = wp_get_attachment_url( $attachment_id );
            if ( $src ) {
                $file = $this->download_to_temp( $src );
                if ( ! $file ) {
                    $this->logger->error( 'Video file not found on disk and could not download.', [ 'attachment_id' => $attachment_id ] );
                    return new \WP_Error( 'ctb_no_video_file', 'Video file not found.' );
                }
                $this->logger->debug( 'Downloaded video to temp.', [ 'temp' => $file ] );
            } else {
                return new \WP_Error( 'ctb_no_video_file', 'Video file not found.' );
            }
        }

        // Validate size
        $size = filesize( $file );
        if ( $size > self::MAX_SIZE_BYTES ) {
            $this->logger->error( 'Video exceeds 100 MB limit.', [ 'attachment_id' => $attachment_id, 'size' => $size ] );
            return new \WP_Error( 'ctb_video_too_large', sprintf( 'Video is %.1f MB; Bluesky limit is 100 MB.', $size / 1_000_000 ) );
        }

        // Validate mime type
        $mime = wp_check_filetype( $file )['type'] ?: ( function_exists( 'mime_content_type' ) ? mime_content_type( $file ) : '' );
        if ( ! $mime || ! in_array( $mime, self::SUPPORTED_MIMES, true ) ) {
            // Be lenient — Bluesky only accepts mp4 but let the API reject it
            $mime = $mime ?: 'video/mp4';
            $this->logger->debug( 'Video mime type: ' . $mime . '. Bluesky only processes video/mp4.', [ 'attachment_id' => $attachment_id ] );
        }

        $raw = file_get_contents( $file );
        if ( ! $raw ) return new \WP_Error( 'ctb_read_fail', 'Could not read video file.' );

        $aspect = $this->get_aspect_ratio( $attachment_id, $file );
        $name   = basename( get_attached_file( $attachment_id ) ?: 'video.mp4' );

        // Try recommended method (video service) first
        $blob = $this->upload_via_video_service( $raw, $mime, $name, $access_jwt, $did );

        // Fall back to simple method if service auth isn't available
        if ( is_wp_error( $blob ) ) {
            $this->logger->debug( 'Video service upload failed, trying simple uploadBlob fallback.', [ 'error' => $blob->get_error_message() ] );
            $blob = $this->upload_simple( $raw, $mime, $access_jwt );
        }

        if ( is_wp_error( $blob ) ) return $blob;

        $this->logger->info( 'Video uploaded successfully.', [ 'attachment_id' => $attachment_id ] );
        return [ 'blob' => $blob, 'aspectRatio' => $aspect ];
    }

    /**
     * Recommended method: upload to video.bsky.app and poll for the processed blob.
     */
    private function upload_via_video_service( string $raw, string $mime, string $name, string $access_jwt, string $did ): array|\WP_Error {
        // Step 1: Get a PDS-scoped service token
        $pds_host = rtrim( (string) $this->options->get( 'pds_host', 'https://bsky.social' ), '/' );
        // Derive the PDS DID: for bsky.social it is did:web:bsky.social
        $host_only = preg_replace( '#^https?://#', '', $pds_host );
        $pds_did   = 'did:web:' . $host_only;

        $service_token = $this->auth->get_service_auth( $access_jwt, $pds_did );
        if ( is_wp_error( $service_token ) ) {
            $this->logger->error( 'Could not get service auth token: ' . $service_token->get_error_message() );
            return $service_token;
        }
        $this->logger->debug( 'Service auth token obtained.' );

        // Step 2: Upload to video.bsky.app
        $upload_url = add_query_arg( [ 'did' => $did, 'name' => rawurlencode( $name ) ], self::VIDEO_SERVICE . '/xrpc/app.bsky.video.uploadVideo' );
        $this->logger->debug( 'Uploading video to video service.', [ 'url' => $upload_url, 'bytes' => strlen( $raw ) ] );

        $response = wp_remote_post( $upload_url, [
            'headers' => [
                'Authorization'  => 'Bearer ' . $service_token,
                'Content-Type'   => $mime,
                'Content-Length' => (string) strlen( $raw ),
            ],
            'body'    => $raw,
            'timeout' => 180, // large file upload
        ] );

        if ( is_wp_error( $response ) ) {
            $this->logger->error( 'Video upload request failed: ' . $response->get_error_message() );
            return $response;
        }

        $code       = wp_remote_retrieve_response_code( $response );
        $job_status = json_decode( wp_remote_retrieve_body( $response ), true );
        $this->logger->debug( 'Video upload response.', [ 'status' => $code, 'body' => $job_status ] );

        if ( $code < 200 || $code >= 300 ) {
            $msg = $job_status['message'] ?? ( 'Video upload HTTP ' . $code );
            $this->logger->error( 'Video upload HTTP error: ' . $msg );
            return new \WP_Error( 'ctb_video_upload_error', $msg );
        }

        // If blob already came back immediately, use it
        if ( ! empty( $job_status['blob'] ) ) {
            $this->logger->info( 'Video blob returned immediately (already processed).' );
            return $job_status['blob'];
        }

        $job_id = $job_status['jobId'] ?? null;
        if ( ! $job_id ) return new \WP_Error( 'ctb_no_job_id', 'No jobId returned from video service.' );

        // Step 3: Poll for completion
        return $this->poll_job_status( $job_id );
    }

    /**
     * Poll video.bsky.app/xrpc/app.bsky.video.getJobStatus until blob is ready.
     */
    private function poll_job_status( string $job_id ): array|\WP_Error {
        $poll_url  = self::VIDEO_SERVICE . '/xrpc/app.bsky.video.getJobStatus';
        $deadline  = time() + self::POLL_MAX_SECONDS;
        $attempts  = 0;

        $this->logger->info( 'Polling video job status.', [ 'job_id' => $job_id ] );

        while ( time() < $deadline ) {
            sleep( self::POLL_INTERVAL );
            $attempts++;

            $result = $this->http->get_json( add_query_arg( 'jobId', $job_id, $poll_url ) );
            if ( is_wp_error( $result ) ) {
                $this->logger->error( 'Poll request failed.', [ 'attempt' => $attempts, 'error' => $result->get_error_message() ] );
                continue;
            }

            $state    = $result['jobStatus']['state']    ?? '';
            $progress = $result['jobStatus']['progress'] ?? '';
            $blob     = $result['jobStatus']['blob']     ?? null;

            $this->logger->debug( 'Video job poll.', [ 'attempt' => $attempts, 'state' => $state, 'progress' => $progress ] );

            // already_exists error still returns the blob
            if ( $blob ) {
                $this->logger->info( 'Video processing complete.', [ 'attempts' => $attempts ] );
                return $blob;
            }

            if ( $state === 'JOB_STATE_FAILED' ) {
                $error = $result['jobStatus']['error'] ?? 'Video processing failed.';
                $this->logger->error( 'Video job failed.', [ 'error' => $error ] );
                return new \WP_Error( 'ctb_video_processing_failed', $error );
            }
        }

        $this->logger->error( 'Video processing timed out.', [ 'job_id' => $job_id, 'attempts' => $attempts ] );
        return new \WP_Error( 'ctb_video_timeout', 'Video processing timed out after ' . self::POLL_MAX_SECONDS . ' seconds.' );
    }

    /**
     * Simple fallback: upload directly to PDS via uploadBlob (video processes after post).
     */
    private function upload_simple( string $raw, string $mime, string $access_jwt ): array|\WP_Error {
        $host   = rtrim( (string) $this->options->get( 'pds_host', 'https://bsky.social' ), '/' );
        $this->logger->debug( 'Simple video upload to PDS uploadBlob.' );
        $result = $this->http->post_binary(
            $host . '/xrpc/com.atproto.repo.uploadBlob',
            $raw, $mime,
            [ 'Authorization' => 'Bearer ' . $access_jwt ],
            180
        );
        if ( is_wp_error( $result ) ) return $result;
        if ( empty( $result['blob'] ) ) return new \WP_Error( 'ctb_blob_empty', 'No blob returned from uploadBlob.' );
        $this->logger->info( 'Simple video upload succeeded (video will process after post).' );
        return $result['blob'];
    }

    /**
     * Get aspect ratio from WP video metadata.
     * Falls back to 16:9 if unavailable.
     */
    private function get_aspect_ratio( int $attachment_id, string $file ): array {
        // Use WP's built-in video metadata reader
        if ( ! function_exists( 'wp_read_video_metadata' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
        $meta = wp_read_video_metadata( $file );
        if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
            $this->logger->debug( 'Aspect ratio from metadata.', [ 'width' => $meta['width'], 'height' => $meta['height'] ] );
            return [ 'width' => (int) $meta['width'], 'height' => (int) $meta['height'] ];
        }
        // Also try stored WP attachment metadata
        $wp_meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $wp_meta['width'] ) && ! empty( $wp_meta['height'] ) ) {
            return [ 'width' => (int) $wp_meta['width'], 'height' => (int) $wp_meta['height'] ];
        }
        $this->logger->debug( 'Could not determine aspect ratio; using 16:9 fallback.', [ 'attachment_id' => $attachment_id ] );
        return [ 'width' => 1920, 'height' => 1080 ]; // 16:9 fallback
    }

    private function download_to_temp( string $url ): ?string {
        $response = wp_remote_get( $url, [ 'timeout' => 60 ] );
        if ( is_wp_error( $response ) ) return null;
        $body = wp_remote_retrieve_body( $response );
        if ( ! $body ) return null;
        $tmp = wp_tempnam();
        file_put_contents( $tmp, $body );
        return $tmp;
    }
}

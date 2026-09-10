<?php
namespace CTB\Api;
use CTB\Core\Options;
use CTB\Core\Logger;
class Auth {
    public function __construct( private Options $options, private HttpClient $http, private Logger $logger ) {}

    public function create_session() {
        $identifier = trim( (string) $this->options->get( 'identifier', '' ) );
        $password   = trim( (string) $this->options->get( 'app_password', '' ) );
        $host       = rtrim( (string) $this->options->get( 'pds_host', 'https://bsky.social' ), '/' );
        if ( ! $identifier || ! $password ) {
            return new \WP_Error( 'ctb_no_creds', 'Bluesky identifier or app password not configured. Go to Settings → Crosspost to Bluesky.' );
        }
        $this->logger->debug( 'Creating Bluesky session.', [ 'identifier' => $identifier ] );
        return $this->http->post_json(
            $host . '/xrpc/com.atproto.server.createSession',
            [ 'identifier' => $identifier, 'password' => $password ]
        );
    }

    /**
     * Create a service auth token for the video service.
     * Audience must be the PDS DID (did:web:bsky.social or custom PDS host).
     */
    public function get_service_auth( string $access_jwt, string $pds_did ): string|\WP_Error {
        $host   = rtrim( (string) $this->options->get( 'pds_host', 'https://bsky.social' ), '/' );
        $expiry = (int) ( time() + 30 * 60 ); // 30 minutes
        $result = $this->http->post_json(
            $host . '/xrpc/com.atproto.server.getServiceAuth',
            [ 'aud' => $pds_did, 'lxm' => 'com.atproto.repo.uploadBlob', 'exp' => $expiry ],
            [ 'Authorization' => 'Bearer ' . $access_jwt ]
        );
        if ( is_wp_error( $result ) ) return $result;
        if ( empty( $result['token'] ) ) return new \WP_Error( 'ctb_no_service_token', 'No service auth token returned.' );
        return $result['token'];
    }
}

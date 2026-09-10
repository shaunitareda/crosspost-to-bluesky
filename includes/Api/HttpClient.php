<?php
namespace CTB\Api;
use CTB\Core\Logger;
class HttpClient {
    public function __construct( private Logger $logger ) {}

    public function post_json( string $url, array $body, array $headers = [] ) {
        $this->logger->debug( 'HTTP POST JSON', [ 'url' => $url ] );
        $response = wp_remote_post( $url, [
            'headers' => array_merge( [ 'Content-Type' => 'application/json; charset=utf-8' ], $headers ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ] );
        return $this->parse( $response, $url );
    }

    public function post_binary( string $url, string $data, string $mime, array $headers = [], int $timeout = 120 ) {
        $this->logger->debug( 'HTTP POST binary', [ 'url' => $url, 'mime' => $mime, 'bytes' => strlen( $data ) ] );
        $response = wp_remote_post( $url, [
            'headers' => array_merge( [ 'Content-Type' => $mime ], $headers ),
            'body'    => $data,
            'timeout' => $timeout,
        ] );
        return $this->parse( $response, $url );
    }

    public function get_json( string $url, array $headers = [] ) {
        $this->logger->debug( 'HTTP GET', [ 'url' => $url ] );
        $response = wp_remote_get( $url, [ 'headers' => $headers, 'timeout' => 30 ] );
        return $this->parse( $response, $url );
    }

    private function parse( $response, string $url ) {
        if ( is_wp_error( $response ) ) {
            $this->logger->error( 'HTTP WP_Error: ' . $response->get_error_message(), [ 'url' => $url ] );
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $this->logger->debug( 'HTTP response', [ 'url' => $url, 'status' => $code, 'body' => $this->redact_sensitive( $body ) ] );
        if ( $code < 200 || $code >= 300 ) {
            $msg = $body['message'] ?? ( 'HTTP ' . $code );
            $this->logger->error( 'HTTP error: ' . $msg, [ 'url' => $url, 'status' => $code ] );
            return new \WP_Error( 'ctb_http_error', $msg, [ 'status' => $code ] );
        }
        return is_array( $body ) ? $body : [];
    }

    private function redact_sensitive( $value ) {
        if ( ! is_array( $value ) ) return $value;
        $sensitive = [
            'password', 'app_password', 'token', 'access_token', 'refresh_token',
            'accessjwt', 'refreshjwt', 'jwt', 'client_secret', 'authorization',
        ];
        $clean = [];
        foreach ( $value as $key => $item ) {
            $normalized = strtolower( str_replace( [ '-', '_' ], '', (string) $key ) );
            $blocked = false;
            foreach ( $sensitive as $name ) {
                if ( $normalized === strtolower( str_replace( [ '-', '_' ], '', $name ) ) ) {
                    $blocked = true;
                    break;
                }
            }
            $clean[ $key ] = $blocked ? '[REDACTED]' : $this->redact_sensitive( $item );
        }
        return $clean;
    }
}

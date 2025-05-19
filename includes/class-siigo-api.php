<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Siigo_API {

    private $client_id;
    private $client_secret;
    private $base_url;
    private $access_token;
    private $token_expires;

    public function __construct() {
        $opts = get_option( 'siigo_sync_settings', array() );
        $this->client_id     = $opts['client_id']     ?? '';
        $this->client_secret = $opts['client_secret'] ?? '';
        $this->base_url      = rtrim( $opts['base_url'] ?? '', '/' );
    }

    private function authenticate() {
        if ( $this->access_token && time() < $this->token_expires ) {
            return;
        }

        $response = wp_remote_post( $this->base_url . '/auth/oauth2/token', array(
            'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
            'body'    => http_build_query([
                'client_id'     => $this->client_id,
                'client_secret' => $this->client_secret,
                'grant_type'    => 'client_credentials',
            ]),
        ) );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'Siigo auth error: ' . $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['access_token'] ) ) {
            throw new Exception( 'Invalid auth response from Siigo.' );
        }

        $this->access_token  = $data['access_token'];
        $this->token_expires = time() + ( $data['expires_in'] ?? 3600 );
    }

    private function request( $method, $endpoint, $body = null ) {
        $this->authenticate();

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type'  => 'application/json',
            ],
        ];
        if ( $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $resp = wp_remote_request( $this->base_url . $endpoint, $args );
        if ( is_wp_error( $resp ) ) {
            throw new Exception( 'Siigo API error: ' . $resp->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $resp );
        $data = json_decode( wp_remote_retrieve_body( $resp ), true );

        if ( $code < 200 || $code >= 300 ) {
            throw new Exception( "Siigo API ({$code}): " . wp_json_encode( $data ) );
        }

        return $data;
    }

    public function create_invoice( $payload ) {
        return $this->request( 'POST', '/invoices', $payload );
    }

    public function update_inventory( $product_code, $stock ) {
        return $this->request( 'PUT', "/inventory/{$product_code}", [ 'stock' => $stock ] );
    }

    public function get_inventory( $product_code ) {
        return $this->request( 'GET', "/inventory/{$product_code}" );
    }
}

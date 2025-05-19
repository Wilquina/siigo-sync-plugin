<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Siigo_API {
    private $client_id;
    private $client_secret;
    private $access_token;
    private $token_expires;

    public function __construct() {
        $opts = get_option( 'siigo_sync_settings', [] );
        $this->client_id     = $opts['client_id']     ?? '';
        $this->client_secret = $opts['client_secret'] ?? '';
    }

    private function authenticate() {
        if ( ! empty( $this->access_token ) && time() < $this->token_expires ) {
            return;
        }

        $response = wp_remote_post( SIIGO_API_BASE_URL . '/auth', [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode([
                'username'   => $this->client_id,
                'access_key' => $this->client_secret,
            ]),
        ] );

        if ( is_wp_error( $response ) ) {
            throw new Exception( 'Siigo auth error: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || empty( $data['access_token'] ) ) {
            throw new Exception( 'Invalid auth response: ' . wp_json_encode( $data ) );
        }

        $this->access_token  = $data['access_token'];
        $this->token_expires = time() + 3600;
    }

    private function request( $method, $endpoint, $body = null ) {
        $this->authenticate();

        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => $this->access_token,
                'Partner-Id'    => SIIGO_PARTNER_ID,
                'Content-Type'  => 'application/json',
            ],
        ];
        if ( $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $resp = wp_remote_request( SIIGO_API_BASE_URL . $endpoint, $args );
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

    public function get_products() {
        return $this->request( 'GET', '/v1/products' );
    }

    public function get_product_by_code( $code ) {
        $all = $this->get_products();
        if ( is_array( $all ) ) {
            foreach ( $all as $p ) {
                if ( isset( $p['code'] ) && $p['code'] === $code ) {
                    return $p;
                }
            }
        }
        return null;
    }

    public function create_product( $data ) {
        return $this->request( 'POST', '/v1/products', $data );
    }

    public function update_product( $id, $data ) {
        return $this->request( 'PUT', "/v1/products/{$id}", $data );
    }

    public function update_inventory( $id, $stock ) {
        return $this->update_product( $id, [ 'stock' => $stock ] );
    }
}

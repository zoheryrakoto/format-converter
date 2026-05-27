<?php 

/**
 * Plugin Name: Format Converter
 * Plugin URI: https://github.com/zoheryrakoto/format-converter
 * Description: Convert a format and expose on an API endpoint.
 * Version: 1.0.0
 * Author: Albert Rakoto
 * Author URI: https://github.com/zoheryrakoto
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: format-converter
 * Domain Path: /languages
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

// Define plugin constants
define( 'FORMAT_CONVERTER_VERSION', '1.0.0' );
define( 'FORMAT_CONVERTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORMAT_CONVERTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Main plugin class 
if ( ! class_exists( 'Format_Converter' ) ) {
  
  class Format_Converter 
  {

    public function __construct() {
      add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
      add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function load_textdomain() {
      load_plugin_textdomain(
        'format-converter',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
      );
    }

    public function register_routes() {
      register_rest_route(
        'format-converter/v1',
        '/esf-responses',
        array(
          'methods' => WP_REST_Server::READABLE,
          'callback' => array( $this, 'get_esf_responses' ),
          'permission_callback' => array( $this, 'check_permission' ),
          'args' => array(
            'per_page' => array(
              'default' => 10,
              'sanitize_callback' => 'absint',
            ),
            'last_entry' => array(
              'default' => 0,
              'sanitize_callback' => 'absint',
            ),
            'last_import' => array(
              'default' => '1970-01-01 00:00:00',
              'sanitize_callback' => 'sanitize_text_field',
            )
          )
        )
      );
    }

    public function get_esf_responses( WP_REST_Request $request ) {
      global $wpdb;

      $per_page = $request->get_param( 'per_page' );
      $last_entry = $request->get_param( 'last_entry' );
      $last_import = $request->get_param( 'last_import' );
      $table = $wpdb->prefix . 'esf_responses';

      $results = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT * FROM {$table} 
          WHERE ( id > %d AND submitted_at = %s )
            OR ( submitted_at > %s )
          ORDER BY submitted_at ASC
          LIMIT %d",
          $last_entry,
          $last_import,
          $last_import,
          $per_page
        ),
        ARRAY_A
      );

      if ( $wpdb->last_error ) {
        return new WP_Error(
          'db_error',
          $wpdb->last_error,
          array( 'status' => 500 )
        );
      }

      $converted = array_map( array( $this, 'convert_responses' ), $results );

      $response = rest_ensure_response( $converted );
      $response->header( 'X-WP-Total', count( $converted ) );

      return $response;
    }

    private function convert_responses( array $row ) {
      $response_data = json_decode( $row['response_data'], true );

      $raw_data_blob = json_encode( array(
        'alg' => 'hybrid-aes-rsa-v1',
        'encrypted_key' => $response_data['wrappedKey'] ?? '',
        'iv' => $response_data['iv'] ?? '',
        'ciphertext' => $response_data['ciphertext'] ?? '',
      ) );

      return array(
        'source_entry_id' => $row['id'],
        'raw_data_blob' => $raw_data_blob,
        'created_at' => $row['submitted_at'],
      );
    }

    public function check_permission() {
      $api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
      $valid_api_key = '18a4c9645245ad9ad6edc0b74c848f218445a45a1a83aba6f4baf2bb50c258eb';

      if ( ! hash_equals( $valid_api_key, $api_key ) ) { 
        return new WP_Error(
          'rest_forbidden',
          __( 'Invalid or missing API key.', 'format-converter' ),
          array( 'status' => 401 )
        );
      }

      return true;
    }
  } 

  new Format_Converter();
}
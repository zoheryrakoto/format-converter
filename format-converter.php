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
          'methods' => 'GET, POST',
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

      register_rest_route(
        'format-converter/v1',
        '/esf-campaigns/(?P<id>\d+)/field-structure',
        array(
          'methods' => 'GET',
          'callback' => array( $this, 'export_field_structure' ),
          'permission_callback' => array( $this, 'allow_public' ),
          'args' => array(
            'id' => array(
              'required' => true,
              'sanitize_callback' => 'absint',
            )
          )
        )
      );

      register_rest_route(
        'format-converter/v1',
        '/esf-campaigns/(?P<id>\d+)/field-mapping',
        array(
          'methods' => 'GET',
          'callback' => array( $this, 'export_field_mapping' ),
          'permission_callback' => array( $this, 'allow_public' ),
          'args' => array(
            'id' => array(
              'required' => true,
              'sanitize_callback' => 'absint',
            ),
          ),
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

    public function export_field_structure( WP_REST_Request $request ) {
      global $wpdb;

      $campaign_id = $request->get_param( 'id' );
      $table = $wpdb->prefix . 'esf_campaigns';

      $campaign = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT * FROM {$table} WHERE id = %d", 
          $campaign_id
        ),
        ARRAY_A
      );

      if ( $wpdb->last_error ) {
        return new WP_Error( 'db_error', $wpdb->last_error, array( 'status' => 500 ) );
      }

      if ( ! $campaign ) {
        return new WP_Error( 'not_found', __( 'Campaign not found.', 'format-converter' ), array( 'status' => 404 ) );
      }

      $raw_fields = json_decode( $campaign['field_structure'], true );

      if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $raw_fields ) ) {
        return new WP_Error( 'invalid_structure', __( 'Invalid field structure.', 'format-converter' ), array( 'status' => 500 ) );
      }

      $converted = array_map( array( $this, 'convert_field' ), $raw_fields );
      $converted = array_filter($converted, function($item){ return isset($item['name']); });
      $converted = array_values($converted);
      $json = wp_json_encode( $converted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

      $filename = sanitize_file_name( ( $campaign['name'] ?? 'campaign-' . $campaign_id ) . '-fields.json' );

      header( 'Content-Type: application/json' );
      header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
      header( 'Content-Length: ' . strlen( $json ) );

      echo $json;
      exit;
    }

    public function export_field_mapping( WP_REST_Request $request ) {
      global $wpdb;

      $campaign_id = $request->get_param( 'id' );
      $table = $wpdb->prefix . 'esf_campaigns';

      $campaign = $wpdb->get_row(
        $wpdb->prepare(
          "SELECT * FROM {$table} WHERE id = %d",
          $campaign_id
        ),
        ARRAY_A
      );

      if ( $wpdb->last_error ) {
        return new WP_Error( 'db_error', $wpdb->last_error, array( 'status' => 500 ) );
      }

      if ( ! $campaign ) {
        return new WP_Error( 'not_found', __( 'Campaign not found.', 'format-converter' ), array( 'status' => 404 ) );
      }

      $raw_fields = json_decode( $campaign['field_structure'], true );

      if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $raw_fields ) ) {
        return new WP_Error( 'invalid_structure', __( 'Invalid field structure.', 'format-converter' ), array( 'status' => 500 ) );
      }

      $mapping = array();
      foreach ( $raw_fields as $field ) {
        $name = $field['name'] ?? $field['id'] ?? '';
        if ( empty( $name ) ) {
          continue;
        }
        $mapping[] = array(
          'system' => $name,
          'source' => $name,
        );
      }

      $json = wp_json_encode( $mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
      $filename = sanitize_file_name( ( $campaign['name'] ?? 'campaign-' . $campaign_id ) . '-mapping.json' );

      header( 'Content-Type: application/json' );
      header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
      header( 'Content-Length: ' . strlen( $json ) );

      echo $json;
      exit;
    }

    private function convert_field( array $field ): array {
      $type_map = array(
        'text' => 'text',
        'email' => 'email',
        'textarea' => 'textarea',
        'radio' => 'radio',
        'checkbox' => 'checkbox',
        'select' => 'select',
        'number' => 'number',
        'date' => 'date',
      );

      $type = ! empty( $field['hidden'] )
        ? 'hidden'
        : $type_map[ $field['type'] ?? 'text' ] ?? 'text';

      // Determine views: fields marked as list_visible appear in both views
      $views = array( 'single', 'list' );

      $name = $field['name'] ?? $field['id'] ?? '';

      $converted = array(
        'label' => $field['label'] ?? '',
        'type' => $type,
        'required' => (bool) ( $field['required'] ?? false ),
        'views' => $views,
      );

      if ( ! empty( $name ) ) {
        $converted = array_merge( array( 'name' => $name ), $converted );
      }

      // Map options for radio/checkbox/select 
      if ( in_array( $type, array( 'radio', 'checkbox', 'select' ), true ) && ! empty( $field['options'] ) ) {
        $converted['options'] = array_map( function( $opt ) {
          if ( is_array( $opt ) ) {
            return array(
              'label' => $opt['label'] ?? $opt['value'] ?? '',
              'value' => $opt['value'] ?? '',
            );
          }

          return array( 'label' => $opt, 'value' => $opt );
        }, $field['options'] );
      }

      return $converted;
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

    public function allow_public() {
      return true;
    }
  } 

  new Format_Converter();
}
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
    }

    public function load_textdomain() {
      load_plugin_textdomain(
        'format-converter',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages'
      );
    }
  } 

  new Format_Converter();
}
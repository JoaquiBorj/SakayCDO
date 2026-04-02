<?php
/**
 * Plugin Name: Philippines Map Path Manager
 * Description: Create and manage custom path buttons for Philippines map with interactive admin interface.
 * Version: 1.0.0
 * Author: You
 */

if (!defined('ABSPATH')) { exit; }

require_once plugin_dir_path(__FILE__) . 'includes/class-phmap-plugin.php';

new PHMapPlugin(__FILE__);
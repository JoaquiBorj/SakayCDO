<?php

if (!defined('ABSPATH')) { exit; }

class PHMapPlugin {
    private $table_name;
    private $places_table;
    private $route_waypoints_table;
    private $schema_version_option = 'ph_map_normalized_schema_version';
    private $plugin_dir;
    private $plugin_url;
    private $assets_version;
    private $should_enqueue_frontend_assets = false;

    public function __construct($plugin_file = '') {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ph_map_buttons';
        $this->places_table = $wpdb->prefix . 'ph_map_places';
        $this->route_waypoints_table = $wpdb->prefix . 'ph_map_route_waypoints';

        $plugin_file = $plugin_file !== '' ? $plugin_file : __FILE__;
        $this->plugin_dir = plugin_dir_path($plugin_file);
        $this->plugin_url = plugin_dir_url($plugin_file);
        $this->assets_version = defined('WP_DEBUG') && WP_DEBUG ? (string)time() : '1.0.0';
        
        register_activation_hook($plugin_file, [$this, 'activate']);
        register_deactivation_hook($plugin_file, [$this, 'deactivate']);
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_ph_map_save_button', [$this, 'save_button']);
        add_action('admin_post_ph_map_delete_button', [$this, 'delete_button']);
        add_action('wp_ajax_ph_map_update_button_order', [$this, 'update_button_order']);
        add_shortcode('ph_map', [$this, 'render_shortcode']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        
        // Check and update table schema on admin pages
        add_action('admin_init', [$this, 'check_table_schema']);
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_ph-map-buttons') {
            return;
        }

        wp_enqueue_style('phmap-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('phmap-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);

        wp_enqueue_style('phmap-admin', $this->plugin_url . 'assets/css/admin.css', [], $this->assets_version);
        wp_enqueue_script('phmap-admin-map', $this->plugin_url . 'assets/js/admin-map.js', ['phmap-leaflet'], $this->assets_version, true);
        wp_enqueue_script('phmap-admin-sort', $this->plugin_url . 'assets/js/admin-sort.js', ['jquery', 'jquery-ui-sortable'], $this->assets_version, true);
    }

    public function enqueue_frontend_assets() {
        if (!$this->should_enqueue_frontend_assets && !$this->current_request_has_map_shortcode()) {
            return;
        }

        wp_enqueue_style('phmap-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', [], '1.9.4');
        wp_enqueue_script('phmap-leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);

        wp_enqueue_style('phmap-frontend', $this->plugin_url . 'assets/css/frontend.css', [], $this->assets_version);
        wp_enqueue_script('phmap-frontend-map', $this->plugin_url . 'assets/js/frontend-map.js', ['phmap-leaflet'], $this->assets_version, true);
    }

    private function current_request_has_map_shortcode() {
        if (is_admin() || !is_singular()) {
            return false;
        }

        $post = get_post();
        if (!$post || !isset($post->post_content)) {
            return false;
        }

        return has_shortcode((string)$post->post_content, 'ph_map');
    }

    public function activate() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            label varchar(255) NOT NULL,
            from_location varchar(255) NULL,
            to_location varchar(255) NULL,
            origin_place_id bigint(20) unsigned NULL,
            destination_place_id bigint(20) unsigned NULL,
            variant_code varchar(30) NULL,
            sub_label varchar(120) NULL,
            canonical_label varchar(255) NULL,
            migration_notes text NULL,
            description text NULL,
            waypoints longtext NOT NULL,
            route_data longtext NOT NULL,
            is_loop tinyint(1) NOT NULL DEFAULT 0,
            direction varchar(20) NOT NULL DEFAULT 'inbound',
            color varchar(7) NOT NULL DEFAULT '#ff2f6d',
            route_type varchar(20) NOT NULL DEFAULT 'transportation',
            multiple_paths longtext NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL,
            PRIMARY KEY (id)
        ) $charset_collate;";

        $places_sql = "CREATE TABLE IF NOT EXISTS $this->places_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            place_type varchar(50) NOT NULL DEFAULT 'general',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_place_name (name)
        ) $charset_collate;";

        $route_waypoints_sql = "CREATE TABLE IF NOT EXISTS $this->route_waypoints_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            route_id mediumint(9) NOT NULL,
            path_group varchar(50) NOT NULL DEFAULT 'inbound',
            direction varchar(20) NOT NULL DEFAULT 'inbound',
            waypoint_name varchar(255) NULL,
            lat decimal(10,7) NOT NULL,
            lng decimal(10,7) NOT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY route_direction_sort (route_id, direction, sort_order)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($places_sql);
        dbDelta($route_waypoints_sql);
        
        // Force schema check
        $this->check_table_schema();
    }

    public function check_table_schema() {
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        
        if (!$table_exists) {
            error_log('Table does not exist, creating: ' . $this->table_name);
            $this->activate();
            return;
        }
        
        // Ensure dependent normalized tables exist
        $this->ensure_places_table();
        $this->ensure_route_waypoints_table();

        // Check if required columns exist
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name}");
        $column_names = array_column($columns, 'Field');
        
        $required_columns = [
            'id',
            'label',
            'from_location',
            'to_location',
            'origin_place_id',
            'destination_place_id',
            'variant_code',
            'sub_label',
            'canonical_label',
            'migration_notes',
            'description',
            'waypoints',
            'route_data',
            'is_loop',
            'direction',
            'color',
            'route_type',
            'multiple_paths',
            'sort_order',
            'created_at',
            'updated_at'
        ];
        $missing_columns = array_diff($required_columns, $column_names);
        
        if (!empty($missing_columns)) {
            error_log('Missing columns in table ' . $this->table_name . ': ' . implode(', ', $missing_columns));
            $this->update_table_schema($missing_columns);
        }

        // Backfill normalized columns and waypoint relationships once per schema version.
        if ((int)get_option($this->schema_version_option, 0) < 1) {
            $this->migrate_existing_routes_to_normalized_schema();
            update_option($this->schema_version_option, 1, false);
        }
    }

    private function update_table_schema($missing_columns) {
        global $wpdb;
        
        foreach ($missing_columns as $column) {
            switch ($column) {
                case 'origin_place_id':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN origin_place_id BIGINT(20) UNSIGNED NULL";
                    break;
                case 'destination_place_id':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN destination_place_id BIGINT(20) UNSIGNED NULL";
                    break;
                case 'variant_code':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN variant_code VARCHAR(30) NULL";
                    break;
                case 'sub_label':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN sub_label VARCHAR(120) NULL";
                    break;
                case 'canonical_label':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN canonical_label VARCHAR(255) NULL";
                    break;
                case 'migration_notes':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN migration_notes TEXT NULL";
                    break;
                case 'from_location':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN from_location VARCHAR(255) NULL";
                    break;
                case 'to_location':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN to_location VARCHAR(255) NULL";
                    break;
                case 'description':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN description TEXT NULL";
                    break;
                case 'waypoints':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN waypoints LONGTEXT NOT NULL";
                    break;
                case 'route_data':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN route_data LONGTEXT NOT NULL";
                    break;
                case 'is_loop':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN is_loop TINYINT(1) NOT NULL DEFAULT 0";
                    break;
                case 'direction':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN direction VARCHAR(20) NOT NULL DEFAULT 'inbound'";
                    break;
                case 'color':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN color VARCHAR(7) NOT NULL DEFAULT '#ff2f6d'";
                    break;
                case 'route_type':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN route_type VARCHAR(20) NOT NULL DEFAULT 'transportation'";
                    break;
                case 'multiple_paths':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN multiple_paths LONGTEXT NULL";
                    break;
                case 'sort_order':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN sort_order INT(11) NOT NULL DEFAULT 0";
                    break;
                case 'created_at':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN created_at DATETIME DEFAULT CURRENT_TIMESTAMP";
                    break;
                case 'updated_at':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN updated_at DATETIME NULL";
                    break;
                case 'label':
                    $sql = "ALTER TABLE {$this->table_name} ADD COLUMN label VARCHAR(255) NOT NULL";
                    break;
                default:
                    continue 2; // Skip unknown columns
            }
            
            $result = $wpdb->query($sql);
            if ($result === false) {
                error_log('Failed to add column ' . $column . ' to table ' . $this->table_name . ': ' . $wpdb->last_error);
            } else {
                error_log('Successfully added column ' . $column . ' to table ' . $this->table_name);
                
                // Initialize values for existing records
                if ($column === 'sort_order') {
                    $wpdb->query("UPDATE {$this->table_name} SET sort_order = id WHERE sort_order = 0");
                } elseif ($column === 'route_type') {
                    $wpdb->query("UPDATE {$this->table_name} SET route_type = 'transportation' WHERE route_type = ''");
                }
            }
        }
    }

    private function ensure_places_table() {
        global $wpdb;

        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->places_table}'");
        if ($exists) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $this->places_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            place_type varchar(50) NOT NULL DEFAULT 'general',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_place_name (name)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function ensure_route_waypoints_table() {
        global $wpdb;

        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->route_waypoints_table}'");
        if ($exists) {
            return;
        }

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $this->route_waypoints_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            route_id mediumint(9) NOT NULL,
            path_group varchar(50) NOT NULL DEFAULT 'inbound',
            direction varchar(20) NOT NULL DEFAULT 'inbound',
            waypoint_name varchar(255) NULL,
            lat decimal(10,7) NOT NULL,
            lng decimal(10,7) NOT NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY route_direction_sort (route_id, direction, sort_order)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function normalize_place_name($name) {
        $name = trim((string)$name);
        if ($name === '') {
            return '';
        }

        $name = preg_replace('/\s+/', ' ', $name);
        return $name;
    }

    private function normalize_variant_code($variant_code) {
        $variant_code = strtoupper(trim((string)$variant_code));
        return preg_replace('/\s+/', '', $variant_code);
    }

    private function build_generated_route_label($origin_name, $destination_name, $variant_code = '', $sub_label = '') {
        $origin_name = $this->normalize_place_name($origin_name);
        $destination_name = $this->normalize_place_name($destination_name);
        $variant_code = $this->normalize_variant_code($variant_code);
        $sub_label = trim((string)$sub_label);

        if ($origin_name === '' || $destination_name === '') {
            return '';
        }

        if ($variant_code !== '' && $sub_label !== '') {
            return sprintf('%s %s %s - %s', $origin_name, $variant_code, $sub_label, $destination_name);
        }

        if ($variant_code !== '') {
            return sprintf('%s %s - %s', $origin_name, $variant_code, $destination_name);
        }

        return sprintf('%s - %s', $origin_name, $destination_name);
    }

    private function parse_legacy_route_label($legacy_label) {
        $legacy_label = trim((string)$legacy_label);
        $result = [
            'origin_name' => '',
            'destination_name' => '',
            'variant_code' => '',
            'sub_label' => '',
            'manual_review' => false,
            'reason' => ''
        ];

        if ($legacy_label === '') {
            $result['manual_review'] = true;
            $result['reason'] = 'Empty label';
            return $result;
        }

        if (!preg_match('/^(.*?)\s*(?:-|–|—)\s*(.+)$/u', $legacy_label, $parts)) {
            $result['manual_review'] = true;
            $result['reason'] = 'Label missing expected separator';
            return $result;
        }

        $left = trim($parts[1]);
        $result['destination_name'] = $this->normalize_place_name($parts[2]);

        if (preg_match('/^(.*?)\s+([Rr]\d+[A-Za-z0-9-]*)\s*(.*)$/u', $left, $left_parts)) {
            $result['origin_name'] = $this->normalize_place_name($left_parts[1]);
            $result['variant_code'] = $this->normalize_variant_code($left_parts[2]);
            $result['sub_label'] = $this->normalize_place_name($left_parts[3]);
        } else {
            $result['origin_name'] = $this->normalize_place_name($left);
        }

        if ($result['origin_name'] === '' || $result['destination_name'] === '') {
            $result['manual_review'] = true;
            $result['reason'] = 'Could not parse origin/destination with confidence';
        }

        return $result;
    }

    private function upsert_place($name, $place_type = 'general') {
        global $wpdb;

        $name = $this->normalize_place_name($name);
        if ($name === '') {
            return 0;
        }

        $existing_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->places_table} WHERE name = %s LIMIT 1",
            $name
        ));

        if ($existing_id > 0) {
            return $existing_id;
        }

        $wpdb->insert(
            $this->places_table,
            [
                'name' => $name,
                'place_type' => sanitize_key($place_type) ?: 'general',
                'updated_at' => current_time('mysql')
            ],
            ['%s', '%s', '%s']
        );

        if ($wpdb->insert_id) {
            return (int)$wpdb->insert_id;
        }

        // In case of race condition with unique constraint.
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->places_table} WHERE name = %s LIMIT 1",
            $name
        ));
    }

    private function get_place_name_by_id($place_id) {
        global $wpdb;

        $place_id = (int)$place_id;
        if ($place_id <= 0) {
            return '';
        }

        return (string)$wpdb->get_var($wpdb->prepare(
            "SELECT name FROM {$this->places_table} WHERE id = %d LIMIT 1",
            $place_id
        ));
    }

    private function get_route_display_label($button) {
        $origin = '';
        $destination = '';

        if (isset($button->origin_name)) {
            $origin = $button->origin_name;
        } elseif (isset($button->from_location)) {
            $origin = $button->from_location;
        }

        if (isset($button->destination_name)) {
            $destination = $button->destination_name;
        } elseif (isset($button->to_location)) {
            $destination = $button->to_location;
        }

        $variant_code = isset($button->variant_code) ? $button->variant_code : '';
        $sub_label = isset($button->sub_label) ? $button->sub_label : '';
        $canonical = isset($button->canonical_label) ? trim((string)$button->canonical_label) : '';

        if ($canonical !== '') {
            return $canonical;
        }

        $generated = $this->build_generated_route_label($origin, $destination, $variant_code, $sub_label);
        if ($generated !== '') {
            return $generated;
        }

        return isset($button->label) ? (string)$button->label : '';
    }

    private function get_all_places() {
        global $wpdb;

        return $wpdb->get_results("SELECT id, name, place_type FROM {$this->places_table} ORDER BY name ASC");
    }

    private function sync_route_waypoints_table($route_id, $inbound_waypoints, $multiple_paths) {
        global $wpdb;

        $route_id = (int)$route_id;
        if ($route_id <= 0) {
            return;
        }

        $wpdb->delete($this->route_waypoints_table, ['route_id' => $route_id], ['%d']);

        $insert_waypoints = function($waypoints, $direction, $path_group) use ($wpdb, $route_id) {
            if (!is_array($waypoints)) {
                return;
            }

            foreach ($waypoints as $index => $point) {
                if (!is_array($point) || !isset($point['lat']) || !isset($point['lng'])) {
                    continue;
                }

                $lat = (float)$point['lat'];
                $lng = (float)$point['lng'];

                $wpdb->insert(
                    $this->route_waypoints_table,
                    [
                        'route_id' => $route_id,
                        'path_group' => $path_group,
                        'direction' => $direction,
                        'waypoint_name' => sprintf('%s waypoint %d', ucfirst($direction), $index + 1),
                        'lat' => $lat,
                        'lng' => $lng,
                        'sort_order' => $index + 1,
                    ],
                    ['%d', '%s', '%s', '%s', '%f', '%f', '%d']
                );
            }
        };

        $insert_waypoints($inbound_waypoints, 'inbound', 'inbound');

        if (is_array($multiple_paths)) {
            foreach ($multiple_paths as $index => $path) {
                if (!is_array($path) || !isset($path['waypoints']) || !is_array($path['waypoints'])) {
                    continue;
                }

                $path_group = isset($path['id']) && $path['id'] !== '' ? sanitize_key($path['id']) : 'outbound_' . ($index + 1);
                $insert_waypoints($path['waypoints'], 'outbound', $path_group);
            }
        }
    }

    private function migrate_existing_routes_to_normalized_schema() {
        global $wpdb;

        $routes = $wpdb->get_results("SELECT * FROM {$this->table_name}");
        if (empty($routes)) {
            return;
        }

        foreach ($routes as $route) {
            $parsed = $this->parse_legacy_route_label($route->label);

            $origin_name = $this->normalize_place_name($route->from_location ?: $parsed['origin_name']);
            $destination_name = $this->normalize_place_name($route->to_location ?: $parsed['destination_name']);

            $variant_code = $this->normalize_variant_code($route->variant_code ?: $parsed['variant_code']);
            $sub_label = $this->normalize_place_name($route->sub_label ?: $parsed['sub_label']);

            $origin_place_id = (int)$route->origin_place_id;
            if ($origin_place_id <= 0) {
                $origin_place_id = $this->upsert_place($origin_name, 'origin');
            }

            $destination_place_id = (int)$route->destination_place_id;
            if ($destination_place_id <= 0) {
                $destination_place_id = $this->upsert_place($destination_name, 'destination');
            }

            if ($origin_name === '' && $origin_place_id > 0) {
                $origin_name = $this->get_place_name_by_id($origin_place_id);
            }

            if ($destination_name === '' && $destination_place_id > 0) {
                $destination_name = $this->get_place_name_by_id($destination_place_id);
            }

            $canonical_label = $this->build_generated_route_label($origin_name, $destination_name, $variant_code, $sub_label);
            if ($canonical_label === '') {
                $canonical_label = (string)$route->label;
            }

            $migration_note = '';
            if (!empty($parsed['manual_review'])) {
                $migration_note = 'manual_review: ' . $parsed['reason'] . ' (source: ' . $route->label . ')';
            }

            $wpdb->update(
                $this->table_name,
                [
                    'from_location' => $origin_name,
                    'to_location' => $destination_name,
                    'origin_place_id' => $origin_place_id ?: null,
                    'destination_place_id' => $destination_place_id ?: null,
                    'variant_code' => $variant_code,
                    'sub_label' => $sub_label,
                    'canonical_label' => $canonical_label,
                    'migration_notes' => $migration_note,
                    'label' => $canonical_label,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => (int)$route->id]
            );

            $inbound_waypoints = json_decode((string)$route->waypoints, true);
            if (!is_array($inbound_waypoints)) {
                $inbound_waypoints = [];
            }

            $multiple_paths = json_decode((string)$route->multiple_paths, true);
            if (!is_array($multiple_paths)) {
                $multiple_paths = [];
            }

            $this->sync_route_waypoints_table((int)$route->id, $inbound_waypoints, $multiple_paths);
        }
    }

    public function deactivate() {
        // Keep data on deactivation
    }

    public function add_admin_menu() {
        add_menu_page(
            'PH Map Buttons',
            'PH Map Buttons',
            'manage_options',
            'ph-map-buttons',
            [$this, 'admin_page'],
            'dashicons-location-alt',
            30
        );
    }

    public function admin_page() {
        global $wpdb;
        
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        $button_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if ($action === 'edit' && $button_id) {
            $button = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, po.name AS origin_name, pd.name AS destination_name
                 FROM {$this->table_name} b
                 LEFT JOIN {$this->places_table} po ON po.id = b.origin_place_id
                 LEFT JOIN {$this->places_table} pd ON pd.id = b.destination_place_id
                 WHERE b.id = %d",
                $button_id
            ));
            $this->render_edit_form($button);
        } elseif ($action === 'add') {
            $this->render_edit_form();
        } else {
            $this->render_list_page();
        }
    }

    private function render_list_page() {
        global $wpdb;

        $search_query = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $origin_filter = isset($_GET['origin']) ? sanitize_text_field(wp_unslash($_GET['origin'])) : '';
        $destination_filter = isset($_GET['destination']) ? sanitize_text_field(wp_unslash($_GET['destination'])) : '';
        $variant_filter = isset($_GET['variant']) ? sanitize_text_field(wp_unslash($_GET['variant'])) : '';
        $needs_review_filter = isset($_GET['needs_review']) && $_GET['needs_review'] === '1';

        $where_clauses = ['1=1'];
        $params = [];

        if ($search_query !== '') {
            $search_like = '%' . $wpdb->esc_like($search_query) . '%';
            $where_clauses[] = "(
                b.canonical_label LIKE %s OR
                b.label LIKE %s OR
                b.from_location LIKE %s OR
                b.to_location LIKE %s OR
                b.variant_code LIKE %s OR
                b.sub_label LIKE %s OR
                b.description LIKE %s
            )";
            $params = array_merge($params, array_fill(0, 7, $search_like));
        }

        if ($origin_filter !== '') {
            $origin_like = '%' . $wpdb->esc_like($origin_filter) . '%';
            $where_clauses[] = 'COALESCE(po.name, b.from_location) LIKE %s';
            $params[] = $origin_like;
        }

        if ($destination_filter !== '') {
            $destination_like = '%' . $wpdb->esc_like($destination_filter) . '%';
            $where_clauses[] = 'COALESCE(pd.name, b.to_location) LIKE %s';
            $params[] = $destination_like;
        }

        if ($variant_filter !== '') {
            $variant_like = '%' . $wpdb->esc_like($variant_filter) . '%';
            $where_clauses[] = 'b.variant_code LIKE %s';
            $params[] = $variant_like;
        }

        if ($needs_review_filter) {
            $where_clauses[] = "(b.migration_notes IS NOT NULL AND b.migration_notes <> '')";
        }

        $has_filters = ($search_query !== '' || $origin_filter !== '' || $destination_filter !== '' || $variant_filter !== '' || $needs_review_filter);

        $sql = "SELECT b.*, po.name AS origin_name, pd.name AS destination_name
                FROM {$this->table_name} b
                LEFT JOIN {$this->places_table} po ON po.id = b.origin_place_id
                LEFT JOIN {$this->places_table} pd ON pd.id = b.destination_place_id
                WHERE " . implode(' AND ', $where_clauses) . "
                ORDER BY b.sort_order ASC, b.id ASC";

        if (!empty($params)) {
            $buttons = $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            $buttons = $wpdb->get_results($sql);
        }

        echo $this->render_template('admin-list.php', [
            'search_query' => $search_query,
            'origin_filter' => $origin_filter,
            'destination_filter' => $destination_filter,
            'variant_filter' => $variant_filter,
            'needs_review_filter' => $needs_review_filter,
            'has_filters' => $has_filters,
            'buttons' => $buttons,
        ]);
    }

    private function render_edit_form($button = null) {
        $is_edit = $button !== null;
        $title = $is_edit ? 'Edit Route' : 'Add New Route';
        $waypoints = $is_edit ? $button->waypoints : '[]';
        $route_data = $is_edit ? $button->route_data : '[]';
        $is_loop = $is_edit && isset($button->is_loop) ? (bool)$button->is_loop : false;
        $direction = $is_edit && isset($button->direction) ? $button->direction : 'inbound';
        $color = $is_edit && isset($button->color) ? $button->color : '#ff2f6d';
        $from_location = $is_edit && isset($button->from_location) ? $button->from_location : '';
        $to_location = $is_edit && isset($button->to_location) ? $button->to_location : '';
        $description = $is_edit && isset($button->description) ? $button->description : '';
        $route_type = $is_edit && isset($button->route_type) ? $button->route_type : 'transportation';
        $multiple_paths = $is_edit && isset($button->multiple_paths) ? $button->multiple_paths : '[]';
        $origin_place_id = $is_edit && isset($button->origin_place_id) ? (int)$button->origin_place_id : 0;
        $destination_place_id = $is_edit && isset($button->destination_place_id) ? (int)$button->destination_place_id : 0;
        $variant_code = $is_edit && isset($button->variant_code) ? $button->variant_code : '';
        $sub_label = $is_edit && isset($button->sub_label) ? $button->sub_label : '';

        $origin_place_name = $is_edit && !empty($button->origin_name) ? $button->origin_name : $from_location;
        $destination_place_name = $is_edit && !empty($button->destination_name) ? $button->destination_name : $to_location;
        $generated_label = $this->build_generated_route_label($origin_place_name, $destination_place_name, $variant_code, $sub_label);
        if ($generated_label === '' && $is_edit && isset($button->label)) {
            $generated_label = $button->label;
        }

        $places = $this->get_all_places();

        echo $this->render_template('admin-edit.php', [
            'button' => $button,
            'is_edit' => $is_edit,
            'title' => $title,
            'waypoints' => $waypoints,
            'route_data' => $route_data,
            'is_loop' => $is_loop,
            'direction' => $direction,
            'color' => $color,
            'from_location' => $from_location,
            'to_location' => $to_location,
            'description' => $description,
            'route_type' => $route_type,
            'multiple_paths' => $multiple_paths,
            'origin_place_id' => $origin_place_id,
            'destination_place_id' => $destination_place_id,
            'variant_code' => $variant_code,
            'sub_label' => $sub_label,
            'origin_place_name' => $origin_place_name,
            'destination_place_name' => $destination_place_name,
            'generated_label' => $generated_label,
            'places' => $places,
        ]);
    }

    private function render_template($template, array $vars = []) {
        $path = dirname(__DIR__) . '/templates/' . ltrim($template, '/');
        if (!file_exists($path)) {
            return '';
        }

        extract($vars, EXTR_SKIP);

        ob_start();
        include $path;
        return ob_get_clean();
    }

    public function save_button() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['ph_map_nonce'], 'save_button')) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        
        // Ensure table schema is correct before saving
        $this->check_table_schema();
        
        $button_id = isset($_POST['button_id']) ? intval($_POST['button_id']) : 0;
        $origin_place_id = isset($_POST['origin_place_id']) ? (int)$_POST['origin_place_id'] : 0;
        $destination_place_id = isset($_POST['destination_place_id']) ? (int)$_POST['destination_place_id'] : 0;
        $origin_place_name_input = isset($_POST['origin_place_name']) ? sanitize_text_field($_POST['origin_place_name']) : '';
        $destination_place_name_input = isset($_POST['destination_place_name']) ? sanitize_text_field($_POST['destination_place_name']) : '';
        $variant_code = isset($_POST['variant_code']) ? $this->normalize_variant_code(sanitize_text_field($_POST['variant_code'])) : '';
        $sub_label = isset($_POST['sub_label']) ? sanitize_text_field($_POST['sub_label']) : '';

        $selected_origin_name = $origin_place_id > 0 ? $this->get_place_name_by_id($origin_place_id) : '';
        $selected_destination_name = $destination_place_id > 0 ? $this->get_place_name_by_id($destination_place_id) : '';

        $from_location = $this->normalize_place_name($origin_place_name_input !== '' ? $origin_place_name_input : $selected_origin_name);
        $to_location = $this->normalize_place_name($destination_place_name_input !== '' ? $destination_place_name_input : $selected_destination_name);

        if ($origin_place_id <= 0 && $from_location !== '') {
            $origin_place_id = $this->upsert_place($from_location, 'origin');
        }
        if ($destination_place_id <= 0 && $to_location !== '') {
            $destination_place_id = $this->upsert_place($to_location, 'destination');
        }

        if ($origin_place_id > 0 && $from_location === '') {
            $from_location = $this->get_place_name_by_id($origin_place_id);
        }
        if ($destination_place_id > 0 && $to_location === '') {
            $to_location = $this->get_place_name_by_id($destination_place_id);
        }

        $canonical_label = $this->build_generated_route_label($from_location, $to_location, $variant_code, $sub_label);
        $label = $canonical_label !== '' ? $canonical_label : sanitize_text_field($_POST['label']);
        $description = isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '';
        $waypoints_raw = isset($_POST['waypoints']) ? $_POST['waypoints'] : '';
        $route_data_raw = isset($_POST['route_data']) ? $_POST['route_data'] : '';
        $multiple_paths_raw = isset($_POST['multiple_paths']) ? $_POST['multiple_paths'] : '';
        $is_loop = isset($_POST['is_loop']) ? 1 : 0;
        $direction = 'inbound'; // Always inbound for main path
        $color = isset($_POST['color']) ? sanitize_hex_color($_POST['color']) : '#ff2f6d';
        $route_type = isset($_POST['route_type']) ? sanitize_text_field($_POST['route_type']) : 'transportation';
        
        // Validate route_type
        if (!in_array($route_type, ['transportation', 'personal'])) {
            $route_type = 'transportation';
        }
        
        // Clean and validate the JSON data
        $waypoints = wp_unslash($waypoints_raw);
        $route_data = wp_unslash($route_data_raw);
        $multiple_paths = wp_unslash($multiple_paths_raw);
        
        // Debug logging
        error_log('Saving button - Label: ' . $label);
        error_log('Button ID: ' . $button_id);
        error_log('Is Loop: ' . $is_loop);
        error_log('Waypoints length: ' . strlen($waypoints));
        error_log('Route data length: ' . strlen($route_data));
        
        // Validate label
        if (empty($label)) {
            wp_die('Route label cannot be generated. Please provide valid origin and destination places.');
        }
        
        // Validate waypoints JSON
        $waypoint_array = json_decode($waypoints, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('JSON decode error: ' . json_last_error_msg());
            error_log('Problematic JSON: ' . substr($waypoints, 0, 500));
            wp_die('Invalid JSON in waypoint data: ' . json_last_error_msg() . '. Please clear your path and try again.');
        }
        
        if (!is_array($waypoint_array)) {
            error_log('Waypoint data is not an array: ' . var_export($waypoint_array, true));
            wp_die('Waypoint data is not an array. Please clear your path and try again.');
        }
        
        if (count($waypoint_array) < 2) {
            error_log('Insufficient waypoints: ' . count($waypoint_array));
            wp_die('Invalid waypoint data. Please add at least 2 waypoints. Currently have: ' . count($waypoint_array) . ' waypoints.');
        }
        
        // Validate each waypoint
        foreach ($waypoint_array as $index => $point) {
            if (!is_array($point) || !isset($point['lat']) || !isset($point['lng'])) {
                error_log('Invalid waypoint structure at index ' . $index . ': ' . var_export($point, true));
                wp_die('Invalid waypoint structure detected. Please clear your path and try again.');
            }
            
            if (!is_numeric($point['lat']) || !is_numeric($point['lng'])) {
                error_log('Invalid coordinates at index ' . $index . ': lat=' . $point['lat'] . ', lng=' . $point['lng']);
                wp_die('Invalid coordinates detected. Please clear your path and try again.');
            }
        }
        
        // Validate route data JSON (optional)
        if (!empty($route_data)) {
            $route_array = json_decode($route_data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('Route data JSON error: ' . json_last_error_msg());
                // Don't fail for route data, just clear it
                $route_data = '[]';
            }
        } else {
            $route_data = '[]';
        }
        
        // Check data sizes to prevent MySQL errors
        $waypoints_size = strlen($waypoints);
        $route_data_size = strlen($route_data);
        
        error_log('Final waypoints size: ' . $waypoints_size . ' bytes');
        error_log('Final route data size: ' . $route_data_size . ' bytes');
        
        // MySQL LONGTEXT can handle up to 4GB, but let's be reasonable
        if ($waypoints_size > 1048576) { // 1MB limit
            wp_die('Waypoint data is too large. Please reduce the number of waypoints.');
        }
        
        if ($route_data_size > 16777216) { // 16MB limit for route data
            error_log('Route data too large, clearing it');
            $route_data = '[]';
        }
        
        // Verify table structure one more time before insert/update
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name}");
        $column_names = array_column($columns, 'Field');
        error_log('Available table columns: ' . implode(', ', $column_names));
        
        $data = [
            'label' => $label,
            'from_location' => $from_location,
            'to_location' => $to_location,
            'origin_place_id' => $origin_place_id ?: null,
            'destination_place_id' => $destination_place_id ?: null,
            'variant_code' => $variant_code,
            'sub_label' => $sub_label,
            'canonical_label' => $label,
            'migration_notes' => '',
            'description' => $description,
            'waypoints' => $waypoints,
            'route_data' => $route_data,
            'multiple_paths' => $multiple_paths,
            'is_loop' => $is_loop,
            'direction' => $direction,
            'color' => $color,
            'route_type' => $route_type,
            'updated_at' => current_time('mysql')
        ];
        
        // Set sort_order for new buttons
        if (!$button_id) {
            $max_sort_order = $wpdb->get_var("SELECT MAX(sort_order) FROM {$this->table_name}");
            $data['sort_order'] = ($max_sort_order ? $max_sort_order + 1 : 1);
        }
        
        $data_sizes = array_map(function($value) {
            return strlen((string)$value);
        }, $data);
        error_log('Preparing to save data sizes: ' . var_export($data_sizes, true));
        
        if ($button_id) {
            // Check if button exists
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_name} WHERE id = %d", $button_id));
            if (!$existing) {
                wp_die('Button not found for editing.');
            }
            
            $result = $wpdb->update($this->table_name, $data, ['id' => $button_id]);
            $message = 'Route updated successfully!';
            
            if ($result === false) {
                error_log('Update failed. MySQL Error: ' . $wpdb->last_error);
                error_log('Update query: ' . $wpdb->last_query);
                wp_die('Database update error: ' . $wpdb->last_error . '. Please try again.');
            }
        } else {
            $result = $wpdb->insert($this->table_name, $data);
            $message = 'Route added successfully!';
            
            if ($result === false) {
                error_log('Insert failed. MySQL Error: ' . $wpdb->last_error);
                error_log('Insert query: ' . $wpdb->last_query);
                wp_die('Database insert error: ' . $wpdb->last_error . '. Please try again.');
            }

            $button_id = (int)$wpdb->insert_id;
        }

        $decoded_multiple_paths = json_decode($multiple_paths, true);
        if (!is_array($decoded_multiple_paths)) {
            $decoded_multiple_paths = [];
        }
        $this->sync_route_waypoints_table($button_id, $waypoint_array, $decoded_multiple_paths);
        
        error_log('Database operation successful. Result: ' . $result);
        
        wp_redirect(admin_url('admin.php?page=ph-map-buttons&message=' . urlencode($message)));
        exit;
    }

    public function delete_button() {
        $button_id = intval($_GET['id']);
        if (!current_user_can('manage_options') || !wp_verify_nonce($_GET['_wpnonce'], 'delete_button_' . $button_id)) {
            wp_die('Unauthorized');
        }
        
        global $wpdb;
        $wpdb->delete($this->table_name, ['id' => $button_id]);
        $wpdb->delete($this->route_waypoints_table, ['route_id' => $button_id], ['%d']);
        
        wp_redirect(admin_url('admin.php?page=ph-map-buttons&message=' . urlencode('Button deleted successfully!')));
        exit;
    }

    public function update_button_order() {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'ph_map_reorder')) {
            wp_die('Unauthorized');
        }
        
        $button_order = isset($_POST['button_order']) ? array_map('intval', $_POST['button_order']) : [];
        
        if (empty($button_order)) {
            wp_send_json_error('No button order provided');
            return;
        }
        
        global $wpdb;
        
        // Update sort_order for each button
        foreach ($button_order as $index => $button_id) {
            $sort_order = $index + 1; // Start from 1
            $result = $wpdb->update(
                $this->table_name,
                array('sort_order' => $sort_order),
                array('id' => $button_id),
                array('%d'),
                array('%d')
            );
            
            if ($result === false) {
                wp_send_json_error('Failed to update sort order for button ' . $button_id);
                return;
            }
        }
        
        wp_send_json_success('Button order updated successfully');
    }

    public function render_shortcode($atts = []) {
        global $wpdb;
        $this->should_enqueue_frontend_assets = true;
        $this->enqueue_frontend_assets();
        
        $atts = shortcode_atts([
            'height' => '100vh',
            'zoom' => 11,
        ], $atts, 'ph_map');

        $buttons = $wpdb->get_results(
            "SELECT b.*, po.name AS origin_name, pd.name AS destination_name
             FROM {$this->table_name} b
             LEFT JOIN {$this->places_table} po ON po.id = b.origin_place_id
             LEFT JOIN {$this->places_table} pd ON pd.id = b.destination_place_id
             ORDER BY b.sort_order ASC, b.id ASC"
        );
        
        $id = 'phmap_' . uniqid();
        $mapId = $id . '_map';
        $height = preg_replace('/[^0-9.%vhrempx]/i', '', (string)$atts['height']);
        if ($height === '') { $height = '600px'; }
        $zoom = max(3, min(18, (int)$atts['zoom']));

        $build_route_meta = function($display_label, $description, $start, $end) {
            $clean_label = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string)$display_label)));
            $clean_description = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags((string)$description)));
            $via = '';

            $start = trim((string)$start);
            $end = trim((string)$end);

            if ($start === '' || $end === '') {
                if (preg_match('/^(.*?)\s*(?:-|–|—)\s*(.+)$/u', $clean_label, $parts)) {
                    $start = trim($parts[1]);
                    $end = trim($parts[2]);
                }
            }

            if ($end !== '' && preg_match('/^(.+?)\s+(?:via)\s+(.+)$/i', $end, $end_parts)) {
                $end = trim($end_parts[1]);
                $via = trim($end_parts[2]);
            }

            if ($via === '' && preg_match('/\bvia\b\s+(.+)$/i', $clean_description, $via_parts)) {
                $via = trim($via_parts[1]);
            }

            $main_label = $clean_label;
            if ($start !== '' && $end !== '') {
                $main_label = $start . ' -> ' . $end;
            }

            $inbound_label = 'Inbound';
            $outbound_label = 'Outbound';
            if ($start !== '' && $end !== '') {
                $inbound_label = 'To ' . $end;
                $outbound_label = 'To ' . $start;
            }

            $search_text = strtolower(trim(implode(' ', array_filter([
                $clean_label,
                $main_label,
                $start,
                $end,
                $via,
                $clean_description
            ]))));

            return [
                'main_label' => $main_label,
                'via' => $via,
                'start' => $start,
                'end' => $end,
                'inbound_label' => $inbound_label,
                'outbound_label' => $outbound_label,
                'search_text' => $search_text,
            ];
        };

        $button_view_data = array_map(function($btn) use ($build_route_meta) {
            $waypoints = json_decode($btn->waypoints, true);
            if (!is_array($waypoints)) {
                $waypoints = [];
            }

            $route = json_decode($btn->route_data, true);
            if (!is_array($route)) {
                $route = [];
            }

            $multiple_paths = isset($btn->multiple_paths) ? json_decode($btn->multiple_paths, true) : [];
            if (!is_array($multiple_paths)) {
                $multiple_paths = [];
            }

            $has_inbound = count($waypoints) >= 2;
            $has_outbound = false;

            foreach ($multiple_paths as $path) {
                if (isset($path['waypoints']) && is_array($path['waypoints']) && count($path['waypoints']) >= 2) {
                    $has_outbound = true;
                    break;
                }
            }

            $description = isset($btn->description) ? $btn->description : '';
            $origin_name = !empty($btn->origin_name) ? $btn->origin_name : $btn->from_location;
            $destination_name = !empty($btn->destination_name) ? $btn->destination_name : $btn->to_location;
            $display_label = $this->get_route_display_label($btn);
            $meta = $build_route_meta($display_label, $description, $origin_name, $destination_name);

            return [
                'label' => $display_label,
                'description' => $description,
                'waypoints' => $waypoints,
                'route' => $route,
                'is_loop' => isset($btn->is_loop) ? (bool)$btn->is_loop : false,
                'direction' => isset($btn->direction) ? $btn->direction : 'inbound',
                'color' => isset($btn->color) ? $btn->color : '#ff2f6d',
                'route_type' => isset($btn->route_type) ? $btn->route_type : 'transportation',
                'multiple_paths' => $multiple_paths,
                'variant_code' => isset($btn->variant_code) ? $btn->variant_code : '',
                'sub_label' => isset($btn->sub_label) ? $btn->sub_label : '',
                'has_inbound' => $has_inbound,
                'has_outbound' => $has_outbound,
                'main_label' => $meta['main_label'],
                'via' => $meta['via'],
                'start' => $meta['start'],
                'end' => $meta['end'],
                'inbound_label' => $meta['inbound_label'],
                'outbound_label' => $meta['outbound_label'],
                'search_text' => $meta['search_text'],
                'from_search' => strtolower(trim(implode(' ', array_filter([
                    $meta['start'],
                    $display_label,
                    $description
                ])))),
                'to_search' => strtolower(trim(implode(' ', array_filter([
                    $meta['end'],
                    $display_label,
                    $description
                ])))),
            ];
        }, $buttons);

        return $this->render_template('shortcode-map.php', [
            'id' => $id,
            'mapId' => $mapId,
            'height' => $height,
            'zoom' => $zoom,
            'button_view_data' => $button_view_data,
        ]);
    }
}




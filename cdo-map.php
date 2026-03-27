<?php
/**
 * Plugin Name: Philippines Map Path Manager
 * Description: Create and manage custom path buttons for Philippines map with interactive admin interface.
 * Version: 1.0.0
 * Author: You
 */

if (!defined('ABSPATH')) { exit; }

class PHMapPlugin {
    private $table_name;
    private $places_table;
    private $route_waypoints_table;
    private $schema_version_option = 'ph_map_normalized_schema_version';

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ph_map_buttons';
        $this->places_table = $wpdb->prefix . 'ph_map_places';
        $this->route_waypoints_table = $wpdb->prefix . 'ph_map_route_waypoints';
        
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_post_ph_map_save_button', [$this, 'save_button']);
        add_action('admin_post_ph_map_delete_button', [$this, 'delete_button']);
        add_action('wp_ajax_ph_map_update_button_order', [$this, 'update_button_order']);
        add_shortcode('ph_map', [$this, 'render_shortcode']);
        
        // Check and update table schema on admin pages
        add_action('admin_init', [$this, 'check_table_schema']);
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
        ?>
        <div class="wrap">
            <h1>PH Map Path Buttons <a href="<?php echo admin_url('admin.php?page=ph-map-buttons&action=add'); ?>" class="page-title-action">Add New</a></h1>

            <form method="get" style="margin: 12px 0 16px; padding: 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px;">
                <input type="hidden" name="page" value="ph-map-buttons">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; align-items: end;">
                    <label>
                        <strong>Search</strong><br>
                        <input type="search" name="s" value="<?php echo esc_attr($search_query); ?>" class="regular-text" placeholder="Label, place, variant, notes" style="width: 100%;">
                    </label>
                    <label>
                        <strong>Origin</strong><br>
                        <input type="text" name="origin" value="<?php echo esc_attr($origin_filter); ?>" class="regular-text" placeholder="e.g. Balulang" style="width: 100%;">
                    </label>
                    <label>
                        <strong>Destination</strong><br>
                        <input type="text" name="destination" value="<?php echo esc_attr($destination_filter); ?>" class="regular-text" placeholder="e.g. Carmen Public Market" style="width: 100%;">
                    </label>
                    <label>
                        <strong>Variant</strong><br>
                        <input type="text" name="variant" value="<?php echo esc_attr($variant_filter); ?>" class="regular-text" placeholder="R1, R2..." style="width: 100%;">
                    </label>
                    <label style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                        <input type="checkbox" name="needs_review" value="1" <?php checked($needs_review_filter); ?>>
                        <strong>Needs Review Only</strong>
                    </label>
                </div>
                <p style="margin: 12px 0 0; display: flex; gap: 8px;">
                    <button type="submit" class="button button-primary">Apply Filters</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=ph-map-buttons')); ?>" class="button">Reset</a>
                </p>
            </form>
            
            <div class="notice notice-info">
                <p><strong>Usage:</strong> Use shortcode <code>[ph_map]</code> to display the map with all configured buttons.</p>
                <?php if ($has_filters): ?>
                    <p><strong>Tip:</strong> Reordering is disabled while filters are active. Reset filters to reorder all routes safely.</p>
                <?php else: ?>
                    <p><strong>Tip:</strong> Drag and drop the rows below to reorder how buttons appear on the frontend.</p>
                <?php endif; ?>
            </div>
            
            <style>
                .sortable-table tbody {
                    cursor: move;
                }
                .sortable-table tbody tr {
                    transition: background-color 0.2s ease;
                }
                .sortable-table tbody tr:hover {
                    background-color: #f9f9f9;
                }
                .sortable-table tbody tr.ui-sortable-helper {
                    background-color: #fff3cd;
                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                }
                .sortable-table tbody tr.ui-sortable-placeholder {
                    background-color: #d1ecf1;
                    height: 60px;
                }
                .drag-handle {
                    cursor: move;
                    color: #666;
                    font-size: 16px;
                    margin-right: 8px;
                }
                .drag-handle:hover {
                    color: #0073aa;
                }
                <?php if ($has_filters): ?>
                .drag-handle {
                    opacity: 0.4;
                    cursor: not-allowed;
                }
                <?php endif; ?>
            </style>
            
            <table class="wp-list-table widefat fixed striped sortable-table" id="buttons-table">
                <thead>
                    <tr>
                        <th width="30px">Order</th>
                        <th>ID</th>
                        <th>Route Label</th>
                        <th>Origin</th>
                        <th>Destination</th>
                        <th>Waypoints</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="sortable-buttons">
                    <?php if (empty($buttons)): ?>
                        <tr>
                            <td colspan="8">No buttons configured yet. <a href="<?php echo admin_url('admin.php?page=ph-map-buttons&action=add'); ?>">Add your first button</a>.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($buttons as $button): ?>
                            <?php 
                            $waypoints_data = json_decode($button->waypoints, true);
                            $waypoint_count = is_array($waypoints_data) ? count($waypoints_data) : 0;
                            $color = isset($button->color) ? $button->color : '#ff2f6d';
                            $display_label = $this->get_route_display_label($button);
                            $origin_name = !empty($button->origin_name) ? $button->origin_name : $button->from_location;
                            $destination_name = !empty($button->destination_name) ? $button->destination_name : $button->to_location;
                            ?>
                            <tr data-button-id="<?php echo $button->id; ?>">
                                <td>
                                    <span class="drag-handle">⋮⋮</span>
                                </td>
                                <td><?php echo $button->id; ?></td>
                                <td>
                                    <strong><?php echo esc_html($display_label); ?></strong>
                                    <div style="width: 20px; height: 3px; background: <?php echo esc_attr($color); ?>; margin-top: 2px;"></div>
                                    <?php if (!empty($button->migration_notes)): ?>
                                        <div style="color: #b45309; margin-top: 4px; font-size: 12px;">
                                            Needs review: <?php echo esc_html($button->migration_notes); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($origin_name ?: 'N/A'); ?></td>
                                <td><?php echo esc_html($destination_name ?: 'N/A'); ?></td>
                                <td><?php echo $waypoint_count; ?> waypoints</td>
                                <td><?php echo isset($button->created_at) ? date('M j, Y', strtotime($button->created_at)) : 'Unknown'; ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=ph-map-buttons&action=edit&id=' . $button->id); ?>">Edit</a> |
                                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=ph_map_delete_button&id=' . $button->id), 'delete_button_' . $button->id); ?>" onclick="return confirm('Are you sure?')">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
            <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/ui-lightness/jquery-ui.css">
            
            <script>
            jQuery(document).ready(function($) {
                var filtersActive = <?php echo $has_filters ? 'true' : 'false'; ?>;
                if (filtersActive) {
                    return;
                }

                if ($('#sortable-buttons tr').length > 1) {
                    $('#sortable-buttons').sortable({
                        handle: '.drag-handle',
                        placeholder: 'ui-sortable-placeholder',
                        helper: function(e, tr) {
                            var $originals = tr.children();
                            var $helper = tr.clone();
                            $helper.children().each(function(index) {
                                $(this).width($originals.eq(index).width());
                            });
                            return $helper;
                        },
                        update: function(event, ui) {
                            var buttonOrder = [];
                            $('#sortable-buttons tr[data-button-id]').each(function() {
                                buttonOrder.push($(this).data('button-id'));
                            });
                            
                            // Save the new order via AJAX
                            $.post(ajaxurl, {
                                action: 'ph_map_update_button_order',
                                button_order: buttonOrder,
                                nonce: '<?php echo wp_create_nonce('ph_map_reorder'); ?>'
                            }, function(response) {
                                if (response.success) {
                                    // Show a subtle success indication
                                    var $notice = $('<div class="notice notice-success is-dismissible"><p>Button order updated!</p></div>');
                                    $('.wrap h1').after($notice);
                                    setTimeout(function() {
                                        $notice.fadeOut();
                                    }, 3000);
                                }
                            }).fail(function() {
                                alert('Failed to update button order. Please try again.');
                                location.reload(); // Reload to reset the order
                            });
                        }
                    });
                    
                    // Add visual feedback
                    $('#sortable-buttons').disableSelection();
                }
            });
            </script>
        </div>
        <?php
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
        ?>
        <div class="wrap">
            <h1><?php echo $title; ?></h1>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="path-form">
                <input type="hidden" name="action" value="ph_map_save_button">
                <?php if ($is_edit): ?>
                    <input type="hidden" name="button_id" value="<?php echo $button->id; ?>">
                <?php endif; ?>
                <?php wp_nonce_field('save_button', 'ph_map_nonce'); ?>
                <input type="hidden" name="waypoints" id="waypoints_data" value="<?php echo esc_attr($waypoints); ?>">
                <input type="hidden" name="route_data" id="route_data" value="<?php echo esc_attr($route_data); ?>">
                <input type="hidden" name="multiple_paths" id="multiple_paths_data" value="<?php echo esc_attr($multiple_paths); ?>">
                <input type="hidden" name="label" id="generated_label_input" value="<?php echo esc_attr($generated_label); ?>">
                <input type="hidden" name="route_type" value="transportation">
                <input type="checkbox" id="is_loop" value="1" style="display:none;" aria-hidden="true" tabindex="-1">
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Origin Place</th>
                        <td>
                            <select name="origin_place_id" id="origin_place_id" class="regular-text">
                                <option value="">Select existing origin place</option>
                                <?php foreach ($places as $place): ?>
                                    <option value="<?php echo (int)$place->id; ?>" <?php selected($origin_place_id, (int)$place->id); ?>>
                                        <?php echo esc_html($place->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p style="margin: 6px 0;">or</p>
                            <input type="text" name="origin_place_name" id="origin_place_name" value="<?php echo esc_attr($origin_place_name); ?>" class="regular-text" placeholder="Type a new origin place">
                            <p class="description">Use an existing place for canonical naming or add a new one.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Destination Place</th>
                        <td>
                            <select name="destination_place_id" id="destination_place_id" class="regular-text">
                                <option value="">Select existing destination place</option>
                                <?php foreach ($places as $place): ?>
                                    <option value="<?php echo (int)$place->id; ?>" <?php selected($destination_place_id, (int)$place->id); ?>>
                                        <?php echo esc_html($place->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p style="margin: 6px 0;">or</p>
                            <input type="text" name="destination_place_name" id="destination_place_name" value="<?php echo esc_attr($destination_place_name); ?>" class="regular-text" placeholder="Type a new destination place">
                            <p class="description">Use an existing place for canonical naming or add a new one.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Route Variant Code</th>
                        <td>
                            <input type="text" name="variant_code" id="variant_code" value="<?php echo esc_attr($variant_code); ?>" class="regular-text" placeholder="R1, R2, R4..." maxlength="30">
                            <p class="description">Optional route variant code.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Sub-label / Branch</th>
                        <td>
                            <input type="text" name="sub_label" id="sub_label" value="<?php echo esc_attr($sub_label); ?>" class="regular-text" placeholder="Centro, Villa Verde, Xavier Heights..." maxlength="120">
                            <p class="description">Optional branch, descriptor, or sub-route name.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Generated Route Label</th>
                        <td>
                            <strong id="generated_label_preview"><?php echo esc_html($generated_label ?: 'Fill origin and destination to generate route label'); ?></strong>
                            <p class="description">Label is generated automatically from structured route fields.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Path Management</th>
                        <td>
                            <div id="path-tabs" style="margin-bottom: 20px;">
                                <button type="button" class="path-tab button button-primary" data-path="inbound">🔴 Inbound Path</button>
                                <button type="button" class="path-tab button button-secondary" data-path="outbound">🔵 Outbound Path</button>
                            </div>
                            
                            <div id="current-path-info" style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin-bottom: 15px;">
                                <strong>Currently editing: <span id="current-path-name">Inbound Path</span></strong>
                                <p style="margin: 5px 0 0 0; color: #666;">
                                    <span id="current-path-description">Red path for routes going toward the city center or main destination</span>
                                </p>
                            </div>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Draw/Edit Paths</th>
                        <td>
                            <div id="admin-map-container" style="height: 500px; width: 100%; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 10px;"></div>
                            <div id="path-controls" style="margin-bottom: 10px;">
                                <button type="button" id="clear-current-path" class="button">Clear Current Path</button>
                                <button type="button" id="undo-point" class="button">Undo Last Point</button>
                                <span id="point-count" style="margin-left: 15px;">Waypoints: 0</span>
                                <span id="route-status" style="margin-left: 15px; color: #666;"></span>
                            </div>
                            <p class="description">Click on the map to add waypoints to the current path. Switch between Inbound (red) and Outbound (blue) paths using the tabs above.</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" class="button-primary" value="<?php echo $is_edit ? 'Update Button' : 'Add Button'; ?>" id="submit-btn">
                    <a href="<?php echo admin_url('admin.php?page=ph-map-buttons'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>

        <script>
        (function(){
            function ensureLeaflet(cb){
                console.log('Checking for Leaflet...'); // Debug log
                if (window.L && L.map) { 
                    console.log('Leaflet already loaded'); // Debug log
                    cb(); 
                    return; 
                }
                if (!document.querySelector('link[data-leaflet-admin]')){
                    console.log('Loading Leaflet CSS...'); // Debug log
                    var link = document.createElement('link'); 
                    link.rel = 'stylesheet'; 
                    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; 
                    link.setAttribute('data-leaflet-admin','1'); 
                    document.head.appendChild(link);
                }
                var existing = document.querySelector('script[data-leaflet-admin]');
                if (existing){ 
                    console.log('Leaflet script already exists, waiting for load...'); // Debug log
                    existing.addEventListener('load', function(){ 
                        console.log('Leaflet loaded via existing script'); // Debug log
                        cb(); 
                    }); 
                    return; 
                }
                console.log('Loading Leaflet JS...'); // Debug log
                var s = document.createElement('script'); 
                s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; 
                s.defer = true; 
                s.setAttribute('data-leaflet-admin','1'); 
                s.onload = function(){ 
                    console.log('Leaflet JS loaded successfully'); // Debug log
                    cb(); 
                }; 
                s.onerror = function(){
                    console.error('Failed to load Leaflet JS'); // Debug log
                };
                document.head.appendChild(s);
            }

            ensureLeaflet(function(){
                console.log('Starting map initialization...'); // Debug log
                var mapContainer = document.getElementById('admin-map-container');
                var waypointsInput = document.getElementById('waypoints_data');
                var routeDataInput = document.getElementById('route_data');
                var multiplePathsInput = document.getElementById('multiple_paths_data');
                var pointCountEl = document.getElementById('point-count');
                var routeStatusEl = document.getElementById('route-status');
                var clearCurrentBtn = document.getElementById('clear-current-path');
                var undoBtn = document.getElementById('undo-point');
                var submitBtn = document.getElementById('submit-btn');
                var isLoopCheckbox = document.getElementById('is_loop');
                var currentPathNameEl = document.getElementById('current-path-name');
                var currentPathDescEl = document.getElementById('current-path-description');
                var originSelectEl = document.getElementById('origin_place_id');
                var destinationSelectEl = document.getElementById('destination_place_id');
                var originNameEl = document.getElementById('origin_place_name');
                var destinationNameEl = document.getElementById('destination_place_name');
                var variantCodeEl = document.getElementById('variant_code');
                var subLabelEl = document.getElementById('sub_label');
                var generatedLabelPreviewEl = document.getElementById('generated_label_preview');
                var generatedLabelInputEl = document.getElementById('generated_label_input');

                function getSelectedText(selectEl) {
                    if (!selectEl) {
                        return '';
                    }
                    var index = selectEl.selectedIndex;
                    if (index <= 0) {
                        return '';
                    }
                    return (selectEl.options[index].text || '').trim();
                }

                function normalizeSpaces(value) {
                    return (value || '').replace(/\s+/g, ' ').trim();
                }

                function composeLabel(origin, destination, variantCode, subLabel) {
                    origin = normalizeSpaces(origin);
                    destination = normalizeSpaces(destination);
                    variantCode = normalizeSpaces((variantCode || '').toUpperCase()).replace(/\s+/g, '');
                    subLabel = normalizeSpaces(subLabel);

                    if (!origin || !destination) {
                        return '';
                    }

                    if (variantCode && subLabel) {
                        return origin + ' ' + variantCode + ' ' + subLabel + ' - ' + destination;
                    }

                    if (variantCode) {
                        return origin + ' ' + variantCode + ' - ' + destination;
                    }

                    return origin + ' - ' + destination;
                }

                function refreshGeneratedLabel() {
                    var origin = normalizeSpaces(originNameEl && originNameEl.value ? originNameEl.value : getSelectedText(originSelectEl));
                    var destination = normalizeSpaces(destinationNameEl && destinationNameEl.value ? destinationNameEl.value : getSelectedText(destinationSelectEl));
                    var variantCode = variantCodeEl ? variantCodeEl.value : '';
                    var subLabel = subLabelEl ? subLabelEl.value : '';
                    var generated = composeLabel(origin, destination, variantCode, subLabel);

                    if (generatedLabelPreviewEl) {
                        generatedLabelPreviewEl.textContent = generated || 'Fill origin and destination to generate route label';
                    }

                    if (generatedLabelInputEl) {
                        generatedLabelInputEl.value = generated;
                    }
                }

                [originSelectEl, destinationSelectEl, originNameEl, destinationNameEl, variantCodeEl, subLabelEl].forEach(function(el) {
                    if (el) {
                        el.addEventListener('input', refreshGeneratedLabel);
                        el.addEventListener('change', refreshGeneratedLabel);
                    }
                });
                refreshGeneratedLabel();

                // Initialize map
                var cityCenter = [12.8797, 121.7740]; // Center of Philippines
                var map = L.map(mapContainer).setView(cityCenter, 6);

                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(map);

                // Set bounds for entire Philippines
                var southWest = L.latLng(4.5, 116.0), northEast = L.latLng(21.0, 127.0);
                var bounds = L.latLngBounds(southWest, northEast);
                map.setMaxBounds(bounds);
                map.options.maxBoundsViscosity = 1.0;

                // Path management variables
                var pathsData = {
                    inbound: {
                        waypoints: [],
                        route: [],
                        color: '#dc3545' // Red
                    },
                    outbound: {
                        waypoints: [],
                        route: [],
                        color: '#007bff' // Blue
                    }
                };
                
                var currentEditingPath = 'inbound';
                var waypointMarkers = [];
                var routeLines = [];
                var routingInProgress = false;

                // Load existing data if editing
                try {
                    var existingWaypoints = JSON.parse(waypointsInput.value);
                    var existingRoute = JSON.parse(routeDataInput.value);
                    var existingMultiplePaths = JSON.parse(multiplePathsInput.value);
                    
                    if (Array.isArray(existingWaypoints) && existingWaypoints.length > 0) {
                        pathsData.inbound.waypoints = existingWaypoints;
                        pathsData.inbound.route = existingRoute || [];
                    }
                    
                    if (Array.isArray(existingMultiplePaths) && existingMultiplePaths.length > 0) {
                        var outboundPath = existingMultiplePaths.find(function(p) { return p.id === 'outbound' || p.name === 'Outbound Path'; });
                        if (outboundPath) {
                            pathsData.outbound.waypoints = outboundPath.waypoints || [];
                            pathsData.outbound.route = outboundPath.route || [];
                        }
                    }
                    
                    redrawAllPaths();
                } catch(e) {
                    console.log('Error loading existing data:', e);
                }

                function switchToPath(pathId) {
                    // Save current path data before switching
                    saveCurrentPathData();
                    
                    // Update active tab
                    document.querySelectorAll('.path-tab').forEach(function(tab) {
                        tab.classList.remove('button-primary');
                        tab.classList.add('button-secondary');
                    });
                    
                    var targetTab = document.querySelector('[data-path="' + pathId + '"]');
                    if (targetTab) {
                        targetTab.classList.remove('button-secondary');
                        targetTab.classList.add('button-primary');
                    }
                    
                    // Switch current editing path
                    currentEditingPath = pathId;
                    
                    if (pathId === 'inbound') {
                        currentPathNameEl.textContent = 'Inbound Path';
                        currentPathDescEl.textContent = 'Red path for routes going toward the city center or main destination';
                    } else {
                        currentPathNameEl.textContent = 'Outbound Path';
                        currentPathDescEl.textContent = 'Blue path for routes going away from the city center';
                    }
                    
                    redrawAllPaths();
                    updateStatus();
                }

                function saveCurrentPathData() {
                    // This will be handled in computeFullRoute and other functions
                }

                function redrawAllPaths() {
                    // Clear all existing markers and lines
                    waypointMarkers.forEach(function(marker) {
                        map.removeLayer(marker);
                    });
                    waypointMarkers = [];
                    
                    routeLines.forEach(function(line) {
                        map.removeLayer(line);
                    });
                    routeLines = [];

                    // Draw both paths
                    Object.keys(pathsData).forEach(function(pathKey) {
                        var pathData = pathsData[pathKey];
                        var isActive = pathKey === currentEditingPath;
                        
                        if (pathData.waypoints && pathData.waypoints.length > 0) {
                            drawPath(pathData, pathKey, isActive);
                        }
                    });
                }

                function drawPath(pathData, pathKey, isActive) {
                    var pathWaypoints = pathData.waypoints || [];
                    var pathRoute = pathData.route || [];
                    var pathColor = pathData.color;
                    var pathName = pathKey === 'inbound' ? 'Inbound' : 'Outbound';
                    
                    // Add markers for each waypoint
                    pathWaypoints.forEach(function(point, index) {
                        var isStart = index === 0;
                        var isEnd = index === pathWaypoints.length - 1;
                        var popupText = pathName + ' Path - Waypoint ' + (index + 1);
                        
                        if (isStart) {
                            popupText += ' (Start)';
                        } else if (isEnd && isLoopCheckbox.checked) {
                            popupText += ' (Returns to start)';
                        } else if (isEnd) {
                            popupText += ' (End)';
                        }
                        
                        var iconSize = isActive ? 30 : 20;
                        var iconHtml = '<div style="background: ' + pathColor + '; color: white; border-radius: 50%; width: ' + iconSize + 'px; height: ' + iconSize + 'px; display: flex; align-items: center; justify-content: center; border: 2px solid white; box-shadow: 0 2px 4px rgba(0,0,0,0.3); font-size: ' + (iconSize > 25 ? '12' : '10') + 'px;">📍</div>';
                        
                        var customIcon = L.divIcon({
                            html: iconHtml,
                            iconSize: [iconSize, iconSize],
                            iconAnchor: [iconSize/2, iconSize/2],
                            className: 'custom-waypoint-icon'
                        });
                        
                        var marker = L.marker([point.lat, point.lng], { icon: customIcon })
                            .addTo(map)
                            .bindPopup(popupText);
                        waypointMarkers.push(marker);
                    });

                    // Draw route
                    if (pathRoute.length > 0) {
                        var routeStyle = { 
                            color: pathColor,
                            weight: isActive ? 5 : 3, 
                            opacity: isActive ? 0.9 : 0.6
                        };
                        
                        if (isLoopCheckbox.checked) {
                            routeStyle.dashArray = '15, 5';
                        }
                        
                        var routeLine = L.polyline(pathRoute, routeStyle).addTo(map);
                        routeLines.push(routeLine);
                    }
                }

                function updateStatus() {
                    var currentPath = pathsData[currentEditingPath];
                    var waypoints = currentPath.waypoints || [];
                    
                    pointCountEl.textContent = 'Waypoints: ' + waypoints.length;
                    
                    var totalWaypoints = (pathsData.inbound.waypoints || []).length + (pathsData.outbound.waypoints || []).length;
                    
                    submitBtn.disabled = totalWaypoints < 2 || routingInProgress;
                    if (totalWaypoints < 2) {
                        submitBtn.title = 'Please add at least 2 waypoints total to create paths';
                        routeStatusEl.textContent = '';
                    } else if (routingInProgress) {
                        submitBtn.title = 'Please wait for routing to complete';
                        routeStatusEl.textContent = 'Computing routes...';
                    } else {
                        submitBtn.title = '';
                        var statusText = 'Route ready';
                        if (isLoopCheckbox.checked && waypoints.length >= 2) {
                            statusText += ' (loop enabled)';
                        }
                        routeStatusEl.textContent = statusText;
                    }
                }

                function clampLatLng(latlng) {
                    var sw = bounds.getSouthWest();
                    var ne = bounds.getNorthEast();
                    var lat = Math.max(sw.lat, Math.min(ne.lat, latlng.lat));
                    var lng = Math.max(sw.lng, Math.min(ne.lng, latlng.lng));
                    return L.latLng(lat, lng);
                }

                function computeFullRoute() {
                    var currentPath = pathsData[currentEditingPath];
                    var waypoints = currentPath.waypoints || [];
                    
                    console.log('Computing route for', waypoints.length, 'waypoints in', currentEditingPath, 'path');
                    
                    if (waypoints.length < 2) {
                        currentPath.route = [];
                        redrawAllPaths();
                        updateStatus();
                        return;
                    }

                    routingInProgress = true;
                    updateStatus();

                    var routeWaypoints = waypoints.slice();
                    
                    if (isLoopCheckbox.checked && waypoints.length >= 2) {
                        routeWaypoints.push(waypoints[0]);
                    }

                    var coordsString = routeWaypoints.map(function(point) {
                        return point.lng + ',' + point.lat;
                    }).join(';');

                    var url = 'https://router.project-osrm.org/route/v1/driving/' + coordsString + 
                              '?overview=full&geometries=geojson';

                    fetch(url)
                        .then(function(res) { return res.json(); })
                        .then(function(data) {
                            routingInProgress = false;
                            if (data && data.routes && data.routes[0] && data.routes[0].geometry) {
                                var coords = data.routes[0].geometry.coordinates || [];
                                currentPath.route = coords.map(function(c) { return [c[1], c[0]]; });
                                console.log('Route computed successfully:', currentPath.route.length, 'coordinates');
                            } else {
                                console.log('No route found, using direct lines');
                                var fallbackWaypoints = waypoints.slice();
                                if (isLoopCheckbox.checked && waypoints.length >= 2) {
                                    fallbackWaypoints.push(waypoints[0]);
                                }
                                currentPath.route = fallbackWaypoints.map(function(point) {
                                    return [point.lat, point.lng];
                                });
                            }
                            
                            redrawAllPaths();
                            updateStatus();
                        })
                        .catch(function(err) {
                            console.error('Routing failed:', err);
                            routingInProgress = false;
                            routeStatusEl.textContent = 'Routing failed - using direct lines';
                            var fallbackWaypoints = waypoints.slice();
                            if (isLoopCheckbox.checked && waypoints.length >= 2) {
                                fallbackWaypoints.push(waypoints[0]);
                            }
                            currentPath.route = fallbackWaypoints.map(function(point) {
                                return [point.lat, point.lng];
                            });
                            
                            redrawAllPaths();
                            updateStatus();
                        });
                }

                // Add click handler for adding waypoints
                map.on('click', function(e) {
                    console.log('Map clicked at:', e.latlng);
                    var clampedLatLng = clampLatLng(e.latlng);
                    console.log('Adding waypoint to', currentEditingPath, 'path:', clampedLatLng);
                    
                    var currentPath = pathsData[currentEditingPath];
                    if (!currentPath.waypoints) {
                        currentPath.waypoints = [];
                    }
                    
                    currentPath.waypoints.push({
                        lat: clampedLatLng.lat,
                        lng: clampedLatLng.lng
                    });
                    
                    console.log('Total waypoints in', currentEditingPath, 'now:', currentPath.waypoints.length);
                    
                    updateStatus();
                    computeFullRoute();
                });

                // Clear current path button
                clearCurrentBtn.addEventListener('click', function() {
                    if (confirm('Are you sure you want to clear the current path?')) {
                        var currentPath = pathsData[currentEditingPath];
                        currentPath.waypoints = [];
                        currentPath.route = [];
                        redrawAllPaths();
                        updateStatus();
                    }
                });

                // Undo last waypoint button
                undoBtn.addEventListener('click', function() {
                    var currentPath = pathsData[currentEditingPath];
                    if (currentPath.waypoints && currentPath.waypoints.length > 0) {
                        currentPath.waypoints.pop();
                        computeFullRoute();
                    }
                });

                // Loop checkbox change handler
                isLoopCheckbox.addEventListener('change', function() {
                    var currentPath = pathsData[currentEditingPath];
                    if (currentPath.waypoints && currentPath.waypoints.length >= 2) {
                        computeFullRoute();
                    } else {
                        redrawAllPaths();
                        updateStatus();
                    }
                });

                // Path tab click handlers
                document.querySelectorAll('.path-tab').forEach(function(tab) {
                    tab.addEventListener('click', function() {
                        var pathId = this.getAttribute('data-path');
                        switchToPath(pathId);
                    });
                });

                // Form validation
                document.getElementById('path-form').addEventListener('submit', function(e) {
                    console.log('Form submitting...');

                    refreshGeneratedLabel();

                    if (!generatedLabelInputEl || !generatedLabelInputEl.value) {
                        e.preventDefault();
                        alert('Please choose or enter both origin and destination places.');
                        return false;
                    }
                    
                    var totalWaypoints = (pathsData.inbound.waypoints || []).length + (pathsData.outbound.waypoints || []).length;
                    
                    console.log('Total waypoints across all paths:', totalWaypoints);
                    
                    if (totalWaypoints < 2) {
                        e.preventDefault();
                        alert('Please add at least 2 waypoints total across all paths.');
                        return false;
                    }
                    
                    if (routingInProgress) {
                        e.preventDefault();
                        alert('Please wait for routing to complete.');
                        return false;
                    }
                    
                    // Update form data before submission
                    waypointsInput.value = JSON.stringify(pathsData.inbound.waypoints || []);
                    routeDataInput.value = JSON.stringify(pathsData.inbound.route || []);
                    
                    // Pack outbound path into multiple_paths
                    var multiplePaths = [];
                    if (pathsData.outbound.waypoints && pathsData.outbound.waypoints.length > 0) {
                        multiplePaths.push({
                            id: 'outbound',
                            name: 'Outbound Path',
                            waypoints: pathsData.outbound.waypoints,
                            route: pathsData.outbound.route || [],
                            direction: 'outbound',
                            color: '#007bff',
                            is_loop: false
                        });
                    }
                    multiplePathsInput.value = JSON.stringify(multiplePaths);
                    
                    console.log('Form data updated:', {
                        inbound_waypoints: (pathsData.inbound.waypoints || []).length,
                        inbound_route: (pathsData.inbound.route || []).length,
                        outbound_waypoints: (pathsData.outbound.waypoints || []).length,
                        multiple_paths: multiplePaths.length
                    });
                });

                // Initial setup
                updateStatus();
                
                // Test if map is properly initialized
                setTimeout(function() {
                    console.log('Map center:', map.getCenter());
                    console.log('Current editing path:', currentEditingPath);
                    console.log('Paths data:', pathsData);
                }, 1000);
            });
        })();
        </script>
        <?php
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
        
        $atts = shortcode_atts([
            'height' => '92vh',
            'zoom' => 12,
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

        ob_start();
        ?>
        <style>
            #<?php echo $id; ?> { 
                --ph-accent: #1f7a8c;
                --ph-border: #d8dde3;
                --ph-panel: #ffffff;
                --ph-bg: #f4f7fb;
                --ph-text: #1f2a37;
                --ph-text-light: #4b5563;
                --ph-focus: #0e7490;
                color: var(--ph-text);
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }

            #<?php echo $id; ?> .phmap-shell {
                position: relative;
                border: 1px solid var(--ph-border);
                border-radius: 16px;
                overflow: hidden;
                height: <?php echo esc_attr($height); ?>;
                min-height: 560px;
                background: #dbe8f3;
            }

            #<?php echo $id; ?> .phmap-map-canvas {
                position: absolute;
                inset: 0;
                z-index: 1;
            }

            #<?php echo $id; ?> .phmap-floating-status {
                position: absolute;
                left: 14px;
                top: 14px;
                z-index: 900;
                max-width: min(420px, calc(100% - 28px));
                background: rgba(20, 32, 47, 0.72);
                color: #f8fafc;
                border: 1px solid rgba(255, 255, 255, 0.2);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                border-radius: 10px;
                padding: 8px 12px;
                font-size: 12px;
                line-height: 1.45;
            }

            #<?php echo $id; ?> .phmap-bottom-sheet {
                position: absolute;
                left: 12px;
                right: 12px;
                bottom: 12px;
                z-index: 1000;
                background: rgba(245, 250, 255, 0.92);
                border: 1px solid rgba(203, 213, 225, 0.9);
                border-radius: 16px;
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
                box-shadow: 0 20px 40px rgba(15, 23, 42, 0.25);
                padding: 10px;
                height: min(78%, 620px);
                max-height: calc(100% - 24px);
                overflow: hidden;
                transition: transform 0.28s ease;
                display: flex;
                flex-direction: column;
            }

            #<?php echo $id; ?>.phmap-sheet-collapsed .phmap-bottom-sheet {
                transform: translateY(calc(100% - 74px));
            }

            #<?php echo $id; ?> .phmap-sheet-handle {
                width: 56px;
                height: 5px;
                border-radius: 999px;
                background: #93a9bc;
                margin: 2px auto 8px;
                border: 0;
                display: block;
                cursor: pointer;
                padding: 0;
            }

            #<?php echo $id; ?> .phmap-sheet-head {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 8px;
                padding: 2px 4px 8px;
            }

            #<?php echo $id; ?> .phmap-sheet-title {
                font-size: 14px;
                font-weight: 700;
                color: #0f172a;
            }

            #<?php echo $id; ?> .phmap-sheet-subtitle {
                font-size: 12px;
                color: #475569;
            }

            #<?php echo $id; ?> .phmap-sheet-content {
                overflow-y: auto;
                flex: 1 1 auto;
                min-height: 0;
                padding-right: 2px;
            }

            #<?php echo $id; ?> .phmap-section {
                background: rgba(255, 255, 255, 0.9);
                border: 1px solid rgba(203, 213, 225, 0.95);
                border-radius: 12px;
                padding: 10px;
                margin-bottom: 8px;
            }

            #<?php echo $id; ?> .phmap-section-title {
                font-size: 15px;
                font-weight: 700;
                margin-bottom: 4px;
                color: #182433;
            }

            #<?php echo $id; ?> .phmap-section-subtitle {
                margin: 0;
                font-size: 13px;
                color: var(--ph-text-light);
            }

            #<?php echo $id; ?> .phmap-toolbar {
                display: flex;
                gap: 12px;
                align-items: center;
                justify-content: space-between;
                flex-wrap: wrap;
                margin-top: 8px;
            }

            #<?php echo $id; ?> .phmap-search-wrap {
                flex: 1 1 280px;
            }

            #<?php echo $id; ?> .phmap-search-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
                width: 100%;
            }

            #<?php echo $id; ?> .phmap-search-field {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }

            #<?php echo $id; ?> .phmap-search-label {
                font-size: 12px;
                font-weight: 600;
                color: #334155;
            }

            #<?php echo $id; ?> .phmap-search-input {
                width: 100%;
                border: 1px solid var(--ph-border);
                border-radius: 10px;
                background: #fff;
                font-size: 15px;
                line-height: 1.35;
                padding: 12px 14px;
                color: var(--ph-text);
            }

            #<?php echo $id; ?> .phmap-search-input:focus {
                outline: 2px solid rgba(14, 116, 144, 0.2);
                border-color: var(--ph-focus);
            }

            #<?php echo $id; ?> .phmap-result-count {
                font-size: 13px;
                color: var(--ph-text-light);
                white-space: nowrap;
            }

            #<?php echo $id; ?> .phmap-result-detail {
                font-size: 12px;
                color: #64748b;
                margin-top: 2px;
                min-height: 20px;
            }

            #<?php echo $id; ?> .phmap-controls {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
                gap: 10px;
            }

            #<?php echo $id; ?> .phmap-btn {
                appearance: none;
                border: 1px solid var(--ph-border);
                background: #fff;
                color: var(--ph-text);
                padding: 14px;
                border-radius: 12px;
                cursor: pointer;
                transition: all 0.3s ease;
                position: relative;
                text-align: left;
                min-height: 112px;
                display: flex;
                flex-direction: column;
                justify-content: center;
            }

            #<?php echo $id; ?> .phmap-btn:hover {
                border-color: #aeb8c4;
                box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
            }

            #<?php echo $id; ?> .phmap-btn.active {
                border-color: var(--ph-focus);
                box-shadow: 0 0 0 2px rgba(14, 116, 144, 0.22), 0 6px 18px rgba(15, 23, 42, 0.12);
                background: #f3fbfd;
            }

            #<?php echo $id; ?> .phmap-btn.is-hidden {
                display: none;
            }

            #<?php echo $id; ?> .phmap-btn-content {
                position: relative;
                z-index: 2;
            }

            #<?php echo $id; ?> .phmap-btn-badge {
                display: inline-block;
                font-size: 11px;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: #5b6777;
                background: #eef2f7;
                border-radius: 999px;
                padding: 4px 8px;
                margin-bottom: 8px;
            }

            #<?php echo $id; ?> .phmap-btn-title {
                font-size: 17px;
                font-weight: 700;
                line-height: 1.3;
                margin-bottom: 6px;
            }

            #<?php echo $id; ?> .phmap-btn-via,
            #<?php echo $id; ?> .phmap-btn-description {
                font-size: 13px;
                line-height: 1.4;
                color: var(--ph-text-light);
            }

            #<?php echo $id; ?> .phmap-btn-via {
                margin-bottom: 6px;
            }

            #<?php echo $id; ?> .phmap-direction-toggle {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-top: 10px;
            }

            #<?php echo $id; ?> .phmap-direction-btn {
                background: #f7fafc;
                border: 1px solid #d6dce4;
                color: #334155;
                padding: 8px 10px;
                border-radius: 8px;
                font-size: 12px;
                line-height: 1.25;
                min-height: 36px;
                cursor: pointer;
                transition: all 0.2s ease;
            }

            #<?php echo $id; ?> .phmap-direction-btn:hover {
                background: #ecf3fb;
                border-color: #afc3da;
            }

            #<?php echo $id; ?> .phmap-direction-btn.active {
                background: #dff2f7;
                color: #0f4f5b;
                border-color: #79b8c4;
                font-weight: 600;
            }

            #<?php echo $id; ?> .phmap-action-chips {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
            }

            #<?php echo $id; ?> .phmap-action-chip {
                border: 1px solid #bfd0e0;
                background: rgba(255, 255, 255, 0.9);
                color: #1f2937;
                border-radius: 999px;
                font-size: 12px;
                line-height: 1.2;
                padding: 8px 12px;
                cursor: pointer;
                min-height: 34px;
                transition: background 0.2s ease, border-color 0.2s ease;
            }

            #<?php echo $id; ?> .phmap-action-chip:hover {
                background: #eff6ff;
                border-color: #9fb8cf;
            }

            #<?php echo $id; ?> .phmap-map-status {
                background: #ffffff;
                border: 1px solid var(--ph-border);
                border-left: 4px solid var(--ph-focus);
                border-radius: 10px;
                padding: 10px 12px;
                color: #243447;
                font-size: 13px;
                line-height: 1.4;
            }

            #<?php echo $id; ?> .phmap-selected-summary {
                background: #f8fbff;
                border: 1px solid #d9e6ef;
                border-left: 4px solid #5aa0b2;
                border-radius: 10px;
                padding: 10px 12px;
            }

            #<?php echo $id; ?> .phmap-selected-route-name {
                font-size: 14px;
                font-weight: 700;
                color: #1f2a37;
                margin-bottom: 4px;
            }

            #<?php echo $id; ?> .phmap-selected-route-meta {
                font-size: 12px;
                color: #4b5563;
                line-height: 1.45;
            }

            #<?php echo $mapId; ?> { 
                height: 100%; 
                width: 100%; 
                border: 0;
            }
            #<?php echo $mapId; ?> .leaflet-control-attribution a { 
                color: var(--ph-accent); 
            }

            #<?php echo $id; ?> .phmap-help {
                margin-top: 8px;
                font-size: 12px;
                color: #334155;
            }

            @media (max-width: 860px) {
                #<?php echo $id; ?> .phmap-controls {
                    grid-template-columns: 1fr;
                }
            }

            @media (max-width: 640px) {
                #<?php echo $id; ?> .phmap-shell {
                    min-height: 80vh;
                }

                #<?php echo $id; ?> .phmap-btn {
                    padding: 12px;
                    min-height: 104px;
                }

                #<?php echo $id; ?> .phmap-btn-title {
                    font-size: 16px;
                }

                #<?php echo $id; ?> .phmap-direction-btn {
                    width: 100%;
                }

                #<?php echo $id; ?> .phmap-search-grid {
                    grid-template-columns: 1fr;
                }

                #<?php echo $id; ?> .phmap-bottom-sheet {
                    left: 8px;
                    right: 8px;
                    bottom: 8px;
                    height: min(86%, 560px);
                    max-height: calc(100% - 16px);
                }

                #<?php echo $id; ?>.phmap-sheet-collapsed .phmap-bottom-sheet {
                    transform: translateY(calc(100% - 66px));
                }

                #<?php echo $id; ?> .phmap-action-chips {
                    gap: 6px;
                }

                #<?php echo $id; ?> .phmap-action-chip {
                    font-size: 11px;
                    padding: 8px 10px;
                }
            }
        </style>

        <div id="<?php echo $id; ?>" class="phmap phmap-no-selection phmap-sheet-expanded">
            <div class="phmap-shell">
                <div class="phmap-map-canvas">
                    <div id="<?php echo $mapId; ?>" aria-label="Map of Cagayan de Oro"></div>
                    <div class="phmap-floating-status">Focused on Cagayan de Oro commuter routes.</div>
                </div>

                <div class="phmap-bottom-sheet" role="region" aria-label="Route finder">
                    <button type="button" class="phmap-sheet-handle" aria-label="Toggle route panel"></button>
                    <div class="phmap-sheet-head">
                        <div>
                            <div class="phmap-sheet-title">Find a Route</div>
                            <div class="phmap-sheet-subtitle">Enter your From and To, then choose a route to preview it on the map.</div>
                        </div>
                        <div class="phmap-result-count" aria-live="polite">Enter From/To to search</div>
                    </div>
                    <div class="phmap-sheet-content">
                        <div class="phmap-section phmap-search-section">
                            <div class="phmap-toolbar">
                                <div class="phmap-search-wrap">
                                    <div class="phmap-search-grid">
                                        <label class="phmap-search-field">
                                            <span class="phmap-search-label">From</span>
                                            <input type="search" class="phmap-search-input phmap-from-input" placeholder="e.g., Agora Terminal" aria-label="From location">
                                        </label>
                                        <label class="phmap-search-field">
                                            <span class="phmap-search-label">To</span>
                                            <input type="search" class="phmap-search-input phmap-to-input" placeholder="e.g., Divisoria" aria-label="To location">
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="phmap-result-detail" aria-live="polite"></div>
                        </div>

                        <?php if (!empty($button_view_data)): ?>
                            <div class="phmap-section">
                                <div class="phmap-action-chips">
                                    <button type="button" class="phmap-action-chip phmap-view-all" data-action="view-all">Show All Routes</button>
                                    <button type="button" class="phmap-action-chip phmap-clear">Reset Map</button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="phmap-section phmap-selection-section">
                            <div class="phmap-section-title">Selected Route</div>
                            <div class="phmap-selected-summary" aria-live="polite">
                                <div class="phmap-selected-route-name">No route selected yet</div>
                                <div class="phmap-selected-route-meta">Search and choose a route to preview direction and path details.</div>
                            </div>
                            <div class="phmap-help">Map updates as soon as you select a route card or direction.</div>
                            <div class="phmap-map-status" aria-live="polite">Select a route to view it on the map.</div>
                        </div>

                        <div class="phmap-section phmap-results-section">
                        <div class="phmap-section-title">Route Results</div>
                        <p class="phmap-section-subtitle">Choose a route card to preview and plot it on the map.</p>
                        <div class="phmap-controls">
                <?php foreach ($button_view_data as $index => $button): ?>
                    <?php
                    $is_loop = $button['is_loop'];
                    $color = $button['color'];
                    $description = $button['description'];
                    $route_type = $button['route_type'];
                    $has_inbound = $button['has_inbound'];
                    $has_outbound = $button['has_outbound'];
                    $badge_label = $route_type === 'transportation' ? 'Commuter route' : 'Personal route';
                    ?>
                    <button type="button" 
                            class="phmap-btn phmap-path-btn" 
                            data-path-index="<?php echo $index; ?>"
                            data-active-direction="both"
                            data-search="<?php echo esc_attr($button['search_text']); ?>"
                            data-from-search="<?php echo esc_attr($button['from_search']); ?>"
                            data-to-search="<?php echo esc_attr($button['to_search']); ?>"
                            style="--ph-accent: <?php echo esc_attr($color); ?>;"
                            title="<?php echo esc_attr($description); ?>">
                        <div class="phmap-btn-content">
                            <div class="phmap-btn-badge"><?php echo esc_html($badge_label); ?><?php if ($is_loop): ?> | Loop<?php endif; ?></div>
                            <div class="phmap-btn-title"><?php echo esc_html($button['main_label']); ?></div>
                            <?php if (!empty($button['via'])): ?>
                                <div class="phmap-btn-via">Via <?php echo esc_html($button['via']); ?></div>
                            <?php endif; ?>
                            <?php if ($description): ?>
                                <div class="phmap-btn-description">
                                    <?php echo esc_html(wp_trim_words($description, 16, '...')); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($has_inbound || $has_outbound): ?>
                                <div class="phmap-direction-toggle">
                                    <?php if ($has_inbound): ?>
                                        <span class="phmap-direction-btn" data-direction="inbound" data-path-index="<?php echo $index; ?>"><?php echo esc_html($button['inbound_label']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($has_outbound): ?>
                                        <span class="phmap-direction-btn" data-direction="outbound" data-path-index="<?php echo $index; ?>"><?php echo esc_html($button['outbound_label']); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </button>
                <?php endforeach; ?>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>(function(){
            var root = document.getElementById('<?php echo $id; ?>');
            if (!root) return;
            var mapEl = document.getElementById('<?php echo $mapId; ?>');
            var mapStatusEl = root.querySelector('.phmap-map-status');
            var resultCountEl = root.querySelector('.phmap-result-count');
            var resultDetailEl = root.querySelector('.phmap-result-detail');
            var fromInputEl = root.querySelector('.phmap-from-input');
            var toInputEl = root.querySelector('.phmap-to-input');
            var selectedRouteNameEl = root.querySelector('.phmap-selected-route-name');
            var selectedRouteMetaEl = root.querySelector('.phmap-selected-route-meta');
            var sheetHandleEl = root.querySelector('.phmap-sheet-handle');

            var buttonConfigs = <?php echo json_encode($button_view_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

            function updateMapStatus(title, subtitle) {
                if (!mapStatusEl) return;
                var text = title || 'Select a route to view it on the map.';
                if (subtitle) {
                    text += ' ' + subtitle;
                }
                mapStatusEl.textContent = text;
            }

            function updateSelectedRouteSummary(config, activeDirection) {
                if (!selectedRouteNameEl || !selectedRouteMetaEl) return;

                if (!config) {
                    selectedRouteNameEl.textContent = 'No route selected yet';
                    selectedRouteMetaEl.textContent = 'Search and choose a route to preview direction and path details.';
                    return;
                }

                selectedRouteNameEl.textContent = config.main_label || config.label;

                var metaParts = [];
                if (activeDirection === 'inbound') {
                    metaParts.push(config.inbound_label || 'Inbound direction');
                } else if (activeDirection === 'outbound') {
                    metaParts.push(config.outbound_label || 'Outbound direction');
                } else {
                    metaParts.push('Both directions');
                }

                if (config.via) {
                    metaParts.push('Via ' + config.via);
                }

                selectedRouteMetaEl.textContent = metaParts.join(' | ');
            }

            function setSelectionState(hasSelection) {
                root.classList.toggle('phmap-no-selection', !hasSelection);
                root.classList.toggle('phmap-has-selection', hasSelection);
                root.classList.toggle('phmap-sheet-collapsed', hasSelection);
                root.classList.toggle('phmap-sheet-expanded', !hasSelection);
            }

            function setSheetCollapsed(collapsed) {
                root.classList.toggle('phmap-sheet-collapsed', collapsed);
                root.classList.toggle('phmap-sheet-expanded', !collapsed);
            }

            function updateSearchResults() {
                var fromQuery = fromInputEl ? fromInputEl.value.trim().toLowerCase() : '';
                var toQuery = toInputEl ? toInputEl.value.trim().toLowerCase() : '';
                var hasQuery = fromQuery.length > 0 || toQuery.length > 0;
                var visibleCount = 0;
                var routeButtons = root.querySelectorAll('.phmap-path-btn');

                routeButtons.forEach(function(btn) {
                    var fromHaystack = (btn.getAttribute('data-from-search') || btn.getAttribute('data-search') || '').toLowerCase();
                    var toHaystack = (btn.getAttribute('data-to-search') || btn.getAttribute('data-search') || '').toLowerCase();
                    var matchesFrom = fromQuery.length === 0 || fromHaystack.indexOf(fromQuery) !== -1;
                    var matchesTo = toQuery.length === 0 || toHaystack.indexOf(toQuery) !== -1;
                    var matches = hasQuery && matchesFrom && matchesTo;
                    btn.classList.toggle('is-hidden', !matches);
                    if (matches) {
                        visibleCount++;
                    } else if (btn.classList.contains('active')) {
                        clearCurrentPath();
                    }
                });

                if (resultCountEl) {
                    if (!hasQuery) {
                        resultCountEl.textContent = 'Enter From/To to search';
                    } else if (visibleCount === 0) {
                        resultCountEl.textContent = 'No matching routes';
                    } else {
                        resultCountEl.textContent = 'Showing ' + visibleCount + ' route' + (visibleCount === 1 ? '' : 's');
                    }
                }

                if (resultDetailEl) {
                    if (!hasQuery) {
                        resultDetailEl.textContent = 'Enter at least one field to start filtering routes. Matching route cards will appear below.';
                    } else {
                        var terms = [];
                        if (fromQuery.length > 0) {
                            terms.push('From "' + fromQuery + '"');
                        }
                        if (toQuery.length > 0) {
                            terms.push('To "' + toQuery + '"');
                        }
                        resultDetailEl.textContent = 'Filtering by ' + terms.join(' | ');
                    }
                }

                if (hasQuery) {
                    setSheetCollapsed(false);
                }
            }

            function ensureLeaflet(cb){
                console.log('Checking for Leaflet...'); // Debug log
                if (window.L && L.map) { 
                    console.log('Leaflet already loaded'); // Debug log
                    cb(); 
                    return; 
                }
                if (!document.querySelector('link[data-leaflet]')){
                    console.log('Loading Leaflet CSS...'); // Debug log
                    var link = document.createElement('link'); link.rel = 'stylesheet'; link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; link.setAttribute('data-leaflet','1'); document.head.appendChild(link);
                }
                var existing = document.querySelector('script[data-leaflet]');
                if (existing){ 
                    console.log('Leaflet script already exists, waiting for load...'); // Debug log
                    existing.addEventListener('load', function(){ 
                        console.log('Leaflet loaded via existing script'); // Debug log
                        cb(); 
                    }); 
                    return; 
                }
                console.log('Loading Leaflet JS...'); // Debug log
                var s = document.createElement('script'); 
                s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'; 
                s.defer = true; 
                s.setAttribute('data-leaflet','1'); 
                s.onload = function(){ 
                    console.log('Leaflet JS loaded successfully'); // Debug log
                    cb(); 
                }; 
                s.onerror = function(){
                    console.error('Failed to load Leaflet JS'); // Debug log
                };
                document.head.appendChild(s);
            }

            ensureLeaflet(init);

            function init(){
                if (!window.L || !L.map) return;
                var cityCenter = [8.4542, 124.6319]; // Cagayan de Oro center
                var map = L.map(mapEl, { zoomControl:true, attributionControl:true }).setView(cityCenter, <?php echo (int)$zoom; ?>);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
                }).addTo(map);

                var currentMarkers = [];
                var currentPaths = []; // Changed to array to hold multiple paths
                var activeButton = null;

                var cdoBounds = L.latLngBounds(
                    L.latLng(8.33, 124.50),
                    L.latLng(8.58, 124.78)
                );
                map.setView(cityCenter, <?php echo (int)$zoom; ?>);
                map.setMaxBounds(cdoBounds.pad(0.35));
                map.options.maxBoundsViscosity = 0.7;

                function clearCurrentPath(){
                    currentMarkers.forEach(function(m){ map.removeLayer(m); });
                    currentMarkers = [];
                    
                    // Remove all current paths
                    currentPaths.forEach(function(path){ map.removeLayer(path); });
                    currentPaths = [];
                    
                    if (activeButton) { 
                        activeButton.classList.remove('active'); 
                        activeButton.style.backgroundColor = ''; 
                        activeButton.style.borderColor = '';
                        
                        // Reset direction buttons to starter state
                        var allDirectionBtns = activeButton.querySelectorAll('.phmap-direction-btn');
                        allDirectionBtns.forEach(function(btn) {
                            btn.classList.remove('active');
                        });
                        activeButton.setAttribute('data-active-direction', 'both');
                        
                        activeButton = null; 
                    }

                    updateMapStatus('Select a route to view it on the map.');
                    updateSelectedRouteSummary(null, 'both');
                    setSelectionState(false);
                }

                function showPath(config, buttonEl, activeDirection){
                    clearCurrentPath();
                    activeButton = buttonEl;
                    buttonEl.classList.add('active');
                    buttonEl.style.backgroundColor = config.color;
                    buttonEl.style.borderColor = config.color;

                    console.log('showPath called with direction:', activeDirection);
                    console.log('Config:', config);

                    var directionSummary = 'Showing both directions.';
                    if (activeDirection === 'inbound') {
                        directionSummary = config.inbound_label || 'Showing inbound direction.';
                    } else if (activeDirection === 'outbound') {
                        directionSummary = config.outbound_label || 'Showing outbound direction.';
                    }
                    updateMapStatus('Now showing: ' + (config.main_label || config.label), directionSummary);
                    updateSelectedRouteSummary(config, activeDirection);
                    setSelectionState(true);

                    // Render based on active direction
                    if (activeDirection === 'inbound') {
                        // Render inbound path (red) only
                        if (config.waypoints && Array.isArray(config.waypoints) && config.waypoints.length >= 2) {
                            console.log('Drawing inbound path with', config.waypoints.length, 'waypoints');
                            drawSinglePath(
                                config.waypoints,
                                config.route,
                                'inbound',
                                '#dc3545', // Red
                                config.is_loop,
                                config.label + ' - Inbound',
                                0
                            );
                        }
                    } else if (activeDirection === 'outbound') {
                        // Render outbound path (blue) only
                        if (config.multiple_paths && Array.isArray(config.multiple_paths)) {
                            console.log('Drawing outbound path with', config.multiple_paths.length, 'paths');
                            config.multiple_paths.forEach(function(path, pathIndex) {
                                if (path.waypoints && path.waypoints.length >= 2) {
                                    drawSinglePath(
                                        path.waypoints,
                                        path.route,
                                        'outbound',
                                        '#007bff', // Blue
                                        path.is_loop || false,
                                        config.label + ' - Outbound',
                                        0
                                    );
                                }
                            });
                        }
                    } else if (activeDirection === 'both') {
                        // Render both inbound and outbound paths
                        if (config.waypoints && Array.isArray(config.waypoints) && config.waypoints.length >= 2) {
                            console.log('Drawing inbound path with', config.waypoints.length, 'waypoints');
                            drawSinglePath(
                                config.waypoints,
                                config.route,
                                'inbound',
                                '#dc3545', // Red
                                config.is_loop,
                                config.label + ' - Inbound',
                                0
                            );
                        }
                        
                        if (config.multiple_paths && Array.isArray(config.multiple_paths)) {
                            console.log('Drawing outbound path with', config.multiple_paths.length, 'paths');
                            config.multiple_paths.forEach(function(path, pathIndex) {
                                if (path.waypoints && path.waypoints.length >= 2) {
                                    drawSinglePath(
                                        path.waypoints,
                                        path.route,
                                        'outbound',
                                        '#007bff', // Blue
                                        path.is_loop || false,
                                        config.label + ' - Outbound',
                                        1 // Offset for better separation
                                    );
                                }
                            });
                        }
                    }
                    
                    // Fit map to show all paths
                    if (currentPaths.length > 0) {
                        var group = new L.featureGroup(currentPaths);
                        map.fitBounds(group.getBounds().pad(0.1), { maxZoom: 16 });
                    }
                }

                function showAllRoutes() {
                    clearCurrentPath();
                    updateMapStatus('Now showing all routes.', 'Tap a single route card to focus on one path.');
                    updateSelectedRouteSummary({
                        main_label: 'All available routes',
                        via: ''
                    }, 'both');
                    setSelectionState(true);
                    
                    var colorIndex = 0;
                    // More distinct color palette with better contrast
                    var routeColors = [
                        '#e74c3c',  // Bright Red
                        '#3498db',  // Bright Blue
                        '#2ecc71',  // Emerald Green
                        '#f39c12',  // Orange
                        '#9b59b6',  // Purple
                        '#1abc9c',  // Turquoise
                        '#e67e22',  // Carrot Orange
                        '#34495e',  // Dark Blue Gray
                        '#e91e63',  // Pink
                        '#00bcd4',  // Cyan
                        '#ff5722',  // Deep Orange
                        '#795548'   // Brown
                    ];
                    
                    buttonConfigs.forEach(function(config, configIndex) {
                        if (!config.waypoints || !Array.isArray(config.waypoints) || config.waypoints.length < 2) return;

                        var routeColor = routeColors[colorIndex % routeColors.length];
                        colorIndex++;

                        // Merge inbound and outbound paths into one color per button
                        var allPaths = [];
                        
                        // Add inbound path
                        if (config.waypoints && config.waypoints.length >= 2) {
                            allPaths.push({
                                waypoints: config.waypoints,
                                route: config.route,
                                is_loop: config.is_loop,
                                name: config.label + ' - Inbound',
                                pathType: 'inbound'
                            });
                        }

                        // Add outbound path if it exists
                        if (config.multiple_paths && Array.isArray(config.multiple_paths)) {
                            config.multiple_paths.forEach(function(path) {
                                if (path.waypoints && path.waypoints.length >= 2) {
                                    allPaths.push({
                                        waypoints: path.waypoints,
                                        route: path.route,
                                        is_loop: path.is_loop || false,
                                        name: config.label + ' - Outbound',
                                        pathType: 'outbound'
                                    });
                                }
                            });
                        }

                        // Draw all paths for this button with the same color but better separation
                        allPaths.forEach(function(pathData, pathIndex) {
                            drawSinglePath(
                                pathData.waypoints,
                                pathData.route,
                                pathData.pathType,
                                routeColor, // Same color for both inbound and outbound
                                pathData.is_loop,
                                pathData.name,
                                pathIndex // Offset for path separation
                            );
                        });
                    });
                    
                    // Fit map to show all paths
                    if (currentPaths.length > 0) {
                        var group = new L.featureGroup(currentPaths);
                        map.fitBounds(group.getBounds().pad(0.1), { maxZoom: 14 });
                    }
                }

                // Helper function to calculate perpendicular offset for parallel lines
                function calculatePerpendicularOffset(coord1, coord2, offsetDistance) {
                    // Calculate the direction vector
                    var dx = coord2[1] - coord1[1]; // longitude difference
                    var dy = coord2[0] - coord1[0]; // latitude difference
                    
                    // Calculate the length of the vector
                    var length = Math.sqrt(dx * dx + dy * dy);
                    
                    if (length === 0) return [0, 0];
                    
                    // Normalize and rotate 90 degrees for perpendicular
                    var perpX = -dy / length * offsetDistance;
                    var perpY = dx / length * offsetDistance;
                    
                    return [perpX, perpY];
                }

                function drawSinglePath(waypoints, route, direction, color, isLoop, pathName, pathOffset) {
                    pathOffset = pathOffset || 0; // Default to no offset

                    // Draw route with solid lines and better path separation
                    var routeCoords = route && Array.isArray(route) && route.length > 0 ? route : 
                        (function() {
                            var fallbackWaypoints = waypoints.slice();
                            if (isLoop) {
                                fallbackWaypoints.push(waypoints[0]);
                            }
                            return fallbackWaypoints.map(function(point) {
                                return [point.lat, point.lng];
                            });
                        })();
                    
                    // Solid lines with better visual distinction
                    var routeStyle = { 
                        color: color,
                        weight: 5,
                        opacity: 0.8,
                        lineCap: 'round',
                        lineJoin: 'round'
                    };
                    
                    // No more dashed patterns - all solid lines
                    // Visual distinction only through positioning and weight
                    if (pathOffset > 0) {
                        routeStyle.weight = 4; // Slightly thinner for secondary paths
                        routeStyle.opacity = 0.7; // Slightly more transparent
                    }
                    
                    // Special styling for loops - thicker line
                    if (isLoop) {
                        routeStyle.weight = 6;
                        routeStyle.opacity = 0.9;
                    }
                    
                    // Apply better positional offset for path separation
                    var offsetRoute = routeCoords;
                    if (pathOffset > 0 && routeCoords.length > 1) {
                        var offsetDistance = 0.000005; // Larger offset for better separation
                        var offsetPattern = pathOffset % 2 === 0 ? 1 : -1;
                        
                        offsetRoute = routeCoords.map(function(coord, index) {
                            if (index === 0 || index === routeCoords.length - 1) {
                                // Don't offset start and end points as much
                                return [
                                    coord[0] + (offsetDistance * 0.3 * offsetPattern),
                                    coord[1] + (offsetDistance * 0.3 * offsetPattern)
                                ];
                            } else {
                                // Full offset for middle points to create parallel paths
                                return [
                                    coord[0] + (offsetDistance * offsetPattern),
                                    coord[1] + (offsetDistance * offsetPattern)
                                ];
                            }
                        });
                    }
                    
                    var pathPolyline = L.polyline(offsetRoute, routeStyle).addTo(map);
                    currentPaths.push(pathPolyline);

                    if (offsetRoute.length > 0) {
                        var startpoint = offsetRoute[0];
                        var startpointMarker = L.circleMarker(startpoint, {
                            radius: 8,
                            color: '#ffffff',
                            weight: 2,
                            fillColor: '#22c55e',
                            fillOpacity: 0.95
                        }).addTo(map);
                        currentMarkers.push(startpointMarker);
                    }

                    // Highlight the endpoint of each shown path in pink for better visibility.
                    if (offsetRoute.length > 0) {
                        var endpoint = offsetRoute[offsetRoute.length - 1];
                        var endpointMarker = L.circleMarker(endpoint, {
                            radius: 8,
                            color: '#ffffff',
                            weight: 2,
                            fillColor: '#ff2f92',
                            fillOpacity: 0.95
                        }).addTo(map);
                        currentMarkers.push(endpointMarker);
                    }
                }

                var pathButtons = root.querySelectorAll('.phmap-path-btn');
                console.log('Found', pathButtons.length, 'path buttons');
                
                pathButtons.forEach(function(btn, btnIndex){
                    console.log('Setting up button', btnIndex, 'with path index:', btn.getAttribute('data-path-index'));
                    
                    btn.addEventListener('click', function(e){
                        console.log('Button clicked:', e.target, 'classList:', e.target.classList.toString());
                        
                        // Check if we clicked on a direction button or its child elements
                        if (e.target.classList.contains('phmap-direction-btn') || 
                            e.target.closest('.phmap-direction-btn')) {
                            console.log('Clicked on direction button or its child, stopping propagation');
                            e.stopPropagation();
                            return; // Direction button clicks are handled separately
                        }
                        
                        // Check if we clicked inside the direction toggle area
                        if (e.target.closest('.phmap-direction-toggle')) {
                            console.log('Clicked inside direction toggle area, stopping propagation');
                            e.stopPropagation();
                            return;
                        }
                        
                        var index = parseInt(btn.getAttribute('data-path-index'));
                        var activeDirection = 'both'; // Always show both when clicking main button
                        
                        console.log('Main button clicked - Index:', index, 'Direction:', activeDirection);
                        
                        if (buttonConfigs[index]) {
                            if (btn.classList.contains('active')) {
                                console.log('Button is active, clearing path');
                                clearCurrentPath();
                            } else {
                                console.log('Button is not active, showing path');
                                // Clear any other active buttons first
                                clearCurrentPath();
                                
                                // Reset direction buttons to show neither is specifically selected
                                var allDirectionBtns = btn.querySelectorAll('.phmap-direction-btn');
                                allDirectionBtns.forEach(function(dirBtn) {
                                    dirBtn.classList.remove('active');
                                });
                                btn.setAttribute('data-active-direction', 'both');
                                showPath(buttonConfigs[index], btn, activeDirection);
                            }
                        } else {
                            console.log('No config found for index:', index);
                        }
                    });
                });

                // Enhanced direction button handling with direct event listeners
                var directionButtons = root.querySelectorAll('.phmap-direction-btn');
                console.log('Found', directionButtons.length, 'direction buttons');
                
                directionButtons.forEach(function(dirBtn, dirIndex) {
                    console.log('Setting up direction button', dirIndex, 'with direction:', dirBtn.getAttribute('data-direction'));
                    
                    dirBtn.addEventListener('click', function(e) {
                        console.log('Direction button clicked directly:', e.target);
                        e.preventDefault();
                        e.stopPropagation();
                        
                        var direction = this.getAttribute('data-direction');
                        var pathIndex = parseInt(this.getAttribute('data-path-index'));
                        var parentBtn = this.closest('.phmap-path-btn');
                        
                        console.log('Direction button - Direction:', direction, 'Path Index:', pathIndex);
                        
                        if (!parentBtn || !buttonConfigs[pathIndex]) {
                            console.log('No parent button or config found');
                            return;
                        }
                        
                        // Clear any other active buttons first
                        clearCurrentPath();
                        
                        // Update active direction button
                        var allDirectionBtns = parentBtn.querySelectorAll('.phmap-direction-btn');
                        allDirectionBtns.forEach(function(btn) {
                            btn.classList.remove('active');
                        });
                        this.classList.add('active');
                        
                        // Update the parent button's active direction
                        parentBtn.setAttribute('data-active-direction', direction);
                        
                        console.log('Showing path for direction:', direction);
                        // Show the path with the selected direction
                        showPath(buttonConfigs[pathIndex], parentBtn, direction);
                    });
                });

                // Backup event delegation (in case direct listeners fail)
                root.addEventListener('click', function(e) {
                    if (e.target.classList.contains('phmap-direction-btn')) {
                        console.log('Backup: Direction button clicked via delegation');
                        e.preventDefault();
                        e.stopPropagation();
                        
                        var direction = e.target.getAttribute('data-direction');
                        var pathIndex = parseInt(e.target.getAttribute('data-path-index'));
                        var parentBtn = e.target.closest('.phmap-path-btn');
                        
                        console.log('Backup: Direction:', direction, 'Path Index:', pathIndex);
                        
                        if (!parentBtn || !buttonConfigs[pathIndex]) {
                            console.log('Backup: No parent button or config found');
                            return;
                        }
                        
                        // Clear any other active buttons first
                        clearCurrentPath();
                        
                        // Update active direction button
                        var allDirectionBtns = parentBtn.querySelectorAll('.phmap-direction-btn');
                        allDirectionBtns.forEach(function(btn) {
                            btn.classList.remove('active');
                        });
                        e.target.classList.add('active');
                        
                        // Update the parent button's active direction
                        parentBtn.setAttribute('data-active-direction', direction);
                        
                        console.log('Backup: Showing path for direction:', direction);
                        // Show the path with the selected direction
                        showPath(buttonConfigs[pathIndex], parentBtn, direction);
                    }
                });

                var viewAllBtn = root.querySelector('.phmap-view-all');
                if (viewAllBtn) {
                    viewAllBtn.addEventListener('click', function(){
                        console.log('View All button clicked');
                        if (this.classList.contains('active')) {
                            console.log('View All is active, clearing paths');
                            clearCurrentPath();
                        } else {
                            console.log('View All is not active, showing all routes');
                            clearCurrentPath();
                            this.classList.add('active');
                            this.style.backgroundColor = '#007cba'; // WordPress blue
                            activeButton = this;
                            showAllRoutes();
                        }
                    });
                }

                var clearBtn = root.querySelector('.phmap-clear');
                if (clearBtn) {
                    clearBtn.addEventListener('click', function(){ 
                        console.log('Clear button clicked');
                        clearCurrentPath(); 
                    });
                }

                if (fromInputEl) {
                    fromInputEl.addEventListener('input', updateSearchResults);
                    fromInputEl.addEventListener('focus', function() {
                        setSheetCollapsed(false);
                    });
                }

                if (toInputEl) {
                    toInputEl.addEventListener('input', updateSearchResults);
                    toInputEl.addEventListener('focus', function() {
                        setSheetCollapsed(false);
                    });
                }

                updateSearchResults();

                if (sheetHandleEl) {
                    sheetHandleEl.addEventListener('click', function() {
                        setSheetCollapsed(!root.classList.contains('phmap-sheet-collapsed'));
                    });
                }

                updateSelectedRouteSummary(null, 'both');
                setSelectionState(false);
            }
        })();</script>
        <?php
        return ob_get_clean();
    }
}

new PHMapPlugin();
?>
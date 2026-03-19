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

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ph_map_buttons';
        
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
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
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
        
        // Check if required columns exist
        $columns = $wpdb->get_results("SHOW COLUMNS FROM {$this->table_name}");
        $column_names = array_column($columns, 'Field');
        
        $required_columns = ['id', 'label', 'description', 'waypoints', 'route_data', 'is_loop', 'direction', 'color', 'route_type', 'multiple_paths', 'sort_order', 'created_at'];
        $missing_columns = array_diff($required_columns, $column_names);
        
        if (!empty($missing_columns)) {
            error_log('Missing columns in table ' . $this->table_name . ': ' . implode(', ', $missing_columns));
            $this->update_table_schema($missing_columns);
        }
    }

    private function update_table_schema($missing_columns) {
        global $wpdb;
        
        foreach ($missing_columns as $column) {
            switch ($column) {
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
            $button = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_name WHERE id = %d", $button_id));
            $this->render_edit_form($button);
        } elseif ($action === 'add') {
            $this->render_edit_form();
        } else {
            $this->render_list_page();
        }
    }

    private function render_list_page() {
        global $wpdb;
        $buttons = $wpdb->get_results("SELECT * FROM $this->table_name ORDER BY sort_order ASC, id ASC");
        ?>
        <div class="wrap">
            <h1>PH Map Path Buttons <a href="<?php echo admin_url('admin.php?page=ph-map-buttons&action=add'); ?>" class="page-title-action">Add New</a></h1>
            
            <div class="notice notice-info">
                <p><strong>Usage:</strong> Use shortcode <code>[ph_map]</code> to display the map with all configured buttons.</p>
                <p><strong>Tip:</strong> Drag and drop the rows below to reorder how buttons appear on the frontend.</p>
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
            </style>
            
            <table class="wp-list-table widefat fixed striped sortable-table" id="buttons-table">
                <thead>
                    <tr>
                        <th width="30px">Order</th>
                        <th>ID</th>
                        <th>Button Label</th>
                        <th>Description</th>
                        <th>Waypoints</th>
                        <th>Type</th>
                        <th>Direction</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="sortable-buttons">
                    <?php if (empty($buttons)): ?>
                        <tr>
                            <td colspan="9">No buttons configured yet. <a href="<?php echo admin_url('admin.php?page=ph-map-buttons&action=add'); ?>">Add your first button</a>.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($buttons as $button): ?>
                            <?php 
                            $waypoints_data = json_decode($button->waypoints, true);
                            $waypoint_count = is_array($waypoints_data) ? count($waypoints_data) : 0;
                            $is_loop = isset($button->is_loop) ? (bool)$button->is_loop : false;
                            $direction = isset($button->direction) ? $button->direction : 'inbound';
                            $color = isset($button->color) ? $button->color : '#ff2f6d';
                            $description = isset($button->description) ? $button->description : '';
                            
                            $direction_icons = [
                                'inbound' => '🔴 Inbound',
                                'outbound' => '🔵 Outbound'
                            ];
                            ?>
                            <tr data-button-id="<?php echo $button->id; ?>">
                                <td>
                                    <span class="drag-handle">⋮⋮</span>
                                </td>
                                <td><?php echo $button->id; ?></td>
                                <td>
                                    <strong><?php echo esc_html($button->label); ?></strong>
                                    <div style="width: 20px; height: 3px; background: <?php echo esc_attr($color); ?>; margin-top: 2px;"></div>
                                </td>
                                <td>
                                    <?php if ($description): ?>
                                        <span title="<?php echo esc_attr($description); ?>">
                                            <?php echo esc_html(wp_trim_words($description, 8, '...')); ?>
                                        </span>
                                    <?php else: ?>
                                        <em style="color: #666;">No description</em>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $waypoint_count; ?> waypoints</td>
                                <td><?php echo $is_loop ? '🔄 Loop' : '📍 One-way'; ?></td>
                                <td><?php echo $direction_icons[$direction] ?? $direction; ?></td>
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
        $title = $is_edit ? 'Edit Button' : 'Add New Button';
        $waypoints = $is_edit ? $button->waypoints : '[]';
        $route_data = $is_edit ? $button->route_data : '[]';
        $is_loop = $is_edit && isset($button->is_loop) ? (bool)$button->is_loop : false;
        $direction = $is_edit && isset($button->direction) ? $button->direction : 'inbound';
        $color = $is_edit && isset($button->color) ? $button->color : '#ff2f6d';
        $description = $is_edit && isset($button->description) ? $button->description : '';
        $route_type = $is_edit && isset($button->route_type) ? $button->route_type : 'transportation';
        $multiple_paths = $is_edit && isset($button->multiple_paths) ? $button->multiple_paths : '[]';
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
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Button Label</th>
                        <td>
                            <input type="text" name="label" value="<?php echo $is_edit ? esc_attr($button->label) : ''; ?>" class="regular-text" required>
                            <p class="description">The text that will appear on the button.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Button Description</th>
                        <td>
                            <textarea name="description" rows="3" class="large-text" placeholder="e.g., Express route to downtown area via main highways"><?php echo $is_edit ? esc_textarea($description) : ''; ?></textarea>
                            <p class="description">Optional description that will appear as a tooltip when users hover over the button.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Route Settings</th>
                        <td>
                            <div style="margin-bottom: 15px;">
                                <label style="margin-right: 20px; display: inline-block;">
                                    Route Type: 
                                    <select name="route_type" id="route_type" style="margin-left: 5px;">
                                        <option value="transportation" <?php selected($route_type, 'transportation'); ?>>🚌 Transportation (Jeepney/Bus)</option>
                                        <option value="personal" <?php selected($route_type, 'personal'); ?>>👤 Personal Route</option>
                                    </select>
                                </label>
                                <label>
                                    <input type="checkbox" name="is_loop" id="is_loop" value="1" <?php checked($is_loop); ?>>
                                    Loop (return to start)
                                </label>
                            </div>
                            <p class="description">Transportation routes are for jeepneys, buses, etc. Personal routes are for walking, driving, etc.</p>
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
        $label = sanitize_text_field($_POST['label']);
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
            wp_die('Button label cannot be empty.');
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
            'description' => $description,
            'waypoints' => $waypoints,
            'route_data' => $route_data,
            'multiple_paths' => $multiple_paths,
            'is_loop' => $is_loop,
            'direction' => $direction,
            'color' => $color,
            'route_type' => $route_type
        ];
        
        // Set sort_order for new buttons
        if (!$button_id) {
            $max_sort_order = $wpdb->get_var("SELECT MAX(sort_order) FROM {$this->table_name}");
            $data['sort_order'] = ($max_sort_order ? $max_sort_order + 1 : 1);
        }
        
        error_log('Preparing to save data: ' . var_export(array_map('strlen', $data), true));
        
        if ($button_id) {
            // Check if button exists
            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_name} WHERE id = %d", $button_id));
            if (!$existing) {
                wp_die('Button not found for editing.');
            }
            
            $result = $wpdb->update($this->table_name, $data, ['id' => $button_id]);
            $message = 'Button updated successfully!';
            
            if ($result === false) {
                error_log('Update failed. MySQL Error: ' . $wpdb->last_error);
                error_log('Update query: ' . $wpdb->last_query);
                wp_die('Database update error: ' . $wpdb->last_error . '. Please try again.');
            }
        } else {
            $result = $wpdb->insert($this->table_name, $data);
            $message = 'Button added successfully!';
            
            if ($result === false) {
                error_log('Insert failed. MySQL Error: ' . $wpdb->last_error);
                error_log('Insert query: ' . $wpdb->last_query);
                wp_die('Database insert error: ' . $wpdb->last_error . '. Please try again.');
            }
        }
        
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
            'height' => '600px',
            'zoom' => 10,
        ], $atts, 'ph_map');

        $buttons = $wpdb->get_results("SELECT * FROM $this->table_name ORDER BY sort_order ASC, id ASC");
        
        $id = 'phmap_' . uniqid();
        $mapId = $id . '_map';
        $height = preg_replace('/[^0-9.%vhrempx]/i', '', (string)$atts['height']);
        if ($height === '') { $height = '600px'; }
        $zoom = max(3, min(18, (int)$atts['zoom']));

        ob_start();
        ?>
        <style>
            #<?php echo $id; ?> { 
                --ph-accent: #ff2f6d; 
                --ph-border: #e1e5e9; 
                --ph-panel: #ffffff; 
                --ph-text: #2c3e50;
                --ph-text-light: #7f8c8d;
                color: var(--ph-text); 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            #<?php echo $id; ?> .phmap-controls { 
                display: grid; 
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); 
                gap: 16px; 
                margin: 20px 0; 
                padding: 20px;
                background: var(--ph-panel);
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            #<?php echo $id; ?> .phmap-btn { 
                appearance: none; 
                border: 2px solid var(--ph-border); 
                background: var(--ph-panel); 
                color: var(--ph-text); 
                padding: 16px 20px; 
                border-radius: 12px; 
                cursor: pointer; 
                transition: all 0.3s ease;
                position: relative;
                overflow: hidden;
                text-align: left;
                min-height: 80px;
                display: flex;
                flex-direction: column;
                justify-content: center;
            }
            #<?php echo $id; ?> .phmap-btn:hover { 
                transform: translateY(-2px); 
                box-shadow: 0 4px 16px rgba(0,0,0,0.15);
                border-color: var(--ph-accent);
            }
            #<?php echo $id; ?> .phmap-btn.active { 
                background: var(--ph-accent); 
                border-color: var(--ph-accent); 
                color: white;
                box-shadow: 0 4px 16px rgba(255,47,109,0.3);
            }
            #<?php echo $id; ?> .phmap-btn-content {
                position: relative;
                z-index: 2;
            }
            #<?php echo $id; ?> .phmap-btn-title {
                font-size: 16px;
                font-weight: 600;
                margin-bottom: 4px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            #<?php echo $id; ?> .phmap-btn-description {
                font-size: 13px;
                opacity: 0.8;
                line-height: 1.4;
                margin-top: 4px;
            }
            #<?php echo $id; ?> .phmap-btn-meta {
                font-size: 11px;
                opacity: 0.7;
                margin-top: 8px;
                display: flex;
                gap: 12px;
                align-items: center;
            }
            #<?php echo $id; ?> .phmap-direction-toggle {
                display: flex;
                gap: 4px;
                margin-top: 8px;
            }
            #<?php echo $id; ?> .phmap-direction-btn {
                background: rgba(255,255,255,0.2);
                border: 1px solid rgba(255,255,255,0.3);
                color: inherit;
                padding: 4px 8px;
                border-radius: 4px;
                font-size: 11px;
                cursor: pointer;
                transition: all 0.2s ease;
            }
            #<?php echo $id; ?> .phmap-direction-btn:hover {
                background: rgba(255,255,255,0.3);
            }
            #<?php echo $id; ?> .phmap-direction-btn.active {
                background: rgba(255,255,255,0.9);
                color: #333;
                font-weight: 600;
            }
            #<?php echo $id; ?> .phmap-btn:not(.active) .phmap-direction-btn {
                background: rgba(0,0,0,0.1);
                border-color: rgba(0,0,0,0.2);
            }
            #<?php echo $id; ?> .phmap-btn:not(.active) .phmap-direction-btn:hover {
                background: rgba(0,0,0,0.15);
            }
            #<?php echo $id; ?> .phmap-btn:not(.active) .phmap-direction-btn.active {
                background: rgba(0,0,0,0.2);
                color: #333;
                border-color: rgba(0,0,0,0.3);
            }
            #<?php echo $mapId; ?> { 
                height: <?php echo esc_attr($height); ?>; 
                width: 100%; 
                border: 1px solid var(--ph-border); 
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            #<?php echo $mapId; ?> .leaflet-control-attribution a { 
                color: var(--ph-accent); 
            }
            
            /* Direction indicators */
            #<?php echo $id; ?> .direction-inbound .phmap-btn-title::before { content: '⬅️'; }
            #<?php echo $id; ?> .direction-outbound .phmap-btn-title::before { content: '➡️'; }
            #<?php echo $id; ?> .direction-neutral .phmap-btn-title::before { content: '🔄'; }
            #<?php echo $id; ?> .loop-btn .phmap-btn-title::after { content: '🔄'; margin-left: 4px; }
        </style>

        <div id="<?php echo $id; ?>" class="phmap">
            <div class="phmap-controls">
                <?php foreach ($buttons as $index => $button): ?>
                    <?php 
                    $is_loop = isset($button->is_loop) ? (bool)$button->is_loop : false;
                    $direction = isset($button->direction) ? $button->direction : 'inbound';
                    $color = isset($button->color) ? $button->color : '#ff2f6d';
                    $description = isset($button->description) ? $button->description : '';
                    $route_type = isset($button->route_type) ? $button->route_type : 'transportation';
                    
                    // Check for multiple paths
                    $multiple_paths = isset($button->multiple_paths) ? json_decode($button->multiple_paths, true) : [];
                    $has_multiple_paths = is_array($multiple_paths) && count($multiple_paths) > 0;
                    $has_inbound = json_decode($button->waypoints, true) && count(json_decode($button->waypoints, true)) >= 2;
                    $has_outbound = $has_multiple_paths && isset($multiple_paths[0]['waypoints']) && count($multiple_paths[0]['waypoints']) >= 2;
                    
                    $type_icon = $route_type === 'transportation' ? '🚌' : '👤';
                    $loop_class = $is_loop ? 'loop-btn' : '';
                    $multi_path_class = $has_multiple_paths ? 'multi-path-btn' : '';
                    ?>
                    <button type="button" 
                            class="phmap-btn phmap-path-btn <?php echo $loop_class; ?> <?php echo $multi_path_class; ?>" 
                            data-path-index="<?php echo $index; ?>"
                            data-active-direction="both"
                            style="--ph-accent: <?php echo esc_attr($color); ?>;"
                            title="<?php echo esc_attr($description); ?>">
                        <div class="phmap-btn-content">
                            <div class="phmap-btn-title">
                                <?php echo $type_icon; ?> <?php echo esc_html($button->label); ?>
                                <?php if ($is_loop): ?> 🔄<?php endif; ?>
                            </div>
                            <?php if ($description): ?>
                                <div class="phmap-btn-description">
                                    <?php echo esc_html($description); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($has_inbound || $has_outbound): ?>
                                <div class="phmap-direction-toggle">
                                    <?php if ($has_inbound): ?>
                                        <span class="phmap-direction-btn" data-direction="inbound" data-path-index="<?php echo $index; ?>">🔴 Inbound</span>
                                    <?php endif; ?>
                                    <?php if ($has_outbound): ?>
                                        <span class="phmap-direction-btn" data-direction="outbound" data-path-index="<?php echo $index; ?>">🔵 Outbound</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </button>
                <?php endforeach; ?>
                <?php if (!empty($buttons)): ?>
                    <button type="button" class="phmap-btn phmap-view-all" data-action="view-all">
                        <div class="phmap-btn-content">
                            <div class="phmap-btn-title">🗺️ View All Routes</div>
                            <div class="phmap-btn-description">Display all routes simultaneously</div>
                        </div>
                    </button>
                    <button type="button" class="phmap-btn phmap-clear">
                        <div class="phmap-btn-content">
                            <div class="phmap-btn-title">Clear All Paths</div>
                        </div>
                    </button>
                <?php endif; ?>
            </div>
            <div id="<?php echo $mapId; ?>" aria-label="Map of Cagayan de Oro"></div>
            <div class="phmap-help">
                <?php if (!empty($buttons)): ?>
                    <strong>How to use:</strong> Click any route button above to display the path on the map. 🚌 = Transportation routes, 👤 = Personal routes.
                    <br><small>• Individual buttons: 🔴 Red = Inbound, 🔵 Blue = Outbound • View All Routes: Each button gets a unique color with dashed (inbound) and dotted (outbound) lines • 🔄 = Loop routes</small>
                <?php else: ?>
                    No routes configured yet. <?php if (current_user_can('manage_options')): ?><a href="<?php echo admin_url('admin.php?page=ph-map-buttons'); ?>">Configure routes in admin panel</a>.<?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <script>(function(){
            var root = document.getElementById('<?php echo $id; ?>');
            if (!root) return;
            var mapEl = document.getElementById('<?php echo $mapId; ?>');

            var buttonConfigs = <?php echo json_encode(array_map(function($btn) {
                $multiple_paths = isset($btn->multiple_paths) ? json_decode($btn->multiple_paths, true) : [];
                if (!is_array($multiple_paths)) $multiple_paths = [];
                
                return array(
                    'label' => $btn->label,
                    'description' => isset($btn->description) ? $btn->description : '',
                    'waypoints' => json_decode($btn->waypoints, true),
                    'route' => json_decode($btn->route_data, true),
                    'is_loop' => isset($btn->is_loop) ? (bool)$btn->is_loop : false,
                    'direction' => isset($btn->direction) ? $btn->direction : 'inbound',
                    'color' => isset($btn->color) ? $btn->color : '#ff2f6d',
                    'route_type' => isset($btn->route_type) ? $btn->route_type : 'transportation',
                    'multiple_paths' => $multiple_paths
                );
            }, $buttons), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

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

                // Set bounds for entire Philippines
                var southWest = L.latLng(4.5, 116.0), northEast = L.latLng(21.0, 127.0);
                var bounds = L.latLngBounds(southWest, northEast);
                map.setMaxBounds(bounds);
                map.options.maxBoundsViscosity = 1.0;

                var currentMarkers = [];
                var currentPaths = []; // Changed to array to hold multiple paths
                var activeButton = null;

                function clearCurrentPath(){
                    currentMarkers.forEach(function(m){ map.removeLayer(m); });
                    currentMarkers = [];
                    
                    // Remove all current paths
                    currentPaths.forEach(function(path){ map.removeLayer(path); });
                    currentPaths = [];
                    
                    if (activeButton) { 
                        activeButton.classList.remove('active'); 
                        activeButton.style.backgroundColor = ''; 
                        
                        // Reset direction buttons to starter state
                        var allDirectionBtns = activeButton.querySelectorAll('.phmap-direction-btn');
                        allDirectionBtns.forEach(function(btn) {
                            btn.classList.remove('active');
                        });
                        activeButton.setAttribute('data-active-direction', 'both');
                        
                        activeButton = null; 
                    }
                }

                function showPath(config, buttonEl, activeDirection){
                    clearCurrentPath();
                    activeButton = buttonEl;
                    buttonEl.classList.add('active');
                    buttonEl.style.backgroundColor = config.color;

                    console.log('showPath called with direction:', activeDirection);
                    console.log('Config:', config);

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
                    
                    // Skip adding markers for waypoints - only show paths
                    // (Markers removed for cleaner visualization)

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
            }
        })();</script>
        <?php
        return ob_get_clean();
    }
}

new PHMapPlugin();
?>
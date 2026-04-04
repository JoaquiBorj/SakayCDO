
        <div class="wrap <?php echo $has_filters ? 'phmap-filters-active' : ''; ?>" id="phmap-admin-list" data-filters-active="<?php echo $has_filters ? '1' : '0'; ?>" data-reorder-nonce="<?php echo esc_attr(wp_create_nonce('ph_map_reorder')); ?>">
            <?php $export_url = wp_nonce_url(admin_url('admin-post.php?action=ph_map_export_routes'), 'ph_map_export_routes'); ?>
            <h1>
                PH Map Path Buttons
                <a href="<?php echo admin_url('admin.php?page=ph-map-buttons&action=add'); ?>" class="page-title-action">Add New</a>
                <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">Download Routes Backup</a>
            </h1>

            <?php if (!empty($message)): ?>
                <div class="notice <?php echo $message_type === 'error' ? 'notice-error' : ($message_type === 'warning' ? 'notice-warning' : 'notice-success'); ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="margin: 12px 0 16px; padding: 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 6px;">
                <input type="hidden" name="action" value="ph_map_import_routes">
                <?php wp_nonce_field('ph_map_import_routes', 'ph_map_import_nonce'); ?>
                <div style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                    <label for="routes_file"><strong>Import Routes:</strong></label>
                    <input type="file" name="routes_file" id="routes_file" accept=".json,.geojson,application/json" required>
                    <button type="submit" class="button button-secondary">Upload and Import</button>
                </div>
                <p class="description" style="margin: 8px 0 0;">Supports plugin backup JSON and GeoJSON FeatureCollection (LineString or MultiLineString), compatible with Leaflet/OpenStreetMap workflows.</p>
            </form>

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
            
            
        </div>




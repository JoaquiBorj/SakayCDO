
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



        
        



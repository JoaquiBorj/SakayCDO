

<?php if (!wp_style_is('phmap-frontend', 'done')): ?>
    <link rel="stylesheet" href="<?php echo esc_url($this->plugin_url . 'assets/css/frontend.css?ver=' . rawurlencode($this->assets_version)); ?>">
<?php endif; ?>
<?php if (!wp_style_is('phmap-leaflet', 'done')): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<?php endif; ?>

        <div id="<?php echo $id; ?>" class="phmap phmap-no-selection phmap-sheet-expanded" data-zoom="<?php echo (int)$zoom; ?>">
            <div class="phmap-shell">
                <div class="phmap-map-canvas">
                    <div id="<?php echo $mapId; ?>" class="phmap-map" aria-label="Map of Cagayan de Oro"></div>
                </div>

                <div class="phmap-bottom-sheet" role="region" aria-label="Route finder">
                    <button type="button" class="phmap-sheet-handle" aria-label="Toggle route panel"></button>
                    <div class="phmap-sheet-head">
                        <div>
                            <div class="phmap-sheet-title">Browse jeepney routes</div>
                            <div class="phmap-sheet-subtitle">All predefined routes are listed below. Use From and To as optional filters to narrow results.</div>
                        </div>
                        <div class="phmap-result-count" aria-live="polite">Loading route options...</div>
                    </div>
                    <div class="phmap-sheet-content">
                        <div class="phmap-section phmap-search-section">
                            <div class="phmap-toolbar">
                                <div class="phmap-search-wrap">
                                    <div class="phmap-search-grid">
                                        <label class="phmap-search-field">
                                            <span class="phmap-search-label">From</span>
                                            <input type="search" class="phmap-search-input phmap-from-input" placeholder="Optional: e.g., Agora Terminal" aria-label="From location">
                                        </label>
                                        <label class="phmap-search-field">
                                            <span class="phmap-search-label">To</span>
                                            <input type="search" class="phmap-search-input phmap-to-input" placeholder="Optional: e.g., Divisoria" aria-label="To location">
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div class="phmap-result-detail" aria-live="polite"></div>
                        </div>

                        <?php if (!empty($button_view_data)): ?>
                            <div class="phmap-section">
                                <div class="phmap-action-chips">
                                    <button type="button" class="phmap-action-chip phmap-view-all" data-action="view-all">Show all routes on map</button>
                                    <button type="button" class="phmap-action-chip phmap-clear">Clear selected route</button>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="phmap-section phmap-results-section">
                        <div class="phmap-results-head">
                            <div>
                                <div class="phmap-results-title">Route options</div>
                                <div class="phmap-results-subtitle">Choose a route card to preview it on the map.</div>
                            </div>
                        </div>
                        <div class="phmap-controls">
                <?php foreach ($button_view_data as $index => $button): ?>
                    <?php
                    $is_loop = $button['is_loop'];
                    $color = $button['color'];
                    $description = $button['description'];
                    $route_type = $button['route_type'];
                    $variant_code = isset($button['variant_code']) ? trim((string)$button['variant_code']) : '';
                    $route_label = $variant_code !== '' ? strtoupper($variant_code) : 'ROUTE';
                    $has_inbound = $button['has_inbound'];
                    $has_outbound = $button['has_outbound'];
                    $route_kind = $route_type === 'personal' ? 'Personal' : 'Jeepney';
                    $route_summary = '';
                    if (!empty($button['start']) && !empty($button['end'])) {
                        $route_summary = $button['start'] . ' to ' . $button['end'];
                    } elseif (!empty($button['start']) || !empty($button['end'])) {
                        $route_summary = !empty($button['start']) ? $button['start'] : $button['end'];
                    }
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
                            <div class="phmap-btn-top">
                                <div class="phmap-btn-badge"><?php echo esc_html($route_label); ?><?php if ($is_loop): ?> | LOOP<?php endif; ?></div>
                                <div class="phmap-btn-kind"><?php echo esc_html($route_kind); ?></div>
                            </div>
                            <div class="phmap-btn-title"><?php echo esc_html($button['main_label']); ?></div>
                            <?php if (!empty($route_summary)): ?>
                                <div class="phmap-btn-summary"><?php echo esc_html($route_summary); ?></div>
                            <?php endif; ?>
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

                        <div class="phmap-section phmap-selection-section">
                            <div class="phmap-section-title">Route preview</div>
                            <div class="phmap-selected-summary" aria-live="polite">
                                <div class="phmap-selected-route-name">No route selected yet</div>
                                <div class="phmap-selected-route-meta">Pick a route option to preview its path and direction.</div>
                            </div>
                            <div class="phmap-help">Selected routes are highlighted on the map with start and end markers for quick orientation.</div>
                            <div class="phmap-map-status" aria-live="polite">Select a route option to preview it on the map.</div>
                        </div>
                    </div>
                </div>
            </div>
            <script type="application/json" class="phmap-config-data"><?php echo wp_json_encode($button_view_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?></script>
        </div>

<?php if (!wp_script_is('phmap-leaflet', 'done') && !wp_script_is('phmap-leaflet', 'enqueued')): ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<?php endif; ?>
<?php if (!wp_script_is('phmap-frontend-map', 'done') && !wp_script_is('phmap-frontend-map', 'enqueued')): ?>
    <script src="<?php echo esc_url($this->plugin_url . 'assets/js/frontend-map.js?ver=' . rawurlencode($this->assets_version)); ?>"></script>
<?php endif; ?>



        
        



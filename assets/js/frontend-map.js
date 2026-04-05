(function(){
    function initSingleMap(root){
        if (!root) return;
        document.documentElement.classList.add('phmap-page-lock');
        document.body.classList.add('phmap-page-lock');
        var defaultZoom = parseInt(root.getAttribute('data-zoom') || '12', 10);

            var mapEl = root.querySelector('.phmap-map');
            var mapStatusEl = root.querySelector('.phmap-map-status');
            var resultCountEl = root.querySelector('.phmap-result-count');
            var resultDetailEl = root.querySelector('.phmap-result-detail');
            var fromInputEl = root.querySelector('.phmap-from-input');
            var toInputEl = root.querySelector('.phmap-to-input');
            var selectedRouteNameEl = root.querySelector('.phmap-selected-route-name');
            var selectedRouteMetaEl = root.querySelector('.phmap-selected-route-meta');
            var sheetHandleEl = root.querySelector('.phmap-sheet-handle');
            var suppressAutoPreview = false;
            var lastQuerySignature = '';

            var configEl = root.querySelector('.phmap-config-data');
            var buttonConfigs = [];
            if (configEl) {
                try {
                    buttonConfigs = JSON.parse(configEl.textContent || '[]');
                } catch (e) {
                    buttonConfigs = [];
                }
            }

            function updateMapStatus(title, subtitle) {
                if (!mapStatusEl) return;
                var text = title || 'Select a route option to preview it on the map.';
                if (subtitle) {
                    text += ' ' + subtitle;
                }
                mapStatusEl.textContent = text;
            }

            function updateSelectedRouteSummary(config, activeDirection) {
                if (!selectedRouteNameEl || !selectedRouteMetaEl) return;

                if (!config) {
                    selectedRouteNameEl.textContent = 'No route selected yet';
                    selectedRouteMetaEl.textContent = 'Choose a route card to preview direction and path details.';
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
            }

            function setSheetCollapsed(collapsed) {
                root.classList.toggle('phmap-sheet-collapsed', collapsed);
                root.classList.toggle('phmap-sheet-expanded', !collapsed);
            }

            function clearRecommendedCards() {
                var routeButtons = root.querySelectorAll('.phmap-path-btn');
                routeButtons.forEach(function(btn) {
                    btn.classList.remove('is-recommended');
                });
            }

            function computeMatchScore(btn, fromQuery, toQuery) {
                var score = 0;
                var fromHaystack = (btn.getAttribute('data-from-search') || btn.getAttribute('data-search') || '').toLowerCase();
                var toHaystack = (btn.getAttribute('data-to-search') || btn.getAttribute('data-search') || '').toLowerCase();
                var fullHaystack = (btn.getAttribute('data-search') || '').toLowerCase();

                if (fromQuery.length > 0) {
                    if (fromHaystack === fromQuery) {
                        score += 5;
                    } else if (fromHaystack.indexOf(fromQuery) !== -1) {
                        score += 3;
                    }
                }

                if (toQuery.length > 0) {
                    if (toHaystack === toQuery) {
                        score += 5;
                    } else if (toHaystack.indexOf(toQuery) !== -1) {
                        score += 3;
                    }
                }

                if (fromQuery.length > 0 && toQuery.length > 0 && fullHaystack.indexOf(fromQuery) !== -1 && fullHaystack.indexOf(toQuery) !== -1) {
                    score += 2;
                }

                return score;
            }

            function autoPreviewBestMatch(bestBtn, fromQuery, toQuery) {
                if (!bestBtn || suppressAutoPreview) {
                    return;
                }

                var hasBothQueries = fromQuery.length > 0 && toQuery.length > 0;
                var hasSingleQuery = (fromQuery.length > 0 || toQuery.length > 0);
                if (!hasSingleQuery) {
                    return;
                }

                if (activeButton && activeButton.classList && activeButton.classList.contains('phmap-path-btn') && !activeButton.classList.contains('is-hidden')) {
                    return;
                }

                if (hasBothQueries || bestBtn.classList.contains('is-recommended')) {
                    var index = parseInt(bestBtn.getAttribute('data-path-index'));
                    if (buttonConfigs[index]) {
                        clearCurrentPath();
                        bestBtn.setAttribute('data-active-direction', 'both');
                        showPath(buttonConfigs[index], bestBtn, 'both');
                    }
                }
            }

            function updateSearchResults() {
                var fromQuery = fromInputEl ? fromInputEl.value.trim().toLowerCase() : '';
                var toQuery = toInputEl ? toInputEl.value.trim().toLowerCase() : '';
                var hasQuery = fromQuery.length > 0 || toQuery.length > 0;
                var visibleCount = 0;
                var routeButtons = root.querySelectorAll('.phmap-path-btn');
                var bestBtn = null;
                var bestScore = -1;
                var hasScoreTie = false;
                var querySignature = fromQuery + '|' + toQuery;

                if (querySignature !== lastQuerySignature) {
                    suppressAutoPreview = false;
                    lastQuerySignature = querySignature;
                }

                clearRecommendedCards();

                routeButtons.forEach(function(btn) {
                    var fromHaystack = (btn.getAttribute('data-from-search') || btn.getAttribute('data-search') || '').toLowerCase();
                    var toHaystack = (btn.getAttribute('data-to-search') || btn.getAttribute('data-search') || '').toLowerCase();
                    var matchesFrom = fromQuery.length === 0 || fromHaystack.indexOf(fromQuery) !== -1;
                    var matchesTo = toQuery.length === 0 || toHaystack.indexOf(toQuery) !== -1;
                    var matches = !hasQuery || (matchesFrom && matchesTo);
                    btn.classList.toggle('is-hidden', !matches);
                    if (matches) {
                        visibleCount++;
                        var score = computeMatchScore(btn, fromQuery, toQuery);
                        if (score > bestScore) {
                            bestScore = score;
                            bestBtn = btn;
                            hasScoreTie = false;
                        } else if (score === bestScore && score > 0) {
                            hasScoreTie = true;
                        }
                    } else if (btn.classList.contains('active')) {
                        clearCurrentPath();
                    }
                });

                if (bestBtn && bestScore > 0 && !hasScoreTie) {
                    bestBtn.classList.add('is-recommended');
                }

                if (resultCountEl) {
                    if (!hasQuery) {
                        resultCountEl.textContent = visibleCount + ' route option' + (visibleCount === 1 ? '' : 's') + ' available';
                    } else if (visibleCount === 0) {
                        resultCountEl.textContent = 'No route match yet';
                    } else {
                        resultCountEl.textContent = visibleCount + ' route option' + (visibleCount === 1 ? '' : 's') + ' found';
                    }
                }

                if (resultDetailEl) {
                    if (!hasQuery) {
                        resultDetailEl.innerHTML = '<strong>Tip:</strong> Filters are optional. You can directly pick any route from the list.';
                    } else {
                        var terms = [];
                        if (fromQuery.length > 0) {
                            terms.push('From "' + fromQuery + '"');
                        }
                        if (toQuery.length > 0) {
                            terms.push('To "' + toQuery + '"');
                        }
                        if (visibleCount === 0) {
                            resultDetailEl.textContent = 'No routes match those filters. Try a shorter place name or clear one field.';
                        } else if (bestBtn && bestScore > 0 && !hasScoreTie) {
                            var bestTitleEl = bestBtn.querySelector('.phmap-btn-title');
                            var bestTitle = bestTitleEl ? bestTitleEl.textContent.trim() : 'top route';
                            resultDetailEl.innerHTML = '<strong>Best match:</strong> ' + bestTitle + '. Showing matches for ' + terms.join(' and ') + '.';
                        } else {
                            resultDetailEl.textContent = 'Showing matches for ' + terms.join(' and ') + '.';
                        }
                    }
                }

                autoPreviewBestMatch(bestBtn, fromQuery, toQuery);

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
                var map = L.map(mapEl, { zoomControl:true, attributionControl:true, minZoom: 11, maxZoom: 19 }).setView(cityCenter, defaultZoom);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
                }).addTo(map);

                var currentMarkers = [];
                var currentPaths = []; // Changed to array to hold multiple paths
                var activeButton = null;

                var cdoBounds = L.latLngBounds(
                    L.latLng(8.30, 124.47),
                    L.latLng(8.61, 124.81)
                );
                map.setView(cityCenter, defaultZoom);
                map.setMaxBounds(cdoBounds);
                map.options.maxBoundsViscosity = 1.0;

                function softenHexColor(hex, amount) {
                    amount = typeof amount === 'number' ? amount : 0.35;
                    var normalized = (hex || '').replace('#', '').trim();
                    if (!/^[0-9a-fA-F]{6}$/.test(normalized)) {
                        return '#1565C0';
                    }

                    var r = parseInt(normalized.slice(0, 2), 16);
                    var g = parseInt(normalized.slice(2, 4), 16);
                    var b = parseInt(normalized.slice(4, 6), 16);

                    var soften = function(channel) {
                        return Math.round(channel + (255 - channel) * amount);
                    };

                    var toHex = function(channel) {
                        var value = soften(channel).toString(16);
                        return value.length === 1 ? '0' + value : value;
                    };

                    return '#' + toHex(r) + toHex(g) + toHex(b);
                }

                function clearCurrentPath(){
                    currentMarkers.forEach(function(m){ map.removeLayer(m); });
                    currentMarkers = [];
                    
                    // Remove all current paths
                    currentPaths.forEach(function(path){ map.removeLayer(path); });
                    currentPaths = [];
                    
                    if (activeButton) { 
                        activeButton.classList.remove('active'); 
                        activeButton.style.removeProperty('--route-accent');
                        
                        // Reset direction buttons to starter state
                        var allDirectionBtns = activeButton.querySelectorAll('.phmap-direction-btn');
                        allDirectionBtns.forEach(function(btn) {
                            btn.classList.remove('active');
                        });
                        activeButton.setAttribute('data-active-direction', 'both');
                        
                        activeButton = null; 
                    }

                    updateMapStatus('Select a route option to preview it on the map.');
                    updateSelectedRouteSummary(null, 'both');
                    setSelectionState(false);
                }

                function showPath(config, buttonEl, activeDirection){
                    clearCurrentPath();
                    activeButton = buttonEl;
                    buttonEl.classList.add('active');
                    buttonEl.style.setProperty('--route-accent', softenHexColor(config.color || '#1565C0', 0.35));

                    console.log('showPath called with direction:', activeDirection);
                    console.log('Config:', config);

                    var directionSummary = 'Showing both directions.';
                    if (activeDirection === 'inbound') {
                        directionSummary = config.inbound_label || 'Showing inbound direction.';
                    } else if (activeDirection === 'outbound') {
                        directionSummary = config.outbound_label || 'Showing outbound direction.';
                    }
                    updateMapStatus('Previewing: ' + (config.main_label || config.label), directionSummary);
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
                                '#c97a8a', // Soft red
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
                                '#c97a8a', // Soft red
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
                    updateMapStatus('Previewing all routes.', 'Tap a single route card to focus on one path.');
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

                    btn.addEventListener('mouseenter', function(){
                        var index = parseInt(btn.getAttribute('data-path-index'));
                        var config = buttonConfigs[index];
                        if (!config || (activeButton && activeButton === btn)) return;
                        updateMapStatus('Route option: ' + (config.main_label || config.label), 'Click to preview this route on the map.');
                    });

                    btn.addEventListener('mouseleave', function(){
                        if (activeButton) return;
                        updateMapStatus('Select a route option to preview it on the map.');
                    });
                    
                    btn.addEventListener('click', function(e){
                        console.log('Button clicked:', e.target, 'classList:', e.target.classList.toString());
                        suppressAutoPreview = true;
                        
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
                        suppressAutoPreview = true;
                        
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
                        suppressAutoPreview = true;
                        if (this.classList.contains('active')) {
                            console.log('View All is active, clearing paths');
                            clearCurrentPath();
                        } else {
                            console.log('View All is not active, showing all routes');
                            clearCurrentPath();
                            this.classList.add('active');
                            activeButton = this;
                            showAllRoutes();
                        }
                    });
                }

                var clearBtn = root.querySelector('.phmap-clear');
                if (clearBtn) {
                    clearBtn.addEventListener('click', function(){ 
                        console.log('Clear button clicked');
                        suppressAutoPreview = false;
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
        
    }

    function initAllMaps(){
        var roots = document.querySelectorAll('.phmap');
        roots.forEach(function(root){
            initSingleMap(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAllMaps);
    } else {
        initAllMaps();
    }
})();



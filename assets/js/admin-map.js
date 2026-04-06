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
                        color: '#ef4444' // Bright red
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

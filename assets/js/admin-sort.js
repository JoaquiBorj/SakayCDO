jQuery(document).ready(function($) {

                var root = document.getElementById('phmap-admin-list');
                var filtersActive = root ? root.getAttribute('data-filters-active') === '1' : false;
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
                                nonce: (root ? root.getAttribute('data-reorder-nonce') : '')
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

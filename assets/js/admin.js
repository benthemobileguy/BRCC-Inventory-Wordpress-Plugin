/**
 * BRCC Inventory Tracker Admin JavaScript
 */

// Make chart functions globally accessible
window.loadChartData = function(days) {
    console.log('Global loadChartData called with days:', days);
    
    jQuery.ajax({
        url: brcc_admin.ajax_url,
        type: 'POST',
        data: {
            action: 'brcc_get_chart_data',
            nonce: brcc_admin.nonce,
            days: days,
            end_date: jQuery('#brcc-date-filter').val() || null
        },
        success: function(response) {
            console.log('Chart data received:', response);
            
            if (response.success && response.data && response.data.chart_data) {
                window.updateSalesChart(response.data.chart_data);
            } else {
                console.error('Invalid chart data response', response);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX request failed:', status, error);
        }
    });
};

window.updateSalesChart = function(chartData) {
    console.log('Global updateSalesChart called with data:', chartData);
    
    if (window.salesChart) {
        window.salesChart.data.labels = chartData.labels;
        window.salesChart.data.datasets = chartData.datasets;
        window.salesChart.update();
        console.log('Chart updated successfully');
    } else {
        console.error('Cannot update chart: salesChart is not defined');
    }
};

(function ($) {
    'use strict';

    // Document ready
    $(function () {
        // Initialize datepickers
        if ($.fn.datepicker) {
            $('.brcc-datepicker').datepicker({
                dateFormat: 'yy-mm-dd',
                changeMonth: true,
                changeYear: true,
                maxDate: 0
            });
        }

        // Regenerate API key
        $('#regenerate-api-key').on('click', function (e) {
            e.preventDefault();

            if (confirm(brcc_admin.regenerate_key_confirm)) {
                $.ajax({
                    url: brcc_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'brcc_regenerate_api_key',
                        nonce: brcc_admin.nonce
                    },
                    success: function (response) {
                        if (response.success) {
                            $('#api_key').val(response.data.api_key);
                        } else {
                            alert(response.data.message);
                        }
                    },
                    error: function () {
                        alert(brcc_admin.ajax_error);
                    }
                });
            }
        });

        // Update date filter
        $('#brcc-update-date').on('click', function (e) {
            e.preventDefault();

            var date = $('#brcc-date-filter').val();

            if (date) {
                window.location.href = brcc_admin.admin_url + '?page=brcc-inventory&date=' + encodeURIComponent(date);
            }
        });

        // Filter date range
        $('#brcc-filter-date-range').on('click', function (e) {
            e.preventDefault();

            var startDate = $('#brcc-start-date').val();
            var endDate = $('#brcc-end-date').val();

            if (startDate && endDate) {
                window.location.href = brcc_admin.admin_url + '?page=brcc-daily-sales&start_date=' + encodeURIComponent(startDate) + '&end_date=' + encodeURIComponent(endDate);
            }
        });

        // CSV Export
        $('#brcc-export-csv').on('click', function (e) {
            e.preventDefault();

            var startDate = $('#brcc-start-date').val() || '';
            var endDate = $('#brcc-end-date').val() || '';

            if (!startDate || !endDate) {
                alert('Please select both start and end dates');
                return;
            }

            // Trigger the direct download link instead
            $('#brcc-direct-download').trigger('click');
        });
        
        // Sync now button
        $('#brcc-sync-now').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            $button.prop('disabled', true).text(brcc_admin.syncing);

            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_sync_inventory_now',
                    nonce: brcc_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        // Reload page to show updated data
                        window.location.reload();
                    } else {
                        alert(response.data.message);
                        $button.prop('disabled', false).text(brcc_admin.sync_now);
                    }
                },
                error: function () {
                    alert(brcc_admin.ajax_error);
                    $button.prop('disabled', false).text(brcc_admin.sync_now);
                }
            });
        });

        // Save product mappings
        $('#brcc-save-mappings').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            $button.prop('disabled', true).text(brcc_admin.saving);

            var mappings = {};

            // Collect all mapping inputs
            $('input[name^="brcc_product_mappings"]').each(function () {
                var $input = $(this);
                var name = $input.attr('name');
                var matches = name.match(/brcc_product_mappings\[(\d+)\]\[([^\]]+)\]/);

                if (matches && matches.length === 3) {
                    var productId = matches[1];
                    var field = matches[2];

                    if (!mappings[productId]) {
                        mappings[productId] = {};
                    }

                    mappings[productId][field] = $input.val();
                }
            });

            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_save_product_mappings',
                    nonce: brcc_admin.nonce,
                    mappings: mappings
                },
                success: function (response) {
                    if (response.success) {
                        $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-success').append($('<p>').text(response.data.message))).show(); // Use .text()
                    } else {
                        $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(response.data.message))).show(); // Use .text()
                    }

                    $button.prop('disabled', false).text(brcc_admin.save_mappings);

                    // Hide message after 5 seconds
                    setTimeout(function () {
                        $('#brcc-mapping-result').fadeOut();
                    }, 5000);
                },
                error: function () {
                    $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(brcc_admin.ajax_error))).show(); // Use .text()
                    $button.prop('disabled', false).text(brcc_admin.save_mappings);
                }
            });
        });

        // Test mapping
        $('.brcc-test-mapping').on('click', function (e) {
            e.preventDefault();

            var $button = $(this);
            var productId = $button.data('product-id');

            $button.prop('disabled', true).text(brcc_admin.testing);

            // Get mapping values from inputs
            var eventbriteId = $('input[name="brcc_product_mappings[' + productId + '][eventbrite_id]"]').val();

            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_test_product_mapping',
                    nonce: brcc_admin.nonce,
                    product_id: productId,
                    eventbrite_id: eventbriteId
                },
                success: function (response) {
                    if (response.success) {
                        // Display message as text for security
                        $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-success').append($('<p>').text(response.data.message))).show(); // Use .text()
                    } else {
                        $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(response.data.message))).show(); // Use .text() for error message
                    }

                    $button.prop('disabled', false).text(brcc_admin.test);

                    // Hide message after 5 seconds
                    setTimeout(function () {
                        $('#brcc-mapping-result').fadeOut();
                    }, 5000);
                },
                error: function () {
                    $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(brcc_admin.ajax_error))).show(); // Use .text()
                    $button.prop('disabled', false).text(brcc_admin.test);
                }
            });
        });

        // Update chart button click handler
        $('#brcc-update-chart').on('click', function() {
            var days = $('#brcc-chart-days').val();
            console.log('Update chart clicked - days:', days);
            window.loadChartData(days);
        });
        
        // Filter logs
        $('#brcc-filter-logs').on('click', function() {
            var source = $('#brcc-log-source').val();
            var mode = $('#brcc-log-mode').val();
            
            $('.brcc-log-row').show();
            
            if (source) {
                $('.brcc-log-row').not('[data-source="' + source + '"]').hide();
            }
            
            if (mode) {
                $('.brcc-log-row').not('[data-mode="' + mode + '"]').hide();
            }
        });
        
        // Date mapping modal
        var currentProductId = null;
        var datesMappings = {};
        
        // Open modal when "View/Edit Dates" button is clicked
        $(document).on('click', '.brcc-view-dates', function() {
            currentProductId = $(this).data('product-id');
            
            // Reset modal content
            $('#brcc-dates-table-body').html('');
            $('#brcc-dates-table').hide();
            $('#brcc-no-dates').hide();
            $('#brcc-dates-loading').show();
            
            // Open modal
            $('#brcc-date-mappings-modal').show();
            
            // Load dates for this product
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_get_product_dates',
                    nonce: brcc_admin.nonce,
                    product_id: currentProductId
                },
                success: function(response) {
                    $('#brcc-dates-loading').hide();
                    
                    if (response.success && response.data.dates && response.data.dates.length > 0) {
                        // Store date mappings for this product
                        datesMappings[currentProductId] = response.data.dates;
                        
                        // Populate table
                        var html = '';
                        $.each(response.data.dates, function(index, date) {
                            html += '<tr data-date="' + date.date + '">';
                            html += '<td>' + date.formatted_date + '</td>';
                            html += '<td>' + (date.inventory !== null ? date.inventory : 'N/A') + '</td>';
                            html += '<td><input type="text" class="regular-text date-eventbrite-id" value="' + (date.eventbrite_id || '') + '" /></td>';
                            html += '<td><button type="button" class="button brcc-test-date-mapping" data-date="' + date.date + '">' + brcc_admin.test + '</button>';
                            html += '<div class="brcc-date-test-result"></div></td>';
                            html += '</tr>';
                        });
                        
                        $('#brcc-dates-table-body').html(html);
                        $('#brcc-dates-table').show();
                    } else {
                        $('#brcc-no-dates').show();
                    }
                },
                error: function() {
                    $('#brcc-dates-loading').hide();
                    $('#brcc-no-dates').html('<p>' + brcc_admin.ajax_error + '</p>').show();
                }
            });
        });
        
        // Close modal
        $('.brcc-modal-close, #brcc-close-modal').on('click', function() {
            $('#brcc-date-mappings-modal').hide();
        });
        
        // Click outside to close
        $(window).on('click', function(event) {
            if ($(event.target).is('#brcc-date-mappings-modal')) {
                $('#brcc-date-mappings-modal').hide();
            }
        });
        
        // Save date mappings
        $('#brcc-save-date-mappings').on('click', function() {
            var $button = $(this);
            $button.prop('disabled', true).text(brcc_admin.saving);
            
            // Collect all date mappings for the current product
            var mappings = [];
            $('#brcc-dates-table-body tr').each(function() {
                var $row = $(this);
                var date = $row.data('date');
                var eventbriteId = $row.find('.date-eventbrite-id').val();
                
                if (eventbriteId) {
                    mappings.push({
                        date: date,
                        eventbrite_id: eventbriteId
                    });
                }
            });
            
            // Save via AJAX
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_save_product_date_mappings',
                    nonce: brcc_admin.nonce,
                    product_id: currentProductId,
                    mappings: mappings
                },
                success: function(response) {
                    $button.prop('disabled', false).text('Save Date Mappings');
                    
                    if (response.success) {
                        // Update button text to reflect saved mappings
                        $('.brcc-view-dates[data-product-id="' + currentProductId + '"]').text('View/Edit Dates');
                        
                        // Show success message
                        alert(response.data.message);
                        
                        // Close modal
                        $('#brcc-date-mappings-modal').hide();
                    } else {
                        alert(response.data.message || brcc_admin.ajax_error);
                    }
                },
                error: function() {
                    $button.prop('disabled', false).text('Save Date Mappings');
                    alert(brcc_admin.ajax_error);
                }
            });
        });
       
    // Test date mapping
        $(document).on('click', '.brcc-test-date-mapping', function() {
            var $button = $(this);
            var date = $button.data('date');
            var $row = $button.closest('tr');
            var eventbriteId = $row.find('.date-eventbrite-id').val();
            var $resultContainer = $row.find('.brcc-date-test-result');
            
            $button.prop('disabled', true).text(brcc_admin.testing);
            $resultContainer.hide();
            
            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_test_product_date_mapping',
                    nonce: brcc_admin.nonce,
                    product_id: currentProductId,
                    date: date,
                    eventbrite_id: eventbriteId
                },
                success: function(response) {
                    $button.prop('disabled', false).text(brcc_admin.test);
                    
                    if (response.success) {
                        $resultContainer.html(response.data.message).show();
                    } else {
                        $resultContainer.html(response.data.message || brcc_admin.ajax_error).show();
                    }
                    
                    // Hide the result after a few seconds
                    setTimeout(function() {
                        $resultContainer.fadeOut();
                    }, 8000);
                },
                error: function() {
                    $button.prop('disabled', false).text(brcc_admin.test);
                    $resultContainer.html(brcc_admin.ajax_error).show();
                    
                    // Hide the result after a few seconds
                    setTimeout(function() {
                        $resultContainer.fadeOut();
                    }, 8000);
                }
            });
        });
    });

    // Suggest Eventbrite ID
    $(document).on('click', '.brcc-suggest-eventbrite-id', function(e) {
        e.preventDefault();
        var $button = $(this);
        var productId = $button.data('product-id');
        var $inputField = $('input[name="brcc_product_mappings[' + productId + '][eventbrite_id]"]');
        var $resultDiv = $('#brcc-suggestion-' + productId);

        $button.prop('disabled', true).text('Suggesting...');
        $resultDiv.html('<i>' + ('Suggesting ID...') + '</i>').show(); // Use localized string if available

        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_suggest_eventbrite_id', // New AJAX action
                nonce: brcc_admin.nonce,
                product_id: productId
            },
            success: function(response) {
                if (response.success && response.data.suggestion) {
                    var suggestion = response.data.suggestion;
                    var suggestionHtml =
                        $('<span>').text('Suggestion: ') // Use text() for security
                        .append($('<code>').text(suggestion.ticket_id))
                        .append($('<br>'))
                        .append($('<em>').text(suggestion.event_name + ' - ' + suggestion.ticket_name))
                        .append($('<br>'))
                        .append(
                            $('<button>')
                                .addClass('button button-small brcc-apply-suggestion')
                                .text('Apply') // Use localized string if available
                                .data('ticket-id', suggestion.ticket_id)
                                .data('event-id', suggestion.event_id) // Add event ID
                                .data('target-input', 'brcc_product_mappings[' + productId + '][eventbrite_id]')
                                .data('target-event-input', 'brcc_product_mappings[' + productId + '][eventbrite_event_id]') // Target for hidden input
                        );
                    $resultDiv.html(suggestionHtml);
                } else {
                     $resultDiv.html('<i>' + (response.data.message || 'No suggestion found.') + '</i>'); // Use localized string
                }
            },
            error: function() {
                $resultDiv.html('<i>' + brcc_admin.ajax_error + '</i>');
            },
            complete: function() {
                 $button.prop('disabled', false).text('Suggest'); // Use localized string
                 // Optional: Hide message after a delay if needed
                 // setTimeout(function() { $resultDiv.fadeOut(); }, 10000);
            }
        });
    });

    // Apply Suggestion Button
    $(document).on('click', '.brcc-apply-suggestion', function() {
        var $button = $(this);
        var ticketId = $button.data('ticket-id');
        var eventId = $button.data('event-id'); // Get event ID
        var targetInputName = $button.data('target-input');
        var targetEventInputName = $button.data('target-event-input'); // Get hidden input target name

        // Set value for visible ticket ID input
        $('input[name="' + targetInputName + '"]').val(ticketId);
        // Set value for hidden event ID input
        $('input[name="' + targetEventInputName + '"]').val(eventId);

        $button.closest('.brcc-suggestion-result').html('<i>Applied!</i>').fadeOut(2000); // Provide feedback
    });
/**
 * Test Square Connection
 */
$('#brcc-test-square-connection').on('click', function(e) {
    e.preventDefault();
    
    var $button = $(this);
    $button.prop('disabled', true).text('Testing connection...');
    
    $.ajax({
        url: brcc_admin.ajax_url,
        type: 'POST',
        data: {
            action: 'brcc_test_square_connection',
            nonce: brcc_admin.nonce
        },
        success: function(response) {
            $button.prop('disabled', false).text('Test Square Connection');
            
            if (response.success) {
                $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-success').append($('<p>').text(response.data.message))).show(); // Use .text()
            } else {
                $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(response.data.message))).show(); // Use .text()
            }
            
            // Hide message after 5 seconds
            setTimeout(function() {
                $('#brcc-mapping-result').fadeOut();
            }, 5000);
        },
        error: function() {
            $button.prop('disabled', false).text('Test Square Connection');
            $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(brcc_admin.ajax_error))).show(); // Use .text()
        }
    });
});

/**
 * Fetch Square Catalog
 */
 // ... (existing Square catalog code) ...

/**
 * Attendee List Page Logic
 */
jQuery(document).ready(function($) {
    // Only run on the attendee list page
    if ($('#brcc-attendee-product-select').length) {

        var $productSelect = $('#brcc-attendee-product-select');
        var $fetchButton = $('#brcc-fetch-attendees');
        var $container = $('#brcc-attendee-list-container');
        var $dateFilterPlaceholder = $('#brcc-attendee-date-filter-placeholder'); // Placeholder for future date filters

        // Enable button only when a product is selected
        $productSelect.on('change', function() {
            if ($(this).val()) {
                $fetchButton.prop('disabled', false);
                // Reset date filter and results when product changes
                $dateFilterPlaceholder.empty();
                $container.html('<p>' + brcc_admin.select_product_prompt + '</p>'); // Use localized string
            } else {
                $fetchButton.prop('disabled', true);
                 $dateFilterPlaceholder.empty();
                 $container.html('<p>' + brcc_admin.select_product_prompt + '</p>'); // Use localized string
            }
        });

        // Fetch attendees on button click
        $fetchButton.on('click', function() {
            var productId = $productSelect.val();
            if (!productId) return;

            $fetchButton.prop('disabled', true).text(brcc_admin.fetching || 'Fetching...'); // Use localized string
            $container.html('<p><span class="spinner is-active" style="float: none; vertical-align: middle;"></span> ' + (brcc_admin.loading_attendees || 'Loading attendee data...') + '</p>'); // Use localized string

            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'brcc_fetch_attendees',
                    nonce: brcc_admin.nonce,
                    product_id: productId
                    // Add date/time parameters here if date filters are implemented
                },
                success: function(response) {
                    $container.empty(); // Clear loading message

                    if (response.success && response.data.attendees) {
                        var attendees = response.data.attendees;

                        if (attendees.length > 0) {
                            var tableHtml = '<table class="wp-list-table widefat fixed striped"><thead><tr>' +
                                            '<th>' + (brcc_admin.col_name || 'Name') + '</th>' +
                                            '<th>' + (brcc_admin.col_email || 'Email') + '</th>' +
                                            '<th>' + (brcc_admin.col_source || 'Source') + '</th>' +
                                            '<th>' + (brcc_admin.col_purchase_date || 'Purchase Date') + '</th>' +
                                            '</tr></thead><tbody>';

                            attendees.forEach(function(attendee) {
                                tableHtml += '<tr>' +
                                             '<td>' + escapeHtml(attendee.name || '') + '</td>' +
                                             '<td>' + escapeHtml(attendee.email || '') + '</td>' +
                                             '<td>' + escapeHtml(attendee.source || '') + '</td>' +
                                             '<td>' + escapeHtml(attendee.purchase_date || '') + '</td>' +
                                             '</tr>';
                            });

                            tableHtml += '</tbody></table>';
                            $container.html(tableHtml);
                        } else {
                            $container.html('<p>' + (brcc_admin.no_attendees_found || 'No attendees found for this product.') + '</p>'); // Use localized string
                        }

                    } else {
                        var message = response.data && response.data.message ? response.data.message : (brcc_admin.error_fetching_attendees || 'Error fetching attendees.'); // Use localized string
                        $container.html('<div class="notice notice-error"><p>' + escapeHtml(message) + '</p></div>');
                    }
                },
                error: function() {
                    $container.html('<div class="notice notice-error"><p>' + brcc_admin.ajax_error + '</p></div>');
                },
                complete: function() {
                    $fetchButton.prop('disabled', false).text(brcc_admin.fetch_attendees_btn || 'Fetch Attendees'); // Use localized string
                }
            });
        });

        // Helper function to escape HTML
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        }

        // Initial state message
         $container.html('<p>' + (brcc_admin.select_product_prompt || 'Please select a product to fetch attendees.') + '</p>'); // Use localized string

    } // end if on attendee page
});

$('#brcc-fetch-square-catalog').on('click', function(e) {
    e.preventDefault();
    
    var $button = $(this);
    $button.prop('disabled', true).text('Fetching catalog...');
    
    $.ajax({
        url: brcc_admin.ajax_url,
        type: 'POST',
        data: {
            action: 'brcc_get_square_catalog',
            nonce: brcc_admin.nonce
        },
        success: function(response) {
            $button.prop('disabled', false).text('View Square Catalog');
            
            var $catalogContainer = $('#brcc-square-catalog-items'); // Target container
            $catalogContainer.empty(); // Clear previous results

            if (response.success) {
                var catalog = response.data.catalog;
                
                if (catalog && catalog.length > 0) {
                    // Create table structure safely
                    var $table = $('<table>').addClass('wp-list-table widefat fixed striped');
                    var $thead = $('<thead>').appendTo($table);
                    var $tbody = $('<tbody>').appendTo($table);
                    var $trHead = $('<tr>').appendTo($thead);

                    // Add headers safely
                    $('<th>').text('Item Name').appendTo($trHead);
                    $('<th>').text('Item ID').appendTo($trHead);
                    $('<th>').text('Description').appendTo($trHead);
                    $('<th>').text('Variations').appendTo($trHead);
                    
                    // Populate table body safely
                    $.each(catalog, function(i, item) {
                        var $tr = $('<tr>').appendTo($tbody);
                        $('<td>').text(item.name || '').appendTo($tr); // Use .text()
                        $('<td>').append($('<code>').text(item.id || '')).appendTo($tr); // Use .text() within code tag
                        $('<td>').text(item.description || '').appendTo($tr); // Use .text()
                        
                        var $variationsTd = $('<td>').appendTo($tr);
                        if (item.variations && item.variations.length > 0) {
                            var $ul = $('<ul>').css({margin: 0, paddingLeft: '20px'}).appendTo($variationsTd);
                            $.each(item.variations, function(j, variation) {
                                var $li = $('<li>').appendTo($ul);
                                $li.append(document.createTextNode((variation.name || '') + ' - '));
                                $li.append($('<code>').text(variation.id || ''));
                                $li.append(document.createTextNode(' ($' + (variation.price || '0.00') + ')'));
                            });
                        } else {
                            $variationsTd.text('No variations'); // Use .text()
                        }
                    });
                    
                    $catalogContainer.append($table); // Append the generated table
                } else {
                    $catalogContainer.append($('<p>').text('No catalog items found.')); // Use .text()
                }
                
                $('#brcc-square-catalog-container').show();
            } else {
                $('#brcc-mapping-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>').show();
            }
        },
        error: function() {
            $button.prop('disabled', false).text('View Square Catalog');
            $('#brcc-mapping-result').html('<div class="notice notice-error"><p>' + brcc_admin.ajax_error + '</p></div>').show();
        }
    });
});

/**
 * Test Square Mapping
 */
$('.brcc-test-square-mapping').on('click', function(e) {
    e.preventDefault();
    
    var $button = $(this);
    var productId = $button.data('product-id');
    
    $button.prop('disabled', true).text(brcc_admin.testing);
    
    // Get mapping values from inputs
    var squareId = $('input[name="brcc_product_mappings[' + productId + '][square_id]"]').val();
    
    $.ajax({
        url: brcc_admin.ajax_url,
        type: 'POST',
        data: {
            action: 'brcc_test_square_mapping',
            nonce: brcc_admin.nonce,
            product_id: productId,
            square_id: squareId
        },
        success: function(response) {
            if (response.success) {
                $('#brcc-mapping-result').html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>').show();
            } else {
                $('#brcc-mapping-result').html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>').show();
            }
            
            $button.prop('disabled', false).text('Test Square');
            
            // Hide message after 5 seconds
            setTimeout(function() {
                $('#brcc-mapping-result').fadeOut();
            }, 5000);
        },
        error: function() {
            $('#brcc-mapping-result').empty().append($('<div>').addClass('notice notice-error').append($('<p>').text(brcc_admin.ajax_error))).show(); // Use .text()
            $button.prop('disabled', false).text('Test Square');
        }
    });
});

    // --- Import History Page ---
    if ($('#brcc-start-import').length) {
        
        // Initialize date pickers for import range
        $('#brcc-import-start-date, #brcc-import-end-date').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true,
            maxDate: 0 // Allow selecting past dates
        });

        var importLogContainer = $('#brcc-import-log');
        var importProgressBar = $('#brcc-import-progress-bar');
        var importStatusMessage = $('#brcc-import-status-message');
        var importCompleteButton = $('#brcc-import-complete');
        var importInProgress = false;

        // Function to add log messages
        function addImportLog(message, type) {
            var logClass = type === 'error' ? 'color: red;' : (type === 'warning' ? 'color: orange;' : '');
            // Use text() to set content safely, then wrap if needed or apply style directly
            var $logEntry = $('<div>').css('color', type === 'error' ? 'red' : (type === 'warning' ? 'orange' : 'inherit')).text(message);
            importLogContainer.append($logEntry);
            importLogContainer.scrollTop(importLogContainer[0].scrollHeight); // Scroll to bottom
        }

        // Function to process an import batch
        function processImportBatch(offset, data) {
            data.offset = offset;
            data.action = 'brcc_import_batch'; // New AJAX action
            data.nonce = $('input[name="brcc_import_nonce"]').val(); // Get the correct nonce from the hidden field

            $.ajax({
                url: brcc_admin.ajax_url,
                type: 'POST',
                data: data,
                success: function(response) {
                    if (!importInProgress) return; // Stop if cancelled

                    if (response.success) {
                        // Append logs
                        if (response.data.logs && response.data.logs.length > 0) {
                            response.data.logs.forEach(function(log) {
                                addImportLog(log.message, log.type);
                            });
                        }

                        // Update progress
                        var progress = response.data.progress || 0;
                        importProgressBar.val(progress);
                        importStatusMessage.text(response.data.message || 'Processing...'); // Use .text()

                        // Process next batch or complete
                        if (response.data.next_offset !== null && response.data.next_offset !== undefined) {
                            processImportBatch(response.data.next_offset, data);
                        } else {
                            addImportLog('Import completed!', 'success');
                            importStatusMessage.text('Import completed!'); // Use .text()
                            importProgressBar.val(100);
                            importCompleteButton.show();
                            $('#brcc-start-import').prop('disabled', false).text('Start Import');
                            importInProgress = false;
                        }
                    } else {
                        addImportLog('Error: ' + (response.data.message || 'Unknown error during import.'), 'error');
                        importStatusMessage.text('Import failed. Check log for details.'); // Use .text()
                        importCompleteButton.show();
                         $('#brcc-start-import').prop('disabled', false).text('Start Import');
                        importInProgress = false;
                    }
                },
                error: function(xhr, status, error) {
                    if (!importInProgress) return;
                    addImportLog('AJAX Error: ' + status + ' - ' + error, 'error');
                    importStatusMessage.text('Import failed due to network or server error.'); // Use .text()
                    importCompleteButton.show();
                     $('#brcc-start-import').prop('disabled', false).text('Start Import');
                    importInProgress = false;
                }
            });
        }

        // Start Import button click
        $('#brcc-start-import').on('click', function() {
            var $button = $(this);
            var startDate = $('#brcc-import-start-date').val();
            var endDate = $('#brcc-import-end-date').val();
            var sources = $('input[name="brcc_import_sources[]"]:checked').map(function() {
                return $(this).val();
            }).get();

            if (!startDate || !endDate) {
                alert('Please select both a start and end date.');
                return;
            }
            if (sources.length === 0) {
                alert('Please select at least one data source (WooCommerce or Square).');
                return;
            }
             if (new Date(startDate) > new Date(endDate)) {
                 alert('Start date cannot be after end date.');
                 return;
             }

            if (!confirm('Start importing historical data from ' + startDate + ' to ' + endDate + '? This might take a while.')) {
                return;
            }

            // Prepare UI
            $button.prop('disabled', true).text('Importing...');
            importLogContainer.html(''); // Clear previous logs
            importProgressBar.val(0);
            importStatusMessage.text('Starting import...'); // Use .text()
            importCompleteButton.hide();
            $('#brcc-import-status').show();
            importInProgress = true;
            addImportLog('Starting import for ' + sources.join(', ') + ' from ' + startDate + ' to ' + endDate + '...');

            // Start the first batch
            processImportBatch(0, {
                start_date: startDate,
                end_date: endDate,
                sources: sources
            });
        });

        // Import Complete button click
        importCompleteButton.on('click', function() {
            $('#brcc-import-status').hide();
            importInProgress = false; // Allow starting a new import
        });
    }
    // --- End Import History Page ---

})(jQuery);

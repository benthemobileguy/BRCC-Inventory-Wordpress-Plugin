/**
 * BRCC Date-Time Mappings with Eventbrite Integration
 */
jQuery(document).ready(function($) {
    // Global variables
    var currentProductId = null;
    var eventbriteCache = {};
    var mappingInProgress = false;
    var availableTimes = [];
    var eventbriteSuggestions = {};

    // Open modal when "View/Edit Dates" button is clicked
    $(document).on('click', '.brcc-view-dates', function() {
        currentProductId = $(this).data('product-id');
        
        // Reset modal content
        $('#brcc-dates-table-body').html('');
        $('#brcc-dates-table').hide();
        $('#brcc-no-dates').hide();
        $('#brcc-dates-loading').show();
        $('#brcc-fetch-from-eventbrite').hide();
        $('#brcc-eventbrite-status').hide();
        
        // Open modal
        $('#brcc-date-mappings-modal').show();
        
        // Load dates for this product
        loadProductDates(false);
    });
    
    // Fetch from Eventbrite button
    $('#brcc-fetch-from-eventbrite').on('click', function() {
        $(this).prop('disabled', true).text('Fetching...');
        loadProductDates(true);
    });
    
    /**
     * Load product dates with option to fetch from Eventbrite
     */
    function loadProductDates(fetchFromEventbrite) {
        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_get_product_dates',
                nonce: brcc_admin.nonce,
                product_id: currentProductId,
                fetch_from_eventbrite: fetchFromEventbrite
            },
            success: function(response) {
                $('#brcc-dates-loading').hide();
                $('#brcc-fetch-from-eventbrite').prop('disabled', false).text('Fetch Events from Eventbrite');
                
                if (response.success) {
                    // Store available times if provided
                    if (response.data.availableTimes) {
                        availableTimes = response.data.availableTimes;
                    }
                    
                    // Show Eventbrite fetch button if we have a base ID
                    if (response.data.base_id) {
                        $('#brcc-fetch-from-eventbrite').show();
                    } else {
                        // Show fetch button anyway if we've successfully loaded dates
                        $('#brcc-fetch-from-eventbrite').show();
                    }
                    
                    // Check if we have dates
                    if (response.data.dates && response.data.dates.length > 0) {
                        // Store in cache
                        eventbriteCache[currentProductId] = response.data;
                        
                        // Store suggestions from title matching
                        if (response.data.suggestions) {
                            eventbriteSuggestions = response.data.suggestions;
                        }
                        
                        // Check if there was a connection error
                        var hasConnectionError = false;
                        response.data.dates.forEach(function(date) {
                            if (date.eventbrite_connection_failed) {
                                hasConnectionError = true;
                                showEventbriteStatus('error', date.error || 'Error connecting to Eventbrite');
                            }
                        });
                        
                        if (!hasConnectionError && response.data.source === 'eventbrite') {
                            showEventbriteStatus('success', 'Successfully connected to Eventbrite. ' + 
                                               response.data.dates.length + ' events loaded.');
                        }
                        
                        // Populate table - with a slight delay to ensure DOM is ready
                        setTimeout(function() {
                            populateDatesTable(response.data.dates);
                            // Trigger event to ensure Add Time buttons are added
                            $(document).trigger('brcc-dates-loaded');
                        }, 100);
                    } else {
                        $('#brcc-no-dates').show();
                    }
                } else {
                    $('#brcc-no-dates').empty().append($('<p>').text(response.data.message || 'Error loading dates')).show(); // Use .text()
                }
            },
            error: function() {
                $('#brcc-dates-loading').hide();
                $('#brcc-fetch-from-eventbrite').prop('disabled', false).text('Fetch Events from Eventbrite');
                $('#brcc-no-dates').empty().append($('<p>').text(brcc_admin.ajax_error)).show(); // Use .text()
            }
        });
    }
    
    /**
     * Show Eventbrite connection status
     */
    function showEventbriteStatus(status, message) {
        var $status = $('#brcc-eventbrite-status');
        $status.removeClass('notice-success notice-error notice-warning notice-info').addClass('notice-' + status);
        $status.find('p').text(message); // Use .text()
        $status.show();
    }

    /**
     * Add a time slot row for a specific date using safe DOM manipulation
     */
    function addTimeSlotForDate(date) {
        var rows = document.querySelectorAll('#brcc-dates-table-body tr[data-date="' + date + '"]');
        if (rows.length === 0) return;

        var lastRow = rows[rows.length - 1];
        var firstRow = rows[0];

        // Get the formatted date text safely from the first row's first cell
        var formattedDateText = '';
        if (firstRow && firstRow.cells.length > 0) {
             // Find the text node, ignoring potential icon spans
             for(var i = 0; i < firstRow.cells[0].childNodes.length; i++) {
                 if (firstRow.cells[0].childNodes[i].nodeType === Node.TEXT_NODE) {
                     formattedDateText = firstRow.cells[0].childNodes[i].textContent.trim();
                     break;
                 }
             }
             if (!formattedDateText) { // Fallback if only icon exists
                 formattedDateText = $(firstRow.cells[0]).text().trim();
             }
        }
        
        // Create new row element
        var newRow = document.createElement('tr');
        newRow.setAttribute('data-date', date);
        newRow.setAttribute('data-same-date', 'true');
        newRow.className = 'brcc-same-date-row'; // Add class for styling

        // Helper to create TD with text
        function createTdWithText(text) {
            var td = document.createElement('td');
            td.textContent = text;
            return td;
        }
        
        // Helper to create TD with element
        function createTdWithElement(element) {
            var td = document.createElement('td');
            td.appendChild(element);
            return td;
        }

        // 1. Date Cell (reuse formatted date text)
        newRow.appendChild(createTdWithText(formattedDateText));

        // 2. Time Cell (use refactored function)
        var timeSelector = createTimeSelectorHtml(null); // Use refactored function
        newRow.appendChild(createTdWithElement(timeSelector));

        // 3. Inventory Cell
        newRow.appendChild(createTdWithText('N/A'));

        // 4. Eventbrite ID Cell with Suggest Button
        var ebInput = document.createElement('input');
        ebInput.type = 'text';
        ebInput.className = 'regular-text date-eventbrite-id';
        ebInput.value = '';

        var suggestButton = document.createElement('button');
        suggestButton.type = 'button';
        suggestButton.className = 'button button-secondary brcc-suggest-date-eventbrite-id';
        suggestButton.textContent = brcc_admin.suggest || 'Suggest'; // Use localized text
        suggestButton.title = brcc_admin.suggest_tooltip_date || 'Suggest Eventbrite Ticket ID based on date/time'; // Tooltip

        var inputGroup = document.createElement('div');
        inputGroup.className = 'brcc-mapping-input-group';
        inputGroup.appendChild(ebInput);
        inputGroup.appendChild(suggestButton);

        var resultDiv = document.createElement('div');
        resultDiv.className = 'brcc-date-suggestion-result';

        var ebTd = document.createElement('td');
        ebTd.appendChild(inputGroup);
        ebTd.appendChild(resultDiv);
        newRow.appendChild(ebTd);

        // 5. Square ID Cell
        var sqInput = document.createElement('input');
        sqInput.type = 'text';
        sqInput.className = 'regular-text date-square-id';
        sqInput.value = '';
        newRow.appendChild(createTdWithElement(sqInput));

        // 6. Actions Cell
        var testButton = document.createElement('button');
        testButton.type = 'button';
        testButton.className = 'button brcc-test-date-mapping';
        testButton.setAttribute('data-date', date);
        testButton.textContent = brcc_admin.test || 'Test'; // Use localized text
        
        var resultDiv = document.createElement('div');
        resultDiv.className = 'brcc-date-test-result';
        
        var actionsTd = document.createElement('td');
        actionsTd.appendChild(testButton);
        actionsTd.appendChild(document.createTextNode(' ')); // Add space
        actionsTd.appendChild(resultDiv);
        newRow.appendChild(actionsTd);

        // Insert after the last row for this date
        $(lastRow).after(newRow); // Use jQuery for easy insertion after
        
        // Add click handler for new row's test button
        $(newRow).find('.brcc-test-date-mapping').on('click', testDateMapping);
        
        // Apply visual styling to indicate it's the same date
        $(newRow).addClass('brcc-same-date-row');
        
        // Trigger suggestions for the new row
        var timeElement = $(newRow).find('.brcc-time-selector');
        timeElement.on('change', function() {
            var selectedTime = $(this).val();
            if (selectedTime) {
                suggestEventbriteIdForDateAndTime(date, selectedTime, $newRow);
            }
        });
    }
    
    /**
     * Create DOM element for time selector
     */
    function createTimeSelectorHtml(selectedValue) { // Added selectedValue parameter
        var select = document.createElement('select');
        select.className = 'brcc-time-selector';

        var defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = 'Select time...';
        select.appendChild(defaultOption);

        // Use available times from server or default times
        var timesToUse = availableTimes && availableTimes.length > 0 ?
            availableTimes : [
                 {value: '08:00', label: '8:00 AM'}, {value: '08:30', label: '8:30 AM'},
                 {value: '09:00', label: '9:00 AM'}, {value: '09:30', label: '9:30 AM'},
                 {value: '10:00', label: '10:00 AM'}, {value: '10:30', label: '10:30 AM'},
                 {value: '11:00', label: '11:00 AM'}, {value: '11:30', label: '11:30 AM'},
                 {value: '12:00', label: '12:00 PM'}, {value: '12:30', label: '12:30 PM'},
                 {value: '13:00', label: '1:00 PM'}, {value: '13:30', label: '1:30 PM'},
                 {value: '14:00', label: '2:00 PM'}, {value: '14:30', label: '2:30 PM'},
                 {value: '15:00', label: '3:00 PM'}, {value: '15:30', label: '3:30 PM'},
                 {value: '16:00', label: '4:00 PM'}, {value: '16:30', label: '4:30 PM'},
                 {value: '17:00', label: '5:00 PM'}, {value: '17:30', label: '5:30 PM'},
                 {value: '18:00', label: '6:00 PM'}, {value: '18:30', label: '6:30 PM'},
                 {value: '19:00', label: '7:00 PM'}, {value: '19:30', label: '7:30 PM'},
                 {value: '20:00', label: '8:00 PM'}, {value: '20:30', label: '8:30 PM'},
                 {value: '21:00', label: '9:00 PM'}, {value: '21:30', label: '9:30 PM'},
                 {value: '22:00', label: '10:00 PM'}, {value: '22:30', label: '10:30 PM'},
                 {value: '23:00', label: '11:00 PM'}, {value: '23:30', label: '11:30 PM'}
            ]; // Expanded default times

        timesToUse.forEach(function(time) {
            var option = document.createElement('option');
            option.value = time.value || ''; // Ensure value exists
            option.textContent = time.label || ''; // Ensure label exists
            if (selectedValue && time.value === selectedValue) {
                 option.selected = true;
            }
            select.appendChild(option);
        });

        return select; // Return the DOM element
    }

    // Handle click on Add Time button
    $(document).on('click', '.brcc-add-time-slot', function() {
        var date = $(this).data('date');
        addTimeSlotForDate(date);
    });
    
    // Handle click on Auto-Match button
    $(document).on('click', '#brcc-auto-match', function() {
        autoMatchEventbriteEvents();
    });

    /**
     * Auto-match Eventbrite events to dates
     */
    function autoMatchEventbriteEvents() {
        var $button = $('#brcc-auto-match');
        $button.prop('disabled', true).text('Matching...');
        
        // Get all rows with empty Eventbrite IDs
        var unmappedRows = [];
        $('#brcc-dates-table-body tr').each(function() {
            var $row = $(this);
            var $eventbriteIdInput = $row.find('.date-eventbrite-id');
            
            if ($eventbriteIdInput.val() === '') {
                unmappedRows.push($row);
            }
        });
        
        // If we have suggestions, apply them
        var matchedCount = 0;
        if (eventbriteSuggestions && Object.keys(eventbriteSuggestions).length > 0) {
            unmappedRows.forEach(function($row) {
                var date = $row.data('date');
                var time = $row.find('.brcc-time-selector').val() || $row.find('.brcc-time-value').val();
                
                // Create a key for this date/time combination
                var key = date;
                if (time) {
                    key += "_" + time;
                }
                
                // Check if we have a suggestion for this date/time
                if (eventbriteSuggestions[key] && eventbriteSuggestions[key].eventbrite_id) {
                    $row.find('.date-eventbrite-id').val(eventbriteSuggestions[key].eventbrite_id);
                    
                    // Add suggestion indicator
                    $row.addClass('brcc-eventbrite-suggested');
                    
                    // Update with suggestion details if available
                    if (eventbriteSuggestions[key].event_name) {
                        updateEventbriteDetails($row, {
                            event_name: eventbriteSuggestions[key].event_name,
                            venue_name: eventbriteSuggestions[key].venue_name,
                            event_time: eventbriteSuggestions[key].event_time
                        });
                    }
                    
                    matchedCount++;
                }
            });
        }
        
        // If we couldn't match all rows and we have dates, try to match by day name
        if (matchedCount < unmappedRows.length && eventbriteCache[currentProductId] && eventbriteCache[currentProductId].dates) {
            var productDates = eventbriteCache[currentProductId].dates;
            var eventbriteSources = productDates.filter(function(date) {
                return date.from_eventbrite;
            });
            
            unmappedRows.forEach(function($row) {
                // Skip if already matched
                if ($row.find('.date-eventbrite-id').val() !== '') {
                    return;
                }
                
                var date = $row.data('date');
                var time = $row.find('.brcc-time-selector').val() || $row.find('.brcc-time-value').val();
                
                // Get day name from date
                var dayName = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase();
                
                // Find matching events for this day and possibly time
                var matchingEvents = eventbriteSources.filter(function(event) {
                    var eventDate = new Date(event.date + 'T00:00:00');
                    var eventDayName = eventDate.toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase();
                    
                    if (eventDayName === dayName) {
                        // If time is specified, check if it matches
                        if (time && event.time) {
                            return isTimeClose(time, event.time);
                        }
                        return true;
                    }
                    return false;
                });
                
                if (matchingEvents.length > 0) {
                    // Sort by date (closer to our target date is better)
                    matchingEvents.sort(function(a, b) {
                        var dateA = new Date(a.date + 'T00:00:00');
                        var dateB = new Date(b.date + 'T00:00:00');
                        var targetDate = new Date(date + 'T00:00:00');
                        
                        return Math.abs(dateA - targetDate) - Math.abs(dateB - targetDate);
                    });
                    
                    // Use the closest event
                    var bestMatch = matchingEvents[0];
                    $row.find('.date-eventbrite-id').val(bestMatch.eventbrite_id);
                    
                    // Add suggestion indicator
                    $row.addClass('brcc-eventbrite-suggested');
                    
                    // Update with suggestion details
                    updateEventbriteDetails($row, bestMatch);
                    
                    matchedCount++;
                }
            });
        }
        
        $button.prop('disabled', false).text('Auto-Match Events');
        
        // Show success message
        if (matchedCount > 0) {
            showEventbriteStatus('success', 'Auto-matched ' + matchedCount + ' events. Please verify and adjust as needed.');
        } else {
            showEventbriteStatus('warning', 'No automatic matches found. Try fetching events from Eventbrite first.');
        }
    }
    
    /**
     * Check if two times are close
     */
    function isTimeClose(time1, time2, bufferMinutes = 30) {
        // Convert to minutes since midnight for comparison
        function timeToMinutes(time) {
            var parts = time.split(':');
            return parseInt(parts[0]) * 60 + parseInt(parts[1]);
        }
        
        var minutes1 = timeToMinutes(time1);
        var minutes2 = timeToMinutes(time2);
        
        return Math.abs(minutes1 - minutes2) <= bufferMinutes;
    }

    /**
     * Suggest Eventbrite ID for a specific date and time
     */
    function suggestEventbriteIdForDateAndTime(date, time, $row) {
        // Check if we have suggestions in cache
        if (eventbriteSuggestions) {
            // Create a key for this date/time combination
            var key = date;
            if (time) {
                key += "_" + time;
            }
            
            // Check if we have a suggestion for this date/time
            if (eventbriteSuggestions[key] && eventbriteSuggestions[key].eventbrite_id) {
                $row.find('.date-eventbrite-id').val(eventbriteSuggestions[key].eventbrite_id);
                
                // Add suggestion indicator
                $row.addClass('brcc-eventbrite-suggested');
                
                // Update with suggestion details if available
                if (eventbriteSuggestions[key].event_name) {
                    updateEventbriteDetails($row, {
                        event_name: eventbriteSuggestions[key].event_name,
                        venue_name: eventbriteSuggestions[key].venue_name,
                        event_time: eventbriteSuggestions[key].event_time
                    });
                }
                
                return true;
            }
        }
        
        // Try to match from fetched Eventbrite events
        if (eventbriteCache[currentProductId] && eventbriteCache[currentProductId].dates) {
            var productDates = eventbriteCache[currentProductId].dates;
            var eventbriteSources = productDates.filter(function(date) {
                return date.from_eventbrite;
            });
            
            // Get day name from date
            var dayName = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase();
            
            // Find matching events for this day and time
            var matchingEvents = eventbriteSources.filter(function(event) {
                var eventDate = new Date(event.date + 'T00:00:00');
                var eventDayName = eventDate.toLocaleDateString('en-US', { weekday: 'long' }).toLowerCase();
                
                if (eventDayName === dayName && event.time) {
                    return isTimeClose(time, event.time);
                }
                return false;
            });
            
            if (matchingEvents.length > 0) {
                // Sort by date (closer to our target date is better)
                matchingEvents.sort(function(a, b) {
                    var dateA = new Date(a.date + 'T00:00:00');
                    var dateB = new Date(b.date + 'T00:00:00');
                    var targetDate = new Date(date + 'T00:00:00');
                    
                    return Math.abs(dateA - targetDate) - Math.abs(dateB - targetDate);
                });
                
                // Use the closest event
                var bestMatch = matchingEvents[0];
                $row.find('.date-eventbrite-id').val(bestMatch.eventbrite_id);
                
                // Add suggestion indicator
                $row.addClass('brcc-eventbrite-suggested');
                
                // Update with suggestion details
                updateEventbriteDetails($row, bestMatch);
                
                return true;
            }
        }
        
        return false;
    }

    // --- NEW: Event handler for Date Suggest Button ---
    $(document).on('click', '.brcc-suggest-date-eventbrite-id', function() {
        var $button = $(this);
        var $row = $button.closest('tr');
        var $input = $row.find('.date-eventbrite-id');
        var $resultDiv = $row.find('.brcc-date-suggestion-result');
        var date = $row.data('date');
        // Find time either from selector or hidden input
        var $timeElement = $row.find('.brcc-time-selector');
        var time = $timeElement.length > 0 ? $timeElement.val() : $row.find('.brcc-time-value').val();

        if (!currentProductId || !date) {
            $resultDiv.text('Error: Missing Product ID or Date.').addClass('error').show();
            return;
        }

        // Optional: Check if time is selected if a selector exists
        if ($timeElement.length > 0 && !time) {
             $resultDiv.text('Please select a time first.').addClass('notice notice-warning').show();
             setTimeout(function() { $resultDiv.hide().removeClass('notice notice-warning'); }, 3000);
             return;
        }

        $button.prop('disabled', true);
        $resultDiv.text('Suggesting...').removeClass('error success notice notice-warning').addClass('notice notice-info').show();

        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_suggest_eventbrite_ticket_id_for_date',
                nonce: brcc_admin.nonce,
                product_id: currentProductId,
                date: date,
                time: time // Send time if available
            },
            success: function(response) {
                if (response.success && response.data && response.data.eventbrite_id) {
                    $input.val(response.data.eventbrite_id);
                    var suggestionText = 'Suggested: ' + (response.data.event_name || 'Event') + ' - ' + (response.data.ticket_name || 'Ticket');
                    if (response.data.event_time) {
                         suggestionText += ' (' + response.data.event_time + ')';
                    }
                    $resultDiv.text(suggestionText).removeClass('notice-info error').addClass('success').show();
                    // Add visual indicator
                    $row.addClass('brcc-eventbrite-suggested').removeClass('brcc-eventbrite-connected'); // Ensure correct class
                    // Optionally update hover details
                    updateEventbriteDetails($row, response.data);

                } else {
                    var message = response.data && response.data.message ? response.data.message : 'No suggestion found.';
                    $resultDiv.text(message).removeClass('notice-info success').addClass('error').show();
                }
            },
            error: function() {
                $resultDiv.text(brcc_admin.ajax_error || 'AJAX error').removeClass('notice-info success').addClass('error').show();
            },
            complete: function() {
                $button.prop('disabled', false);
                 // Optionally hide the message after a few seconds unless it's an error
                 if (!$resultDiv.hasClass('error')) {
                     setTimeout(function() { $resultDiv.fadeOut(); }, 5000);
                 }
            }
        });
    });
    // --- END NEW ---

    /**
     * Add the "Add Time" button to each row
     */
    function addTimeButtons() {
        // Get all unique dates
        var dates = {};
        $('#brcc-dates-table-body tr').each(function() {
            var $row = $(this);
            // Skip if this is a separator row
            if ($row.hasClass('brcc-date-separator')) {
                return;
            }
            
            var date = $row.data('date');
            if (date) {
                dates[date] = true;
            }
        });
        
        // For each unique date, add the "Add Time" button to the last row for that date
        Object.keys(dates).forEach(function(date) {
            var rows = $('#brcc-dates-table-body tr[data-date="' + date + '"]');
            if (rows.length === 0) return;
            
            var lastRow = rows[rows.length - 1];
            var $actionsCell = $(lastRow).find('td:last-child');
            
            // Only add the button if it doesn't already exist
            if ($actionsCell.find('.brcc-add-time-slot').length === 0) {
                $actionsCell.append(' <button type="button" class="button brcc-add-time-slot" data-date="' + date + '">+ Add Time</button>');
            }
        });
    }
    
    // Add time buttons when dates are loaded
    $(document).on('brcc-dates-loaded', addTimeButtons);
    
    /**
     * Create time dropdown selector
     */
    function createTimeSelector(selectedTime) {
        // Define default times if not provided by server
        var defaultTimes = [
            {value: '08:00', label: '8:00 AM'},
            {value: '08:30', label: '8:30 AM'},
            {value: '09:00', label: '9:00 AM'},
            {value: '09:30', label: '9:30 AM'},
            {value: '10:00', label: '10:00 AM'},
            {value: '10:30', label: '10:30 AM'},
            {value: '11:00', label: '11:00 AM'},
            {value: '11:30', label: '11:30 AM'},
            {value: '12:00', label: '12:00 PM'},
            {value: '12:30', label: '12:30 PM'},
            {value: '13:00', label: '1:00 PM'},
            {value: '13:30', label: '1:30 PM'},
            {value: '14:00', label: '2:00 PM'},
            {value: '14:30', label: '2:30 PM'},
            {value: '15:00', label: '3:00 PM'},
            {value: '15:30', label: '3:30 PM'},
            {value: '16:00', label: '4:00 PM'},
            {value: '16:30', label: '4:30 PM'},
            {value: '17:00', label: '5:00 PM'},
            {value: '17:30', label: '5:30 PM'},
            {value: '18:00', label: '6:00 PM'},
            {value: '18:30', label: '6:30 PM'},
            {value: '19:00', label: '7:00 PM'},
            {value: '19:30', label: '7:30 PM'},
            {value: '20:00', label: '8:00 PM'},
            {value: '20:30', label: '8:30 PM'},
            {value: '21:00', label: '9:00 PM'},
            {value: '21:30', label: '9:30 PM'},
            {value: '22:00', label: '10:00 PM'},
            {value: '22:30', label: '10:30 PM'},
            {value: '23:00', label: '11:00 PM'},
            {value: '23:30', label: '11:30 PM'}
        ];
        
        // Use either server-provided times or default times
        var timesToUse = (availableTimes && availableTimes.length > 0) ? availableTimes : defaultTimes;
        
        var $select = $('<select>').addClass('brcc-time-selector');
        
        // Add empty option
        $select.append($('<option>').val('').text('Select time...'));
        
        // Add time options
        $.each(timesToUse, function(index, time) {
            var $option = $('<option>').val(time.value).text(time.label);
            if (selectedTime === time.value) {
                $option.prop('selected', true);
            }
            $select.append($option);
        });
        
        return $select;
    }
    
 /**
 * Populate dates table with proper time support
 */
function populateDatesTable(dates) {
    $('#brcc-dates-table-body').empty();
    
    // Make sure Auto-Match button is shown
    if (!$('#brcc-auto-match').length) {
        var $autoMatchButton = $('<button>')
            .attr('type', 'button')
            .attr('id', 'brcc-auto-match')
            .addClass('button button-secondary')
            .text('Auto-Match Events');
        
        $('#brcc-fetch-from-eventbrite').after(' ').after($autoMatchButton);
    }
    
    // Group dates by date to handle multiple times per date
    var dateGroups = {};
    
    dates.forEach(function(date) {
        var dateKey = date.date;
        if (!dateGroups[dateKey]) {
            dateGroups[dateKey] = [];
        }
        dateGroups[dateKey].push(date);
    });
    
    // Track if we need to add a date separator
    var previousDate = null;
    
    // For each date, create rows for each time or a single row if no times
    Object.keys(dateGroups).sort().forEach(function(dateKey) {
        var dateItems = dateGroups[dateKey];
        
        // Add a date separator if this is a new date
        if (previousDate !== null) {
            $('#brcc-dates-table-body').append(
                $('<tr>').addClass('brcc-date-separator')
                    .append($('<td colspan="6">').html('<hr style="margin: 5px 0; border-top: 1px dashed #ccc;">'))
            );
        }
        previousDate = dateKey;
        
        // Check if we have multiple items with different times
        var hasMultipleTimes = dateItems.some(function(item) { 
            return item.time && item.time.length > 0; 
        });
        
        if (hasMultipleTimes) {
            // First add items with defined times
            var itemsWithTime = dateItems.filter(function(item) {
                return item.time && item.time.length > 0;
            }).sort(function(a, b) {
                return a.time.localeCompare(b.time);
            });
            
            // Then add items without defined times
            var itemsWithoutTime = dateItems.filter(function(item) {
                return !item.time || item.time.length === 0;
            });
            
            // Create a row for each time
            var isFirstRow = true;
            itemsWithTime.forEach(function(date) {
                createDateRow(date, isFirstRow);
                isFirstRow = false;
            });
            
            // Add no-time items if any exist
            itemsWithoutTime.forEach(function(date) {
                createDateRow(date, isFirstRow);
                isFirstRow = false;
            });
        } else {
            // Just one row without specific time
            createDateRow(dateItems[0], true);
        }
    });
    
    $('#brcc-dates-table').show();
    
    // Function to create a date row with constrained content
    function createDateRow(date, isFirstRowForDate) {
        // Generate a CSS class based on date status
        var rowClass = '';
        var statusText = '';
        var statusIcon = '';
        
        if (date.eventbrite_connection_successful) {
            rowClass = 'brcc-eventbrite-connected';
            statusText = '<span class="brcc-connection-status connected">Connected</span>';
            statusIcon = '<span class="dashicons dashicons-yes-alt" style="color:green;"></span>';
        } else if (date.suggestion) {
            rowClass = 'brcc-eventbrite-suggested';
            statusText = '<span class="brcc-connection-status suggested">Suggested</span>';
            statusIcon = '<span class="dashicons dashicons-editor-help" style="color:orange;"></span>';
        }
        
        // Special styling for date rows
        if (date.is_day_match) {
            rowClass += ' brcc-day-match';
        }
        
        // Mark if it's a subsequent row for the same date
        if (!isFirstRowForDate) {
            rowClass += ' brcc-same-date-row';
        }
        
        // Create the row
        var $row = $('<tr>')
            .attr('data-date', date.date)
            .attr('data-same-date', !isFirstRowForDate ? 'true' : 'false')
            .addClass(rowClass);
        
        // Date column
        var $dateCell = $('<td>');
        // Prepend safe HTML icon, then append text date
        $dateCell.html(statusIcon + ' '); // Add space after icon
        $dateCell.append(document.createTextNode(date.formatted_date || ''));
        
        // Time column with dropdown or fixed time
        var $timeCell = $('<td>');
        if (date.from_eventbrite && date.time) {
            // If from Eventbrite with specific time, show formatted time
            $timeCell.text(date.formatted_time || date.time || ''); // Use .text() for safety
            $timeCell.append($('<input type="hidden">').addClass('brcc-time-value').val(date.time));
        } else if (date.time) {
            // If we have a time but it's not from Eventbrite, show a selector with this time selected
            var $timeSelector = createTimeSelector(date.time);
            $timeCell.append($timeSelector);
            
            // Add change handler to trigger suggestions
            $timeSelector.on('change', function() {
                var selectedTime = $(this).val();
                if (selectedTime) {
                    suggestEventbriteIdForDateAndTime(date.date, selectedTime, $row);
                }
            });
        } else {
            // Otherwise, show empty time selector
            var $timeSelector = createTimeSelector();
            $timeCell.append($timeSelector);
            
            // Add change handler to trigger suggestions
            $timeSelector.on('change', function() {
                var selectedTime = $(this).val();
                if (selectedTime) {
                    suggestEventbriteIdForDateAndTime(date.date, selectedTime, $row);
                }
            });
        }
        
        // Inventory column
        var $inventoryCell = $('<td>').text(date.inventory !== null ? date.inventory : 'N/A');
        
        // Eventbrite ID column with Suggest Button
        var $eventbriteCell = $('<td>');
        var $eventbriteInput = $('<input>')
            .attr('type', 'text')
            .addClass('regular-text date-eventbrite-id')
            .val(date.eventbrite_id || '');

        // Add Eventbrite data if available (moved before button creation)
        if (date.eventbrite_name) {
            $eventbriteInput
                .attr('data-eventbrite-name', date.eventbrite_name)
                .attr('data-eventbrite-venue', date.eventbrite_venue || '')
                .attr('data-eventbrite-time', date.eventbrite_time || '');
        }

        var $suggestButton = $('<button>')
            .attr('type', 'button')
            .addClass('button button-secondary brcc-suggest-date-eventbrite-id')
            .attr('title', brcc_admin.suggest_tooltip_date || 'Suggest Eventbrite Ticket ID based on date/time') // Tooltip
            .text(brcc_admin.suggest || 'Suggest'); // Use localized text

        var $inputGroup = $('<div>').addClass('brcc-mapping-input-group');
        $inputGroup.append($eventbriteInput).append($suggestButton);

        var $resultDiv = $('<div>').addClass('brcc-date-suggestion-result');

        $eventbriteCell.append($inputGroup).append($resultDiv);
        
        // Add status indicator for suggestions
        if (date.suggestion) {
            $eventbriteCell.append(' <span class="brcc-connection-status suggested">Suggested</span>');
        }
        
        // Add Eventbrite details if available
        if (date.eventbrite_name) {
            var $details = $('<div>').addClass('brcc-eventbrite-details').css('display', 'none');
            // Safely create elements and set text content
            var $pEvent = $('<p>').append($('<strong>').text('Event:'));
            $pEvent.append(document.createTextNode(' ' + (date.eventbrite_name || '')));
            $details.append($pEvent);
            
            if (date.eventbrite_venue) {
                 var $pVenue = $('<p>').append($('<strong>').text('Venue:'));
                 $pVenue.append(document.createTextNode(' ' + (date.eventbrite_venue || '')));
                 $details.append($pVenue);
            }
            
            if (date.eventbrite_time) {
                 var $pTime = $('<p>').append($('<strong>').text('Time:'));
                 $pTime.append(document.createTextNode(' ' + (date.formatted_time || date.eventbrite_time || '')));
                 $details.append($pTime);
            }
            
            if (date.available !== undefined) {
                 var $pAvailable = $('<p>').append($('<strong>').text('Available tickets:'));
                 $pAvailable.append(document.createTextNode(' ' + date.available));
                 $details.append($pAvailable);
            }
            
            $eventbriteCell.append($details);
            
            // Add hover events
            $eventbriteInput.on('mouseover', function() {
                $(this).siblings('.brcc-eventbrite-details').fadeIn(200);
            }).on('mouseout', function() {
                $(this).siblings('.brcc-eventbrite-details').fadeOut(200);
            });
        }
        
        // Square ID column
        var $squareCell = $('<td>');
        var $squareInput = $('<input>')
            .attr('type', 'text')
            .addClass('regular-text date-square-id')
            .val(date.square_id || '');
        
        $squareCell.append($squareInput);
        
        // Actions column
        var $actionsCell = $('<td>');
        var $testButton = $('<button>')
            .attr('type', 'button')
            .addClass('button brcc-test-date-mapping')
            .attr('data-date', date.date)
            .text('Test');
            
        var $resultContainer = $('<div>').addClass('brcc-date-test-result');
        
        $actionsCell.append($testButton);
        
        // Only add the Add Time button to the last row for a date
        if (isFirstRowForDate) {
            var $addTimeButton = $('<button>')
                .attr('type', 'button')
                .addClass('button brcc-add-time-slot')
                .attr('data-date', date.date)
                .text('+ Add Time');
            
            $actionsCell.append(' ').append($addTimeButton);
        }
        
        $actionsCell.append($resultContainer);
        
        // Add all cells to the row
        $row.append($dateCell)
            .append($timeCell)
            .append($inventoryCell)
            .append($eventbriteCell)
            .append($squareCell)
            .append($actionsCell);
        
        // Add row to table
        $('#brcc-dates-table-body').append($row);
        
        // Add click handler for the test button
        $testButton.on('click', testDateMapping);
    }
}

   // --- New Suggestion Logic for Date/Time Rows ---

   // Handle click on "Suggest" button within the dialog row
   $(document).on('click', '.brcc-suggest-date-ticket-id', function() {
       var $button = $(this);
       var $row = $button.closest('tr');
       var date = $button.data('date');
       var time = $row.find('.brcc-time-selector').val() || $row.find('.brcc-time-value').val(); // Get selected or static time
       var $suggestionDiv = $row.find('.brcc-date-suggestion-result');

       $button.prop('disabled', true).text('Suggesting...');
       $suggestionDiv.hide().empty(); // Clear previous suggestions

       $.ajax({
           url: brcc_admin.ajax_url,
           type: 'POST',
           data: {
               action: 'brcc_suggest_eventbrite_ticket_id_for_date',
               nonce: brcc_admin.nonce,
               product_id: currentProductId, // Use the globally stored product ID for the modal
               date: date,
               time: time
           },
           success: function(response) {
               $button.prop('disabled', false).text('Suggest');
               if (response.success && response.data.ticket_id) {
                   var suggestionText = 'Suggestion: ' + response.data.ticket_id + '<br>' +
                                        (response.data.event_name || 'Unknown Event') + ' - ' +
                                        (response.data.ticket_name || 'Unknown Ticket');
                   
                   var applyButton = $('<button>')
                       .attr('type', 'button')
                       .addClass('button button-primary brcc-apply-date-ticket-id')
                       .text('Apply')
                       .data('ticket-id', response.data.ticket_id);

                   $suggestionDiv.html(suggestionText + '<br>').append(applyButton).show();
               } else {
                   var errorMessage = response.data && response.data.message ? response.data.message : 'No suggestion found.';
                   $suggestionDiv.html('<span style="color: red;">' + errorMessage + '</span>').show();
               }
           },
           error: function() {
               $button.prop('disabled', false).text('Suggest');
               $suggestionDiv.html('<span style="color: red;">' + brcc_admin.ajax_error + '</span>').show();
           }
       });
   });

   // Handle click on "Apply" button for date/time suggestion
   $(document).on('click', '.brcc-apply-date-ticket-id', function() {
       var $button = $(this);
       var $row = $button.closest('tr');
       var ticketId = $button.data('ticket-id');
       var $suggestionDiv = $row.find('.brcc-date-suggestion-result');

       // Set the value in the input field
       $row.find('.date-eventbrite-id').val(ticketId).trigger('change'); // Trigger change for any listeners

       // Remove the suggestion text and apply button
       $suggestionDiv.hide().empty();
   });

   // --- End New Suggestion Logic ---

    // --- New Suggestion Logic for Date/Time Rows ---

    // Handle click on "Suggest" button within the dialog row
    $(document).on('click', '.brcc-suggest-date-ticket-id', function() {
        var $button = $(this);
        var $row = $button.closest('tr');
        var date = $button.data('date');
        var time = $row.find('.brcc-time-selector').val() || $row.find('.brcc-time-value').val(); // Get selected or static time
        var $suggestionDiv = $row.find('.brcc-date-suggestion-result');

        $button.prop('disabled', true).text('Suggesting...');
        $suggestionDiv.hide().empty(); // Clear previous suggestions

        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_suggest_eventbrite_ticket_id_for_date',
                nonce: brcc_admin.nonce,
                product_id: currentProductId, // Use the globally stored product ID for the modal
                date: date,
                time: time
            },
            success: function(response) {
                $button.prop('disabled', false).text('Suggest');
                if (response.success && response.data.ticket_id) {
                    var suggestionText = 'Suggestion: ' + response.data.ticket_id + '<br>' +
                                         (response.data.event_name || 'Unknown Event') + ' - ' +
                                         (response.data.ticket_name || 'Unknown Ticket');
                    
                    var applyButton = $('<button>')
                        .attr('type', 'button')
                        .addClass('button button-primary brcc-apply-date-ticket-id')
                        .text('Apply')
                        .data('ticket-id', response.data.ticket_id);

                    $suggestionDiv.html(suggestionText + '<br>').append(applyButton).show();
                } else {
                    var errorMessage = response.data && response.data.message ? response.data.message : 'No suggestion found.';
                    $suggestionDiv.html('<span style="color: red;">' + errorMessage + '</span>').show();
                }
            },
            error: function() {
                $button.prop('disabled', false).text('Suggest');
                $suggestionDiv.html('<span style="color: red;">' + brcc_admin.ajax_error + '</span>').show();
            }
        });
    });

    // Handle click on "Apply" button for date/time suggestion
    $(document).on('click', '.brcc-apply-date-ticket-id', function() {
        var $button = $(this);
        var $row = $button.closest('tr');
        var ticketId = $button.data('ticket-id');
        var $suggestionDiv = $row.find('.brcc-date-suggestion-result');

        // Set the value in the input field
        $row.find('.date-eventbrite-id').val(ticketId).trigger('change'); // Trigger change for any listeners

        // Remove the suggestion text and apply button
        $suggestionDiv.hide().empty();
    });

    // --- End New Suggestion Logic ---


    // Test date mapping handler
    function testDateMapping() {
        var $button = $(this);
        var $row = $button.closest('tr');
        var date = $row.data('date');
        var time = $row.find('.brcc-time-selector').val() || $row.find('.brcc-time-value').val();
        var eventbriteId = $row.find('.date-eventbrite-id').val();
        var $resultContainer = $row.find('.brcc-date-test-result');
        
        $button.prop('disabled', true).text('Testing...');
        $resultContainer.hide();
        
        $.ajax({
            url: brcc_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'brcc_test_product_date_mapping',
                nonce: brcc_admin.nonce,
                product_id: currentProductId,
                date: date,
                time: time,
                eventbrite_id: eventbriteId
            },
            success: function(response) {
                $button.prop('disabled', false).text('Test');
                
                if (response.success) {
                    // Add a status class based on test status
                    $resultContainer.removeClass('status-success status-warning status-error')
                                  .addClass('status-' + (response.data.status || 'info'));
                    
                    $resultContainer.text(response.data.message || '').show(); // Use .text()
                    
                    // If test was successful, update row styling
                    if (response.data.status === 'success') {
                        $row.removeClass('brcc-eventbrite-suggested').addClass('brcc-eventbrite-connected');
                        
                        // Update input with any new data
                        if (response.data.details && response.data.details.event_id) {
                            // Preserve the current value
                            var currentValue = $row.find('.date-eventbrite-id').val();
                            if (currentValue === eventbriteId) {
                                // Only update if value hasn't changed
                                updateEventbriteDetails($row, response.data.details);
                            }
                        }
                    } else {
                        $row.removeClass('brcc-eventbrite-connected');
                    }
                } else {
                    $resultContainer.removeClass('status-success status-warning status-error')
                                  .addClass('status-error');
                    $resultContainer.text(response.data.message || brcc_admin.ajax_error).show(); // Use .text()
                    $row.removeClass('brcc-eventbrite-connected');
                }
                
                // Hide the result after a moment
                setTimeout(function() {
                    $resultContainer.fadeOut();
                }, 8000);
            },
            error: function() {
                $button.prop('disabled', false).text('Test');
                $resultContainer.removeClass('status-success status-warning status-error')
                              .addClass('status-error');
                $resultContainer.text(brcc_admin.ajax_error).show(); // Use .text()
                $row.removeClass('brcc-eventbrite-connected');
                
                // Hide the result after a moment
                setTimeout(function() {
                    $resultContainer.fadeOut();
                }, 8000);
            }
        });
    }
    
    /**
     * Update Eventbrite details in the row
     */
    function updateEventbriteDetails($row, details) {
        var $input = $row.find('.date-eventbrite-id');
        
        // Update details div or create it if it doesn't exist
        var $details = $row.find('.brcc-eventbrite-details');
        if ($details.length === 0) {
            $details = $('<div class="brcc-eventbrite-details"></div>');
            $input.after($details);
        }
        
        // Add event info
        var detailsHtml = '';
        if (details.event_name) {
            detailsHtml += '<p><strong>Event:</strong> ' + details.event_name + '</p>';
        }
        if (details.venue_name) {
            detailsHtml += '<p><strong>Venue:</strong> ' + details.venue_name + '</p>';
        }
        if (details.formatted_time || details.event_time) {
            detailsHtml += '<p><strong>Time:</strong> ' + (details.formatted_time || details.event_time) + '</p>';
        }
        if (details.available !== undefined) {
            detailsHtml += '<p><strong>Available:</strong> ' + details.available + ' tickets</p>';
        }
        
        $details.html(detailsHtml);
        
        // Add hover behavior
        $details.hide();
        $input.off('mouseover mouseout').on('mouseover', function() {
            $(this).siblings('.brcc-eventbrite-details').fadeIn(200);
        }).on('mouseout', function() {
            $(this).siblings('.brcc-eventbrite-details').fadeOut(200);
        });
    }
    
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
        if (mappingInProgress) return;
        
        var $button = $(this);
        mappingInProgress = true;
        $button.prop('disabled', true).text('Saving...');
        
        // Collect all date mappings for the current product
        var mappings = [];
        $('#brcc-dates-table-body tr').each(function() {
            var $row = $(this);
            // Skip separator rows
            if ($row.hasClass('brcc-date-separator')) {
                return;
            }
            
            var date = $row.data('date');
            // Get time either from selector or hidden input
            var time = $row.find('.brcc-time-selector').val() || $row.find('.brcc-time-value').val();
            var eventbriteId = $row.find('.date-eventbrite-id').val();
            var squareId = $row.find('.date-square-id').val();
            
            // Only add if we have at least one ID
            if (eventbriteId || squareId) {
                mappings.push({
                    date: date,
                    time: time,
                    eventbrite_id: eventbriteId,
                    square_id: squareId
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
                mappingInProgress = false;
                $button.prop('disabled', false).text('Save Date Mappings');
                
                if (response.success) {
                    // Update button text on main screen to reflect saved mappings
                    $('.brcc-view-dates[data-product-id="' + currentProductId + '"]').text('View/Edit Dates');
                    
                    // Show success message
                    showEventbriteStatus('success', response.data.message);
                    
                    // Wait a moment, then close modal
                    setTimeout(function() {
                        $('#brcc-date-mappings-modal').hide();
                    }, 2000);
                } else {
                    showEventbriteStatus('error', response.data.message || brcc_admin.ajax_error);
                }
            },
            error: function() {
                mappingInProgress = false;
                $button.prop('disabled', false).text('Save Date Mappings');
                showEventbriteStatus('error', brcc_admin.ajax_error);
            }
        });
    });
});

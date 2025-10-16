/* public/assets/js/room-rates.js */

jQuery(document).ready(function($) {
    
    'use strict';
    
    // Load rates for all rooms when dates are selected
    function loadAllRoomRates() {
        $('.rh-room-rates-container').each(function() {
            const container = $(this);
            const roomId = container.data('room-id');
            const checkin = container.data('checkin');
            const checkout = container.data('checkout');
            const adults = container.data('adults');
            
            if (!checkin || !checkout) {
                return;
            }
            
            // Show loading
            container.html('<div class="rh-rates-loading"><span class="spinner is-active"></span> Loading rates...</div>');
            
            // AJAX request
            $.ajax({
                url: rhRoomRates.ajax_url,
                method: 'POST',
                data: {
                    action: 'rh_get_room_rates',
                    nonce: rhRoomRates.nonce,
                    hotel_id: rhRoomRates.hotel_id,
                    room_id: roomId,
                    checkin: checkin,
                    checkout: checkout,
                    adults: adults
                },
                success: function(response) {
                    if (response.success) {
                        displayRates(container, response.data);
                    } else {
                        container.html('<div class="rh-rates-error">No rates available</div>');
                    }
                },
                error: function() {
                    container.html('<div class="rh-rates-error">Error loading rates</div>');
                }
            });
        });
    }
    
    function displayRates(container, rates) {
        if (!rates || rates.length === 0) {
            container.html('<div class="rh-rates-notice">No rates available for selected dates</div>');
            return;
        }
        
        let html = '<div class="rh-rates-list">';
        
        rates.forEach(function(rate) {
            const cancelClass = rate.cancellation.type === 'free' ? 'free' : 
                               rate.cancellation.type === 'non-refundable' ? 'non-refundable' : 'paid';
            
            html += '<div class="rh-rate-option">';
            html += '<div class="rh-rate-meal">' + escapeHtml(rate.meal) + '</div>';
            html += '<div class="rh-rate-cancel ' + cancelClass + '">';
            html += '<i class="fa fa-' + (rate.cancellation.type === 'free' ? 'check' : 'times') + '-circle"></i> ';
            html += escapeHtml(rate.cancellation.text);
            html += '</div>';
            html += '<div class="rh-rate-price">' + escapeHtml(rate.price.formatted) + '</div>';
            html += '<button class="rh-rate-book" data-hash="' + escapeHtml(rate.book_hash) + '">Book</button>';
            html += '</div>';
        });
        
        html += '</div>';
        
        container.html(html);
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Book button click
    $(document).on('click', '.rh-rate-book', function() {
        const bookHash = $(this).data('hash');
        alert('Booking: ' + bookHash + '\n\nWill redirect to prebook page...');
        // TODO: Redirect to prebook/checkout
    });
    
    // Auto-load on page load if dates present
    if ($('.rh-room-rates-container[data-checkin][data-checkout]').length > 0) {
        loadAllRoomRates();
    }
    
    // Reload when search form submitted
    $(document).on('submit', '.hotel-search-form', function(e) {
        setTimeout(loadAllRoomRates, 1000);
    });
    
});
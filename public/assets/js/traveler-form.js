/**
 * Ratehawk Traveler Form Integration
 * اتصال به فرم Check Availability قالب Traveler و نمایش قیمت‌های زنده
 */

(function($) {
    'use strict';
    
    /**
     * Initialize
     */
    $(document).ready(function() {
        console.log('🔥 Ratehawk Form Handler Loaded');
        
        // صبر کن تا Traveler scripts load بشه
        setTimeout(function() {
            initFormHandler();
        }, 1000);
    });
    
    /**
     * Initialize Form Handler
     */
    function initFormHandler() {
        // پیدا کردن فرم Traveler
        var $form = $('.form-check-availability-hotel');
        
        if ($form.length === 0) {
            console.warn('⚠️ Traveler form not found');
            return;
        }
        
        console.log('✅ Found Traveler form:', $form);
        
        // حذف تمام event handlers قبلی
        $form.off('submit');
        
        // اضافه کردن handler جدید
        $form.on('submit', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            
            console.log('🚀 Form submitted - Fetching Ratehawk rates...');
            
            handleFormSubmit($(this));
            
            return false;
        });
        
        console.log('✅ Form handler attached');
    }
    
    /**
     * مدیریت submit فرم
     */
    function handleFormSubmit($form) {
        // دریافت داده‌های فرم
        var formData = {
            action: 'rh_get_room_rates',
            nonce: rhTravelerForm.nonce,
            hotel_id: rhTravelerForm.hotelId,
            checkin: $form.find('input[name="start"]').val(),
            checkout: $form.find('input[name="end"]').val(),
            adults: parseInt($form.find('input[name="adult_number"]').val()) || 1,
            children: parseInt($form.find('input[name="child_number"]').val()) || 0,
            rooms: parseInt($form.find('input[name="room_num_search"]').val()) || 1
        };
        
        console.log('📤 Sending request:', formData);
        
        // Validation
        if (!formData.checkin || !formData.checkout) {
            showError('Please select check-in and check-out dates');
            return;
        }
        
        // نمایش loading
        showLoading();
        
        // ارسال درخواست AJAX
        $.ajax({
            url: rhTravelerForm.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('📥 Response received:', response);
                
                if (response.success) {
                    displayRates(response.data);
                } else {
                    showError(response.data || 'Unknown error');
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX Error:', error);
                showError('Network error: ' + error);
            }
        });
    }
    
    /**
     * نمایش loading
     */
    function showLoading() {
        var html = '<div class="rh-rates-loading">' +
                   '<i class="fa fa-spinner fa-spin"></i> ' +
                   rhTravelerForm.loadingText +
                   '</div>';
        
        $('#rh-rates-container').remove();
        $('.form-check-availability-hotel').after('<div id="rh-rates-container">' + html + '</div>');
    }
    
    /**
     * نمایش قیمت‌ها
     */
    function displayRates(rates) {
        console.log('🎨 Displaying rates:', rates);
        
        if (!rates || rates.length === 0) {
            showError(rhTravelerForm.noRatesText);
            return;
        }
        
        var html = '<div class="rh-rates-results">';
        html += '<h3 class="rh-rates-title">✅ ' + rates.length + ' Available Rates</h3>';
        html += '<div class="rh-rates-grid">';
        
        rates.forEach(function(rate, index) {
            html += buildRateCard(rate, index);
        });
        
        html += '</div>';
        html += '</div>';
        
        $('#rh-rates-container').html(html);
        
        // Smooth scroll به نتایج
        $('html, body').animate({
            scrollTop: $('#rh-rates-container').offset().top - 100
        }, 500);
    }
    
    /**
     * ساخت کارت قیمت
     */
    function buildRateCard(rate, index) {
        var priceDisplay = formatPrice(rate.price, rate.currency);
        var nights = rate.nights || 1;
        var pricePerNight = rate.price / nights;
        
        var html = '<div class="rh-rate-card" data-rate-index="' + index + '">';
        
        // Header
        html += '<div class="rh-rate-header">';
        html += '<h4 class="rh-room-name">' + escapeHtml(rate.room_name) + '</h4>';
        if (rate.meal && rate.meal !== 'nomeal') {
            html += '<span class="rh-meal-badge">' + getMealLabel(rate.meal) + '</span>';
        }
        html += '</div>';
        
        // Features
        if (rate.room_features && rate.room_features.length > 0) {
            html += '<ul class="rh-rate-features">';
            rate.room_features.slice(0, 3).forEach(function(feature) {
                html += '<li><i class="fa fa-check"></i> ' + escapeHtml(feature) + '</li>';
            });
            html += '</ul>';
        }
        
        // Price
        html += '<div class="rh-rate-price">';
        html += '<div class="rh-price-main">' + priceDisplay + '</div>';
        html += '<div class="rh-price-detail">' + 
                formatPrice(pricePerNight, rate.currency) + 
                ' per night × ' + nights + ' night' + (nights > 1 ? 's' : '') + 
                '</div>';
        html += '</div>';
        
        // Cancellation
        if (rate.cancellation_info) {
            html += '<div class="rh-cancellation">';
            if (rate.cancellation_info.free_cancellation_before) {
                html += '<i class="fa fa-shield-alt"></i> Free cancellation until ' + 
                        formatDate(rate.cancellation_info.free_cancellation_before);
            } else {
                html += '<i class="fa fa-ban"></i> Non-refundable';
            }
            html += '</div>';
        }
        
        // Book Button
        html += '<button class="rh-book-button" ' +
                'data-book-hash="' + escapeHtml(rate.book_hash) + '" ' +
                'data-price="' + rate.price + '" ' +
                'data-currency="' + rate.currency + '">';
        html += '<span class="rh-btn-icon">🛎️</span> Book Now';
        html += '</button>';
        
        html += '</div>';
        
        return html;
    }
    
    /**
     * نمایش خطا
     */
    function showError(message) {
        var html = '<div class="rh-rates-error">' +
                   '<i class="fa fa-exclamation-triangle"></i> ' +
                   escapeHtml(message) +
                   '</div>';
        
        $('#rh-rates-container').html(html);
    }
    
    /**
     * فرمت کردن قیمت
     */
    function formatPrice(amount, currency) {
        var symbol = getCurrencySymbol(currency);
        return symbol + parseFloat(amount).toFixed(2);
    }
    
    /**
     * دریافت سیمبل ارز
     */
    function getCurrencySymbol(currency) {
        var symbols = {
            'USD': '$',
            'EUR': '€',
            'GBP': '£',
            'JPY': '¥',
            'CAD': 'C$',
            'AUD': 'A$'
        };
        return symbols[currency] || currency + ' ';
    }
    
    /**
     * لیبل وعده غذایی
     */
    function getMealLabel(meal) {
        var labels = {
            'nomeal': 'Room Only',
            'breakfast': '🍳 Breakfast',
            'half_board': '🍽️ Half Board',
            'full_board': '🍴 Full Board',
            'all_inclusive': '⭐ All Inclusive'
        };
        return labels[meal] || meal;
    }
    
    /**
     * فرمت تاریخ
     */
    function formatDate(dateString) {
        try {
            var date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        } catch(e) {
            return dateString;
        }
    }
    
    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        if (!text) return '';
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
})(jQuery);
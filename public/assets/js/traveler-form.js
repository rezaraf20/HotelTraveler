/**
 * Ratehawk Traveler Form Integration
 * Override کردن دکمه‌های "Show Price" و نمایش قیمت‌های Ratehawk
 */

(function($) {
    'use strict';
    
    /**
     * Initialize
     */
    $(document).ready(function() {
        console.log('🔥 Ratehawk Show Price Handler Loaded');
        
        // صبر کن تا صفحه کامل load بشه
        setTimeout(function() {
            initShowPriceHandler();
        }, 500);
    });
    
    /**
     * Initialize Show Price Handler
     */
    function initShowPriceHandler() {
        console.log('🎯 Initializing Show Price override...');
        
        // حذف تمام event handlers قبلی از دکمه‌های Show Price
        $('.btn-show-price').off('click');
        
        // اضافه کردن handler جدید
        $(document).on('click', '.btn-show-price', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            
            console.log('🚀 Show Price clicked - Loading Ratehawk rates...');
            
            handleShowPriceClick($(this));
            
            return false;
        });
        
        console.log('✅ Show Price handler attached to', $('.btn-show-price').length, 'buttons');
    }
    
    /**
     * مدیریت کلیک روی Show Price
     */
    function handleShowPriceClick($button) {
        var $form = $button.closest('form.form-booking-inpage');
        
        if ($form.length === 0) {
            console.error('❌ Form not found');
            return;
        }
        
        // دریافت تاریخ‌ها - اول از فرم اتاق، بعد از فرم بالا
        var checkin = $form.find('input[name="check_in"]').val() || 
                     $form.find('input[name="start"]').val() ||
                     $('.form-check-availability-hotel input[name="start"]').val();
                     
        var checkout = $form.find('input[name="check_out"]').val() || 
                      $form.find('input[name="end"]').val() ||
                      $('.form-check-availability-hotel input[name="end"]').val();
        
        // اگر هنوز تاریخ نداریم، از کاربر بخواه
        if (!checkin || !checkout) {
            showError('Please select check-in and check-out dates first');
            resetButton($button);
            return;
        }
        
        // دریافت داده‌های فرم
        var formData = {
            action: 'rh_get_room_rates',
            nonce: rhTravelerForm.nonce,
            hotel_id: rhTravelerForm.hotelId,
            room_id: $form.find('input[name="room_id"]').val(),
            checkin: checkin,
            checkout: checkout,
            adults: parseInt($form.find('input[name="adult_number"]').val()) || 
                   parseInt($('.form-check-availability-hotel input[name="adult_number"]').val()) || 2,
            children: parseInt($form.find('input[name="child_number"]').val()) || 
                     parseInt($('.form-check-availability-hotel input[name="child_number"]').val()) || 0
        };
        
        console.log('📤 Request data:', formData);
        
        // Validation
        if (!formData.room_id) {
            showError('Room ID not found');
            return;
        }
        
        // نمایش loading در جای دکمه
        showLoading($button);
        
        // ارسال درخواست AJAX
        $.ajax({
            url: rhTravelerForm.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('📥 Response:', response);
                
                if (response.success) {
                    displayRates($button, response.data, formData.room_id);
                } else {
                    showError(response.data || 'Unknown error');
                    resetButton($button);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX Error:', error);
                showError('Network error: ' + error);
                resetButton($button);
            }
        });
    }
    
    /**
     * نمایش loading
     */
    function showLoading($button) {
        $button.prop('disabled', true)
               .html('<i class="fa fa-spinner fa-spin"></i> Loading...')
               .addClass('loading');
    }
    
    /**
     * بازگرداندن دکمه به حالت اولیه
     */
    function resetButton($button) {
        $button.prop('disabled', false)
               .html('Show Price')
               .removeClass('loading');
    }
    
    /**
     * نمایش قیمت‌ها
     */
    function displayRates($button, rates, roomId) {
        console.log('🎨 Displaying', rates.length, 'rates');
        
        if (!rates || rates.length === 0) {
            showError(rhTravelerForm.noRatesText);
            resetButton($button);
            return;
        }
        
        var $item = $button.closest('.item');
        var $priceContainer = $item.find('.col-xs-12.col-md-4').first();
        
        // ساخت HTML قیمت‌ها
        var html = '<div class="rh-rates-display">';
        html += '<h4 class="rh-rates-title">Available Rates:</h4>';
        
        rates.forEach(function(rate, index) {
            html += buildRateItem(rate, index);
        });
        
        html += '</div>';
        
        // جایگزینی محتوا
        $priceContainer.html(html);
        
        // Smooth scroll
        $('html, body').animate({
            scrollTop: $item.offset().top - 100
        }, 500);
    }
    
    /**
     * ساخت یک آیتم قیمت
     */
    function buildRateItem(rate, index) {
        var priceDisplay = formatPrice(rate.price, rate.currency);
        var pricePerNight = rate.price / rate.nights;
        
        var html = '<div class="rh-rate-item' + (index === 0 ? ' best-price' : '') + '">';
        
        // Badge برای بهترین قیمت
        if (index === 0) {
            html += '<span class="rh-best-badge">🏆 Best Price</span>';
        }
        
        // Meal
        if (rate.meal && rate.meal !== 'nomeal') {
            html += '<div class="rh-meal">' + getMealLabel(rate.meal) + '</div>';
        }
        
        // Price
        html += '<div class="rh-price-box">';
        html += '<div class="rh-price-main">' + priceDisplay + '</div>';
        html += '<div class="rh-price-detail">';
        html += formatPrice(pricePerNight, rate.currency) + ' × ' + rate.nights + ' night' + (rate.nights > 1 ? 's' : '');
        html += '</div>';
        html += '</div>';
        
        // Features
        if (rate.room_features && rate.room_features.length > 0) {
            html += '<div class="rh-features">';
            rate.room_features.forEach(function(feature) {
                html += '<span class="rh-feature">• ' + escapeHtml(feature) + '</span>';
            });
            html += '</div>';
        }
        
        // Cancellation
        if (rate.cancellation_info && rate.cancellation_info.free_cancellation_before) {
            html += '<div class="rh-cancellation free">';
            html += '<i class="fa fa-shield-alt"></i> Free cancellation';
            html += '</div>';
        } else {
            html += '<div class="rh-cancellation non-refundable">';
            html += '<i class="fa fa-ban"></i> Non-refundable';
            html += '</div>';
        }
        
        // Book Button
        html += '<button class="rh-book-button" ';
        html += 'data-book-hash="' + escapeHtml(rate.book_hash) + '" ';
        html += 'data-price="' + rate.price + '" ';
        html += 'data-currency="' + rate.currency + '">';
        html += '<span class="rh-btn-icon">🛎️</span> Book Now';
        html += '</button>';
        
        html += '</div>';
        
        return html;
    }
    
    /**
     * نمایش خطا
     */
    function showError(message) {
        console.error('❌ Error:', message);
        
        // نمایش toast
        var toast = $('<div class="rh-error-toast">')
            .html('<i class="fa fa-exclamation-circle"></i> ' + escapeHtml(message));
        
        $('body').append(toast);
        
        setTimeout(function() {
            toast.addClass('show');
        }, 10);
        
        setTimeout(function() {
            toast.removeClass('show');
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 5000);
    }
    
    /**
     * فرمت قیمت
     */
    function formatPrice(amount, currency) {
        var symbols = {
            'USD': '$',
            'EUR': '€',
            'GBP': '£',
            'JPY': '¥',
            'CAD': 'C$',
            'AUD': 'A$'
        };
        
        var symbol = symbols[currency] || currency + ' ';
        return symbol + parseFloat(amount).toFixed(2);
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
/**
 * Ratehawk Traveler Form Integration
 * Override کردن دکمه‌های "Show Price" و نمایش قیمت‌های Ratehawk
 * نسخه‌ی کامل + پچ‌های تاریخ و nights و fallback کانتینر قیمت
 */

(function($) {
    'use strict';

    // ========= Helpers: تاریخ، nights، امن‌سازی =========
    function toISOFromDMY(dmy) {
        // dmy => "DD/MM/YYYY"
        const [d, m, y] = dmy.split('/');
        return [y, m.padStart(2,'0'), d.padStart(2,'0')].join('-');
    }
    function normalizeIso(dateStr) {
        if (!dateStr) return '';
        // اگر رنج بود: "30/10/2025 12:00 am-31/10/2025 11:59 pm"
        if (dateStr.includes('-') && dateStr.match(/\d{1,2}\/\d{1,2}\/\d{4}/)) {
            const m = dateStr.match(/(\d{1,2}\/\d{1,2}\/\d{4}).*-(\d{1,2}\/\d{1,2}\/\d{4})/);
            if (m) {
                return {
                    checkin: toISOFromDMY(m[1]),
                    checkout: toISOFromDMY(m[2])
                };
            }
        }
        // اگر از قبل ISO بود
        if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return dateStr;

        // YYYY/MM/DD
        if (/^\d{4}\/\d{1,2}\/\d{1,2}$/.test(dateStr)) {
            const [y, m, d] = dateStr.split('/');
            return [y, String(m).padStart(2,'0'), String(d).padStart(2,'0')].join('-');
        }
        // DD/MM/YYYY
        if (/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(dateStr)) {
            return toISOFromDMY(dateStr);
        }
        // DD.MM.YYYY
        if (/^\d{1,2}\.\d{1,2}\.\d{4}$/.test(dateStr)) {
            const [d, m, y] = dateStr.split('.');
            return [y, m.padStart(2,'0'), d.padStart(2,'0')].join('-');
        }
        // fallback
        return dateStr;
    }
    function ensureIsoPair(checkin, checkout) {
        // ورودی ممکن است یک رنج باشد
        if (checkin && checkin.includes('/') && checkin.includes('-') && !checkout) {
            const res = normalizeIso(checkin);
            if (typeof res === 'object') return res;
        }
        return {
            checkin: normalizeIso(checkin),
            checkout: normalizeIso(checkout)
        };
    }
    function calcNights(isoCheckin, isoCheckout) {
        if (!isoCheckin || !isoCheckout) return 1;
        const t1 = new Date(isoCheckin + 'T12:00:00Z').getTime();
        const t2 = new Date(isoCheckout + 'T12:00:00Z').getTime();
        const n = Math.round((t2 - t1) / (1000*60*60*24));
        return Math.max(1, n || 1);
    }
    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    // ========= UI helpers =========
    function showLoading($button) {
        $button.prop('disabled', true)
               .html('<i class="fa fa-spinner fa-spin"></i> Loading...')
               .addClass('loading');
    }
    function resetButton($button) {
        $button.prop('disabled', false)
               .html('Show Price')
               .removeClass('loading');
    }
    function showError(message) {
        console.error('❌ Error:', message);
        var toast = $('<div class="rh-error-toast">')
            .html('<i class="fa fa-exclamation-circle"></i> ' + escapeHtml(message));
        $('body').append(toast);
        setTimeout(function(){ toast.addClass('show'); }, 10);
        setTimeout(function(){
            toast.removeClass('show');
            setTimeout(function(){ toast.remove(); }, 300);
        }, 5000);
    }
    function formatPrice(amount, currency) {
        var symbols = { USD:'$', EUR:'€', GBP:'£', JPY:'¥', CAD:'C$', AUD:'A$' };
        var symbol = symbols[currency] || (currency ? currency + ' ' : '');
        var num = parseFloat(amount);
        if (isNaN(num)) num = 0;
        return symbol + num.toFixed(2);
    }
    function getMealLabel(meal) {
        var labels = {
            'nomeal': 'Room Only',
            'breakfast': '🍳 Breakfast',
            'half_board': '🍽️ Half Board',
            'full_board': '🍴 Full Board',
            'all_inclusive': '⭐ All Inclusive'
        };
        return labels[meal] || meal || '';
    }

    // ========= Init =========
    $(document).ready(function() {
        console.log('🔥 Ratehawk Show Price Handler Loaded');
        setTimeout(function() {
            initShowPriceHandler();
        }, 500);
    });

    // ========= Binding =========
    function initShowPriceHandler() {
        console.log('🎯 Initializing Show Price override...');
        $('.btn-show-price').off('click'); // پاک‌کردن هندلرهای قبلی

        $(document).on('click', '.btn-show-price', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            console.log('🚀 Show Price clicked - Loading Ratehawk rates...');
            handleShowPriceClick($(this));
            return false;
        });

        console.log('✅ Show Price handler attached to', $('.btn-show-price').length, 'buttons');
    }

    // ========= Click handler =========
    function handleShowPriceClick($button) {
        var $form = $button.closest('form.form-booking-inpage');
        if ($form.length === 0) {
            console.error('❌ Form not found');
            showError('Form not found for this room.');
            return;
        }

        // دریافت تاریخ‌ها از فرم اتاق یا فرم اصلی
        var checkin = $form.find('input[name="check_in"]').val() ||
                      $form.find('input[name="start"]').val() ||
                      $('.form-check-availability-hotel input[name="start"]').val();

        var checkout = $form.find('input[name="check_out"]').val() ||
                       $form.find('input[name="end"]').val() ||
                       $('.form-check-availability-hotel input[name="end"]').val();

        // Hiddenهای استاندارد (قالب Traveler معمولاً ست می‌کند)
        var rawStart = $form.find('input.check-in-input[name="start"]').val() ||
                       $form.find('input[name="start"]').val() ||
                       $form.find('input[name="check_in"]').val() ||
                       $('.form-check-availability-hotel input[name="start"]').val() ||
                       $('.form-check-availability-hotel input[name="check_in"]').val() ||
                       checkin;

        var rawEnd   = $form.find('input.check-out-input[name="end"]').val() ||
                       $form.find('input[name="end"]').val() ||
                       $form.find('input[name="check_out"]').val() ||
                       $('.form-check-availability-hotel input[name="end"]').val() ||
                       $('.form-check-availability-hotel input[name="check_out"]').val() ||
                       checkout;

        var isoPair  = ensureIsoPair(rawStart, rawEnd);
        checkin  = isoPair.checkin;
        checkout = isoPair.checkout;

        if (!checkin || !checkout) {
            showError('Please select check-in and check-out dates first');
            resetButton($button);
            return;
        }
        // اعتبارسنجی ISO
        if (!/^\d{4}-\d{2}-\d{2}$/.test(checkin) || !/^\d{4}-\d{2}-\d{2}$/.test(checkout)) {
            showError('Invalid date format. Please select dates again.');
            resetButton($button);
            return;
        }

        // داده‌های ارسالی
        var formData = {
            action: 'rh_get_room_rates',
            nonce: rhTravelerForm.nonce,
            hotel_id: rhTravelerForm.hotelId,
            room_id: $form.find('input[name="room_id"]').val(),
            checkin: checkin,
            checkout: checkout,
            adults:  parseInt($form.find('input[name="adult_number"]').val()) ||
                     parseInt($('.form-check-availability-hotel input[name="adult_number"]').val()) || 2,
            children: parseInt($form.find('input[name="child_number"]').val()) ||
                      parseInt($('.form-check-availability-hotel input[name="child_number"]').val()) || 0
        };

        if (!formData.room_id) {
            showError('Room ID not found');
            resetButton($button);
            return;
        }

        showLoading($button);

        $.ajax({
            url: rhTravelerForm.ajaxUrl,
            type: 'POST',
            data: formData,
            success: function(response) {
                console.log('📥 Response:', response);

                if (response && response.success) {
                    displayRates($button, response.data, formData.room_id, checkin, checkout);
                } else {
                    showError((response && response.data) || rhTravelerForm.noRatesText || 'No rates available from Ratehawk');
                    resetButton($button);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX Error:', error);
                showError('Network error: ' + (error || status));
                resetButton($button);
            }
        });
    }

    // ========= Render rates =========
    function displayRates($button, rates, roomId, isoCheckin, isoCheckout) {
        console.log('🎨 Displaying rates:', Array.isArray(rates) ? rates.length : rates);

        if (!rates || rates.length === 0) {
            showError(rhTravelerForm.noRatesText || 'No rates available');
            resetButton($button);
            return;
        }

        var $item = $button.closest('.item');
        var $priceContainer =
            $item.find('.col-xs-12.col-md-4').first().length ?
            $item.find('.col-xs-12.col-md-4').first() :
            $item.find('.st-room-price, .st-price').first();

        if (!$priceContainer.length) {
            $priceContainer = $('<div class="col-xs-12 col-md-4"></div>').appendTo($item);
        }

        var html = '<div class="rh-rates-display"><h4 class="rh-rates-title">Available Rates:</h4>';

        rates.forEach(function(rate, index) {
            // nights ممکن است از API نیاید
            if (!rate.nights || parseInt(rate.nights) <= 0) {
                var ci = rate.checkin || isoCheckin || $('input[name="start"]').val();
                var co = rate.checkout || isoCheckout || $('input[name="end"]').val();
                var pair = ensureIsoPair(ci, co);
                rate.nights = calcNights(pair.checkin, pair.checkout);
            }
            html += buildRateItem(rate, index);
        });

        html += '</div>';

        $priceContainer.html(html);
        $('html, body').animate({ scrollTop: $item.offset().top - 100 }, 500);
        resetButton($button);
    }

    function buildRateItem(rate, index) {
        var nights = parseInt(rate.nights) > 0 ? parseInt(rate.nights) : 1;
        var price = parseFloat(rate.price);
        if (isNaN(price)) price = 0;

        var pricePerNight = price / nights;
        var priceDisplay = formatPrice(price, rate.currency);

        var html = '<div class="rh-rate-item' + (index === 0 ? ' best-price' : '') + '">';

        if (index === 0) {
            html += '<span class="rh-best-badge">🏆 Best Price</span>';
        }

        if (rate.meal && rate.meal !== 'nomeal') {
            html += '<div class="rh-meal">' + getMealLabel(rate.meal) + '</div>';
        }

        html += '<div class="rh-price-box">';
        html += '<div class="rh-price-main">' + priceDisplay + '</div>';
        html += '<div class="rh-price-detail">' + formatPrice(pricePerNight, rate.currency) +
                ' × ' + nights + ' night' + (nights > 1 ? 's' : '') + '</div>';
        html += '</div>';

        if (rate.room_features && rate.room_features.length > 0) {
            html += '<div class="rh-features">';
            rate.room_features.forEach(function(feature) {
                html += '<span class="rh-feature">• ' + escapeHtml(feature) + '</span>';
            });
            html += '</div>';
        }

        if (rate.cancellation_info && rate.cancellation_info.free_cancellation_before) {
            html += '<div class="rh-cancellation free"><i class="fa fa-shield-alt"></i> Free cancellation</div>';
        } else {
            html += '<div class="rh-cancellation non-refundable"><i class="fa fa-ban"></i> Non-refundable</div>';
        }

        html += '<button class="rh-book-button" ' +
                'data-book-hash="' + escapeHtml(rate.book_hash || '') + '" ' +
                'data-price="' + price + '" ' +
                'data-currency="' + escapeHtml(rate.currency || '') + '">' +
                '<span class="rh-btn-icon">🛎️</span> Book Now</button>';

        html += '</div>';
        return html;
    }

})(jQuery);

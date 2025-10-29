/**
 * Ratehawk Single Room Rates
 * Version: 1.1 - با نمایش عنوان و قیمت در دکمه Book
 */

(function($) {
    'use strict';

    console.log('%c🔥 RH Single Room Rates Loaded!', 'background:#00f;color:#fff;font-size:16px;padding:10px;');

    const RH_SingleRoom = {
        
        selectedRate: null,
        rates: [],
        
        init: function() {
            console.log('🚀 RH_SingleRoom.init()');
            
            if (typeof rhRoomRates === 'undefined') {
                console.error('❌ rhRoomRates config not found!');
                return;
            }
            
            $(document).ready(() => {
                console.log('📄 Single Room page ready!');
                this.checkIfRoomPage();
            });
        },

        /**
         * چک کن صفحه room هست یا نه
         */
        checkIfRoomPage: function() {
            // چک کردن URL
            if (!window.location.pathname.includes('/hotel_room/')) {
                console.log('⚠️ Not a room page');
                return;
            }
            
            console.log('✅ Room page detected!');
            
            // گرفتن room ID از DOM
            const roomId = this.getRoomId();
            if (!roomId) {
                console.log('⚠️ Room ID not found');
                return;
            }
            
            console.log('📋 Room ID:', roomId);
            
            // گرفتن search params
            const params = this.getSearchParams();
            
            if (!params.checkin || !params.checkout) {
                console.log('⚠️ No search dates');
                this.showNoDateMessage();
                return;
            }
            
            console.log('📅 Search params:', params);
            
            // Load rates
            this.loadRoomRates(roomId, params);
        },

        /**
         * گرفتن Room ID از URL یا DOM
         */
        getRoomId: function() {
            // از URL
            const urlParams = new URLSearchParams(window.location.search);
            const roomId = urlParams.get('room_id');
            
            if (roomId) {
                return roomId;
            }
            
            // از فرم booking
            const $roomInput = $('input[name="room_id"]');
            if ($roomInput.length) {
                return $roomInput.val();
            }
            
            // از post ID
            if (typeof st_room_id !== 'undefined') {
                return st_room_id;
            }
            
            // از data attribute
            const roomIdFromData = $('[data-room-id]').first().data('room-id');
            if (roomIdFromData) {
                return roomIdFromData;
            }
            
            return null;
        },

        /**
         * گرفتن search params از URL
         */
        getSearchParams: function() {
            const urlParams = new URLSearchParams(window.location.search);
            
            return {
                checkin: urlParams.get('start') || urlParams.get('checkin') || '',
                checkout: urlParams.get('end') || urlParams.get('checkout') || '',
                adults: parseInt(urlParams.get('adult_number') || urlParams.get('adults')) || 2,
                children: parseInt(urlParams.get('child_number') || urlParams.get('children')) || 0,
                rooms: parseInt(urlParams.get('room_num_search') || urlParams.get('rooms')) || 1
            };
        },

        /**
         * نمایش پیام وقتی تاریخ انتخاب نشده
         */
        showNoDateMessage: function() {
            const $priceArea = $('.btn_hotel_booking').parent();
            
            if ($priceArea.length) {
                $priceArea.prepend(`
                    <div class="rh-rate-notice" style="padding:15px;background:#fff3cd;border-radius:8px;margin-bottom:15px;text-align:center;">
                        <strong>💡 Please select dates to see available rates</strong>
                        <p style="margin:5px 0 0;font-size:14px;">Go back to the hotel page and choose your check-in/check-out dates.</p>
                    </div>
                `);
            }
        },

        /**
         * لود کردن rates برای این اتاق
         */
        loadRoomRates: function(roomId, params) {
            console.log('📡 Loading rates for room:', roomId);
            
            // نمایش loading
            this.showLoading();
            
            $.ajax({
                url: rhRoomRates.ajax_url,
                type: 'POST',
                data: {
                    action: 'rh_get_room_rates',
                    nonce: rhRoomRates.nonce,
                    hotel_id: rhRoomRates.hotel_id,
                    hid: rhRoomRates.hid,
                    room_id: roomId,
                    checkin: this.formatDate(params.checkin),
                    checkout: this.formatDate(params.checkout),
                    adults: params.adults
                },
                success: (response) => {
                    console.log('✅ Rates received:', response);
                    this.hideLoading();
                    
                    if (response.success && response.data && response.data.length > 0) {
                        this.displayRates(response.data, params);
                    } else {
                        this.showNoRatesMessage();
                    }
                },
                error: (xhr, status, error) => {
                    console.error('❌ AJAX Error:', error);
                    this.hideLoading();
                    this.showErrorMessage();
                }
            });
        },

        /**
         * فرمت تاریخ
         */
        formatDate: function(dateStr) {
            if (!dateStr) return '';
            
            // تبدیل "2025/10/30" به "2025-10-30"
            return dateStr.replace(/\//g, '-');
        },

        /**
         * نمایش Loading
         */
        showLoading: function() {
            const $bookingArea = $('.btn_hotel_booking').parent();
            
            $bookingArea.prepend(`
                <div class="rh-rates-loading" style="padding:20px;text-align:center;background:#f9f9f9;border-radius:8px;margin-bottom:15px;">
                    <div class="spinner" style="display:inline-block;width:30px;height:30px;border:3px solid #f3f3f3;border-top:3px solid #667eea;border-radius:50%;animation:spin 1s linear infinite;"></div>
                    <p style="margin:10px 0 0;">Loading available rates...</p>
                </div>
            `);
            
            // اضافه کردن animation
            if (!$('#rh-spinner-animation').length) {
                $('head').append(`
                    <style id="rh-spinner-animation">
                        @keyframes spin {
                            0% { transform: rotate(0deg); }
                            100% { transform: rotate(360deg); }
                        }
                    </style>
                `);
            }
        },

        hideLoading: function() {
            $('.rh-rates-loading').remove();
        },

        /**
         * نمایش Rates
         */
        displayRates: function(rates, params) {
            console.log('📊 Displaying', rates.length, 'rates');
            
            // پاک کردن قیمت قدیمی
            $('.price-wrapper, .rh-single-room-rates').remove();
            
            // ساخت HTML
            let html = `
                <div class="rh-single-room-rates" style="background:#f9f9f9;padding:20px;border-radius:8px;margin-bottom:20px;">
                    <h3 style="margin:0 0 15px;color:#333;font-size:20px;">
                        <span style="background:#667eea;color:white;padding:5px 10px;border-radius:4px;font-size:14px;margin-right:10px;">RateHawk</span>
                        Available Room Rates
                    </h3>
                    <p style="margin:0 0 15px;color:#666;font-size:14px;">
                        <strong>${params.rooms} Room(s)</strong> × <strong>${this.calculateNights(params.checkin, params.checkout)} Night(s)</strong> for <strong>${params.adults} Adult(s)</strong>
                    </p>
                    <div class="rh-rates-list">
            `;
            
            rates.forEach((rate, index) => {
                const mealType = this.getMealLabel(rate.meal);
                const cancellation = rate.cancellation?.is_refundable ? 
                    '<span style="color:#28a745;">✓ Free cancellation</span>' : 
                    '<span style="color:#dc3545;">✗ Non-refundable</span>';
                
                const price = rate.payment_options?.payment_types?.[0]?.show_amount || 0;
                const currency = rate.payment_options?.payment_types?.[0]?.currency_code || 'USD';
                const symbol = this.getCurrencySymbol(currency);
                
                html += `
                    <div class="rh-rate-option ${index === 0 ? 'selected' : ''}" data-rate-index="${index}" style="background:white;padding:15px;margin-bottom:10px;border-radius:6px;border:2px solid ${index === 0 ? '#667eea' : '#ddd'};cursor:pointer;transition:all 0.3s;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div style="flex:1;">
                                <div style="display:flex;align-items:flex-start;margin-bottom:8px;">
                                    <input type="radio" name="selected_rate" value="${index}" ${index === 0 ? 'checked' : ''} style="margin:3px 10px 0 0;flex-shrink:0;">
                                    <div style="flex:1;">
                                        <strong style="font-size:16px;display:block;margin-bottom:5px;">${mealType}</strong>
                                        <div style="font-size:13px;color:#666;">
                                            ${cancellation}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div style="text-align:right;margin-left:15px;">
                                <div style="font-size:24px;font-weight:700;color:#667eea;white-space:nowrap;">
                                    ${symbol}${Math.round(price)}
                                </div>
                                <div style="font-size:11px;color:#999;">total price</div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            html += `
                    </div>
                </div>
            `;
            
            // اضافه کردن به صفحه
            const $bookingBtn = $('.btn_hotel_booking');
            $bookingBtn.before(html);
            
            // ذخیره rates
            this.rates = rates;
            this.selectedRate = rates[0];
            
            // Event handlers
            this.attachRateSelectionHandlers();
            
            // آپدیت دکمه booking
            this.updateBookingButton();
        },

        /**
         * محاسبه تعداد شب
         */
        calculateNights: function(checkin, checkout) {
            try {
                const start = new Date(checkin.replace(/\//g, '-'));
                const end = new Date(checkout.replace(/\//g, '-'));
                const diff = Math.ceil((end - start) / (1000 * 60 * 60 * 24));
                return diff > 0 ? diff : 1;
            } catch(e) {
                return 1;
            }
        },

        /**
         * دریافت لیبل meal
         */
        getMealLabel: function(meal) {
            const labels = {
                'nomeal': '🛏️ Room Only',
                'room_only': '🛏️ Room Only',
                'breakfast': '🍳 Room + Breakfast',
                'half_board': '🍽️ Half Board (Breakfast + Dinner)',
                'full_board': '🍴 Full Board (All Meals)',
                'all_inclusive': '⭐ All Inclusive'
            };
            
            return labels[meal] || meal || 'Room Only';
        },

        /**
         * Event handlers برای انتخاب rate
         */
        attachRateSelectionHandlers: function() {
            const self = this;
            
            $('.rh-rate-option').on('click', function(e) {
                const $option = $(this);
                const index = $option.data('rate-index');
                
                // آپدیت UI
                $('.rh-rate-option').removeClass('selected').css('border-color', '#ddd');
                $option.addClass('selected').css('border-color', '#667eea');
                
                // آپدیت radio
                $option.find('input[type="radio"]').prop('checked', true);
                
                // ذخیره selected rate
                self.selectedRate = self.rates[index];
                
                console.log('✅ Rate selected:', self.selectedRate);
                
                // آپدیت دکمه
                self.updateBookingButton();
            });
        },

        /**
         * آپدیت دکمه Booking
         */
        updateBookingButton: function() {
            const $bookingBtn = $('.btn_hotel_booking');
            
            if (!this.selectedRate) {
                return;
            }
            
            const price = this.getFormattedPrice();
            const mealType = this.getMealLabel(this.selectedRate.meal);
            
            // تغییر متن دکمه
            $bookingBtn.html(`
                <span style="display:block;font-size:14px;font-weight:normal;margin-bottom:5px;">${mealType}</span>
                <span style="display:block;font-size:18px;font-weight:700;">Book Now - ${price}</span>
            `);
            
            // اضافه کردن book_hash به data attribute
            if (this.selectedRate.book_hash) {
                $bookingBtn.attr('data-book-hash', this.selectedRate.book_hash);
                $bookingBtn.attr('data-rate-price', this.selectedRate.payment_options?.payment_types?.[0]?.show_amount || 0);
                $bookingBtn.attr('data-rate-currency', this.selectedRate.payment_options?.payment_types?.[0]?.currency_code || 'USD');
            }
            
            console.log('🔄 Button updated with:', {
                price: price,
                book_hash: this.selectedRate.book_hash,
                meal: mealType
            });
        },

        getFormattedPrice: function() {
            if (!this.selectedRate) return '$0';
            
            const price = this.selectedRate.payment_options?.payment_types?.[0]?.show_amount || 0;
            const currency = this.selectedRate.payment_options?.payment_types?.[0]?.currency_code || 'USD';
            const symbol = this.getCurrencySymbol(currency);
            
            return symbol + Math.round(price);
        },

        getCurrencySymbol: function(currency) {
            const symbols = {
                'USD': '$',
                'EUR': '€',
                'GBP': '£',
                'CAD': 'C$',
                'AUD': 'A$'
            };
            return symbols[currency] || currency + ' ';
        },

        showNoRatesMessage: function() {
            $('.btn_hotel_booking').parent().prepend(`
                <div class="rh-rate-notice" style="padding:15px;background:#f8d7da;border-radius:8px;margin-bottom:15px;text-align:center;color:#721c24;">
                    <strong>⚠️ No rates available</strong>
                    <p style="margin:5px 0 0;">This room is not available for the selected dates.</p>
                </div>
            `);
        },

        showErrorMessage: function() {
            $('.btn_hotel_booking').parent().prepend(`
                <div class="rh-rate-notice" style="padding:15px;background:#f8d7da;border-radius:8px;margin-bottom:15px;text-align:center;color:#721c24;">
                    <strong>⚠️ Error loading rates</strong>
                    <p style="margin:5px 0 0;">Please try again later.</p>
                </div>
            `);
        }
    };

    // Initialize
    RH_SingleRoom.init();

    // Export
    window.RH_SingleRoom = RH_SingleRoom;

})(jQuery);
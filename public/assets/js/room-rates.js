/**
 * Ratehawk Room Rates - Replace Prices in DOM
 * Version: 4.0 - FINAL
 */

(function($) {
    'use strict';

    console.log('%c🔥 RH Room Rates V4 - Price Replacer!', 'background:#0f0;color:#000;font-size:16px;padding:10px;');

    const RH_RoomRates = {
        
        cache: {}, // Cache برای rates
        metapolicy: {}, // Metapolicy هتل
        
        init: function() {
            console.log('🚀 RH_RoomRates.init() called');
            
            if (typeof rhRoomRates === 'undefined') {
                console.error('❌ rhRoomRates config not found!');
                return;
            }
            
            console.log('✅ Config loaded:', rhRoomRates);
            
            $(document).ready(() => {
                console.log('📄 Document ready!');
                this.setupMutationObserver();
                this.checkExistingRooms();
                
                // Hook به دکمه Check Availability
                this.hookCheckAvailability();
            });
        },

        /**
         * Hook به دکمه Check Availability
         */
        hookCheckAvailability: function() {
            console.log('🎣 Hooking to Check Availability...');
            
            $(document).on('click', '.btn-check-availability, .search-form button[type="submit"]', (e) => {
                console.log('✅ Check Availability clicked!');
                
                // صبر میکنیم تا اتاق‌ها لود بشن
                setTimeout(() => {
                    this.loadAllRates();
                }, 2000); // 2 ثانیه صبر
            });
        },

        /**
         * چک کردن اتاق‌های موجود
         */
        checkExistingRooms: function() {
            console.log('🔍 Checking existing rooms...');
            
            const rooms = $('.st-list-rooms .item.st-border-radius');
            console.log('Found rooms:', rooms.length);
            
            if (rooms.length > 0) {
                this.loadAllRates();
            }
        },

        /**
         * راه‌اندازی MutationObserver
         */
        setupMutationObserver: function() {
            console.log('👀 Setting up MutationObserver...');
            
            const observer = new MutationObserver((mutations) => {
                let roomsAdded = false;
                
                mutations.forEach((mutation) => {
                    if (mutation.addedNodes.length) {
                        mutation.addedNodes.forEach((node) => {
                            if (node.nodeType === 1) {
                                const $node = $(node);
                                
                                // چک کن اگه اتاق اضافه شده
                                if (this.isRoomElement($node) || 
                                    $node.find('.st-list-rooms .item.st-border-radius').length > 0) {
                                    roomsAdded = true;
                                }
                            }
                        });
                    }
                });
                
                if (roomsAdded) {
                    console.log('🎯 Rooms added! Loading rates...');
                    this.loadAllRates();
                }
            });

            const targetNode = document.querySelector('.st-list-rooms');
            if (targetNode) {
                observer.observe(targetNode, {
                    childList: true,
                    subtree: true
                });
                console.log('✅ MutationObserver started!');
            }
        },

        isRoomElement: function($element) {
            return $element.hasClass('item') && 
                   $element.hasClass('st-border-radius') && 
                   $element.closest('.st-list-rooms').length > 0;
        },

        /**
         * لود کردن rates برای همه اتاق‌ها
         */
        loadAllRates: function() {
            console.log('📡 Loading rates for all rooms...');
            
            const searchParams = this.getSearchParams();
            
            if (!searchParams.checkin || !searchParams.checkout) {
                console.log('⚠️ No dates selected, skipping...');
                return;
            }
            
            // چک کردن cache
            const cacheKey = `${searchParams.checkin}_${searchParams.checkout}_${searchParams.adults}`;
            if (this.cache[cacheKey]) {
                console.log('✅ Using cached rates!');
                
                // بازیابی metapolicy از cache
                if (this.cache[cacheKey].metapolicy) {
                    this.metapolicy = this.cache[cacheKey].metapolicy;
                }
                
                this.processAllRates(this.cache[cacheKey].rates || this.cache[cacheKey]);
                return;
            }
            
            // گرفتن rates از API
            console.log('🌐 Fetching rates from API...');
            
            $.ajax({
                url: rhRoomRates.ajax_url,
                type: 'POST',
                data: {
                    action: 'rh_get_hotel_rates', // Action جدید
                    nonce: rhRoomRates.nonce,
                    hotel_id: rhRoomRates.hotel_id,
                    hid: rhRoomRates.hid,
                    checkin: searchParams.checkin,
                    checkout: searchParams.checkout,
                    adults: searchParams.adults
                },
                success: (response) => {
                    console.log('✅ Rates received:', response);
                    
                    if (response.success && response.data) {
                        // ذخیره metapolicy
                        if (response.data.metapolicy) {
                            this.metapolicy = response.data.metapolicy;
                            console.log('📋 Metapolicy loaded:', this.metapolicy);
                        }
                        
                        // ذخیره در cache
                        this.cache[cacheKey] = response.data;
                        
                        // پردازش rates
                        this.processAllRates(response.data.rates || response.data);
                    } else {
                        console.error('❌ No rates available');
                    }
                },
                error: (xhr, status, error) => {
                    console.error('❌ AJAX Error:', error);
                }
            });
        },

        /**
         * پردازش و جایگزینی rates
         */
        processAllRates: function(allRates) {
            console.log('🔄 Processing rates for all rooms...');
            
            const rooms = $('.st-list-rooms .item.st-border-radius');
            let minRate = null;
            
            rooms.each((index, roomEl) => {
                const $room = $(roomEl);
                const roomId = this.getRoomId($room);
                
                if (!roomId) {
                    console.log('⚠️ Room ID not found for room', index);
                    return;
                }
                
                console.log('📋 Processing room:', roomId);
                
                // فیلتر کردن rates برای این اتاق
                const roomRates = this.filterRatesForRoom(allRates, roomId);
                
                if (roomRates.length === 0) {
                    console.log('⚠️ No rates for room:', roomId);
                    return;
                }
                
                // ارزان‌ترین rate
                const cheapestRate = roomRates[0]; // قبلاً sort شده
                
                console.log('💰 Cheapest rate:', cheapestRate.price.formatted);
                
                // جایگزینی قیمت در DOM
                this.replacePriceInRoom($room, cheapestRate);
                
                // آپدیت minimum rate
                if (!minRate || cheapestRate.price.amount < minRate.price.amount) {
                    minRate = cheapestRate;
                }
            });
            
            // آپدیت قیمت شروع هتل
            if (minRate) {
                this.updateHotelStartPrice(minRate);
            }
            
            console.log('✅ All prices updated!');
        },

        /**
         * فیلتر rates برای یک اتاق خاص
         */
        filterRatesForRoom: function(allRates, roomId) {
            const roomName = this.getRoomName(roomId);
            
            if (!roomName) {
                return [];
            }
            
            console.log('🔍 Filtering rates for room:', roomName);
            
            const filtered = [];
            
            allRates.forEach((rate) => {
                // نام اتاق از API
                const rateName = rate.room_data_trans?.main_room_type || 
                                rate.room_name || '';
                
                if (this.matchRoomNames(roomName, rateName)) {
                    // گرفتن قیمت از structure صحیح
                    const priceData = this.extractPrice(rate);
                    
                    if (priceData) {
                        filtered.push({
                            ...rate,
                            price: priceData
                        });
                    }
                }
            });
            
            // Sort by price
            filtered.sort((a, b) => a.price.amount - b.price.amount);
            
            console.log('✅ Found', filtered.length, 'rates for room:', roomName);
            
            return filtered;
        },
        
        /**
         * استخراج قیمت از structure API
         */
        extractPrice: function(rate) {
            let basePrice = null;
            
            // تلاش 1: payment_options
            if (rate.payment_options?.payment_types?.[0]) {
                const payment = rate.payment_options.payment_types[0];
                basePrice = {
                    amount: parseFloat(payment.show_amount || payment.amount || 0),
                    currency: payment.currency_code || 'USD'
                };
            }
            // تلاش 2: daily_prices
            else if (rate.daily_prices && rate.daily_prices.length > 0) {
                const total = rate.daily_prices.reduce((sum, day) => sum + parseFloat(day.amount || 0), 0);
                basePrice = {
                    amount: total,
                    currency: rate.daily_prices[0].currency || 'USD'
                };
            }
            // تلاش 3: rate.amount یا rate.price
            else if (rate.amount || rate.price) {
                basePrice = {
                    amount: parseFloat(rate.amount || rate.price),
                    currency: rate.currency || 'USD'
                };
            }
            
            if (!basePrice) {
                return null;
            }
            
            // اضافه کردن قیمت meal (اگه not_included باشه)
            const mealPrice = this.getMealPrice(rate);
            if (mealPrice > 0) {
                basePrice.amount += mealPrice;
                console.log('💰 Added meal price:', mealPrice, 'to base:', basePrice.amount - mealPrice);
            }
            
            basePrice.formatted = this.formatPrice(basePrice.amount, basePrice.currency);
            
            return basePrice;
        },
        
        /**
         * گرفتن قیمت meal از metapolicy
         */
        getMealPrice: function(rate) {
            // چک کردن meal type در rate
            const mealType = rate.meal || rate.meal_type || '';
            
            if (!mealType || mealType === 'nomeal' || mealType === 'room_only') {
                return 0;
            }
            
            // اگه meal included باشه، قیمتش صفره
            if (rate.meal_included || rate.payment_options?.meal_included) {
                return 0;
            }
            
            // جستجو در metapolicy
            if (!this.metapolicy || !this.metapolicy.meal) {
                return 0;
            }
            
            const meal = this.metapolicy.meal.find(m => 
                m.meal_type === mealType || 
                m.meal_type === mealType.toLowerCase()
            );
            
            if (meal && meal.inclusion === 'not_included') {
                return parseFloat(meal.price || 0);
            }
            
            return 0;
        },
        
        /**
         * فرمت کردن قیمت
         */
        formatPrice: function(amount, currency) {
            const symbol = this.getCurrencySymbol(currency);
            return symbol + Math.round(parseFloat(amount));
        },

        /**
         * گرفتن نام اتاق از DOM
         */
        getRoomName: function(roomId) {
            const $room = $(`.item.st-border-radius`).filter(function() {
                return $(this).find(`input[name="room_id"][value="${roomId}"]`).length > 0;
            });
            
            const roomName = $room.find('.heading a').text().trim();
            return roomName;
        },

        /**
         * مچ کردن نام‌های اتاق
         */
        matchRoomNames: function(name1, name2) {
            if (!name1 || !name2) return false;
            
            const clean1 = name1.toLowerCase().trim();
            const clean2 = name2.toLowerCase().trim();
            
            return clean1.includes(clean2) || clean2.includes(clean1);
        },

        /**
         * جایگزینی قیمت در DOM اتاق
         */
        replacePriceInRoom: function($room, rate) {
            const formattedPrice = rate.price.formatted;
            
            console.log('💵 Replacing price with:', formattedPrice);
            
            // پیدا کردن price wrapper
            const $priceWrapper = $room.find('.price-wrapper');
            
            if ($priceWrapper.length) {
                // جایگزین کردن قیمت
                $priceWrapper.find('.price').html(formattedPrice);
                
                // اضافه کردن badge
                $priceWrapper.css('position', 'relative');
                
                if (!$priceWrapper.find('.rh-badge').length) {
                    $priceWrapper.prepend(`
                        <span class="rh-badge" style="position:absolute;top:-8px;right:-8px;background:#667eea;color:white;font-size:9px;padding:2px 6px;border-radius:10px;font-weight:600;z-index:10;">
                            RH
                        </span>
                    `);
                }
                
                console.log('✅ Price replaced in DOM!');
            } else {
                console.log('⚠️ Price wrapper not found');
            }
        },

        /**
         * آپدیت قیمت شروع هتل
         */
        updateHotelStartPrice: function(minRate) {
            const formattedPrice = minRate.price.formatted;
            
            console.log('🏨 Updating hotel start price:', formattedPrice);
            
            const $formHead = $('.form-head');
            
            if ($formHead.length) {
                $formHead.find('.price').html(formattedPrice);
                
                // اضافه کردن badge
                if (!$formHead.find('.rh-badge').length) {
                    $formHead.css('position', 'relative');
                    $formHead.append(`
                        <span class="rh-badge" style="position:absolute;top:5px;right:5px;background:#667eea;color:white;font-size:10px;padding:3px 8px;border-radius:12px;font-weight:600;">
                            RateHawk
                        </span>
                    `);
                }
                
                console.log('✅ Hotel start price updated!');
            }
        },

        /**
         * گرفتن symbol ارز
         */
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

        getRoomId: function($room) {
            const roomInput = $room.find('input[name="room_id"]');
            if (roomInput.length) {
                return roomInput.val();
            }
            return null;
        },

        getSearchParams: function() {
            // تلاش 1: از URL
            const urlParams = new URLSearchParams(window.location.search);
            let checkin = urlParams.get('checkin') || '';
            let checkout = urlParams.get('checkout') || '';
            let adults = parseInt(urlParams.get('adults')) || 2;
            
            // تلاش 2: از فرم (اگه URL خالی بود)
            if (!checkin || !checkout) {
                // Traveler form
                const $checkinInput = $('input[name="checkin"], input[name="start"]');
                const $checkoutInput = $('input[name="checkout"], input[name="end"]');
                const $adultsInput = $('select[name="adult_number"], select[name="adults"]');
                
                if ($checkinInput.length && $checkinInput.val()) {
                    checkin = this.formatDate($checkinInput.val());
                }
                if ($checkoutInput.length && $checkoutInput.val()) {
                    checkout = this.formatDate($checkoutInput.val());
                }
                if ($adultsInput.length && $adultsInput.val()) {
                    adults = parseInt($adultsInput.val()) || 2;
                }
                
                console.log('📅 Got dates from form:', checkin, checkout);
            }
            
            return {
                checkin: checkin,
                checkout: checkout,
                adults: adults
            };
        },
        
        /**
         * فرمت کردن تاریخ به YYYY-MM-DD
         */
        formatDate: function(dateStr) {
            if (!dateStr) return '';
            
            // اگه قبلاً فرمت درست داره
            if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
                return dateStr;
            }
            
            // تبدیل فرمت‌های مختلف
            // مثل: "30/10/2025" یا "2025/10/30"
            let parts = dateStr.split(/[\/\-]/);
            
            if (parts.length === 3) {
                // اگه سال در اول باشه: 2025/10/30
                if (parts[0].length === 4) {
                    return `${parts[0]}-${parts[1].padStart(2, '0')}-${parts[2].padStart(2, '0')}`;
                }
                // اگه سال در آخر باشه: 30/10/2025
                else if (parts[2].length === 4) {
                    return `${parts[2]}-${parts[1].padStart(2, '0')}-${parts[0].padStart(2, '0')}`;
                }
            }
            
            // تلاش آخر: استفاده از Date parser
            try {
                const date = new Date(dateStr);
                if (!isNaN(date.getTime())) {
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    return `${year}-${month}-${day}`;
                }
            } catch(e) {}
            
            return '';
        },
    };

    // شروع
    RH_RoomRates.init();

    // Export
    window.RH_RoomRates = RH_RoomRates;

})(jQuery);
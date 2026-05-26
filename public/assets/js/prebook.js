/**
 * Ratehawk Prebook Handler
 * مدیریت فرآیند Prebook و تایمر 15 دقیقه‌ای
 */

(function($) {
    'use strict';
    
    let prebookTimer = null;
    let currentPrebookId = null;
    
    /**
     * Initialize
     */
    $(document).ready(function() {
        console.log('🚀 Prebook handler initialized');
        
        // Event delegation برای دکمه Book
        $(document).on('click', '.rh-book-button', function(e) {
            e.preventDefault();
            handleBookButtonClick($(this));
        });
        
        // Event delegation برای دکمه Proceed to Checkout
        $(document).on('click', '.rh-proceed-checkout', function(e) {
            e.preventDefault();
            proceedToCheckout();
        });
        
        // Event delegation برای دکمه Cancel
        $(document).on('click', '.rh-cancel-prebook', function(e) {
            e.preventDefault();
            cancelPrebook();
        });
    });
    
    /**
     * مدیریت کلیک روی دکمه Book
     */
    function handleBookButtonClick($button) {
        const bookHash = $button.data('book-hash');
        const price = parseFloat($button.data('price'));
        const currency = $button.data('currency') || 'USD';
        
        console.log('📦 Starting prebook:', {bookHash, price, currency});
        
        if (!bookHash) {
            showError('Invalid booking data');
            return;
        }
        
        // غیرفعال کردن تمام دکمه‌های Book
        $('.rh-book-button').prop('disabled', true).addClass('loading');
        $button.html('<i class="fa fa-spinner fa-spin"></i> Processing...');
        
        // شروع Prebook
        startPrebook(bookHash, price, currency);
    }
    
    /**
     * شروع Prebook
     */
    function startPrebook(bookHash, originalPrice, currency) {
        $.ajax({
            url: rhPrebook.ajaxUrl,
            type: 'POST',
            data: {
                action: 'rh_start_prebook',
                nonce: rhPrebook.nonce,
                book_hash: bookHash,
                price: originalPrice,
                currency: currency
            },
            success: function(response) {
                console.log('✅ Prebook response:', response);
                
                if (response.success) {
                    handlePrebookSuccess(response.data);
                } else {
                    handlePrebookError(response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('❌ Prebook AJAX error:', error);
                handlePrebookError({
                    message: 'Network error. Please try again.'
                });
            },
            complete: function() {
                // فعال کردن دوباره دکمه‌ها
                $('.rh-book-button').prop('disabled', false).removeClass('loading');
                $('.rh-book-button').each(function() {
                    $(this).html('<span class="rh-btn-icon">🛎️</span> Book Now');
                });
            }
        });
    }
    
    /**
     * مدیریت موفقیت Prebook
     */
    function handlePrebookSuccess(data) {
        currentPrebookId = data.prebook_id;
        
        // نمایش modal prebook
        showPrebookModal(data);
        
        // شروع تایمر
        startCountdownTimer(data.expires_at);
        
        // بررسی دوره‌ای وضعیت
        startStatusChecking(data.prebook_id);
    }
    
    /**
     * نمایش Modal Prebook
     */
    function showPrebookModal(data) {
        // پاک کردن modal قبلی اگر وجود دارد
        $('#rh-prebook-modal').remove();
        
        const priceChangeHtml = data.price_changed ? 
            generatePriceChangeAlert(data) : '';
        
        const cancellationHtml = generateCancellationInfo(data.cancellation_info);
        
        const modalHtml = `
            <div id="rh-prebook-modal" class="rh-modal">
                <div class="rh-modal-overlay"></div>
                <div class="rh-modal-content">
                    <div class="rh-modal-header">
                        <h2>🎉 Booking Reserved!</h2>
                        <button class="rh-modal-close">&times;</button>
                    </div>
                    
                    <div class="rh-modal-body">
                        ${priceChangeHtml}
                        
                        <div class="rh-booking-summary">
                            <h3>${escapeHtml(data.hotel_name)}</h3>
                            <h4>${escapeHtml(data.room_name)}</h4>
                            
                            <div class="rh-price-display">
                                <span class="rh-price-label">Total Price:</span>
                                <span class="rh-price-value">${formatPrice(data.new_price, data.currency)}</span>
                            </div>
                            
                            ${cancellationHtml}
                        </div>
                        
                        <div class="rh-countdown-section">
                            <div class="rh-countdown-label">
                                <i class="fa fa-clock"></i> Time Remaining:
                            </div>
                            <div id="rh-countdown-timer" class="rh-countdown-timer">
                                <span class="rh-countdown-minutes">15</span>:<span class="rh-countdown-seconds">00</span>
                            </div>
                            <div class="rh-countdown-warning">
                                Complete your booking before time runs out!
                            </div>
                        </div>
                    </div>
                    
                    <div class="rh-modal-footer">
                        <button class="rh-cancel-prebook rh-btn-secondary">
                            Cancel
                        </button>
                        <button class="rh-proceed-checkout rh-btn-primary">
                            <i class="fa fa-credit-card"></i> Proceed to Checkout
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        $('body').append(modalHtml);
        
        // نمایش modal با انیمیشن
        setTimeout(() => {
            $('#rh-prebook-modal').addClass('active');
        }, 10);
        
        // بستن modal با کلیک روی overlay یا دکمه close
        $('.rh-modal-overlay, .rh-modal-close').on('click', function() {
            if (confirm('Are you sure you want to cancel this booking?')) {
                cancelPrebook();
            }
        });
    }
    
    /**
     * تولید alert تغییر قیمت
     */
    function generatePriceChangeAlert(data) {
        const isIncrease = data.new_price > data.original_price;
        const changeClass = isIncrease ? 'price-increase' : 'price-decrease';
        const icon = isIncrease ? '📈' : '📉';
        const label = isIncrease ? 'Price Increased' : 'Price Decreased';
        const percentText = Math.abs(data.price_change_percent).toFixed(2) + '%';
        
        return `
            <div class="rh-price-change-alert ${changeClass}">
                <div class="rh-alert-icon">${icon}</div>
                <div class="rh-alert-content">
                    <strong>${label}</strong>
                    <p>The price has changed by ${percentText}</p>
                    <div class="rh-price-comparison">
                        <span class="rh-old-price">Was: ${formatPrice(data.original_price, data.currency)}</span>
                        <span class="rh-arrow">→</span>
                        <span class="rh-new-price">Now: ${formatPrice(data.new_price, data.currency)}</span>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * تولید اطلاعات کنسلی
     */
    function generateCancellationInfo(info) {
        if (!info) return '';
        
        if (info.free_cancellation) {
            const date = new Date(info.free_cancellation_before);
            const dateStr = date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
            
            return `
                <div class="rh-cancellation-info free">
                    <i class="fa fa-shield-alt"></i>
                    <strong>Free Cancellation</strong>
                    <p>Cancel for free until ${dateStr}</p>
                </div>
            `;
        } else {
            return `
                <div class="rh-cancellation-info non-refundable">
                    <i class="fa fa-ban"></i>
                    <strong>Non-Refundable</strong>
                    <p>This booking cannot be cancelled or refunded</p>
                </div>
            `;
        }
    }
    
    /**
     * شروع تایمر معکوس
     */
    function startCountdownTimer(expiresAt) {
        // پاک کردن تایمر قبلی
        if (prebookTimer) {
            clearInterval(prebookTimer);
        }
        
        prebookTimer = setInterval(() => {
            const now = Math.floor(Date.now() / 1000);
            const remaining = expiresAt - now;
            
            if (remaining <= 0) {
                handleTimerExpired();
                return;
            }
            
            updateTimerDisplay(remaining);
            
            // هشدار در 2 دقیقه آخر
            if (remaining <= 120 && remaining > 115) {
                showTimerWarning();
            }
            
        }, 1000);
    }
    
    /**
     * به‌روزرسانی نمایش تایمر
     */
    function updateTimerDisplay(seconds) {
        const minutes = Math.floor(seconds / 60);
        const secs = seconds % 60;
        
        $('.rh-countdown-minutes').text(String(minutes).padStart(2, '0'));
        $('.rh-countdown-seconds').text(String(secs).padStart(2, '0'));
        
        // تغییر رنگ در دقیقه آخر
        if (seconds <= 60) {
            $('#rh-countdown-timer').addClass('warning');
        }
    }
    
    /**
     * نمایش هشدار تایمر
     */
    function showTimerWarning() {
        $('.rh-countdown-warning').addClass('urgent');
        
        // نمایش notification
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('Booking Expiring Soon!', {
                body: 'You have 2 minutes left to complete your booking.',
                icon: '/path/to/icon.png'
            });
        }
    }
    
    /**
     * مدیریت انقضای تایمر
     */
    function handleTimerExpired() {
        clearInterval(prebookTimer);
        prebookTimer = null;
        
        // نمایش پیام انقضا
        $('#rh-prebook-modal .rh-modal-body').html(`
            <div class="rh-expired-message">
                <div class="rh-expired-icon">⏰</div>
                <h3>Booking Session Expired</h3>
                <p>Your booking reservation has expired. Please search again for current rates.</p>
                <button class="rh-btn-primary" onclick="location.reload()">
                    Search Again
                </button>
            </div>
        `);
        
        // مخفی کردن footer
        $('#rh-prebook-modal .rh-modal-footer').hide();
        
        currentPrebookId = null;
    }
    
    /**
     * بررسی دوره‌ای وضعیت
     */
    function startStatusChecking(prebookId) {
        const checkStatus = () => {
            if (!currentPrebookId) return;
            
            $.ajax({
                url: rhPrebook.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rh_check_prebook_status',
                    nonce: rhPrebook.nonce,
                    prebook_id: prebookId
                },
                success: function(response) {
                    if (!response.success) {
                        handleTimerExpired();
                    }
                }
            });
        };
        
        // هر 30 ثانیه چک کن
        setInterval(checkStatus, 30000);
    }
    
    /**
     * انتقال به صفحه پرداخت
     */
    function proceedToCheckout() {
        if (!currentPrebookId) {
            showError('No active booking session');
            return;
        }
        
        console.log('💳 Proceeding to checkout:', currentPrebookId);
        
        // ذخیره prebook_id در session/cookie
        sessionStorage.setItem('rh_prebook_id', currentPrebookId);
        
        // هدایت به صفحه checkout
        // این قسمت بستگی به سیستم پرداخت قالب Traveler دارد
        window.location.href = rhPrebook.checkoutUrl + '?rh_prebook=' + currentPrebookId;
    }
    
    /**
     * لغو Prebook
     */
    function cancelPrebook() {
        if (prebookTimer) {
            clearInterval(prebookTimer);
            prebookTimer = null;
        }
        
        currentPrebookId = null;
        
        $('#rh-prebook-modal').removeClass('active');
        
        setTimeout(() => {
            $('#rh-prebook-modal').remove();
        }, 300);
    }
    
    /**
     * مدیریت خطای Prebook
     */
    function handlePrebookError(data) {
        const message = data.message || 'An error occurred. Please try again.';
        
        showError(message);
    }
    
    /**
     * نمایش خطا
     */
    function showError(message) {
        const errorHtml = `
            <div class="rh-error-toast">
                <i class="fa fa-exclamation-circle"></i>
                <span>${escapeHtml(message)}</span>
            </div>
        `;
        
        $('.rh-error-toast').remove();
        $('body').append(errorHtml);
        
        setTimeout(() => {
            $('.rh-error-toast').addClass('show');
        }, 10);
        
        setTimeout(() => {
            $('.rh-error-toast').removeClass('show');
            setTimeout(() => {
                $('.rh-error-toast').remove();
            }, 300);
        }, 5000);
    }
    
    /**
     * فرمت قیمت
     */
    function formatPrice(amount, currency) {
        const symbols = {
            'USD': '$',
            'EUR': '€',
            'GBP': '£',
            'JPY': '¥'
        };
        
        const symbol = symbols[currency] || currency + ' ';
        return symbol + parseFloat(amount).toFixed(2);
    }
    
    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }
    
    // Cleanup on page unload
    $(window).on('beforeunload', function() {
        if (prebookTimer) {
            clearInterval(prebookTimer);
        }
    });
    
})(jQuery);
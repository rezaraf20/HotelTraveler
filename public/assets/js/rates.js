/* public/assets/js/rates.js */

jQuery(document).ready(function($) {
    
    'use strict';
    
    const RatesManager = {
        
        form: null,
        resultsContainer: null,
        
        init() {
            this.form = $('#rh-rates-form');
            this.resultsContainer = $('#rh-rates-results');
            
            if (!this.form.length) {
                return;
            }
            
            this.bindEvents();
            this.loadInitialRates();
        },
        
        bindEvents() {
            // Form submit
            this.form.on('submit', (e) => {
                e.preventDefault();
                this.searchRates();
            });
            
            // Children count change
            $('#rh-children-count').on('change', (e) => {
                this.updateChildrenAges(parseInt($(e.target).val()));
            });
            
            // Date validation
            $('input[name="checkin"]').on('change', (e) => {
                this.validateDates($(e.target).val());
            });
            
            // Book now button
            $(document).on('click', '.rh-book-now-btn', (e) => {
                e.preventDefault();
                this.handleBookNow($(e.target));
            });
        },
        
        validateDates(checkin) {
            const checkout = $('input[name="checkout"]');
            const minCheckout = new Date(checkin);
            minCheckout.setDate(minCheckout.getDate() + 1);
            
            const minDate = minCheckout.toISOString().split('T')[0];
            checkout.attr('min', minDate);
            
            if (checkout.val() && checkout.val() <= checkin) {
                checkout.val(minDate);
            }
        },
        
        updateChildrenAges(count) {
            const container = $('#rh-children-ages');
            const fieldsContainer = $('#rh-children-ages-fields');
            
            if (count === 0) {
                container.hide();
                fieldsContainer.empty();
                return;
            }
            
            container.show();
            fieldsContainer.empty();
            
            let html = '<div class="rh-ages-grid">';
            for (let i = 0; i < count; i++) {
                html += `
                    <div class="rh-age-field">
                        <label>Child ${i + 1} Age</label>
                        <select name="children[]" required>
                            <option value="">Select</option>
                            ${Array.from({length: 18}, (_, j) => 
                                `<option value="${j}">${j} ${j === 1 ? 'year' : 'years'}</option>`
                            ).join('')}
                        </select>
                    </div>
                `;
            }
            html += '</div>';
            
            fieldsContainer.html(html);
        },
        
        loadInitialRates() {
            // Auto-load if dates in URL
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('checkin') && urlParams.has('checkout')) {
                setTimeout(() => this.searchRates(), 500);
            }
        },
        
        searchRates() {
            const formData = this.getFormData();
            
            if (!this.validateForm(formData)) {
                return;
            }
            
            this.showLoading();
            
            $.ajax({
                url: rhRates.ajax_url,
                method: 'POST',
                data: formData,
                success: (response) => {
                    if (response.success) {
                        this.displayRates(response.data.rates);
                    } else {
                        this.displayError(response.data || rhRates.error_text);
                    }
                },
                error: () => {
                    this.displayError(rhRates.error_text);
                },
                complete: () => {
                    this.hideLoading();
                }
            });
        },
        
        getFormData() {
            return {
                action: 'rh_get_hotel_rates',
                nonce: rhRates.nonce,
                hotel_id: rhRates.hotel_id,
                checkin: this.form.find('[name="checkin"]').val(),
                checkout: this.form.find('[name="checkout"]').val(),
                adults: parseInt(this.form.find('[name="adults"]').val()),
                children: this.form.find('[name="children[]"]').map(function() {
                    return parseInt($(this).val());
                }).get()
            };
        },
        
        validateForm(data) {
            if (!data.checkin || !data.checkout) {
                alert('Please select check-in and check-out dates');
                return false;
            }
            
            if (new Date(data.checkout) <= new Date(data.checkin)) {
                alert('Check-out date must be after check-in date');
                return false;
            }
            
            return true;
        },
        
        showLoading() {
            const btn = this.form.find('.rh-search-btn');
            btn.prop('disabled', true);
            btn.find('.rh-btn-text').hide();
            btn.find('.rh-btn-loading').show();
            
            this.resultsContainer.html(`
                <div class="rh-rates-loading">
                    <i class="fas fa-spinner fa-spin"></i>
                    <div>${rhRates.loading_text}</div>
                </div>
            `);
        },
        
        hideLoading() {
            const btn = this.form.find('.rh-search-btn');
            btn.prop('disabled', false);
            btn.find('.rh-btn-text').show();
            btn.find('.rh-btn-loading').hide();
        },
        
        displayRates(rates) {
            if (!rates || rates.length === 0) {
                this.displayNoRates();
                return;
            }
            
            let html = '<div class="rh-rates-list">';
            
            rates.forEach(rate => {
                const cancelClass = rate.cancellation.type === 'free' ? 'free' : 
                                   rate.cancellation.type === 'non-refundable' ? 'non-refundable' : 
                                   'paid';
                
                html += `
                    <div class="rh-rate-item">
                        <div class="rh-rate-info">
                            <h4>${this.escapeHtml(rate.room_name)}</h4>
                            <span class="rh-rate-meal">
                                <i class="fas fa-utensils"></i> ${this.escapeHtml(rate.meal)}
                            </span>
                            <div class="rh-rate-cancellation ${cancelClass}">
                                <i class="fas fa-${rate.cancellation.icon}"></i>
                                ${this.escapeHtml(rate.cancellation.text)}
                            </div>
                        </div>
                        
                        <div class="rh-rate-price">
                            <div class="rh-rate-price-amount">${this.escapeHtml(rate.price.formatted)}</div>
                            <div class="rh-rate-price-label">Total Price</div>
                        </div>
                        
                        <div class="rh-rate-action">
                            <button class="rh-book-now-btn" data-book-hash="${this.escapeHtml(rate.book_hash)}">
                                <i class="fas fa-check"></i> Book Now
                            </button>
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            this.resultsContainer.html(html);
            
            // Smooth scroll to results
            $('html, body').animate({
                scrollTop: this.resultsContainer.offset().top - 100
            }, 500);
        },
        
        displayNoRates() {
            this.resultsContainer.html(`
                <div class="rh-no-rates">
                    <i class="fas fa-bed"></i>
                    <p>${rhRates.no_rates_text}</p>
                </div>
            `);
        },
        
        displayError(message) {
            this.resultsContainer.html(`
                <div class="rh-rates-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>${this.escapeHtml(message)}</p>
                    <button class="rh-search-btn" onclick="location.reload()">
                        Try Again
                    </button>
                </div>
            `);
        },
        
        handleBookNow(btn) {
            const bookHash = btn.data('book-hash');
            
            if (!bookHash) {
                alert('Invalid booking data');
                return;
            }
            
            // Get search parameters
            const searchParams = this.getFormData();
            searchParams.book_hash = bookHash;
            
            // Store in localStorage (not sessionStorage - that's restricted)
            try {
                localStorage.setItem('rh_booking_params', JSON.stringify(searchParams));
            } catch (e) {
                console.warn('Could not save to localStorage:', e);
            }
            
            // For now, just show alert (we'll create prebook page next)
            alert('Booking feature coming soon!\n\nBook Hash: ' + bookHash);
            
            // TODO: Redirect to prebook page
            // window.location.href = '/prebook/?book_hash=' + encodeURIComponent(bookHash);
        },
        
        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };
    
    // Initialize
    RatesManager.init();
    
});
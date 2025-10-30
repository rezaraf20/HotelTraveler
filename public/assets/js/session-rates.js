/**
 * RH Session Rates - FINAL VERSION
 * Works with class-rh-session-rates.php
 */

(function($) {
  'use strict';

  console.log('🔥 RH Session Rates v5 - FINAL');

  const RH_SessionRates = {
    sessionId: null,
    sessionData: null,
    isProcessing: false,

    init: function() {
      this.sessionId = (window.rhSession && rhSession.session_id) || null;
      $(document).ready(() => {
        setTimeout(() => this.attachCheckAvailabilityHandler(), 500);
      });
    },

    attachCheckAvailabilityHandler: function() {
      const self = this;
      
      // Override handlers
      $(document).off('click', '.btn-show-price');
      $('.btn-show-price').off('click');

      const $form = $('.form-check-availability-hotel, .booking-form').first();
      if (!$form.length) return;

      $form.off('submit').on('submit', function(e) {
        e.preventDefault(); 
        e.stopImmediatePropagation();
        self.checkAvailability($(this));
        return false;
      });

      const $btn = $form.find('button[type="submit"], input[type="submit"]').first();
      if ($btn.length) {
        $btn.off('click').on('click', function(e) {
          e.preventDefault(); 
          e.stopImmediatePropagation();
          self.checkAvailability($form);
          return false;
        });
      }
    },

    checkAvailability: function($container) {
      const self = this;
      if (this.isProcessing) return;
      this.isProcessing = true;

      const formData = this.extractFormData($container);
      if (!formData.checkin || !formData.checkout) {
        alert('Please select check-in and check-out dates');
        this.isProcessing = false;
        return;
      }

      formData.checkin = this.fixDateFormat(formData.checkin);
      formData.checkout = this.fixDateFormat(formData.checkout);

      this.showLoading();

      $.ajax({
        url: rhSession.ajax_url,
        type: 'POST',
        data: {
          action: 'rh_check_availability',
          nonce: rhSession.nonce,
          hotel_id: rhSession.hotel_id,
          checkin: formData.checkin,
          checkout: formData.checkout,
          adults: formData.adults,
          children: formData.children,
          rooms: formData.rooms
        },
        success: function(response) {
          self.hideLoading();
          self.isProcessing = false;

          console.log('🎯 Response:', response);
          
          if (response.success && response.data) {
            self.sessionId = response.data.session_id;
            self.sessionData = response.data;
            document.cookie = `rh_session_id=${self.sessionId}; path=/; max-age=900`;
            
            self.displayRates(response.data, formData);
          } else {
            alert('Error: ' + (response.data || 'Unknown error'));
          }
        },
        error: function(xhr, status, error) {
          self.hideLoading();
          self.isProcessing = false;
          console.error('AJAX Error:', error);
          alert('Error loading rates. Please try again.');
        }
      });
    },

    extractFormData: function($container) {
      const data = { checkin: '', checkout: '', adults: 2, children: 0, rooms: 1 };
      
      const $start = $container.find('input[name="start"], input[name="checkin"], input[name="check_in"], input[name="field-start"]');
      const $end   = $container.find('input[name="end"], input[name="checkout"], input[name="check_out"], input[name="field-end"]');

      if ($start.length) data.checkin = $start.val();
      if ($end.length)   data.checkout = $end.val();

      if (!data.checkin || !data.checkout) {
        const $dr = $container.find('input.daterangepicker-input, input.date-picker, input[data-daterangepicker]');
        $dr.each(function() {
          const picker = $(this).data('daterangepicker');
          if (picker) {
            data.checkin = picker.startDate.format('YYYY-MM-DD');
            data.checkout = picker.endDate.format('YYYY-MM-DD');
            return false;
          }
        });
      }

      const $adults = $container.find('input[name="adult_number"], input[name="adults"]');
      const $children = $container.find('input[name="child_number"], input[name="children"]');
      const $rooms = $container.find('input[name="room_num_search"], input[name="rooms"]');

      if ($adults.length) data.adults = parseInt($adults.val()) || 2;
      if ($children.length) data.children = parseInt($children.val()) || 0;
      if ($rooms.length) data.rooms = parseInt($rooms.val()) || 1;

      return data;
    },

    fixDateFormat: function(dateStr) {
      if (!dateStr || /^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return dateStr;
      const d = new Date(dateStr);
      if (!isNaN(d.getTime())) {
        return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
      }
      return dateStr;
    },

    displayRates: function(data, searchParams) {
      console.log('💰 Display rates');
      console.log('📦 API Rooms:', data.rooms.map(r => r.name));

      if (!data.rooms || !Array.isArray(data.rooms) || data.rooms.length === 0) {
        alert('No rates available!');
        return;
      }

      const self = this;
      let roomsDisplayed = 0;

      // Disable old handlers
      $(document).off('click', '.btn-show-price');
      $('.btn-show-price').off('click');

      // Process each room item
      $('.st-list-rooms .fetch .item.st-border-radius').each(function() {
        const $item = $(this);
        const $form = $item.find('form.form-booking-inpage');
        const roomId = $form.find('input[name="room_id"]').val();

        if (!roomId) return;

        // گرفتن اسم اتاق از DOM - selector صحیح
        const roomTitle = $item.find('a[href*="/hotel_room/"]').first().text().trim();
        console.log('🏠 WP Room:', roomTitle);

        // پیدا کردن rates مخصوص این اتاق
        let matchedRoom = null;
        
        // تلاش 1: exact match
        matchedRoom = data.rooms.find(r => {
          return r.rates && r.rates.length > 0 && 
                 r.name.toLowerCase() === roomTitle.toLowerCase();
        });
        
        // تلاش 2: partial match (contains)
        if (!matchedRoom) {
          matchedRoom = data.rooms.find(r => {
            return r.rates && r.rates.length > 0 && 
                   (r.name.toLowerCase().includes(roomTitle.toLowerCase()) ||
                    roomTitle.toLowerCase().includes(r.name.toLowerCase()));
          });
        }
        
        // تلاش 3: fuzzy match (بدون "full double bed" و غیره)
        if (!matchedRoom) {
          const cleanTitle = roomTitle.toLowerCase()
            .replace(/\s*\(.*?\)\s*/g, '') // حذف محتوای داخل پرانتز
            .replace(/full double bed/gi, '')
            .replace(/double bed/gi, '')
            .replace(/shared bathroom/gi, '')
            .trim();
            
          matchedRoom = data.rooms.find(r => {
            const cleanApiName = r.name.toLowerCase()
              .replace(/\s*\(.*?\)\s*/g, '')
              .replace(/full double bed/gi, '')
              .replace(/double bed/gi, '')
              .replace(/shared bathroom/gi, '')
              .trim();
              
            return r.rates && r.rates.length > 0 && 
                   (cleanApiName.includes(cleanTitle) || 
                    cleanTitle.includes(cleanApiName));
          });
        }
        
        // تلاش 4: fallback به اولین اتاق
        if (!matchedRoom) {
          matchedRoom = data.rooms.find(r => r.rates && r.rates.length > 0);
          console.log('⚠️ No match, using fallback');
        }
        
        if (!matchedRoom || !matchedRoom.rates || matchedRoom.rates.length === 0) {
          console.log('❌ No rates for this room');
          return;
        }

        const cheapest = matchedRoom.rates[0];
        console.log('✅ Matched:', matchedRoom.name, '→', cheapest.price.total, cheapest.price.currency);

        const $btnContainer = $item.find('.col-xs-12.col-md-4').last();
        if (!$btnContainer.length) {
          console.error('❌ Container not found');
          return;
        }

        const roomLink = $item.find('a[href*="/hotel_room/"]').first().attr('href') || '#';
        const sep = roomLink.includes('?') ? '&' : '?';
        const detailUrl = roomLink + sep +
          'session_id=' + encodeURIComponent(data.session_id) +
          '&start=' + encodeURIComponent(searchParams.checkin) +
          '&end=' + encodeURIComponent(searchParams.checkout) +
          '&room_num_search=' + searchParams.rooms +
          '&adult_number=' + searchParams.adults;

        $btnContainer.html(`
          <div class="price-wrapper">
            <span class="price">${self.formatCurrency(cheapest.price.total, cheapest.price.currency)}</span>
            <span class="unit">/night</span>
          </div>
          <a href="${detailUrl}" 
             class="show-detail btn-v2 btn-primary" 
             target="_blank">
            View Rates
          </a>
        `);

        roomsDisplayed++;
      });

      console.log('✅ Displayed:', roomsDisplayed);

      if (roomsDisplayed === 0) {
        alert('No room items found on page!');
        return;
      }

      this.addStyles();
      
      $('html, body').animate({
        scrollTop: $('.st-list-rooms').offset().top - 100
      }, 500);
      
      this.showSuccessNotice(data.rates_count, roomsDisplayed);
    },

    formatCurrency: function(amount, currency) {
      if (amount == null) return '-';
      try {
        return new Intl.NumberFormat(undefined, { 
          style: 'currency', 
          currency: currency || 'USD' 
        }).format(amount);
      } catch(e) {
        return (currency || 'USD') + ' ' + Number(amount).toFixed(2);
      }
    },

    addStyles: function() {
      if ($('#rh-rates-style').length) return;
      
      $('head').append(`
        <style id="rh-rates-style">
          .price-wrapper {
            text-align: center;
            margin-bottom: 15px;
          }
          .price-wrapper .price {
            display: block;
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            line-height: 1.2;
          }
          .price-wrapper .unit {
            display: block;
            font-size: 14px;
            color: #7f8c8d;
            margin-top: 5px;
          }
          .show-detail {
            display: block;
            text-align: center;
            padding: 12px 20px;
            margin-top: 10px;
          }
        </style>
      `);
    },

    showSuccessNotice: function(totalRates, roomsWithRates) {
      $('.rh-success-notice').remove();
      
      $('.st-list-rooms').before(`
        <div class="rh-success-notice" style="background:#d4edda;padding:15px;border-radius:6px;margin:20px 0;border-left:4px solid #28a745;text-align:center;">
          <strong style="color:#155724;font-size:16px;">✅ ${totalRates} Live Rates Loaded!</strong>
          <p style="color:#155724;margin:5px 0 0;font-size:14px;">Showing rates for ${roomsWithRates} room types</p>
        </div>
      `);
      
      setTimeout(() => {
        $('.rh-success-notice').fadeOut(500, function() {
          $(this).remove();
        });
      }, 5000);
    },

    showLoading: function() {
      if ($('.rh-loading-overlay').length) return;
      
      $('body').append(`
        <div class="rh-loading-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;
          background:rgba(0,0,0,0.85);z-index:999999;display:flex;align-items:center;justify-content:center;">
          <div style="background:white;padding:35px;border-radius:12px;text-align:center;box-shadow:0 10px 40px rgba(0,0,0,0.3);">
            <div class="spinner" style="display:inline-block;width:50px;height:50px;border:5px solid #f3f3f3;
              border-top:5px solid #667eea;border-radius:50%;animation:spin 1s linear infinite;"></div>
            <p style="margin:20px 0 0;font-size:16px;color:#333;font-weight:600;">Searching live rates...</p>
            <p style="margin:8px 0 0;font-size:13px;color:#666;">Please wait</p>
          </div>
        </div>
      `);
      
      if (!$('#rh-spinner-style').length) {
        $('head').append(`<style id="rh-spinner-style">@keyframes spin{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}</style>`);
      }
    },

    hideLoading: function() { 
      $('.rh-loading-overlay').remove(); 
    }
  };

  RH_SessionRates.init();
  window.RH_SessionRates = RH_SessionRates;

})(jQuery);
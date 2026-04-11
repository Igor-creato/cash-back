jQuery(function($) {
    'use strict';

    var data = window.cashbackClaimsData || window.cashbackAdminClaimsData || {};
    var ajaxUrl = data.ajaxUrl || ajaxurl;

    /**
     * Build pagination HTML based on current page and total pages.
     *
     * @param {number} currentPage Current active page.
     * @param {number} totalPages  Total number of pages.
     * @return {string} Pagination HTML string.
     */
    function buildPagination(currentPage, totalPages) {
        if (totalPages <= 1) {
            return '';
        }

        var range = 2;
        var edge = 2;
        var pagesSet = {};
        var i;

        // Edge pages from the start
        for (i = 1; i <= Math.min(edge, totalPages); i++) {
            pagesSet[i] = true;
        }
        // Pages around current
        for (i = Math.max(1, currentPage - range); i <= Math.min(totalPages, currentPage + range); i++) {
            pagesSet[i] = true;
        }
        // Edge pages from the end
        for (i = Math.max(1, totalPages - edge + 1); i <= totalPages; i++) {
            pagesSet[i] = true;
        }

        var pages = Object.keys(pagesSet).map(Number).sort(function (a, b) { return a - b; });

        var html = '<nav class="woocommerce-pagination"><ul class="page-numbers">';

        // Back arrow — only if not on first page
        if (currentPage > 1) {
            html += '<li><a href="#" class="page-numbers prev" data-page="' + (currentPage - 1) + '">&lsaquo;</a></li>';
        }

        var prev = 0;
        for (i = 0; i < pages.length; i++) {
            var page = pages[i];
            if (prev && page - prev > 1) {
                html += '<li><span class="page-numbers dots">&hellip;</span></li>';
            }
            var cls = (page === currentPage) ? 'current' : '';
            html += '<li><a href="#" class="page-numbers ' + cls + '" data-page="' + page + '">' + page + '</a></li>';
            prev = page;
        }

        // Forward arrow — only if not on last page
        if (currentPage < totalPages) {
            html += '<li><a href="#" class="page-numbers next" data-page="' + (currentPage + 1) + '">&rsaquo;</a></li>';
        }

        html += '</ul></nav>';
        return html;
    }

    /* ========================================
        Tab switching (matches support module)
        ======================================== */
    // Initialize tabs on page load
    function initializeTabs() {
        $('.cashback-support-tab-content').hide();
        $('.cashback-support-tab-content.active').show();
    }

    // Call on document ready
    $(document).ready(function() {
        initializeTabs();
    });

    $(document).on('click', '.cashback-support-tab', function() {
        var tab = $(this).data('tab');

        $('.cashback-support-tab').removeClass('active');
        $(this).addClass('active');

        $('.cashback-support-tab-content').hide().removeClass('active');
        $('#tab-' + tab).show().addClass('active');

        // Mark claims as read when switching to claims tab
        if (tab === 'claims' && data.markReadNonce) {
            var $badge = $('#claims-tab-unread-badge');
            if ($badge.length) {
                $.post(ajaxUrl, {
                    action: 'claims_mark_read',
                    nonce: data.markReadNonce
                }, function(res) {
                    if (res.success) {
                        $badge.remove();
                        // Remove menu badge CSS
                        $('#cashback-claims-menu-badge-style').remove();
                    }
                });
            }
        }
    });

    /* ========================================
       FRONTEND: Claim modal & submission
       ======================================== */

    // Open claim modal
    $(document).on('click', '.claim-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var clickId = $btn.data('click-id');
        var productName = $btn.data('product-name');
        var clickDate = $btn.data('click-date');
        var merchantId = $btn.data('merchant-id');

        $('#claim-product-name').text(productName);
        $('#claim-click-date').text(clickDate);
        $('#claim-click-id').val(clickId);
        $('#claim-form').hide();
        $('#claim-eligibility-msg').hide();
        $('#claim-result').hide();
        $('#claim-score-display').hide();
        $('#claim-submit-btn').prop('disabled', true);
        $('#claim-order-id').val('');
        $('#claim-order-value').val('');
        $('#claim-order-date').val('');
        $('#claim-comment').val('');
        $('#claim-modal').show();

        // Check eligibility
        $.post(ajaxUrl, {
            action: 'claims_check_eligibility',
            nonce: data.eligibilityNonce,
            click_id: clickId
        }, function(res) {
            if (res.success && res.data.eligible) {
                $('#claim-form').show();
                $('#claim-submit-btn').prop('disabled', false);
                $('#claim-form').data('merchant-id', merchantId || 0);
            } else if (res.success) {
                $('#claim-eligibility-msg').text(res.data.reasons.join(' ')).show();
            } else {
                $('#claim-eligibility-msg').text(res.data.message || 'Ошибка проверки').show();
            }
        });
    });

    // Close modal
    $(document).on('click', '.claim-modal-close, .claim-detail-close', function() {
        $(this).closest('.claim-modal, .claim-detail-modal').hide();
    });

    $(document).on('click', function(e) {
        if ($(e.target).hasClass('claim-modal') || $(e.target).hasClass('claim-detail-modal')) {
            $(e.target).hide();
        }
    });

    // Score calculation on form input change
    var scoreTimeout;
    $(document).on('input', '#claim-order-id, #claim-order-value, #claim-order-date, #claim-comment', function() {
        clearTimeout(scoreTimeout);
        scoreTimeout = setTimeout(calculateScore, 500);
    });

    function calculateScore() {
        var clickId = $('#claim-click-id').val();
        var orderDate = $('#claim-order-date').val();
        var orderValue = $('#claim-order-value').val();
        var comment = $('#claim-comment').val();
        var merchantId = $('#claim-form').data('merchant-id') || 0;

        if (!orderDate || !orderValue) return;

        $.post(ajaxUrl, {
            action: 'claims_calculate_score',
            nonce: data.eligibilityNonce,
            click_id: clickId,
            order_date: orderDate,
            order_value: orderValue,
            merchant_id: merchantId,
            comment: comment
        }, function(res) {
            if (res.success) {
                var score = res.data.score;
                var label = res.data.label;
                var cssClass = score >= 70 ? 'high' : (score >= 40 ? 'medium' : 'low');

                $('#claim-score-value').text(score + '% — ' + label);
                var $bar = $('#claim-score-bar-fill');
                $bar.css('width', score + '%').removeClass('high medium low').addClass(cssClass);
                $('#claim-score-display').show();
            }
        });
    }

    // Submit claim
    $(document).on('submit', '#claim-form', function(e) {
        e.preventDefault();
        var $btn = $('#claim-submit-btn');
        $btn.prop('disabled', true).text(data.i18n ? data.i18n.submitting : 'Отправка...');

        $.post(ajaxUrl, {
            action: 'claims_submit',
            claim_nonce: data.submitNonce,
            click_id: $('#claim-click-id').val(),
            order_id: $('#claim-order-id').val(),
            order_value: $('#claim-order-value').val(),
            order_date: $('#claim-order-date').val(),
            comment: $('#claim-comment').val()
        }, function(res) {
            var $result = $('#claim-result');
            if (res.success) {
                $result.removeClass('error').addClass('success').text(res.data.message).show();
                $('#claim-form').hide();
                $('#claim-score-display').hide();
                setTimeout(function() {
                    location.reload();
                }, 2000);
            } else {
                $result.removeClass('success').addClass('error').text(res.data.message || 'Ошибка').show();
                $btn.prop('disabled', false).text(data.i18n && data.i18n.submit ? data.i18n.submit : 'Отправить заявку');
            }
        });
    });

    /* ========================================
        Clicks filters + AJAX Pagination
        ======================================== */

    function getClicksFilters() {
        return {
            date_from: $('#clicks-date-from').val() || '',
            date_to: $('#clicks-date-to').val() || '',
            search: $('#clicks-search').val() || '',
            can_claim: $('#clicks-can-claim').val() || ''
        };
    }

    $(document).on('click', '#clicks-filter-apply', function() {
        loadClicks(1);
    });

    $(document).on('click', '#clicks-filter-reset', function() {
        $('#clicks-date-from').val('');
        $('#clicks-date-to').val('');
        $('#clicks-search').val('');
        $('#clicks-can-claim').val('');
        loadClicks(1);
    });

    // Search on Enter key
    $(document).on('keypress', '#clicks-search', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            loadClicks(1);
        }
    });

    $(document).on('click', '#clicks-pagination .page-numbers[data-page]', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        loadClicks(page);
    });

    function loadClicks(page) {
        var filters = getClicksFilters();
        var postData = {
            action: 'claims_load_clicks',
            nonce: data.loadNonce,
            page: page,
            date_from: filters.date_from,
            date_to: filters.date_to,
            search: filters.search,
            can_claim: filters.can_claim
        };

        $.post(ajaxUrl, postData, function(res) {
            if (res.success) {
                $('#clicks-table-container').html(res.data.html);
                $('#clicks-pagination').html(buildPagination(page, res.data.pages));
            }
        });
    }

    /* ========================================
        Claims filters + AJAX Pagination
        ======================================== */

    function getClaimsFilters() {
        return {
            date_from: $('#claims-date-from').val() || '',
            date_to: $('#claims-date-to').val() || '',
            search: $('#claims-search').val() || '',
            status: $('#claims-status-filter').val() || ''
        };
    }

    $(document).on('click', '#claims-filter-apply', function() {
        loadClaims(1);
    });

    $(document).on('click', '#claims-filter-reset', function() {
        $('#claims-date-from').val('');
        $('#claims-date-to').val('');
        $('#claims-search').val('');
        $('#claims-status-filter').val('');
        loadClaims(1);
    });

    $(document).on('keypress', '#claims-search', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            loadClaims(1);
        }
    });

    window.CashbackClaims = {
        filterClaims: function() {
            loadClaims(1);
        }
    };

    $(document).on('click', '#claims-pagination .page-numbers[data-page]', function(e) {
        e.preventDefault();
        var page = $(this).data('page');
        loadClaims(page);
    });

    // Toggle claim events row on click
    $(document).on('click', '.claim-row.has-events', function() {
        var claimId = $(this).data('claim-id');
        var $eventsRow = $('#claim-events-' + claimId);
        $eventsRow.toggle();
        $(this).toggleClass('events-open');
    });

    function loadClaims(page) {
        var filters = getClaimsFilters();

        $.post(ajaxUrl, {
            action: 'claims_load_claims',
            nonce: data.loadNonce,
            page: page,
            status: filters.status,
            date_from: filters.date_from,
            date_to: filters.date_to,
            search: filters.search
        }, function(res) {
            if (res.success) {
                $('#claims-table-container').html(res.data.html);
                $('#claims-pagination').html(buildPagination(page, res.data.pages));
            }
        });
    }



    /* ========================================
       ADMIN: Claims management
       ======================================== */

    // View claim detail
    $(document).on('click', '.claims-view-btn', function() {
        var claimId = $(this).data('claim-id');

        $.post(ajaxUrl, {
            action: 'claims_admin_get_detail',
            nonce: data.detailNonce,
            claim_id: claimId
        }, function(res) {
            if (res.success) {
                $('#claim-detail-body').html(res.data.html);
                $('#claim-detail-modal').show();
            }
        });
    });

    // Inline notification helper
    function showNotice(message, type) {
        type = type || 'success';
        var icon = type === 'success' ? 'yes' : 'warning';
        var $notice = $(
            '<div class="claims-notice claims-notice--' + type + '">' +
                '<span class="dashicons dashicons-' + icon + '"></span> ' +
                '<span>' + $('<span>').text(message).html() + '</span>' +
                '<button type="button" class="claims-notice-dismiss">&times;</button>' +
            '</div>'
        );

        // Place inside modal if open, otherwise at top of wrap
        var $container = $('#claim-detail-body');
        if ($container.is(':visible') && $container.length) {
            $container.find('.claims-notice').remove();
            $container.prepend($notice);
        } else {
            $('.cashback-claims-admin').find('.claims-notice').remove();
            $('.cashback-claims-admin .wp-heading-inline').after($notice);
        }

        // Auto-close after 4 seconds
        setTimeout(function() {
            $notice.fadeOut(300, function() { $(this).remove(); });
        }, 4000);
    }

    // Dismiss notice manually
    $(document).on('click', '.claims-notice-dismiss', function() {
        $(this).closest('.claims-notice').fadeOut(200, function() { $(this).remove(); });
    });

    // Confirm dialog helper (replaces native confirm)
    function showConfirm(message, onConfirm) {
        var $overlay = $(
            '<div class="claims-confirm-overlay">' +
                '<div class="claims-confirm-box">' +
                    '<p>' + $('<span>').text(message).html() + '</p>' +
                    '<div class="claims-confirm-buttons">' +
                        '<button class="button button-primary claims-confirm-yes">Да</button>' +
                        '<button class="button claims-confirm-no">Отмена</button>' +
                    '</div>' +
                '</div>' +
            '</div>'
        );
        $('body').append($overlay);

        $overlay.on('click', '.claims-confirm-yes', function() {
            $overlay.remove();
            onConfirm();
        });
        $overlay.on('click', '.claims-confirm-no', function() {
            $overlay.remove();
        });
        $overlay.on('click', function(e) {
            if ($(e.target).hasClass('claims-confirm-overlay')) {
                $overlay.remove();
            }
        });
    }

    // Status transition
    $(document).on('click', '.claims-action-btn', function() {
        var $btn = $(this);
        var action = $btn.data('action');
        var claimId = $btn.data('claim-id') || $btn.closest('.claim-actions').data('claim-id');

        if (!claimId) {
            showNotice('Ошибка: не удалось определить ID заявки', 'error');
            return;
        }

        showConfirm('Вы уверены?', function() {
            $.post(ajaxUrl, {
                action: 'claims_admin_transition',
                nonce: data.transitionNonce,
                claim_id: claimId,
                new_status: action,
                note: $('#claim-note-text').val() || ''
            }, function(res) {
                if (res.success) {
                    showNotice(res.data.message, 'success');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    showNotice(res.data.message || 'Ошибка', 'error');
                }
            });
        });
    });

    // Add note
    $(document).on('click', '.claims-note-btn', function() {
        var claimId = $(this).data('claim-id');
        var note = $('#claim-note-text').val();

        if (!note.trim()) return;

        $.post(ajaxUrl, {
            action: 'claims_admin_add_note',
            nonce: data.noteNonce,
            claim_id: claimId,
            note: note
        }, function(res) {
            if (res.success) {
                showNotice(res.data.message, 'success');
                $('#claim-note-text').val('');
                $.post(ajaxUrl, {
                    action: 'claims_admin_get_detail',
                    nonce: data.detailNonce,
                    claim_id: claimId
                }, function(r) {
                    if (r.success) {
                        $('#claim-detail-body').html(r.data.html);
                    }
                });
            } else {
                showNotice(res.data.message || 'Ошибка', 'error');
            }
        });
    });

    // Auto-refresh stats
    function refreshStats() {
        if (!data.statsNonce) return;
        $.post(ajaxUrl, {
            action: 'claims_admin_stats',
            nonce: data.statsNonce
        }, function(res) {
            if (res.success) {
                // Stats are server-rendered
            }
        });
    }

    if (data.statsNonce) {
        setInterval(refreshStats, 60000);
    }
});

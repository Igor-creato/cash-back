(function ($) {
    'use strict';

    $(document).ready(function () {
        const $form = $('.cashback-filters-form');

        $form.on('submit', function (e) {
            const hasValues = $form.find('select[name="rate_type"]').val() !== '' ||
                $form.find('input[name="date_from"]').val() !== '' ||
                $form.find('input[name="date_to"]').val() !== '' ||
                $form.find('input[name="filter_rate"]').val() !== '' ||
                $form.find('input[name="filter_user"]').val() !== '';

            if (!hasValues) {
                e.preventDefault();
                window.location.href = window.location.pathname + '?page=cashback-rate-history';
                return false;
            }
        });

        $('.cashback-filter-actions .button:not(.button-primary)').on('click', function (e) {
            e.preventDefault();
            window.location.href = window.location.pathname + '?page=cashback-rate-history';
        });
    });
})(jQuery);

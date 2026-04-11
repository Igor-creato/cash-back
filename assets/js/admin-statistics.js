(function ($) {
  'use strict';

  $(document).ready(function () {
    $('#stats-filter-submit').on('click', function () {
      var dateFrom = $('#filter-date-from').val();
      var dateTo = $('#filter-date-to').val();
      var url = new URL(window.location.href);

      if (dateFrom) {
        url.searchParams.set('date_from', dateFrom);
      } else {
        url.searchParams.delete('date_from');
      }

      if (dateTo) {
        url.searchParams.set('date_to', dateTo);
      } else {
        url.searchParams.delete('date_to');
      }

      window.location.href = url.toString();
    });

    $('#stats-filter-reset').on('click', function () {
      var url = new URL(window.location.href);
      url.searchParams.delete('date_from');
      url.searchParams.delete('date_to');
      window.location.href = url.toString();
    });
  });
})(jQuery);

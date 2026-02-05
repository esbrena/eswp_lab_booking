(function ($) {
  $(function () {
    var $start = $('.cie-lab-booking input.cie-date[name="start_date"]');
    var $end = $('.cie-lab-booking input.cie-date[name="end_date"]');

    function setup($el, onSelect) {
      if (!$el.length || !$el.datepicker) return;
      $el.datepicker({
        dateFormat: 'yy-mm-dd',
        numberOfMonths: 3,
        onSelect: onSelect,
      });
    }

    setup($start, function (dateText) {
      if ($end.length) {
        $end.datepicker('option', 'minDate', dateText);
      }
    });
    setup($end, function (dateText) {
      if ($start.length) {
        $start.datepicker('option', 'maxDate', dateText);
      }
    });
  });
})(jQuery);


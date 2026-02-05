(function ($) {
  $(function () {
    var $dates = $('input.cie-date');
    if ($dates.length && $dates.datepicker) {
      $dates.datepicker({
        dateFormat: 'yy-mm-dd',
        numberOfMonths: 2,
      });
    }
  });
})(jQuery);


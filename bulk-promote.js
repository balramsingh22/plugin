jQuery(document).ready(function ($) {
  // Append the button to the action wrap
  var buttonHtml = '<div class="rtcl-bulk-promote-wrap" style="display:inline-block; margin-left: 10px;">' +
    '<button id="rtcl-bulk-promote-btn" class="btn btn-primary">' +
    'Bulk Promote All (Top & Bump Up)' +
    '</button>' +
    '</div>';

  $('.rtcl-action-wrap').append(buttonHtml);

  $('#rtcl-bulk-promote-btn').on('click', function (e) {
    e.preventDefault();

    if (!confirm(rtcl_bulk_promote_vars.confirm_msg)) {
      return;
    }

    var $btn = $(this);
    $btn.prop('disabled', true).text('Processing...');

    $.ajax({
      url: rtcl_bulk_promote_vars.ajax_url,
      type: 'POST',
      data: {
        action: 'rtcl_bulk_promote_listings',
        nonce: rtcl_bulk_promote_vars.nonce
      },
      success: function (response) {
        if (response.success) {
          alert(response.data);
          location.reload();
        } else {
          alert('Error: ' + response.data);
        }
      },
      error: function () {
        alert('An error occurred. Please try again.');
      },
      complete: function () {
        $btn.prop('disabled', false).text('Bulk Promote All (Top & Bump Up)');
      }
    });
  });
});

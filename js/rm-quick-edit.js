(function ($) {
    $(document).ready(function () {
        // Hook into the Quick Edit button click
        $('body').on('click', '.editinline', function () {
            // Get the post ID from the row's ID
            var postId = $(this).closest('tr').attr('id').replace('post-', '');

            // Get the live status value from #inline_{postId}
            var liveStatus = $('#custom_inline_' + postId).find('.rm_live_status').text();

            // Set the value of the dropdown in the Quick Edit form
            $('select[name="rm_quick_edit_live_status"]').val(liveStatus);
        });
    });
})(jQuery);

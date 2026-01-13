jQuery(document).ready(function($) {
    $('.copy-shortcode').on('click', function() {
        var shortcode = $(this).closest('td').find('.shortcode-text').text();
        if (!shortcode) {
            return;
        }
        navigator.clipboard.writeText(shortcode).then(function() {
            alert('Shortcode copied to clipboard!');
        }, function(err) {
            console.error('Could not copy text: ', err);
        });
    });
});


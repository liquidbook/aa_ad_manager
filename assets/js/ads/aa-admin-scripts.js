jQuery(document).ready(function($) {
    var $modal = $('#aa-admin-modal');
    var $modalTitle = $('#aa-admin-modal-title');
    var $modalBody = $('#aa-admin-modal-body');
    var lastFocusedEl = null;

    function getFocusable($root) {
        return $root
            .find('a, button, input, select, textarea, [tabindex]:not([tabindex="-1"])')
            .filter(':visible')
            .filter(function() {
                return !this.disabled;
            });
    }

    function openModal(options) {
        if (!$modal.length) {
            return;
        }

        lastFocusedEl = document.activeElement;

        $modalTitle.text(options.title || '');
        $modalBody.empty();
        if (typeof options.buildBody === 'function') {
            options.buildBody($modalBody);
        }

        $modal.attr('aria-hidden', 'false').addClass('is-open');

        // Focus close button.
        var $closeBtn = $modal.find('[data-aa-modal-close]').first();
        if ($closeBtn.length) {
            $closeBtn.trigger('focus');
        }
    }

    function closeModal() {
        if (!$modal.length) {
            return;
        }
        $modal.attr('aria-hidden', 'true').removeClass('is-open');
        $modalTitle.text('');
        $modalBody.empty();

        if (lastFocusedEl && typeof lastFocusedEl.focus === 'function') {
            lastFocusedEl.focus();
        }
        lastFocusedEl = null;
    }

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

    // Copy target URL from the Statistics thickbox popup.
    $(document).on('click', '.aa-copy-ad-link', function() {
        var url = $(this).data('url') || '';
        if (!url) {
            return;
        }

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(url).then(function() {
                alert('Link copied to clipboard!');
            }, function(err) {
                console.error('Could not copy link: ', err);
                window.prompt('Copy link:', url);
            });
            return;
        }

        window.prompt('Copy link:', url);
    });

    // Open target link modal.
    $(document).on('click', '.aa-open-ad-link-modal', function() {
        var url = $(this).data('url') || '';
        if (!url) {
            return;
        }

        openModal({
            title: 'Target URL',
            buildBody: function($body) {
                var $p = $('<p />').addClass('aa-admin-modal__url');
                var $a = $('<a />')
                    .attr('href', url)
                    .attr('target', '_blank')
                    .attr('rel', 'noopener noreferrer')
                    .text(url);
                $p.append($a);

                var $btnRow = $('<p />').addClass('aa-admin-modal__actions');
                var $copyBtn = $('<button />', {
                    type: 'button',
                    class: 'button button-small aa-copy-ad-link'
                }).data('url', url).text('Copy');
                $btnRow.append($copyBtn);

                $body.append($p, $btnRow);
            }
        });
    });

    // Open image preview modal (image capped at 300px via CSS).
    $(document).on('click', '.aa-open-ad-image-modal', function() {
        var imageUrl = $(this).data('image-url') || '';
        var alt = $(this).data('alt') || 'Ad preview';
        if (!imageUrl) {
            return;
        }

        openModal({
            title: 'Ad preview',
            buildBody: function($body) {
                var $imgWrap = $('<div />').addClass('aa-admin-modal__image-wrap');
                var $img = $('<img />')
                    .attr('src', imageUrl)
                    .attr('alt', alt)
                    .addClass('aa-admin-modal__image');
                $imgWrap.append($img);
                $body.append($imgWrap);
            }
        });
    });

    // Close modal interactions.
    $(document).on('click', '[data-aa-modal-close]', function() {
        closeModal();
    });

    // Focus trap + ESC close.
    $(document).on('keydown', function(e) {
        if (!$modal.length || !$modal.hasClass('is-open')) {
            return;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            closeModal();
            return;
        }

        if (e.key !== 'Tab') {
            return;
        }

        var $focusables = getFocusable($modal);
        if (!$focusables.length) {
            return;
        }

        var first = $focusables.get(0);
        var last = $focusables.get($focusables.length - 1);

        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });
});


jQuery(document).ready(function($) {
    var $modal = $('#aa-admin-modal');
    var $modalTitle = $('#aa-admin-modal-title');
    var $modalBody = $('#aa-admin-modal-body');
    var lastFocusedEl = null;

    // Placement edit helper: auto-fill placement_key from title.
    // Behavior:
    // - Generate/sanitize on leaving the title field (blur/change), not per-keystroke.
    // - Keep auto-updating as long as the key is still auto-generated.
    // - Stop auto-updating once the user edits the key manually.
    // - If the user clears the key, it becomes eligible for auto-generation again.
    // Works with ACF fields by targeting the field wrapper's data-name attribute.
    function initPlacementKeyAutofill() {
        var $acfKeyField = $('.acf-field[data-name="placement_key"] input');
        if (!$acfKeyField.length) {
            return;
        }

        var $title = $('#title');
        if (!$title.length) {
            return;
        }

        function slugify(str) {
            return String(str || '')
                .trim()
                .toLowerCase()
                .replace(/['"]/g, '')
                .replace(/[^a-z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '');
        }

        var $acfKeyFieldWrap = $acfKeyField.closest('.acf-field');
        var $status = $acfKeyFieldWrap.find('.aa-placement-key-status');
        if (!$status.length) {
            $status = $('<p />').addClass('description aa-placement-key-status').css({ marginTop: '6px' });
            $acfKeyFieldWrap.append($status);
        }

        function setStatus(msg) {
            if (!msg) {
                $status.text('').hide();
                return;
            }
            $status.text(msg).show();
        }

        function checkAvailability(key) {
            if (!window.aaAdminSettings || !aaAdminSettings.ajaxUrl || !aaAdminSettings.nonce) {
                // No AJAX settings; fall back to server-side validation on save.
                return $.Deferred().resolve({ available: true, skipped: true }).promise();
            }

            var postId = parseInt($('#post_ID').val(), 10) || 0;
            return $.ajax({
                url: aaAdminSettings.ajaxUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    action: 'aa_validate_placement_key',
                    placement_key: key,
                    post_id: postId,
                    security: aaAdminSettings.nonce
                }
            }).then(function(resp) {
                if (resp && resp.success && resp.data && typeof resp.data.available === 'boolean') {
                    return { available: resp.data.available };
                }
                return { available: true, skipped: true };
            }, function() {
                return { available: true, skipped: true };
            });
        }

        var AUTO_FLAG = 'aaAutofilled';

        function setKeyFromTitle() {
            var titleVal = String($title.val() || '').trim();
            if (!titleVal) {
                return;
            }

            var currentKey = String($acfKeyField.val() || '').trim();
            var isAuto = $acfKeyField.data(AUTO_FLAG) === true;

            // Only generate if empty OR still auto-managed.
            if (currentKey !== '' && !isAuto) {
                return;
            }

            var next = slugify(titleVal);
            if (!next) {
                return;
            }

            if (currentKey !== next) {
                $acfKeyField.val(next).trigger('change');
            }
            $acfKeyField.data(AUTO_FLAG, true);

            // If the key was auto-generated, ensure it's unique by appending _2, _3, ...
            setStatus('Checking key availability...');
            ensureUniqueKey(next);
        }

        var lastCheckToken = 0;
        function ensureUniqueKey(baseKey) {
            var token = ++lastCheckToken;
            var i = 0;
            var MAX = 50;

            function tryNext() {
                if (token !== lastCheckToken) {
                    return;
                }

                var candidate = i === 0 ? baseKey : (baseKey + '_' + (i + 1));
                checkAvailability(candidate).then(function(res) {
                    if (token !== lastCheckToken) {
                        return;
                    }

                    if (res && res.available) {
                        if (String($acfKeyField.val() || '').trim() !== candidate) {
                            $acfKeyField.val(candidate).trigger('change');
                        }
                        $acfKeyField.data(AUTO_FLAG, true);
                        setStatus('');
                        return;
                    }

                    i++;
                    if (i >= MAX) {
                        setStatus('This key appears to be taken. Please edit it to a unique value.');
                        return;
                    }
                    tryNext();
                });
            }

            tryNext();
        }

        function sanitizeKeyField() {
            var raw = String($acfKeyField.val() || '');
            var cleaned = slugify(raw);
            if (raw !== cleaned) {
                $acfKeyField.val(cleaned).trigger('change');
            }
        }

        // Generate on leaving the title field.
        $title.on('blur change', function() {
            setKeyFromTitle();
        });

        // If the user types in the key field, treat it as manual unless it's being cleared.
        $acfKeyField.on('input', function() {
            var v = String($acfKeyField.val() || '').trim();
            if (v === '') {
                // Eligible for re-auto-fill next time title is blurred/changed.
                $acfKeyField.data(AUTO_FLAG, true);
                return;
            }
            // Any non-empty typing in the key marks it as user-managed.
            $acfKeyField.data(AUTO_FLAG, false);
        });

        // Always sanitize key on blur.
        $acfKeyField.on('blur', function() {
            sanitizeKeyField();

            // For manually-entered keys, do a best-effort availability check and show a note.
            var currentKey = String($acfKeyField.val() || '').trim();
            if (!currentKey) {
                setStatus('');
                return;
            }
            var isAuto = $acfKeyField.data(AUTO_FLAG) === true;
            if (isAuto) {
                // Auto keys are handled by ensureUniqueKey().
                return;
            }
            setStatus('Checking key availability...');
            checkAvailability(currentKey).then(function(res) {
                if (res && res.available) {
                    setStatus('');
                    return;
                }
                setStatus('That key is already in use. Please choose a unique key.');
            });
        });
    }

    initPlacementKeyAutofill();

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
            // Placement edit screen meta box (not inside a td).
            shortcode = $(this).closest('.inside').find('.shortcode-text').text();
        }
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


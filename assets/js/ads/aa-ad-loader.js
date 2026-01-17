jQuery(document).ready(function($) {
    $('.aa-ad-container').each(function() {
        var container = $(this);

        // Retrieve parameters from data attributes
        var adSize = container.data('ad-size') || 'wide';
        var campaign = container.data('campaign') || '';
        var placementKey = container.data('placement-key') || '';

        // Prefer localized URL; fall back to container data-ajax-url for compatibility/diagnostics.
        var ajaxUrl = (window.aaAdSettings && aaAdSettings.ajax_url) ? aaAdSettings.ajax_url : container.data('ajax-url');
        var pageId = container.data('page-id') || 0;
        var pageType = container.data('page-type') || '';
        var pageContext = container.data('page-context') || '';

        if (!ajaxUrl) {
            console.warn('No AJAX URL provided for container:', container.attr('id'));
            container.html('');
            return;
        }

        if (!window.aaAdSettings || !aaAdSettings.nonce_get_ad) {
            console.warn('aaAdSettings missing/nonces not set; cannot load ad for container:', container.attr('id'));
            container.html('');
            return;
        }

        $.ajax({
            url: ajaxUrl,
            type: 'GET',
            data: {
                action: 'aa_get_ad',
                ad_size: adSize,
                campaign: campaign,
                placement_key: placementKey,
                page_id: pageId,
                page_type: pageType,
                page_context: pageContext,
                security: aaAdSettings.nonce_get_ad
            },
            success: function(response) {
                if (response && response.success && response.data && response.data.ad_html) {
                    container.html(response.data.ad_html);

                    container.find('.aa-ad-click').on('click', function(e) {
                        e.preventDefault();

                        var adId = $(this).data('ad-id');
                        var clickPageId = $(this).data('page-id') || pageId;
                        var clickPlacementKey = $(this).data('placement-key') || placementKey || '';
                        var refererUrl = document.referrer || '';
                        var redirectUrl = $(this).attr('href');

                        if (!aaAdSettings.nonce_log_click) {
                            window.location.href = redirectUrl;
                            return;
                        }

                        $.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'aa_log_click',
                                ad_id: adId,
                                page_id: clickPageId,
                                placement_key: clickPlacementKey,
                                referer_url: refererUrl,
                                page_type: pageType,
                                page_context: pageContext,
                                security: aaAdSettings.nonce_log_click
                            },
                            complete: function() {
                                // Redirect even if logging fails.
                                window.location.href = redirectUrl;
                            }
                        });
                    });
                } else {
                    console.warn('Ad not found or failed to load for container:', container.attr('id'));
                    container.html('');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error('AJAX request failed for container:', container.attr('id'), 'Status:', textStatus, 'Error:', errorThrown);
                container.html('');
            }
        });
    });
});


jQuery(document).ready(function($) {
    function aaEnsureDataLayer() {
        if (!window.dataLayer) {
            window.dataLayer = [];
        }
        return window.dataLayer;
    }

    function aaIsOutboundUrl(url) {
        try {
            var u = new URL(url, window.location.href);
            return u.origin !== window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function aaExtractAdPayload(container, $adLink) {
        var adId = $adLink && $adLink.data('ad-id') ? $adLink.data('ad-id') : null;
        var pageId = $adLink && $adLink.data('page-id') ? $adLink.data('page-id') : (container.data('page-id') || 0);
        var placementKey = $adLink && $adLink.data('placement-key') ? $adLink.data('placement-key') : (container.data('placement-key') || '');
        var href = $adLink && $adLink.attr('href') ? $adLink.attr('href') : '';

        // Optional creative URL for image-based ads.
        var imgSrc = '';
        try {
            var img = $adLink && $adLink.length ? $adLink.find('img').get(0) : null;
            imgSrc = img && img.getAttribute('src') ? img.getAttribute('src') : '';
        } catch (e) {
            imgSrc = '';
        }

        return {
            ad_id: adId,
            page_id: pageId,
            placement_key: placementKey,
            destination_url: href,
            is_outbound: href ? aaIsOutboundUrl(href) : false,
            creative_url: imgSrc,
            ad_size: container.data('ad-size') || '',
            page_type: container.data('page-type') || '',
            page_context: container.data('page-context') || ''
        };
    }

    function aaPushAnalyticsEvent(eventName, payload) {
        // Always push to dataLayer for GTM-based sites (safe no-op elsewhere).
        aaEnsureDataLayer().push($.extend({ event: eventName }, payload || {}));

        // Also emit to GA4 directly if gtag exists (sites without GTM).
        if (typeof window.gtag === 'function') {
            try {
                window.gtag('event', eventName, $.extend(
                    {
                        transport_type: 'beacon'
                    },
                    payload || {}
                ));
            } catch (e) {
                // Swallow errors to avoid breaking ad clicks.
            }
        }
    }

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

                    // Impression analytics: after injection, if we have ad metadata.
                    if (!container.data('aa-impression-sent')) {
                        var $adLinkForImpression = container.find('.aa-ad-click').first();
                        if ($adLinkForImpression.length) {
                            aaPushAnalyticsEvent('aa_ad_impression', aaExtractAdPayload(container, $adLinkForImpression));
                            container.data('aa-impression-sent', true);
                        }
                    }

                    container.find('.aa-ad-click').on('click', function(e) {
                        e.preventDefault();

                        var $adLink = $(this);
                        var adId = $adLink.data('ad-id');
                        var clickPageId = $(this).data('page-id') || pageId;
                        var clickPlacementKey = $(this).data('placement-key') || placementKey || '';
                        var refererUrl = document.referrer || '';
                        var redirectUrl = $adLink.attr('href');

                        // Click analytics: fire immediately (esp. outbound).
                        aaPushAnalyticsEvent('aa_ad_click', aaExtractAdPayload(container, $adLink));

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


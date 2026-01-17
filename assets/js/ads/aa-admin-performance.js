jQuery(function ($) {
  if (typeof window.aaAdPerfSettings === 'undefined') {
    return;
  }

  var settings = window.aaAdPerfSettings || {};
  var $root = $('.aa-perf').first();
  if (!$root.length) {
    return;
  }

  var adId = parseInt($root.data('ad-id'), 10) || parseInt(settings.adId, 10) || 0;
  var $range = $('#aa-perf-range');
  var $placement = $('#aa-perf-placement');
  var $metric = $('#aa-perf-top-metric');

  var $status = $('#aa-perf-status');
  var $totalImps = $('#aa-perf-total-impressions');
  var $totalClicks = $('#aa-perf-total-clicks');
  var $avgCtr = $('#aa-perf-avg-ctr');
  var $topPages = $('#aa-perf-top-pages');
  var $topPage = $('#aa-perf-top-page');
  var $topCtrPage = $('#aa-perf-top-ctr-page');
  var $breakdown = $('#aa-perf-placement-breakdown');

  function setStatus(msg) {
    $status.text(msg || '');
  }

  function fmtNumber(n) {
    try {
      return new Intl.NumberFormat().format(n);
    } catch (e) {
      return String(n);
    }
  }

  function fmtPct(p) {
    if (p === null || typeof p === 'undefined') {
      return '—';
    }
    var v = Number(p);
    if (!isFinite(v)) {
      return '—';
    }
    return v.toFixed(1) + '%';
  }

  function fmtDateLabel(ymd) {
    // ymd: YYYY-MM-DD
    try {
      var d = new Date(ymd + 'T00:00:00');
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    } catch (e) {
      return ymd;
    }
  }

  var icChart = null;
  var ctrChart = null;

  function buildCharts(payload) {
    if (!window.Chart) {
      setStatus('Chart library not loaded.');
      return;
    }

    var labels = (payload.series && payload.series.labels) ? payload.series.labels : [];
    var labelDisplay = labels.map(fmtDateLabel);
    var impressions = (payload.series && payload.series.impressions) ? payload.series.impressions : [];
    var clicks = (payload.series && payload.series.clicks) ? payload.series.clicks : [];
    var ctr = (payload.series && payload.series.ctr) ? payload.series.ctr : [];

    var icCtx = document.getElementById('aa-perf-ic-chart');
    var ctrCtx = document.getElementById('aa-perf-ctr-chart');

    if (icChart) {
      icChart.destroy();
    }
    if (ctrChart) {
      ctrChart.destroy();
    }

    icChart = new window.Chart(icCtx, {
      type: 'line',
      data: {
        labels: labelDisplay,
        datasets: [
          {
            label: 'Impressions',
            data: impressions,
            borderColor: '#2271b1',
            backgroundColor: 'rgba(34,113,177,0.10)',
            tension: 0.25,
            fill: true,
            pointRadius: 2
          },
          {
            label: 'Clicks',
            data: clicks,
            borderColor: '#00a32a',
            backgroundColor: 'rgba(0,163,42,0.10)',
            tension: 0.25,
            fill: true,
            pointRadius: 2
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { mode: 'index', intersect: false }
        },
        interaction: { mode: 'index', intersect: false },
        scales: {
          y: { beginAtZero: true }
        }
      }
    });

    ctrChart = new window.Chart(ctrCtx, {
      type: 'line',
      data: {
        labels: labelDisplay,
        datasets: [
          {
            label: 'CTR',
            data: ctr,
            borderColor: '#00a32a',
            backgroundColor: 'rgba(0,163,42,0.10)',
            tension: 0.25,
            fill: true,
            pointRadius: 2
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: function (ctx) {
                return 'CTR: ' + fmtPct(ctx.parsed.y);
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            ticks: {
              callback: function (value) {
                return value + '%';
              }
            }
          }
        }
      }
    });
  }

  function renderTopList(items, metric) {
    var copy = (items || []).slice();

    if (metric === 'ctr') {
      copy.sort(function (a, b) {
        var ac = (a && a.ctr !== null && typeof a.ctr !== 'undefined') ? Number(a.ctr) : -1;
        var bc = (b && b.ctr !== null && typeof b.ctr !== 'undefined') ? Number(b.ctr) : -1;
        return bc - ac;
      });
    } else {
      copy.sort(function (a, b) {
        return (Number(b.clicks) || 0) - (Number(a.clicks) || 0);
      });
    }

    var display = copy.slice(0, 5);
    $topPages.empty();
    display.forEach(function (it) {
      var label = it.label || 'Unknown';
      var url = it.url || '';
      var clicks = Number(it.clicks) || 0;
      var ctrTxt = fmtPct(it.ctr);

      var $li = $('<li />');
      var $left = $('<span />');
      if (url) {
        $left.append($('<a />').attr('href', url).attr('target', '_blank').attr('rel', 'noopener noreferrer').text(label));
      } else {
        $left.text(label);
      }
      var $right = $('<span />').text(fmtNumber(clicks) + ' - ' + ctrTxt);
      $li.append($left, $right);
      $topPages.append($li);
    });
  }

  function renderPlacementBreakdown(rows) {
    if (!$breakdown.length) {
      return;
    }

    var list = (rows || []).slice();
    $breakdown.empty();

    if (!list.length) {
      $breakdown.append($('<p />').addClass('description').text('No placement activity in selected range.'));
      return;
    }

    var $table = $('<table />').addClass('widefat striped aa-perf__breakdown-table');
    var $thead = $('<thead />').append(
      $('<tr />').append(
        $('<th />').text('Placement'),
        $('<th />').addClass('column-number').text('Impressions'),
        $('<th />').addClass('column-number').text('Clicks'),
        $('<th />').addClass('column-number').text('CTR')
      )
    );
    var $tbody = $('<tbody />');

    list.forEach(function (row) {
      var key = row.placement_key || '';
      var name = row.placement_title || key || 'Unknown';
      var imps = Number(row.impressions) || 0;
      var clicks = Number(row.clicks) || 0;
      var ctr = (row.ctr === null || typeof row.ctr === 'undefined') ? null : Number(row.ctr);

      var $nameCell = $('<td />');
      if (key && $placement && $placement.length) {
        var $a = $('<a />')
          .attr('href', '#')
          .text(name)
          .on('click', function (e) {
            e.preventDefault();
            $placement.val(key).trigger('change');
          });
        $nameCell.append($a);
      } else {
        $nameCell.text(name);
      }
      if (key) {
        $nameCell.append($('<div />').addClass('description').text(key));
      }

      $tbody.append(
        $('<tr />').append(
          $nameCell,
          $('<td />').addClass('column-number').text(fmtNumber(imps)),
          $('<td />').addClass('column-number').text(fmtNumber(clicks)),
          $('<td />').addClass('column-number').text(fmtPct(ctr))
        )
      );
    });

    $table.append($thead, $tbody);
    $breakdown.append($table);
  }

  function applyPayload(payload) {
    if (!payload) {
      return;
    }

    // Populate placement dropdown (if provided) and preserve current selection.
    if ($placement.length && payload.placement_options) {
      var current = $placement.val() || '';
      var nextSelected = (payload.placement_key !== null && typeof payload.placement_key !== 'undefined')
        ? String(payload.placement_key || '')
        : current;

      // Rebuild options each time to reflect newest known set.
      $placement.empty();
      $placement.append($('<option />').attr('value', '').text('All placements'));

      Object.keys(payload.placement_options).sort().forEach(function (key) {
        var label = payload.placement_options[key] || key;
        $placement.append($('<option />').attr('value', key).text(label));
      });

      $placement.val(nextSelected);
    }

    var totals = payload.totals || {};
    $totalImps.text(typeof totals.impressions === 'number' ? fmtNumber(totals.impressions) : '—');
    $totalClicks.text(typeof totals.clicks === 'number' ? fmtNumber(totals.clicks) : '—');
    $avgCtr.text(fmtPct(payload.avg_ctr));

    var top = payload.top || {};
    $topPage.text(top.top_page || '—');
    $topCtrPage.text(top.top_ctr_page || '—');

    var items = top.items || [];
    renderTopList(items, $metric.val() || 'clicks');
    buildCharts(payload);

    renderPlacementBreakdown(payload.placement_breakdown || []);
  }

  function fetchRange(range) {
    if (!settings.ajaxUrl || !settings.nonce || !adId) {
      return;
    }

    var placementKey = ($placement && $placement.length) ? String($placement.val() || '') : '';

    setStatus('Loading…');
    return $.ajax({
      url: settings.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'aa_ad_manager_get_ad_performance',
        nonce: settings.nonce,
        ad_id: adId,
        range: range,
        placement_key: placementKey
      }
    }).done(function (resp) {
      if (resp && resp.success && resp.data) {
        settings.initialData = resp.data;
        applyPayload(resp.data);
        setStatus('');
      } else {
        setStatus((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed to load data.');
      }
    }).fail(function () {
      setStatus('Failed to load data.');
    });
  }

  // Initial render.
  if (settings.initialData) {
    applyPayload(settings.initialData);
  } else if ($range.length) {
    fetchRange($range.val() || '30');
  }

  $range.on('change', function () {
    fetchRange($(this).val());
  });

  $placement.on('change', function () {
    fetchRange($range.val() || '30');
  });

  $metric.on('change', function () {
    // Re-render list from current payload if we have it.
    var payload = settings.initialData;
    if (payload && payload.top && payload.top.items) {
      renderTopList(payload.top.items, $(this).val());
      return;
    }
    // Fallback: refetch current range.
    fetchRange($range.val() || '30');
  });
});


jQuery(function ($) {
  function parseJsonScript(id) {
    var el = document.getElementById(id);
    if (!el) return null;
    try {
      return JSON.parse(el.textContent || el.innerText || 'null');
    } catch (e) {
      return null;
    }
  }

  function fmtDateLabel(ymd) {
    try {
      var d = new Date(ymd + 'T00:00:00');
      return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    } catch (e) {
      return ymd;
    }
  }

  function buildCharts(series) {
    if (!series || !window.Chart) {
      return;
    }

    var labels = Array.isArray(series.labels) ? series.labels : [];
    var labelDisplay = labels.map(fmtDateLabel);
    var impressions = Array.isArray(series.impressions) ? series.impressions : [];
    var clicks = Array.isArray(series.clicks) ? series.clicks : [];
    var ctr = Array.isArray(series.ctr) ? series.ctr : [];

    var icCtx = document.getElementById('aa-reports-placement-ic-chart');
    var ctrCtx = document.getElementById('aa-reports-placement-ctr-chart');
    if (!icCtx || !ctrCtx) {
      return;
    }

    // eslint-disable-next-line no-new
    new window.Chart(icCtx, {
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
        scales: { y: { beginAtZero: true } }
      }
    });

    // eslint-disable-next-line no-new
    new window.Chart(ctrCtx, {
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
                var v = ctx.parsed && typeof ctx.parsed.y !== 'undefined' ? ctx.parsed.y : null;
                if (v === null || !isFinite(Number(v))) return 'CTR: —';
                return 'CTR: ' + Number(v).toFixed(2) + '%';
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

  var series = parseJsonScript('aa-reports-placement-series');
  buildCharts(series);
});


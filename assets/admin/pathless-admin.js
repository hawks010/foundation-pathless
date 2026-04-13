(function () {
  function asQuery(params) {
    var query = new URLSearchParams();
    Object.keys(params || {}).forEach(function (key) {
      query.append(key, params[key]);
    });
    return query.toString();
  }

  function buildRestUrl(path, params) {
    var base = (window.fpPathlessAdmin && window.fpPathlessAdmin.restRoot) || '';
    var query = params ? ('?' + asQuery(params)) : '';
    return base + path + query;
  }

  function buildAjaxBody(params) {
    var body = new URLSearchParams();
    Object.keys(params || {}).forEach(function (key) {
      body.append(key, params[key]);
    });
    return body;
  }

  function boot() {
    var root = document.getElementById('foundation-admin-app');
    if (!root || root.dataset.pathlessBooted === '1' || !window.fpPathlessAdmin) {
      return;
    }

    root.dataset.pathlessBooted = '1';

    var scanButton = root.querySelector('#fp-trigger-scan');
    var progressBar = root.querySelector('#fp-scan-progress-bar');
    var statusText = root.querySelector('#fp-scan-status-text');
    var pollTimer = null;

    function setStatus(message) {
      if (statusText) {
        statusText.textContent = message || '';
      }
    }

    function setProgress(data) {
      if (!progressBar) {
        return;
      }

      var percent = Math.max(0, Math.min(100, parseInt(data.progress || 0, 10) || 0));
      progressBar.style.width = percent + '%';
      progressBar.setAttribute('aria-valuenow', percent);
    }

    function updateScanUi(data) {
      if (!scanButton || !data) {
        return;
      }

      if (data.status === 'scanning') {
        scanButton.disabled = true;
        scanButton.textContent = 'Scan in progress...';
        setStatus('Scanned ' + (data.scanned || 0) + ' of ' + (data.total || 0) + ' items.');
        setProgress(data);
        return;
      }

      scanButton.disabled = false;
      scanButton.textContent = 'Start new scan';
      setProgress({ progress: 100 });
      setStatus('Scan complete. Reloading results...');
      window.clearTimeout(pollTimer);
      window.setTimeout(function () {
        window.location.reload();
      }, 700);
    }

    function restGet(path, params) {
      return window.fetch(buildRestUrl(path, params), {
        credentials: 'same-origin',
        headers: {
          'X-WP-Nonce': window.fpPathlessAdmin.restNonce
        }
      }).then(function (response) {
        return response.json();
      });
    }

    function ajaxGet(params) {
      return window.fetch(window.fpPathlessAdmin.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
        },
        body: buildAjaxBody(params)
      }).then(function (response) {
        return response.json();
      });
    }

    function pollScanStatus() {
      restGet('status').then(function (data) {
        if (data && data.status) {
          updateScanUi(data);
        } else {
          throw new Error('bad_rest_status');
        }
      }).catch(function () {
        ajaxGet({
          action: 'fp_check_scan_status',
          _wpnonce: window.fpPathlessAdmin.ajaxNonce
        }).then(function (response) {
          if (response && response.success && response.data) {
            updateScanUi(response.data);
            return;
          }
          throw new Error('bad_ajax_status');
        }).catch(function () {
          setStatus('We could not refresh scan progress. Please reload the page.');
          if (scanButton) {
            scanButton.disabled = false;
            scanButton.textContent = 'Start new scan';
          }
        });
      });

      pollTimer = window.setTimeout(pollScanStatus, 5000);
    }

    if (scanButton) {
      scanButton.addEventListener('click', function (event) {
        event.preventDefault();
        scanButton.disabled = true;
        scanButton.textContent = 'Starting scan...';
        setStatus('');
        setProgress({ progress: 0 });

        restGet('start').then(function (data) {
          if (data && (data.ok || data.status || typeof data.progress !== 'undefined')) {
            updateScanUi(data);
            pollScanStatus();
            return;
          }
          throw new Error('bad_rest_start');
        }).catch(function () {
          ajaxGet({
            action: 'fp_start_scan',
            _wpnonce: window.fpPathlessAdmin.ajaxNonce
          }).then(function (response) {
            if (response && response.success && response.data) {
              updateScanUi(response.data);
              pollScanStatus();
              return;
            }
            throw new Error('bad_ajax_start');
          }).catch(function () {
            scanButton.disabled = false;
            scanButton.textContent = 'Start new scan';
            setStatus('We could not start a new scan right now.');
          });
        });
      });

      if (scanButton.disabled) {
        pollScanStatus();
      }
    }

    root.addEventListener('click', function (event) {
      var dismissLink = event.target.closest('.fp-action-link[data-action="dismiss"]');
      if (!dismissLink) {
        return;
      }

      event.preventDefault();
      dismissLink.style.opacity = '0.55';

      var linkId = dismissLink.getAttribute('data-link-id');
      var tableRow = dismissLink.closest('tr');

      restGet('dismiss', { id: linkId }).then(function (data) {
        if (data && data.dismissed && tableRow) {
          tableRow.remove();
          return;
        }
        throw new Error('bad_rest_dismiss');
      }).catch(function () {
        ajaxGet({
          action: 'fp_dismiss_link',
          link_id: linkId,
          _wpnonce: window.fpPathlessAdmin.ajaxNonce
        }).then(function (response) {
          if (response && response.success && tableRow) {
            tableRow.remove();
            return;
          }
          throw new Error('bad_ajax_dismiss');
        }).catch(function () {
          dismissLink.style.opacity = '1';
        });
      });
    });
  }

  window.addEventListener('foundation-admin:ready', function (event) {
    if (event.detail && event.detail.plugin === 'pathless') {
      boot();
    }
  });

  document.addEventListener('DOMContentLoaded', boot);
})();

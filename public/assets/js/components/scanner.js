/* =====================================================================
   MSScanner — camera scanning (html5-qrcode) + manual / USB-scanner entry.
   USB barcode scanners act as keyboards: they type into the manual field and
   press Enter, so they work everywhere without any camera.
     MSScanner.mount(container, { onResult(result), continuous })
     MSScanner.open({ title, onResult(result), continuous })   // modal
     MSScanner.lookup(code) → Promise<result>                   // /api/scan/resolve
   ===================================================================== */
(function () {
    'use strict';
    var seq = 0;

    function lookup(code) {
        return MS.api('api/scan/resolve?code=' + encodeURIComponent(code));
    }

    function formats() {
        if (!window.Html5QrcodeSupportedFormats) { return undefined; }
        var F = window.Html5QrcodeSupportedFormats;
        return [F.QR_CODE, F.CODE_128, F.EAN_13, F.EAN_8, F.UPC_A, F.UPC_E, F.CODE_39, F.DATA_MATRIX].filter(function (f) { return f !== undefined; });
    }

    function mount(container, opts) {
        opts = opts || {};
        var id = 'ms-reader-' + (++seq);
        container.innerHTML =
            '<div class="scanner-reader" id="' + id + '"><div class="scanner-placeholder"><i class="bi bi-camera-video" aria-hidden="true"></i><div>Camera is off</div></div></div>' +
            '<div class="scanner-status" role="status" aria-live="polite"></div>' +
            '<div class="d-flex justify-content-center gap-2 mb-3"><button type="button" class="btn btn-sm btn-primary" data-cam-start><i class="bi bi-camera-video me-1" aria-hidden="true"></i>Start camera</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary" data-cam-stop hidden>Stop camera</button></div>' +
            '<form class="input-group" data-manual novalidate><input type="text" class="form-control" placeholder="Type or scan a code (USB scanner)" aria-label="Code" autocomplete="off" maxlength="120">' +
            '<button class="btn btn-outline-primary" type="submit">Look up</button></form>';
        var status = container.querySelector('.scanner-status');
        var startBtn = container.querySelector('[data-cam-start]');
        var stopBtn = container.querySelector('[data-cam-stop]');
        var manual = container.querySelector('[data-manual]');
        var input = manual.querySelector('input');
        var cam = null;
        var busy = false;
        var lastCode = '';
        var lastAt = 0;

        var setStatus = function (text, danger) { status.textContent = text; status.classList.toggle('text-danger', !!danger); };
        var handle = function (code) {
            code = String(code || '').trim();
            if (!code || busy) { return; }
            var now = Date.now();
            if (code === lastCode && now - lastAt < 2500) { return; } // ignore the same code read repeatedly
            lastCode = code; lastAt = now; busy = true;
            setStatus('Looking up ' + code + '…');
            lookup(code).then(function (result) {
                setStatus(result.type === 'unknown' ? 'Not recognised: ' + code : 'Found: ' + (result.product ? result.product.name : code), result.type === 'unknown');
                if (opts.onResult) { opts.onResult(result); }
                if (!opts.continuous && cam) { stop(); }
            }).catch(function (err) { setStatus(err.message, true); })
              .finally(function () { busy = false; });
        };
        var stop = function () {
            if (!cam) { return Promise.resolve(); }
            var c = cam; cam = null;
            startBtn.hidden = false; stopBtn.hidden = true;
            return c.stop().catch(function () {}).then(function () {
                try { c.clear(); } catch (e) { /* ignore */ }
                document.getElementById(id).innerHTML = '<div class="scanner-placeholder"><i class="bi bi-camera-video" aria-hidden="true"></i><div>Camera is off</div></div>';
            });
        };
        var start = function () {
            if (!window.Html5Qrcode) { setStatus('Camera scanning is unavailable. Use manual entry.', true); return; }
            if (!window.isSecureContext) { setStatus('The camera needs HTTPS (or localhost). Use manual entry or a USB scanner.', true); return; }
            document.getElementById(id).innerHTML = '';
            cam = new Html5Qrcode(id, { formatsToSupport: formats(), verbose: false });
            setStatus('Starting camera…');
            cam.start({ facingMode: 'environment' }, { fps: 10, qrbox: function (w, h) { var s = Math.floor(Math.min(w, h) * 0.7); return { width: s, height: Math.floor(s * 0.75) }; } },
                function (text) { handle(text); }, function () { /* no code in this frame */ })
                .then(function () { startBtn.hidden = true; stopBtn.hidden = false; setStatus('Point the camera at a QR code or barcode.'); })
                .catch(function (err) {
                    cam = null;
                    document.getElementById(id).innerHTML = '<div class="scanner-placeholder"><i class="bi bi-camera-video-off" aria-hidden="true"></i><div>Camera unavailable</div></div>';
                    setStatus('Could not start the camera (' + (err && err.name ? err.name : err) + '). Allow camera access, or use manual entry / a USB scanner.', true);
                });
        };
        startBtn.addEventListener('click', start);
        stopBtn.addEventListener('click', stop);
        manual.addEventListener('submit', function (e) { e.preventDefault(); lastCode = ''; handle(input.value); input.select(); });
        if (opts.autofocus !== false) { setTimeout(function () { input.focus(); }, 50); }
        return { stop: stop, start: start, focus: function () { input.focus(); } };
    }

    var modal = null;
    function open(opts) {
        opts = opts || {};
        if (!modal) {
            var el = document.createElement('div');
            el.className = 'modal fade';
            el.tabIndex = -1;
            el.setAttribute('aria-labelledby', 'scanModalTitle');
            el.innerHTML = '<div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="scanModalTitle">Scan</h5>' +
                '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" data-scan-body></div></div></div>';
            document.body.appendChild(el);
            modal = { el: el, bs: new bootstrap.Modal(el), ctl: null };
            el.addEventListener('hidden.bs.modal', function () { if (modal.ctl) { modal.ctl.stop(); } });
        }
        modal.el.querySelector('#scanModalTitle').textContent = opts.title || 'Scan a code';
        modal.ctl = mount(modal.el.querySelector('[data-scan-body]'), {
            continuous: !!opts.continuous,
            autofocus: false,
            onResult: function (result) {
                if (opts.onResult) { opts.onResult(result); }
                if (!opts.continuous && result.type !== 'unknown') { modal.bs.hide(); }
            }
        });
        modal.bs.show();
        modal.el.addEventListener('shown.bs.modal', function once() { modal.ctl.focus(); modal.el.removeEventListener('shown.bs.modal', once); });
    }

    window.MSScanner = { mount: mount, open: open, lookup: lookup };
})();

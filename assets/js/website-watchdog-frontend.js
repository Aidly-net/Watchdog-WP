(function () {
    if (!window || !navigator || !window.location) { return; }
    var endpoint = '/wp-json/website-watchdog/v1/js-error';
    var ignored = /chrome-extension|moz-extension|safari-extension|edge-extension|script error|ResizeObserver loop|csp/i;
    var maxBodyBytes = 16384;
    function jsonBytes(value) {
        return new Blob([JSON.stringify(value)]).size;
    }
    function limitString(value, maxBytes) {
        if (typeof value !== 'string') { return ''; }
        if (jsonBytes(value) <= maxBytes) { return value; }
        var chars = Array.from(value.slice(0, maxBytes + 1));
        var suffix = '...[truncated]';
        var low = 0;
        var high = Math.min(chars.length, maxBytes);
        while (low < high) {
            var middle = Math.floor((low + high + 1) / 2);
            if (jsonBytes(chars.slice(0, middle).join('') + suffix) <= maxBytes) {
                low = middle;
            } else {
                high = middle - 1;
            }
        }
        return chars.slice(0, low).join('') + suffix;
    }
    function fitTransport(payload) {
        if (jsonBytes(payload) <= maxBodyBytes) { return payload; }
        if (typeof payload.stack === 'string') {
            var frames = payload.stack.split(/\r\n|\r|\n/).map(function (frame) { return frame.trim(); }).filter(Boolean);
            if (frames.length > 15) {
                frames = frames.slice(0, 10).concat(['.........'], frames.slice(-5));
            }
            payload.stack = frames.join('\n');
            if (jsonBytes(payload) <= maxBodyBytes) { return payload; }
            if (frames.length) {
                var frameBudget = Math.floor(8192 / frames.length);
                payload.stack = frames.map(function (frame) { return limitString(frame, frameBudget); }).join('\n');
            }
        } else {
            payload.stack = '';
        }
        payload.type = limitString(payload.type, 512);
        payload.message = limitString(payload.message, 4096);
        payload.source = limitString(payload.source, 1024);
        payload.url = limitString(payload.url, 2048);
        payload.line = typeof payload.line === 'number' && isFinite(payload.line) ? payload.line : 0;
        payload.column = typeof payload.column === 'number' && isFinite(payload.column) ? payload.column : 0;
        return payload;
    }
    function send(payload) {
        try {
            payload = fitTransport(payload);
            if (typeof navigator.sendBeacon === 'function') {
                navigator.sendBeacon(endpoint, JSON.stringify(payload));
                return;
            }
            if (typeof fetch === 'function') {
                fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload), keepalive: true });
            }
        } catch (e) { }
    }
    function clean(value, maxLength) {
        if (typeof value === 'string') {
            value = value.replace(/\s+/g, ' ');
            return maxLength ? value.slice(0, maxLength) : value;
        }
        return value;
    }
    window.onerror = function (msg, src, line, col, error) {
        if (ignored.test(String(msg || '')) || ignored.test(String(src || ''))) { return true; }
        send({ type: 'window.onerror', message: clean(msg), source: clean(src, 500), line: line || 0, column: col || 0, stack: error && error.stack || '', url: location.href });
        return false;
    };
    window.onunhandledrejection = function (event) {
        var reason = event && event.reason;
        if (!reason) { return; }
        send({ type: 'unhandledrejection', message: clean(reason && reason.message || reason), stack: reason && reason.stack || '', url: location.href });
    };
})();

/**
 * error_logger.js
 * Captures client-side JS errors and sends them to log_client_error.php
 * for storage in the audit_trail table. Fire-and-forget, never throws.
 */
(function () {
    'use strict';

    var ENDPOINT = (typeof BASE_URL !== 'undefined' ? BASE_URL : '/') + 'log_client_error.php';

    function classifyError(error, httpStatus) {
        if (httpStatus) return 'HTTP_' + httpStatus;
        if (!error) return 'UNKNOWN';
        var msg = String(error.message || error);
        if (/fetch|network|net::ERR|ECONNREFUSED|Failed to fetch/i.test(msg)) return 'NETWORK_ERROR';
        if (/JSON|Unexpected token|SyntaxError/i.test(msg)) return 'PARSE_ERROR';
        if (/timeout|abort/i.test(msg)) return 'TIMEOUT_ERROR';
        var match = msg.match(/HTTP\s*(\d{3})/i);
        if (match) return 'HTTP_' + match[1];
        return 'JS_ERROR';
    }

    function messageOf(error) {
        if (error instanceof Error) return error.name + ': ' + error.message;
        if (typeof error === 'string') return error;
        return String(error && error.message ? error.message : error || 'Unknown error');
    }

    function send(payload) {
        try {
            fetch(ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                keepalive: true,
            }).catch(function () { /* fire-and-forget */ });
        } catch (_) { /* never throw */ }
    }

    window.logClientError = function (action, error, httpStatus, userEmail) {
        send({
            action: String(action || 'UNKNOWN').substring(0, 80),
            error_type: classifyError(error, httpStatus),
            error_message: messageOf(error).substring(0, 500),
            page_url: window.location.pathname + window.location.search,
            http_status: httpStatus || null,
            user_email: userEmail || null,
        });
    };

    window.onerror = function (message, source, lineno, colno) {
        var msg = String(message || 'Unknown error');
        if (source) msg += ' at ' + source.split('/').pop() + ':' + lineno + ':' + colno;
        send({
            action: 'UNCAUGHT_ERROR',
            error_type: 'JS_ERROR',
            error_message: msg.substring(0, 500),
            page_url: window.location.pathname,
            http_status: null,
        });
        return false;
    };

    window.addEventListener('unhandledrejection', function (event) {
        send({
            action: 'UNHANDLED_REJECTION',
            error_type: classifyError(event.reason, null),
            error_message: messageOf(event.reason).substring(0, 500),
            page_url: window.location.pathname,
            http_status: null,
        });
    });
})();
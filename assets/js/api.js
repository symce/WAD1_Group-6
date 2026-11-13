'use strict';
/**
 * Tiny fetch() wrapper used by the whole app.
 *  - sends cookies (session) with every request
 *  - adds the X-CSRF-Token header to every non-GET request
 *  - turns failures (network, 401, 403, 404, 422, 429, 500 ...) into a friendly ApiError
 */
class ApiError extends Error {
    constructor(message, status = 0, errors = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors || {};
        this.handled = false;
    }
}

const API = (() => {
    let csrfToken = '';
    const fallback = {
        0: 'Cannot reach the server. Check your connection and try again.',
        401: 'Please log in to continue.',
        403: 'You do not have permission to do that.',
        404: 'We could not find what you were looking for.',
        429: 'Too many attempts. Please wait a moment and try again.',
        500: 'Something went wrong on our side. Please try again shortly.'
    };

    async function request(url, { method = 'GET', body = null, form = null, signal } = {}) {
        const headers = { Accept: 'application/json' };
        const opts = { method, headers, credentials: 'same-origin', signal };
        if (method !== 'GET') headers['X-CSRF-Token'] = csrfToken;
        if (form) {
            opts.body = form; // FormData: the browser sets the multipart boundary itself
        } else if (body !== null) {
            headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }

        let res;
        try {
            res = await fetch(url, opts);
        } catch (err) {
            if (err.name === 'AbortError') throw err;
            throw new ApiError(fallback[0], 0);
        }

        let json = null;
        try { json = await res.json(); } catch (_) { /* non-JSON answer, e.g. a PHP fatal error */ }

        if (!res.ok || !json || json.success === false) {
            const status = res.status || 500;
            const message = (json && json.message) || fallback[status] || fallback[500];
            const err = new ApiError(message, status, json && json.data && json.data.errors);
            if (status === 401 && typeof API.onUnauthorized === 'function') API.onUnauthorized(err);
            throw err;
        }
        return json; // { success, message, data }
    }

    return {
        get: (url, opts = {}) => request(url, opts),
        post: (url, body = {}) => request(url, { method: 'POST', body }),
        postForm: (url, form) => request(url, { method: 'POST', form }),
        setCsrf(token) { csrfToken = token || ''; },
        onUnauthorized: null
    };
})();

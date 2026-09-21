/**
 * NOTIFY-01: a small fetch() wrapper for the notification bell/global
 * search JSON endpoints, which are polled/queried outside Inertia's own
 * page-navigation lifecycle (Inertia has no "fetch this JSON and keep the
 * current page" primitive). Reads the `XSRF-TOKEN` cookie Laravel already
 * sets on every response (confirmed present — this app's session/CSRF
 * middleware stack sets it regardless of route) and sends it back as
 * `X-XSRF-TOKEN`, the standard Laravel SPA pattern — no <meta name="csrf-token">
 * tag exists in resources/views/app.blade.php, so this deliberately does
 * NOT depend on one.
 */
function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));

    return match ? decodeURIComponent(match[1]) : null;
}

async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const token = readCookie('XSRF-TOKEN');
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    if (!response.ok) {
        throw new Error(`Request to ${url} failed with ${response.status}`);
    }

    return (await response.json()) as T;
}

export const api = {
    get: <T>(url: string) => request<T>('GET', url),
    post: <T>(url: string, body?: unknown) => request<T>('POST', url, body ?? {}),
    put: <T>(url: string, body?: unknown) => request<T>('PUT', url, body ?? {}),
};

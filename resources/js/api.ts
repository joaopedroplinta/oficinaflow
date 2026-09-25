export class ApiError extends Error {
    constructor(public status: number, public body: { message?: string; code?: string; errors?: Record<string, string[]> }) {
        super(body.message ?? `HTTP ${status}`);
    }
}

const xsrf = () => decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');

export async function api<T = unknown>(method: string, path: string, body?: unknown): Promise<T> {
    if (method !== 'GET' && !xsrf()) await fetch('/sanctum/csrf-cookie');

    const res = await fetch(`/api/v1${path}`, {
        method,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (res.status === 204) return undefined as T;
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new ApiError(res.status, data);
    return data as T;
}

export class ApiError extends Error {
    constructor(public status: number, public body: { message?: string; code?: string; details?: unknown; errors?: Record<string, string[]> }) {
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
    if (res.status === 401 && path !== '/login') window.dispatchEvent(new Event('auth:expired'));
    if (!res.ok) throw new ApiError(res.status, data);
    return data as T;
}

export const fieldErrors = (e: unknown): Record<string, string[]> =>
    e instanceof ApiError && e.status === 422 ? (e.body.errors ?? {}) : {};

export function errorMessage(e: unknown): string {
    if (!(e instanceof ApiError)) return 'Falha de conexão. Verifique sua rede e tente novamente.';
    if (e.status === 401) return 'Sua sessão expirou. Entre novamente.';
    if (e.status === 403) return 'Você não tem permissão para esta ação.';
    if (e.status === 404) return 'Registro não encontrado.';
    if (e.status >= 500) return 'Erro no servidor. Tente novamente em instantes.';
    return e.body.message ?? 'Não foi possível concluir a ação.';
}

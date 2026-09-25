import { useRef } from 'react';
import { api } from '../api';
import { formatDateTime } from '../format';
import { useAction } from '../hooks/use-action';
import { useLoad } from '../hooks/use-load';
import type { Data } from '../types/api';
import type { Attachment } from '../types/work-order';
import { Loading } from './loading';

const size = (bytes: number) => (bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

type Props = { orderId: number; onChanged: () => void };

export function AttachmentsSection({ orderId, onChanged }: Props) {
    const { data, loading, error, reload } = useLoad<Data<Attachment[]>>(`/work-orders/${orderId}/attachments`);
    // Erro de envio fica ao lado do botão; não é "tela velha", então não recarrega a página.
    const upload = useAction(() => undefined);
    const input = useRef<HTMLInputElement>(null);

    const send = async (files: FileList | null) => {
        if (!files || files.length === 0) return;
        const list = Array.from(files);
        await upload.run(async () => {
            try {
                for (const file of list) {
                    const body = new FormData();
                    body.append('file', file);
                    await api('POST', `/work-orders/${orderId}/attachments`, body);
                }
            } finally {
                // Recarrega mesmo se um dos arquivos falhou: os anteriores já foram gravados.
                reload();
                onChanged();
            }
        });
        if (input.current) input.current.value = '';
    };

    const items = data?.data ?? [];
    const images = items.filter((a) => a.is_image);
    const documents = items.filter((a) => !a.is_image);

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-3">
                <label htmlFor="attachment-file" className={`inline-flex min-h-11 cursor-pointer items-center rounded border border-slate-300 px-4 py-2 text-sm focus-within:outline-2 focus-within:outline-slate-900 ${upload.busy ? 'opacity-50' : ''}`}>
                    {upload.busy ? 'Enviando…' : 'Anexar foto ou PDF'}
                    <input
                        ref={input}
                        id="attachment-file"
                        type="file"
                        multiple
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        disabled={upload.busy}
                        onChange={(e) => void send(e.target.files)}
                        aria-describedby="attachment-hint"
                        className="sr-only"
                    />
                </label>
                <span id="attachment-hint" className="text-sm text-slate-500">
                    JPEG, PNG, WebP ou PDF, até 10 MB cada; no máximo 20 por ordem.
                </span>
            </div>

            {upload.error && (
                <p role="alert" className="text-sm text-red-700">
                    {upload.error}
                </p>
            )}

            {loading && !data && <Loading small />}
            {error && (
                <p role="alert" className="text-sm text-red-700">
                    {error}{' '}
                    <button onClick={reload} className="underline">
                        Tentar de novo
                    </button>
                </p>
            )}
            {data && items.length === 0 && <p className="text-sm text-slate-500">Nenhuma foto ou documento ainda. Use o botão acima para anexar.</p>}

            {images.length > 0 && (
                <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4">
                    {images.map((a) => (
                        <li key={a.id} className="flex min-w-0 flex-col gap-1 text-xs">
                            <a href={a.url} target="_blank" rel="noopener noreferrer" aria-label={`Abrir ${a.original_name}`}>
                                <img src={a.url} alt={a.original_name} loading="lazy" className="aspect-square w-full rounded border border-slate-200 object-cover" />
                            </a>
                            <span className="truncate">{a.original_name}</span>
                            <span className="text-slate-500">
                                {a.uploader.name} · {formatDateTime(a.created_at)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {documents.length > 0 && (
                <ul className="flex flex-col gap-2">
                    {documents.map((a) => (
                        <li key={a.id} className="text-sm">
                            <a href={a.url} target="_blank" rel="noopener noreferrer" className="underline">
                                <span aria-hidden="true">📄 </span>
                                {a.original_name}
                            </a>{' '}
                            <span className="text-slate-500">
                                ({size(a.size_bytes)}) · {a.uploader.name} · {formatDateTime(a.created_at)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

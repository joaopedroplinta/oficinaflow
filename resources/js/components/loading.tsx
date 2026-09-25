// Carregando acessível: anunciado por leitor de tela e, nas telas cheias, com um título (h1) para a página não ficar sem cabeçalho.
export function Loading({ title, small = false }: { title?: string; small?: boolean }) {
    return (
        <>
            {title && <h1 className="sr-only">{title}</h1>}
            <p role="status" className={small ? 'text-sm text-slate-500' : 'text-slate-500'}>
                Carregando…
            </p>
        </>
    );
}

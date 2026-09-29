// Baixa um documento em PDF gerado pelo servidor (GET com o cookie de sessão; o navegador faz o download).
export function PdfLink({ href, children }: { href: string; children: string }) {
    return (
        <a href={href} download className="doc-link">
            <svg width="14" height="16" viewBox="0 0 14 16" fill="none" aria-hidden="true">
                <path d="M2 1h7l3.5 3.5V15H2V1Z" stroke="currentColor" strokeWidth="1.5" strokeLinejoin="round" />
                <path d="M5 8.5 7 10.5l2-2M7 10.5V5.5" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
            </svg>
            {children}
        </a>
    );
}

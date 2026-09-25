// Baixa um documento em PDF gerado pelo servidor (GET com o cookie de sessão; o navegador faz o download).
export function PdfLink({ href, children }: { href: string; children: string }) {
    return (
        <a href={href} download className="inline-flex items-center gap-1 text-sm underline">
            <span aria-hidden="true">📄</span>
            {children}
        </a>
    );
}

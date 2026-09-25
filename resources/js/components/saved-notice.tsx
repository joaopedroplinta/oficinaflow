// Confirmação de que salvou. Vive na página (não no formulário): depois de salvar, o formulário remonta com os dados novos.
export function SavedNotice({ message }: { message: string }) {
    return (
        <p role="status" className="rounded border border-green-300 bg-green-50 p-3 text-sm">
            <span aria-hidden="true">✓ </span>
            {message}
        </p>
    );
}

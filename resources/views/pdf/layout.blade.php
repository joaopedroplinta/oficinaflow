<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>@yield('title')</title>
    <style>
        @page { margin: 28px 34px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 18px 0 6px; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; }
        .muted { color: #64748b; }
        .head { border-bottom: 2px solid #0f172a; padding-bottom: 8px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 10px; color: #64748b; border-bottom: 1px solid #cbd5e1; padding: 4px 6px 4px 0; }
        td { padding: 4px 6px 4px 0; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .right { text-align: right; }
        .total td { font-weight: bold; border-bottom: 0; border-top: 2px solid #0f172a; }
        dl { margin: 0; }
        dt { float: left; clear: left; width: 130px; color: #64748b; }
        dd { margin: 0 0 3px 130px; }
        .banner { border: 1px solid #f59e0b; background: #fffbeb; padding: 6px 8px; margin: 8px 0; }
        .footer { margin-top: 24px; font-size: 9px; color: #94a3b8; }
        .pre { white-space: pre-wrap; }
    </style>
</head>
<body>
    <div class="head">
        <strong>OficinaFlow</strong> <span class="muted">· Assistência técnica</span>
        <h1>@yield('heading')</h1>
        <div class="muted">@yield('subheading')</div>
    </div>
    @yield('content')
    <div class="footer">Documento gerado em {{ $generatedAt->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }} (horário de Brasília).</div>
</body>
</html>

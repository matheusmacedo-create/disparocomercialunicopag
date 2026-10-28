@php
    /** @var array{cabecalho: ?string, cabecalho_formato: ?string, corpo: string, rodape: ?string, botoes: list<string>} $balao */
    $balao = $balao ?? ['cabecalho' => null, 'cabecalho_formato' => null, 'corpo' => '', 'rodape' => null, 'botoes' => []];
@endphp

<div style="background:#e5ddd5;border-radius:12px;padding:16px;max-width:360px;font-family:system-ui,-apple-system,sans-serif;">
    <div style="background:#ffffff;border-radius:8px;padding:10px 12px;box-shadow:0 1px 1px rgba(0,0,0,.15);color:#111;font-size:14px;line-height:1.4;">
        @if (filled($balao['cabecalho']))
            @if ($balao['cabecalho_formato'] === 'TEXT')
                <div style="font-weight:600;margin-bottom:6px;">{{ $balao['cabecalho'] }}</div>
            @else
                <div style="background:#d9d9d9;border-radius:6px;height:96px;display:flex;align-items:center;justify-content:center;color:#555;font-size:12px;margin-bottom:8px;">{{ $balao['cabecalho'] }}</div>
            @endif
        @endif

        <div style="white-space:pre-line;">{{ $balao['corpo'] }}</div>

        @if (filled($balao['rodape']))
            <div style="color:#667781;font-size:12px;margin-top:6px;">{{ $balao['rodape'] }}</div>
        @endif

        <div style="text-align:right;color:#667781;font-size:11px;margin-top:4px;">{{ now('America/Sao_Paulo')->format('H:i') }}</div>
    </div>

    @foreach ($balao['botoes'] as $botao)
        <div style="background:#ffffff;border-radius:8px;padding:8px;margin-top:4px;text-align:center;color:#027eb5;font-size:14px;box-shadow:0 1px 1px rgba(0,0,0,.15);">{{ $botao }}</div>
    @endforeach
</div>

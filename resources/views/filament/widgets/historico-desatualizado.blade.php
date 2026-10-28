<x-filament-widgets::widget>
    <x-filament::callout color="warning" icon="heroicon-o-clock" heading="Histórico da Kapso desatualizado">
        Conversas abertas e pedidos para parar recentes podem estar faltando nas guardas. Importe o histórico (php artisan disparo:importar-historico-kapso) antes de aprovar campanha para:
        <ul style="margin-top:.5rem;list-style:disc;padding-left:1.25rem;">
            @foreach ($remetentes as $remetente)
                <li>
                    <strong>{{ $remetente->nome }}</strong>
                    - {{ $remetente->historico_importado_ate ? 'importado até '.$remetente->historico_importado_ate->timezone('America/Sao_Paulo')->format('d/m/Y H:i') : 'nunca importado' }}
                </li>
            @endforeach
        </ul>
    </x-filament::callout>
</x-filament-widgets::widget>

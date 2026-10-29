<x-filament-widgets::widget>
    <x-filament::section heading="Templates com mais aderência ({{ $this->dias() }} dias)" description="Resposta = o contato respondeu citando o template ou em até 72 h depois dele. Só templates com pelo menos {{ \App\Filament\Widgets\AderenciaPorTemplateWidget::MINIMO_ENVIADOS }} envios.">
        @php($linhas = $this->linhas())
        @if ($linhas->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nenhum template com envios suficientes no período.</p>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4 font-semibold">Template</th>
                            <th class="py-2 pr-4 text-right font-semibold">Enviados</th>
                            <th class="py-2 pr-4 text-right font-semibold">Entregues</th>
                            <th class="py-2 pr-4 text-right font-semibold">Lidos</th>
                            <th class="py-2 pr-4 text-right font-semibold">Respostas</th>
                            <th class="py-2 text-right font-semibold">Taxa de resposta</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($linhas as $linha)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="py-2 pr-4 font-mono text-xs">{{ $linha['template'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ $linha['enviados'] }}</td>
                                <td class="py-2 pr-4 text-right">{{ $linha['entregues'] }} <span class="text-gray-400">({{ $linha['taxa_entrega'] }}%)</span></td>
                                <td class="py-2 pr-4 text-right">{{ $linha['lidos'] }} <span class="text-gray-400">({{ $linha['taxa_leitura'] }}%)</span></td>
                                <td class="py-2 pr-4 text-right">{{ $linha['respondidos'] }}</td>
                                <td class="py-2 text-right">
                                    <x-filament::badge :color="$linha['taxa_resposta'] >= 20 ? 'success' : ($linha['taxa_resposta'] >= 10 ? 'warning' : 'gray')">{{ $linha['taxa_resposta'] }}%</x-filament::badge>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>

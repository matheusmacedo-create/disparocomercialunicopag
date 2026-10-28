<?php

declare(strict_types=1);

namespace App\Services\Campanhas;

use App\Models\Campaign;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Support\Variaveis;

/**
 * Tudo o que faria o envio falhar para QUALQUER destinatário, conferido antes da prévia e da aprovação
 * (portado do `ReguaKapso::problemasDoTemplate` do KYC): template existe no cache, aprovado, categoria
 * permitida no remetente, cabeçalho de mídia com URL, botão de URL dinâmica com sufixo, {{n}}/{{nome}} do
 * corpo todos mapeados, variáveis conhecidas, público definido. Sem isto o erro aparecia no envio, um
 * contato por rodada (132000).
 *
 * @return list<string> problemas, vazio = pode seguir
 */
final class ValidadorDeCampanha
{
    /** @return list<string> */
    public static function problemas(Campaign $campaign): array
    {
        $problemas = [];
        $sender = $campaign->sender;

        if ($sender === null) {
            return ['Escolha o remetente.'];
        }

        $template = MessageTemplate::query()
            ->where('sender_id', $sender->id)
            ->where('nome', $campaign->template_nome)
            ->where('idioma', $campaign->template_idioma)
            ->first();

        if ($template === null) {
            return [sprintf('Template "%s" (%s) não está no cache do remetente "%s": sincronize os templates em Configuração > Templates.', $campaign->template_nome, $campaign->template_idioma, $sender->nome)];
        }

        if (($motivo = $template->motivoNaoEnviavel($sender)) !== null) {
            $problemas[] = $motivo;
        }

        $categoria = strtoupper((string) $template->categoria);

        if ($campaign->objetivo === Campaign::OBJETIVO_OFERTAR && $categoria !== Sender::CATEGORIA_MARKETING) {
            $problemas[] = 'Oferta a base externa precisa de template MARKETING: template de utilidade usado para reengajar é "abuso de categoria" para a Meta (KB 2.4).';
        }

        $variaveis = (array) ($campaign->variaveis ?? []);
        $corpo = $variaveis['corpo'] ?? [];
        $pedidos = $template->variaveisDoCorpo();
        $numericos = array_values(array_filter($pedidos, 'ctype_digit'));

        if ($numericos !== []) {
            $posicionais = is_array($corpo) && array_is_list($corpo) ? $corpo : [];
            $maior = (int) max($numericos);

            if ($posicionais === [] && $numericos === ['1']) {
                // {{1}} sozinho = primeiro nome com padrão (regra da casa).
            } elseif (count($posicionais) < $maior) {
                $problemas[] = sprintf('O corpo do template pede %d variável(is) ({{1}}..{{%d}}) e a campanha preenche %d.', $maior, $maior, count($posicionais));
            }

            foreach ($posicionais as $i => $modelo) {
                if (trim((string) $modelo) === '') {
                    $problemas[] = sprintf('A variável {{%d}} está vazia na campanha.', $i + 1);
                }
            }
        } elseif ($pedidos !== []) {
            $mapa = is_array($corpo) && ! array_is_list($corpo) ? $corpo : [];
            $conhecidas = array_keys(Variaveis::exemplo());

            foreach ($pedidos as $nome) {
                if (! isset($mapa[$nome]) && ! in_array(strtolower($nome), $conhecidas, true)) {
                    $problemas[] = sprintf('O template usa {{%s}}, que a campanha não preenche.', $nome);
                }
            }
        }

        $modelos = array_merge(
            is_array($corpo) ? array_map('strval', array_values($corpo)) : [],
            array_filter([(string) ($variaveis['cabecalho_texto'] ?? ''), (string) ($variaveis['botao_url_sufixo'] ?? '')]),
        );

        foreach ($modelos as $modelo) {
            foreach (Variaveis::desconhecidas($modelo, Variaveis::exemplo()) as $desconhecida) {
                $problemas[] = sprintf('A variável {{%s}} não existe (veja a lista de variáveis disponíveis).', $desconhecida);
            }
        }

        $cabecalho = $template->formatoDoCabecalho();

        if (in_array($cabecalho, ['IMAGE', 'DOCUMENT', 'VIDEO'], true) && blank($variaveis['cabecalho_midia_url'] ?? null)) {
            $problemas[] = sprintf('O template tem cabeçalho de %s: informe a URL pública da mídia.', strtolower($cabecalho));
        }

        if ($cabecalho === 'TEXT') {
            foreach ($template->componentes as $componente) {
                if (strtoupper((string) ($componente['type'] ?? '')) === 'HEADER' && preg_match('/\{\{/', (string) ($componente['text'] ?? '')) === 1 && blank($variaveis['cabecalho_texto'] ?? null)) {
                    $problemas[] = 'O cabeçalho do template tem variável: preencha "Texto do cabeçalho".';
                }
            }
        }

        if ($template->temBotaoDeUrlDinamica() && blank($variaveis['botao_url_sufixo'] ?? null)) {
            $problemas[] = 'O template tem botão de URL dinâmica: informe o sufixo do link.';
        }

        $publico = (array) ($campaign->publico ?? []);
        $temPublico = array_filter((array) ($publico['listas'] ?? [])) !== []
            || (int) ($publico['segment_id'] ?? 0) > 0
            || (int) (($publico['campanha_anterior'] ?? [])['campaign_id'] ?? 0) > 0;

        if (! $temPublico) {
            $problemas[] = 'Defina o público: pelo menos uma base, um segmento ou uma campanha anterior. Campanha sem público não existe (seria a base inteira).';
        }

        if ((int) $campaign->prioridade < 1 || (int) $campaign->prioridade > 9) {
            $problemas[] = 'Prioridade entre 1 (primeiro) e 9 (último).';
        }

        return array_values(array_unique($problemas));
    }
}

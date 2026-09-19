<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsStudioController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('reachyn_generate_narration')]
#[Description('Gera narração (texto-pra-voz) avulsa no Reachyn e retorna a URL do áudio na hora. Use reachyn_list_voices pra escolher voice_id.')]
class GenerateNarrationTool extends Tool
{
    use CallsStudioController;

    public function handle(Request $request): Response
    {
        if ($blocked = $this->ensureMcpAbility($request)) {
            return $blocked;
        }

        $res = $this->callStudio($request, 'tts', array_filter([
            'draftId' => $request->get('draftId'),
            'text' => (string) $request->get('text', ''),
            'voice_id' => $request->get('voice_id'),
            'lang' => $request->get('lang'),
            // ⏱️ true → a resposta traz words [{word,start,end}] + duration (relógio real da fala).
            'timestamps' => $request->get('timestamps') ? true : null,
        ], fn ($v) => $v !== null));

        return $this->toToolResponse($res);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()
                ->description('Texto a narrar.')
                ->required(),
            'voice_id' => $schema->string()
                ->description('ID da voz (ver reachyn_list_voices). Omitido usa a voz padrão do tenant.'),
            'lang' => $schema->string()
                ->description('Idioma da narração (ex: pt-BR, en-US). Omitido usa o idioma padrão do tenant.'),
            'draftId' => $schema->integer()
                ->description('Rascunho existente pra anexar o áudio. Omitido cria um novo.'),
            'timestamps' => $schema->boolean()
                ->description('true = devolve também o alinhamento por palavra (words: [{word,start,end}] em segundos) e duration — pra sincronizar legenda/corte com a fala em montagem externa.'),
        ];
    }
}

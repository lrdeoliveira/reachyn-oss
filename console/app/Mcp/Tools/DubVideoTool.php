<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsStudioController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('reachyn_dub_video')]
#[Description('Dubla um vídeo já gerado no Reachyn (ElevenLabs, exclusivo do plano Studio). ASSÍNCRONO (~5-15min): use reachyn_check_media com o draftId depois pra pegar a versão dublada.')]
class DubVideoTool extends Tool
{
    use CallsStudioController;

    public function handle(Request $request): Response
    {
        if ($blocked = $this->ensureMcpAbility($request)) {
            return $blocked;
        }

        $res = $this->callStudio($request, 'dub', [
            'draftId' => $request->get('draftId'),
            'videoUrl' => (string) $request->get('videoUrl', ''),
            'lang' => (string) $request->get('lang', ''),
        ]);

        return $this->toToolResponse($res);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'draftId' => $schema->integer()
                ->description('Rascunho dono do vídeo (retornado por reachyn_generate_video).')
                ->required(),
            'videoUrl' => $schema->string()
                ->description('URL do vídeo (do nosso S3, ex: retornada por reachyn_check_media) a dublar.')
                ->required(),
            'lang' => $schema->string()
                ->description('Idioma de destino da dublagem.')
                ->enum(['en', 'es', 'fr', 'de', 'it', 'pt'])
                ->required(),
        ];
    }
}

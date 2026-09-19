<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsStudioController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('reachyn_generate_video')]
#[Description('Inicia a geração de um vídeo no Reachyn. ASSÍNCRONO: retorna um draftId na hora; use reachyn_check_media (filtrando por esse draftId) depois de alguns minutos para pegar a URL final.')]
class GenerateVideoTool extends Tool
{
    use CallsStudioController;

    public function handle(Request $request): Response
    {
        if ($blocked = $this->ensureMcpAbility($request)) {
            return $blocked;
        }

        $res = $this->callStudio($request, 'media', array_filter([
            'kind' => 'video',
            'prompt' => (string) $request->get('prompt', ''),
            'model' => $request->get('model'),
            'duration' => (string) $request->get('duration', '5'),
            'scenes' => (int) $request->get('scenes', 1),
            'narration' => (bool) $request->get('narration', false),
            'subtitles' => (bool) $request->get('subtitles', false),
            'music' => (bool) $request->get('music', false),
            'aspect' => $request->get('aspect', '9:16'),
            'style' => $request->get('style', 'realista'),
            'imageUrl' => $request->get('imageUrl'),
        ], fn ($v) => $v !== null));

        return $this->toToolResponse($res);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()
                ->description('Descrição do vídeo a gerar (texto livre).')
                ->required(),
            'model' => $schema->string()
                ->description('Slug do modelo de vídeo (gen_models), opcional.'),
            'duration' => $schema->string()
                ->description('Duração de cada clipe em segundos.')
                ->enum(['5', '10'])
                ->default('5'),
            'scenes' => $schema->integer()
                ->description('Número de cenas (cada cena = 1 clipe). >1 aciona o pipeline de Short produzido (custo maior).')
                ->default(1),
            'narration' => $schema->boolean()
                ->description('Adicionar narração falada.')
                ->default(false),
            'subtitles' => $schema->boolean()
                ->description('Adicionar legenda.')
                ->default(false),
            'music' => $schema->boolean()
                ->description('Adicionar trilha sonora.')
                ->default(false),
            'aspect' => $schema->string()
                ->description('Proporção do vídeo.')
                ->enum(['9:16', '16:9'])
                ->default('9:16'),
            'style' => $schema->string()
                ->description('Estilo visual (ex: realista, cartoon, cinematografico).')
                ->default('realista'),
            'imageUrl' => $schema->string()
                ->description('URL de referência (do nosso S3, ex: retornada por reachyn_upload_media) para geração i2v — imagem vira o primeiro frame.'),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsStudioController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('reachyn_generate_music')]
#[Description('Gera uma faixa de música no Reachyn (MiniMax). ASSÍNCRONO: retorna draftId na hora; use reachyn_check_media com esse draftId depois de alguns instantes pra pegar a URL final.')]
class GenerateMusicTool extends Tool
{
    use CallsStudioController;

    public function handle(Request $request): Response
    {
        if ($blocked = $this->ensureMcpAbility($request)) {
            return $blocked;
        }

        $res = $this->callStudio($request, 'studioMusic', array_filter([
            'draftId' => $request->get('draftId'),
            'prompt' => (string) $request->get('prompt', ''),
            'lyrics' => $request->get('lyrics'),
            'instrumental' => (bool) $request->get('instrumental', false),
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
                ->description('Descrição da música (estilo, mood, tema).')
                ->required(),
            'lyrics' => $schema->string()
                ->description('Letra da música (ignorada se instrumental=true).'),
            'instrumental' => $schema->boolean()
                ->description('Gerar só instrumental, sem letra.')
                ->default(false),
            'draftId' => $schema->integer()
                ->description('Rascunho existente pra anexar a faixa. Omitido cria um novo.'),
        ];
    }
}

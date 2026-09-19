<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsStudioController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('reachyn_check_media')]
#[Description('Lista a mídia gerada na galeria do Reachyn (mais recente primeiro). Use draftId pra filtrar o resultado de um reachyn_generate_video específico até a URL aparecer.')]
class CheckMediaTool extends Tool
{
    use CallsStudioController;

    public function handle(Request $request): Response
    {
        if ($blocked = $this->ensureMcpAbility($request)) {
            return $blocked;
        }

        $res = $this->callStudio($request, 'mediaList', [], [], 'GET');
        $data = (array) $res->getData(true);
        if (($data['ok'] ?? false) !== true) {
            return Response::error((string) ($data['error'] ?? 'Falha ao listar mídia.'));
        }

        $items = (array) ($data['items'] ?? []);
        $draftId = $request->get('draftId');
        if ($draftId !== null) {
            $items = array_values(array_filter($items, fn ($it) => (string) ($it['draft_id'] ?? '') === (string) $draftId));
        }

        $limit = max(1, min(50, (int) $request->get('limit', 10)));

        return Response::json(['ok' => true, 'items' => array_slice($items, 0, $limit)]);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'draftId' => $schema->integer()
                ->description('Filtra pela draftId retornada por reachyn_generate_video/reachyn_generate_image.'),
            'limit' => $schema->integer()
                ->description('Máximo de itens a retornar.')
                ->default(10),
        ];
    }
}

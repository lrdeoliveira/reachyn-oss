<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsStudioController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('reachyn_list_voices')]
#[Description('Lista as vozes de narração disponíveis (voice_id + nome) pra usar em reachyn_generate_narration, reachyn_generate_video e reachyn_dub_video.')]
class ListVoicesTool extends Tool
{
    use CallsStudioController;

    public function handle(Request $request): Response
    {
        if ($blocked = $this->ensureMcpAbility($request)) {
            return $blocked;
        }

        $res = $this->callStudio($request, 'voices', [], [], 'GET');

        return $this->toToolResponse($res);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}

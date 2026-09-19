<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsStudioController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('reachyn_clone_voice')]
#[Description('Clona uma voz a partir de uma amostra de áudio local (base64, ElevenLabs, exclusivo do plano Studio). Retorna o voice_id — use em reachyn_generate_narration/reachyn_generate_video.')]
class CloneVoiceTool extends Tool
{
    use CallsStudioController;

    public function handle(Request $request): Response
    {
        if ($blocked = $this->ensureMcpAbility($request)) {
            return $blocked;
        }

        $filename = (string) $request->get('filename', 'sample.mp3');
        $file = $this->base64ToUploadedFile((string) $request->get('file_base64', ''), $filename);
        if (! $file) {
            return Response::error('file_base64 inválido — envie o conteúdo do arquivo em base64.');
        }

        $res = $this->callStudio($request, 'voiceClone', [], ['file' => $file]);

        return $this->toToolResponse($res);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'file_base64' => $schema->string()
                ->description('Amostra de voz (m4a/mp3/wav/ogg, máx. 50MB), codificada em base64.')
                ->required(),
            'filename' => $schema->string()
                ->description('Nome original do arquivo (informativo).')
                ->default('sample.mp3'),
        ];
    }
}

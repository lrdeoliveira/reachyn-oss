<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsStudioController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('reachyn_upload_media')]
#[Description('Sobe uma imagem/vídeo/áudio local (base64) pro storage do Reachyn. Use a URL retornada como imageUrls/imageUrl em reachyn_generate_image / reachyn_generate_video pra usar como referência (i2i/i2v).')]
class UploadMediaTool extends Tool
{
    use CallsStudioController;

    public function handle(Request $request): Response
    {
        if ($blocked = $this->ensureMcpAbility($request)) {
            return $blocked;
        }

        $filename = (string) $request->get('filename', 'upload');
        $file = $this->base64ToUploadedFile((string) $request->get('file_base64', ''), $filename);
        if (! $file) {
            return Response::error('file_base64 inválido — envie o conteúdo do arquivo em base64.');
        }

        $kind = (string) $request->get('kind', 'image');
        $res = $this->callStudio($request, 'upload', ['kind' => $kind], ['file' => $file]);

        return $this->toToolResponse($res);
    }

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'file_base64' => $schema->string()
                ->description('Conteúdo do arquivo, codificado em base64.')
                ->required(),
            'filename' => $schema->string()
                ->description('Nome original do arquivo (informativo).')
                ->default('upload'),
            'kind' => $schema->string()
                ->description('Tipo de mídia — define os limites de tamanho/formato aceitos (imagem 10MB jpg/png/webp/gif, vídeo 100MB mp4/mov, áudio 50MB m4a/mp3).')
                ->enum(['image', 'video', 'audio'])
                ->default('image'),
        ];
    }
}

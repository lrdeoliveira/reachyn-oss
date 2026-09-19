<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\CallsStudioController;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('reachyn_generate_image')]
#[Description('Gera uma imagem no Reachyn (t2i, ou i2i quando imageUrls é informado) e retorna a URL no S3.')]
class GenerateImageTool extends Tool
{
    use CallsStudioController;

    public function handle(Request $request): Response
    {
        if ($blocked = $this->ensureMcpAbility($request)) {
            return $blocked;
        }

        $imageUrls = array_values(array_filter((array) $request->get('imageUrls', []), fn ($u) => is_string($u) && $u !== ''));

        $res = $this->callStudio($request, 'media', array_filter([
            'kind' => 'image',
            'prompt' => (string) $request->get('prompt', ''),
            'model' => $request->get('model'),
            // Este é o default que VALE (o do schema só informa o cliente MCP): quando o agente
            // omite `aspect`, é daqui que sai o valor enviado ao StudioController.
            'aspect' => $request->get('aspect', '9:16'),
            'style' => $request->get('style', 'realista'),
            'imageUrls' => $imageUrls !== [] ? array_slice($imageUrls, 0, 3) : null,
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
                ->description('Descrição da imagem a gerar (texto livre).')
                ->required(),
            'model' => $schema->string()
                ->description('Slug do modelo de imagem (gen_models), opcional — omitido usa o padrão t2i do plano.'),
            // Default 9:16 igual ao Estúdio: a imagem do MCP cai na MESMA galeria, então um
            // agente que não escolhe formato deve receber o mesmo que a tela entrega. Não muda
            // qualidade — no mmx, 1:1 (1536×1536) e 9:16 (1152×2048) têm os mesmos 2,36 MP.
            'aspect' => $schema->string()
                ->description('Proporção da imagem. Padrão 9:16 (vertical), o formato primário do Estúdio.')
                ->enum(['9:16', '1:1', '16:9', '4:5'])
                ->default('9:16'),
            'style' => $schema->string()
                ->description('Estilo visual (ex: realista, cartoon, 3d).')
                ->default('realista'),
            'imageUrls' => $schema->array()
                ->description('URLs de referência (do nosso S3, ex: retornadas por reachyn_upload_media) para geração i2i. Máx. 3.')
                ->items($schema->string())
                ->max(3),
        ];
    }
}

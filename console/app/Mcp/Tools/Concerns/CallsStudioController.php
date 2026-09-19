<?php

namespace App\Mcp\Tools\Concerns;

use App\Http\Controllers\Api\StudioController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;

/**
 * Tools MCP do Reachyn NÃO reimplementam quota/anti-SSRF/resolução de modelo — forwardam pro
 * StudioController já usado pelo browser (mesma auth Sanctum, mesmo tenant ativo do usuário).
 */
trait CallsStudioController
{
    /** Token precisa da ability 'mcp' (separada da 'studio' do browser, emitida via
     *  `php artisan reachyn:mcp-token`). null = autorizado; Response::error() = bloqueado. */
    private function ensureMcpAbility(McpRequest $request): ?Response
    {
        $user = $request->user();
        if (! $user || ! method_exists($user, 'tokenCan') || ! $user->tokenCan('mcp')) {
            return Response::error('Token sem permissão "mcp". Emita um com `php artisan reachyn:mcp-token {email}`.');
        }

        return null;
    }

    /** Monta um Illuminate\Http\Request equivalente ao que o front manda e chama o método do
     *  StudioController diretamente — reusa toda a validação/quota/anti-SSRF já existentes.
     *  Não passa pelo router HTTP real (chamada direta), então o path é só cosmético. */
    private function callStudio(McpRequest $mcpRequest, string $controllerMethod, array $payload, array $files = [], string $verb = 'POST'): JsonResponse
    {
        $httpRequest = HttpRequest::create('/mcp-internal/'.$controllerMethod, $verb, $payload);
        $httpRequest->setUserResolver(fn () => $mcpRequest->user());
        foreach ($files as $key => $file) {
            $httpRequest->files->set($key, $file);
        }

        return app(StudioController::class)->{$controllerMethod}($httpRequest);
    }

    /** Decodifica um arquivo base64 (upload local via MCP) num UploadedFile "de teste" — o mesmo
     *  formato que StudioController::upload()/voiceClone() esperam via $r->file(). null se inválido. */
    private function base64ToUploadedFile(string $base64, string $filename = 'upload'): ?UploadedFile
    {
        $decoded = base64_decode($base64, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }
        $tmpPath = tempnam(sys_get_temp_dir(), 'reachyn_mcp_');
        file_put_contents($tmpPath, $decoded);

        return new UploadedFile($tmpPath, $filename, null, null, true);
    }

    /** Converte o JsonResponse do controller (contrato {ok,error?,...}) numa Response MCP. */
    private function toToolResponse(JsonResponse $res): Response
    {
        $data = (array) $res->getData(true);
        if (($data['ok'] ?? false) !== true) {
            return Response::error((string) ($data['error'] ?? 'Falha na geração (HTTP '.$res->getStatusCode().').'));
        }

        return Response::json($data);
    }
}

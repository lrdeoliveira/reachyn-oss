<?php

use App\Mcp\Servers\ReachynServer;
use Laravel\Mcp\Facades\Mcp;

// MCP remoto do Reachyn (geração de imagem/vídeo via Claude CLI e outros clientes MCP).
// Token pessoal de longa duração emitido via `php artisan reachyn:mcp-token {email}`
// (ability 'mcp', separada da 'studio' do browser — ver GenerateImageTool/GenerateVideoTool/
// UploadMediaTool, que checam tokenCan('mcp') e delegam pro StudioController existente).
// AUD-002/019: mesma auth (Sanctum) + rate limit das outras rotas sensíveis do console.
// Path sob /api/*: bootstrap/app.php só renderiza erro (401/419) como JSON pra esse prefixo —
// fora dele, requisição sem sessão web vira REDIRECT html pro login do Filament (quebra o client
// MCP). Confirmado via smoke test local antes de fixar aqui.
Mcp::web('/api/mcp/reachyn', ReachynServer::class)
    ->middleware(['auth:sanctum', 'throttle:20,1,ai-routes']);

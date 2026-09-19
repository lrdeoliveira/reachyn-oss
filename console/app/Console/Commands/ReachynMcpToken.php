<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Emite um token Sanctum de longa duração (ability 'mcp', separada da 'studio' efêmera do
 * browser/SSO) pra um usuário usar o MCP remoto do Reachyn (routes/ai.php, /api/mcp/reachyn) a
 * partir do Claude CLI ou outro client MCP. CLI-only por ora — sem UI de gestão de token.
 */
class ReachynMcpToken extends Command
{
    protected $signature = 'reachyn:mcp-token {email} {--days=180}';

    protected $description = 'Emite (ou reemite) um token MCP de longa duração pro usuário indicado.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->error("Usuário não encontrado: {$email}");

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));

        // Reemitir revoga o(s) token(s) 'mcp-cli' anterior(es) do usuário — evita acumular tokens
        // esquecidos com a mesma finalidade.
        $revoked = $user->tokens()->where('name', 'mcp-cli')->delete();
        if ($revoked > 0) {
            $this->line("Revogado {$revoked} token(s) 'mcp-cli' anterior(es).");
        }

        $token = $user->createToken('mcp-cli', ['mcp'], now()->addDays($days))->plainTextToken;

        $this->info("Token MCP emitido pra {$email} (válido {$days} dias):");
        $this->line($token);
        $this->newLine();
        $this->line('Registrar no Claude CLI:');
        $this->line('claude mcp add --transport http reachyn https://app.reachyn.agency/api/mcp/reachyn --header "Authorization: Bearer '.$token.'"');

        return self::SUCCESS;
    }
}

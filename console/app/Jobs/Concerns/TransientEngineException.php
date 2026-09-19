<?php

namespace App\Jobs\Concerns;

/**
 * Erro TRANSITÓRIO do engine/provedor (5xx, timeout lógico, 200 sem URL) — vale retentar.
 * Distinta de um erro permanente (4xx: parâmetro inválido), que NÃO deve gastar retry.
 * Lançada por RetriesTransientEngineErrors::engineUrlOrRetry() para devolver o job à fila.
 */
class TransientEngineException extends \RuntimeException {}

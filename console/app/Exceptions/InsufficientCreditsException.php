<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Saldo de créditos insuficiente pra debitar uma operação. Mapeada pra HTTP 402
 * (anti denial-of-wallet — secure-baseline item 8). Carrega o necessário pro front
 * mostrar "compre mais créditos".
 */
class InsufficientCreditsException extends RuntimeException
{
    public function __construct(
        public readonly int $required,
        public readonly int $balance,
        string $message = 'Créditos insuficientes para esta operação.',
    ) {
        parent::__construct($message);
    }

    public function render($request)
    {
        return response()->json([
            'ok' => false,
            'error' => 'insufficient_credits',
            'message' => $this->getMessage(),
            'required' => $this->required,
            'balance' => $this->balance,
        ], 402);
    }
}

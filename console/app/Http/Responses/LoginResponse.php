<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as Contract;
use Filament\Facades\Filament;

// Pós-login: o painel do CLIENTE (id "app") cai direto no Studio na RAIZ (app.example.com/),
// servido na MESMA origem pelo Next.js — NÃO no painel Filament /app.
// O OPERADOR (painel "admin") segue pro painel normalmente.
class LoginResponse implements Contract
{
    // Sem type de retorno: numa ação Livewire (login do Filament), redirect() devolve
    // Livewire\Features\SupportRedirects\Redirector, não Illuminate\Http\RedirectResponse —
    // declarar : RedirectResponse causava TypeError pós-login ("This page expired").
    public function toResponse($request)
    {
        if (Filament::getCurrentPanel()?->getId() === 'app') {
            // Força o dashboard do cliente: o Filament guarda intended=/app, então
            // usamos ->to() (não ->intended()) pra não recair no painel.
            return redirect()->to('/');
        }

        // Demais painéis (admin): comportamento padrão do Filament.
        return redirect()->intended(Filament::getUrl());
    }
}

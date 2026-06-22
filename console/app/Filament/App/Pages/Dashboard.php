<?php

namespace App\Filament\App\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * O cliente NÃO usa o painel Filament — a casa dele é o Studio na raiz
 * (app.example.com/). Qualquer acesso à home do painel /app (inclusive
 * já-logado vindo de /app/login) é redirecionado pro Studio. O painel /app
 * existe só para hospedar o /app/login.
 */
class Dashboard extends BaseDashboard
{
    public function mount(): void
    {
        $this->redirect('/');
    }
}

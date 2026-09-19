<x-filament-panels::page>
    {{-- A página recarrega os dados a cada 10s (polling do Livewire). --}}
    <div wire:poll.10s>
        <x-filament::section heading="⏳ Na fila / rodando ({{ count($pending) }})" description="Jobs aguardando ou em execução nos workers (máx. 50).">
            @if (count($pending) === 0)
                <p class="text-sm text-gray-500">Fila vazia — nenhum job pendente. ✅</p>
            @else
                <div style="overflow-x:auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500">
                            <th class="py-1 pr-4">Job</th><th class="py-1 pr-4">Fila</th>
                            <th class="py-1 pr-4">Estado</th><th class="py-1 pr-4">Tentativas</th><th class="py-1">Enfileirado</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($pending as $j)
                            <tr class="border-t border-gray-800/40">
                                <td class="py-1.5 pr-4 font-medium">{{ $j['name'] }}</td>
                                <td class="py-1.5 pr-4">{{ $j['queue'] }}</td>
                                <td class="py-1.5 pr-4">{{ $j['running'] ? '▶️ rodando' : '⏳ aguardando' }}</td>
                                <td class="py-1.5 pr-4">{{ $j['attempts'] }}</td>
                                <td class="py-1.5">{{ $j['since'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="❌ Falhas ({{ count($failed) }})" description="Jobs que estouraram as tentativas (máx. 50, mais recentes primeiro)." class="mt-6">
            @if (count($failed) > 0)
                <div class="mb-3">
                    <x-filament::button color="warning" size="sm" wire:click="retryAll">🔁 Reprocessar todas</x-filament::button>
                </div>
            @endif
            @if (count($failed) === 0)
                <p class="text-sm text-gray-500">Nenhuma falha registrada. ✅</p>
            @else
                <div style="overflow-x:auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500">
                            <th class="py-1 pr-4">Job</th><th class="py-1 pr-4">Quando</th><th class="py-1 pr-4">Erro</th><th class="py-1">Ações</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($failed as $j)
                            <tr class="border-t border-gray-800/40 align-top">
                                <td class="py-1.5 pr-4 font-medium whitespace-nowrap">{{ $j['name'] }}</td>
                                <td class="py-1.5 pr-4 whitespace-nowrap">{{ $j['failed_at'] }}</td>
                                <td class="py-1.5 pr-4 text-xs text-gray-400" style="max-width:520px">{{ $j['error'] }}</td>
                                <td class="py-1.5 whitespace-nowrap">
                                    <x-filament::button color="success" size="xs" wire:click="retry('{{ $j['uuid'] }}')">🔁 Reprocessar</x-filament::button>
                                    <x-filament::button color="danger" size="xs" wire:click="forget('{{ $j['uuid'] }}')">🗑</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>

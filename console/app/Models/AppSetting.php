<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuração GLOBAL do operador (não por tenant), guardada como key => json.
 * Ex.: key='gen_lines' (principal/fallback por função de GERAÇÃO). NÃO é segredo
 * (só nomes de provedor/modelo) → json em claro, sem cifra.
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /** Lê o valor (array) de uma chave global; default se ausente. */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    /** Grava (upsert) o valor de uma chave global. */
    public static function setValue(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /** Remove uma chave global (volta aos defaults da aplicação). */
    public static function forget(string $key): void
    {
        static::query()->where('key', $key)->delete();
    }
}

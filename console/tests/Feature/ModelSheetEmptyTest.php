<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Services\ModelSheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Folha de model sheet NÃO pode ser composta quando nenhum shot vingou.
 *
 * O bug real (2026-07-21): o provedor de imagem começou a recusar por moderação
 * ("flagged as sensitive") um dachshund cartoon. Todos os shots falharam, mas o ffmpeg
 * compunha a folha assim mesmo — template certo, título certo, TODAS as células em branco —
 * e essa folha vazia era gravada como resultado bom. O personagem ficava "pronto" com um
 * model sheet inútil, e ainda saía e-mail dizendo que estava pronto.
 *
 * O front já sabia se defender (`sheets.length > 0`); quem mentia era o backend, entregando
 * uma folha que existia mas não continha nada.
 */
class ModelSheetEmptyTest extends TestCase
{
    use RefreshDatabase;

    private function group(): array
    {
        return [
            'kind' => 'poses',
            'title' => 'MEL — POSES',
            'cols' => 2,
            'shots' => [
                ['label' => 'A', 'prompt' => 'p1'],
                ['label' => 'B', 'prompt' => 'p2'],
            ],
        ];
    }

    private function mel(): Character
    {
        return new Character([
            'name' => 'Mel',
            'style' => 'cartoon',
            'lock' => 'dachshund cartoon 3d',
            'description' => 'cachorro salsicha',
            'base_url' => 'https://s3.example.com/media/mel-base.jpg',
        ]);
    }

    public function test_nao_compoe_a_folha_quando_todos_os_shots_falham(): void
    {
        Http::fake([
            '*compose-sheet*' => Http::response(['url' => 'https://s3/NAO-DEVIA-EXISTIR.jpg'], 200),
            '*' => Http::response(['error' => 'flagged as sensitive'], 502),
        ]);

        $res = $this->app->make(ModelSheetService::class)->buildSheet($this->mel(), $this->group());

        $this->assertSame('', $res['url'], 'sem nenhum shot, a folha não pode existir');
        $this->assertSame(2, $res['failed']);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'compose-sheet'));
    }

    /** Falha PARCIAL ainda compõe: a folha tem valor e as lacunas ficam visíveis. */
    public function test_compoe_normalmente_quando_ao_menos_um_shot_vinga(): void
    {
        $n = 0;
        Http::fake(function ($req) use (&$n) {
            if (str_contains($req->url(), 'compose-sheet')) {
                return Http::response(['url' => 'https://s3/folha-parcial.jpg'], 200);
            }
            // 1º shot passa, o resto falha.
            return ++$n === 1
                ? Http::response(['url' => 'https://s3/shot-1.jpg'], 200)
                : Http::response(['error' => 'flagged as sensitive'], 502);
        });

        $res = $this->app->make(ModelSheetService::class)->buildSheet($this->mel(), $this->group());

        $this->assertNotSame('', $res['url'], 'com pelo menos 1 shot a folha deve ser composta');
        Http::assertSent(fn ($req) => str_contains($req->url(), 'compose-sheet'));
    }
}

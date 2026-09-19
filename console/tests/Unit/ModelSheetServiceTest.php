<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\ModelSheetService;
use Tests\TestCase;

class ModelSheetServiceTest extends TestCase
{
    private function allPrompts(array $groups): string
    {
        $all = '';
        foreach ($groups as $g) {
            foreach ($g['shots'] ?? [] as $sh) {
                $all .= "\n".$sh['prompt'];
            }
        }

        return $all;
    }

    private function char(array $attrs): Character
    {
        return new Character(array_merge([
            'style' => 'realista',
            'base_url' => 'https://s3.example.com/media/x.jpg',
        ], $attrs));
    }

    public function test_modelsheet_prompts_start_with_identity_instructions(): void
    {
        $c = $this->char([
            'name' => 'Oliver',
            'lock' => 'A handsome young man with brown eyes',
            'description' => 'A young man',
        ]);

        $service = $this->app->make(ModelSheetService::class);
        $groups = $service->groups($c, null, 'pt-BR');

        $this->assertArrayHasKey('angles', $groups);
        $shots = $groups['angles']['shots'] ?? [];
        $this->assertNotEmpty($shots);

        // Prompt abre com o bloco de identidade (não com a vista nem o fundo) e trava HUMANO.
        $prompt = $shots[0]['prompt'];
        $this->assertStringStartsWith('The EXACT SAME character as in the reference image named "Oliver"', $prompt);
        $this->assertStringContainsString('Locked identity: A handsome young man with brown eyes', $prompt);
        $this->assertStringContainsString('HUMAN BEING', $prompt);
        $this->assertStringNotContainsString('breed', $prompt);
    }

    /** Pessoa real com "fur-trimmed coat"/"hair or fur" no lock NÃO pode virar animal (bug 2026-07-13:
     *  a blocklist ganhava de qualquer menção a fur/tail e as âncoras de quadrúpede alucinavam bicho). */
    public function test_human_with_fur_clothing_keeps_human_anchors(): void
    {
        $c = $this->char([
            'name' => 'Luciano',
            'lock' => "MANDATORY CHARACTER LOCK:\nhuman male, adult\naverage build\nhair or fur or skin: short brown hair, light skin\nsignature clothing: green fur-trimmed parka jacket",
            'description' => '',
        ]);

        $all = $this->allPrompts($this->app->make(ModelSheetService::class)->groups($c, null, 'pt-BR'));

        $this->assertStringContainsString('HUMAN BEING', $all);
        $this->assertStringContainsString('SOLES of the feet', $all);
        foreach (['HINDQUARTERS', 'paw pads', 'muzzle', 'sphinx', 'curled up'] as $animalWord) {
            $this->assertStringNotContainsString($animalWord, $all, "âncora de animal '$animalWord' em prancha de humano");
        }
    }

    public function test_explicit_animal_keeps_animal_anchors(): void
    {
        $c = $this->char([
            'name' => 'Mel',
            'lock' => 'female dog, golden retriever, cream fur',
            'description' => 'a female dog',
            'style' => 'cartoon',
        ]);

        $all = $this->allPrompts($this->app->make(ModelSheetService::class)->groups($c, null, 'pt-BR'));

        $this->assertStringContainsString('HINDQUARTERS', $all);
        $this->assertStringContainsString('paw pads', $all);
        $this->assertStringNotContainsString('HUMAN BEING', $all);
    }

    /** Vision falhou (lock/descrição vazios) → âncoras NEUTRAS: nem quadrúpede, nem trava humana.
     *  Antes o desconhecido caía nas âncoras de BICHO — foto de pessoa sem bíblia virava animal. */
    public function test_unknown_subject_uses_neutral_anchors(): void
    {
        $c = $this->char(['name' => 'X', 'lock' => '', 'description' => '']);

        $all = $this->allPrompts($this->app->make(ModelSheetService::class)->groups($c, null, 'pt-BR'));

        foreach (['HINDQUARTERS', 'paw pads', 'muzzle', 'sphinx', 'curled up', 'HUMAN BEING'] as $word) {
            $this->assertStringNotContainsString($word, $all, "sujeito desconhecido não pode ancorar '$word'");
        }
        $this->assertStringContainsString('UNDERSIDE pressed against the glass', $all);
    }

    /** bible.subject (classificação explícita da vision) MANDA sobre o texto do lock. */
    public function test_bible_subject_overrides_lock_text(): void
    {
        $c = $this->char([
            'name' => 'Rex',
            // texto enganoso: cita 'dog' antes de qualquer termo humano
            'lock' => 'dog-themed costume performer, wears a dog mascot hoodie',
            'description' => '',
        ]);

        $all = $this->allPrompts($this->app->make(ModelSheetService::class)->groups($c, ['subject' => 'human'], 'pt-BR'));

        $this->assertStringContainsString('HUMAN BEING', $all);
        $this->assertStringNotContainsString('HINDQUARTERS', $all);
        $this->assertSame('human', ModelSheetService::subjectKind($c, ['subject' => 'human']));
    }

    public function test_subject_kind_fallback_precedence(): void
    {
        // humano vence quando o termo humano vem primeiro, mesmo com fur/tail depois
        $this->assertSame('human', ModelSheetService::subjectKind(
            $this->char(['lock' => 'young woman, long hair in a ponytail, fur-trimmed boots']), null));
        // 'rabo de cavalo' (PT) não pode animalizar uma pessoa
        $this->assertSame('human', ModelSheetService::subjectKind(
            $this->char(['lock' => '', 'description' => 'mulher jovem com rabo de cavalo']), null));
        // sexo sozinho não é termo humano: 'female dog' = animal
        $this->assertSame('animal', ModelSheetService::subjectKind(
            $this->char(['lock' => 'female dog, cream fur']), null));
        // nada reconhecível → desconhecido
        $this->assertSame('', ModelSheetService::subjectKind(
            $this->char(['lock' => '', 'description' => '']), null));
    }

    /** Upload externo do turnaround: nomes com ângulo (0/45/90…/topo/base) ordenam as vistas
     *  na sequência canônica — senão a folha montava com células trocadas. */
    public function test_order_shot_uploads_sorts_turnaround_by_filename(): void
    {
        $svc = $this->app->make(ModelSheetService::class);
        $mk = fn (string $name) => \Illuminate\Http\UploadedFile::fake()->image($name, 64, 64);

        // Ordem embaralhada de propósito (como o Finder pode entregar).
        $files = [
            $mk('mel-topo.png'),
            $mk('mel-costas-180.png'),
            $mk('mel-frente-0.png'),
            $mk('mel-base.png'),
            $mk('mel-perfil-esquerdo-90.png'),
            $mk('mel-3-4-direita-315.png'),
            $mk('mel-3-4-esquerda-45.png'),
            $mk('mel-perfil-direito-270.png'),
            $mk('mel-3-4-traseira-esq-135.png'),
            $mk('mel-3-4-traseira-dir-225.png'),
        ];
        $ordered = $svc->orderShotUploads($files, 'angles');
        $names = array_map(fn ($f) => $f->getClientOriginalName(), $ordered);

        $this->assertSame([
            'mel-frente-0.png',
            'mel-3-4-esquerda-45.png',
            'mel-perfil-esquerdo-90.png',
            'mel-3-4-traseira-esq-135.png',
            'mel-costas-180.png',
            'mel-3-4-traseira-dir-225.png',
            'mel-perfil-direito-270.png',
            'mel-3-4-direita-315.png',
            'mel-topo.png',
            'mel-base.png',
        ], $names);
    }

    public function test_order_shot_uploads_keeps_order_without_angle_hints(): void
    {
        $svc = $this->app->make(ModelSheetService::class);
        $mk = fn (string $name) => \Illuminate\Http\UploadedFile::fake()->image($name, 64, 64);
        $files = [$mk('a.png'), $mk('b.png'), $mk('c.png')];
        $ordered = $svc->orderShotUploads($files, 'angles');
        $this->assertSame(['a.png', 'b.png', 'c.png'], array_map(fn ($f) => $f->getClientOriginalName(), $ordered));
    }
}

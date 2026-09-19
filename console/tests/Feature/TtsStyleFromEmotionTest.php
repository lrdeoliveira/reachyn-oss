<?php

namespace Tests\Feature;

use App\Services\AnimationFlow;
use Tests\TestCase;

/**
 * 🗣️ A direção de atuação da ficha de cena escolhe o preset de entrega da voz.
 *
 * O roteiro escreve coisas como "fascínio sussurrado, respiração contida" e "triunfo silencioso,
 * voz firme e grave" — e tudo isso virava `dramatico` (stability 0.3, style 0.6 no ElevenLabs).
 * Uma fala sussurrada saía com configuração de fala intensa; foi parte da queixa de que "as falas
 * estão ruins" no projeto 20.
 */
class TtsStyleFromEmotionTest extends TestCase
{
    /**
     * O que a direção diz sobre a VOZ vence o que ela diz sobre a ATITUDE.
     *
     * "triunfo silencioso, sorriso lento, VOZ FIRME E GRAVE" saía sussurrado porque o código lia
     * "silencioso" e parava ali — mas esse "silencioso" qualifica o triunfo (sem alarde), não a
     * entrega. Foi assim que a voz da cena 2 do projeto 20 "ficou horrível".
     */
    public function test_direcao_de_voz_vence_a_atitude(): void
    {
        $this->assertSame('dramatico', AnimationFlow::ttsStyleFor('triunfo silencioso, sorriso lento, voz firme e grave'));
        $this->assertSame('dramatico', AnimationFlow::ttsStyleFor('contido, mas com voz imponente'));
        $this->assertSame('dramatico', AnimationFlow::ttsStyleFor('calma aparente, tom ameaçador'));
    }

    /** SUSSURRO é literal — é ele que vira a audio tag [whispers] no v3. */
    public function test_sussurro_explicito_vira_sussurro(): void
    {
        foreach ([
            'fascínio sussurrado, olhos fixos, respiração contida',
            'fala baixinho, quase inaudível',
            'cochichando para não acordar ninguém',
        ] as $e) {
            $this->assertSame('sussurro', AnimationFlow::ttsStyleFor($e), "emocao «{$e}» deveria virar sussurro");
        }
    }

    /** Contenção sem sussurro: voz estável, volume normal, SEM tag. */
    public function test_emocao_contida_vira_calmo(): void
    {
        foreach (['melancolia serena', 'tom íntimo e suave', 'tristeza contida'] as $e) {
            $this->assertSame('calmo', AnimationFlow::ttsStyleFor($e), "emocao «{$e}» deveria virar calmo");
        }
    }

    public function test_emocao_intensa_vira_energetico(): void
    {
        foreach (['euforia total', 'raiva explosiva', 'pânico crescente', 'urgência desesperada'] as $e) {
            $this->assertSame('energetico', AnimationFlow::ttsStyleFor($e), "emocao «{$e}» deveria virar energetico");
        }
    }

    /** Emoção sem marcador claro mantém o dramático — o default de antes. */
    public function test_emocao_generica_continua_dramatico(): void
    {
        $this->assertSame('dramatico', AnimationFlow::ttsStyleFor('determinação, olhar fixo no portão'));
    }

    /** Sem emoção descrita, nada muda: vazio = neutro (comportamento histórico). */
    public function test_sem_emocao_fica_neutro(): void
    {
        $this->assertSame('', AnimationFlow::ttsStyleFor(''));
        $this->assertSame('', AnimationFlow::ttsStyleFor('   '));
    }
}

#!/usr/bin/env python3
"""Testes unitários das funções PURAS do ffmpeg-service (sem subir o servidor, sem ffmpeg).

Cobre o acabamento Hollywood (Sprint B): color grade, grain, letterbox, finish filters,
loudnorm — e os construtores compartilhados (sub_style, tts_voice_settings, brand lexicon).
Rodar: python3 -m unittest test_server -v  (na pasta media/ffmpeg-service)
"""
import unittest

import server


class TestGradeFilters(unittest.TestCase):
    def test_natural_e_desconhecido_sao_vazios(self):
        # "natural" = sem filtro (fast-path -c:v copy); chave desconhecida NUNCA injeta filtro
        # arbitrário (anti-injection: o valor vem de allowlist no console, mas defesa em camada).
        self.assertEqual(server.build_grade_filter({"grade": "natural"}), "")
        self.assertEqual(server.build_grade_filter({}), "")
        self.assertEqual(server.build_grade_filter({"grade": "hax;drawtext=..."}), "")

    def test_presets_conhecidos(self):
        for key in ("cinema_quente", "teal_orange", "noir", "vintage"):
            f = server.build_grade_filter({"grade": key})
            self.assertTrue(f, f"preset {key} deveria gerar filtro")
            self.assertIn(f, server.GRADE_FILTERS.values())

    def test_case_insensitive(self):
        self.assertEqual(
            server.build_grade_filter({"grade": "NoIr"}),
            server.GRADE_FILTERS["noir"],
        )


class TestGrainFilter(unittest.TestCase):
    def test_liga_desliga(self):
        self.assertEqual(server.build_grain_filter({}), "")
        self.assertEqual(server.build_grain_filter({"grain": False}), "")
        self.assertTrue(server.build_grain_filter({"grain": True}).startswith("noise="))


class TestLetterboxFilter(unittest.TestCase):
    def test_so_em_16x9(self):
        # 9:16 (vertical): out_w <= out_h → sem letterbox mesmo ligado.
        self.assertEqual(server.build_letterbox_filter({"letterbox": True}, 1080, 1920), "")
        # desligado → vazio.
        self.assertEqual(server.build_letterbox_filter({}, 1920, 1080), "")

    def test_16x9_gera_crop_pad_com_altura_par(self):
        f = server.build_letterbox_filter({"letterbox": True}, 1920, 1080)
        self.assertIn("crop=1920:", f)
        self.assertIn("pad=1920:1080:", f)
        # altura das barras: 1920/2.39 ≈ 803 → arredonda pra PAR (libx264 exige) = 802.
        bar_h = int(f.split("crop=1920:")[1].split(":")[0])
        self.assertEqual(bar_h % 2, 0, "altura do crop deve ser PAR (requisito libx264)")
        self.assertLess(bar_h, 1080)


class TestFinishFilters(unittest.TestCase):
    def test_tudo_desligado_e_vazio(self):
        # lista vazia = fast-path (não força re-encode) — o contrato mais importante do Sprint B.
        self.assertEqual(server.build_finish_filters({}, 1920, 1080), [])
        self.assertEqual(server.build_finish_filters({"grade": "natural"}, 1920, 1080), [])

    def test_ordem_grade_letterbox_grain(self):
        body = {"grade": "noir", "letterbox": True, "grain": True}
        parts = server.build_finish_filters(body, 1920, 1080)
        self.assertEqual(len(parts), 3)
        self.assertEqual(parts[0], server.GRADE_FILTERS["noir"])
        self.assertIn("pad=", parts[1])
        self.assertTrue(parts[2].startswith("noise="))


class TestLoudnorm(unittest.TestCase):
    def test_padrao_de_plataforma(self):
        # -14 LUFS / TP -1.5 é o padrão das redes — se alguém mudar, quebra aqui de propósito.
        self.assertEqual(server.LOUDNORM, "loudnorm=I=-14:TP=-1.5:LRA=11")


class TestSubStyle(unittest.TestCase):
    def test_default_look_historico(self):
        s = server.build_sub_style({})
        self.assertIn("Fontname=Liberation Sans", s)
        self.assertIn("FontSize=20", s)
        self.assertIn("Alignment=2", s)  # bottom

    def test_allowlist_de_fonte_e_clamps(self):
        # fonte fora da allowlist cai em Liberation Sans (anti-injection no force_style ASS);
        # tamanho/borda são clampados.
        s = server.build_sub_style({
            "subtitle_font": "Comic Sans'; DROP", "subtitle_size": 999, "subtitle_border": 99,
            "subtitle_pos": "top",
        })
        self.assertIn("Fontname=Liberation Sans", s)
        self.assertIn("FontSize=72", s)   # clamp 10..72
        self.assertIn("Outline=12", s)    # clamp 0..12
        self.assertIn("Alignment=8", s)   # top

    def test_cor_invalida_cai_no_fallback(self):
        s = server.build_sub_style({"subtitle_color": "not-a-hex"})
        self.assertIn("PrimaryColour=&H00FFFFFF", s)  # branco default


class TestTtsStyles(unittest.TestCase):
    def test_desconhecido_cai_no_neutro(self):
        self.assertEqual(server.tts_voice_settings("qualquer"), server.TTS_STYLE_SETTINGS["neutro"])
        self.assertEqual(server.tts_voice_settings(None), server.TTS_STYLE_SETTINGS["neutro"])

    def test_locutor_existe(self):
        # preset do comercial (Filme) — stability/similarity/style calibrados.
        self.assertIn("locutor", server.TTS_STYLE_SETTINGS)


class TestBrandLexicon(unittest.TestCase):
    def test_ida_e_volta(self):
        # TTS fala a grafia fonética; a legenda mostra a grafia de exibição.
        self.assertEqual(server.brand_say("Nexusyn é ótimo"), "Nexussyn é ótimo")
        self.assertEqual(server.brand_caption("NEXUSSYN É ÓTIMO"), "NEXUSYN É ÓTIMO")


class TestCameraVf(unittest.TestCase):
    """Câmera programada (/camclip): movimento por zoompan, sem IA de vídeo."""

    def test_movimento_nao_suportado_devolve_none(self):
        # orbit/crane_up/handheld/dolly precisam de paralaxe ou 3D real — o caller cai no i2v.
        for mv in ("orbit", "crane_up", "handheld", "dolly", "", "hax;drawtext=x"):
            self.assertIsNone(server.build_camera_vf(mv, 1080, 1920, 120, 24), f"{mv} não é 2D")

    def test_todos_os_suportados_geram_filtro(self):
        for mv in server.CAM_MOVES:
            vf = server.build_camera_vf(mv, 1080, 1920, 120, 24)
            self.assertTrue(vf and "zoompan=" in vf, f"{mv} deveria gerar zoompan")
            # o upscale grande vem ANTES do zoompan — é o que mata o jitter do filtro
            self.assertLess(vf.index("scale=%d:-2" % (1080 * 4)), vf.index("zoompan="))
            self.assertTrue(vf.endswith("setsar=1"), "SAR fixo: o concat -c copy exige stream homogêneo")

    def test_vocabulario_igual_ao_engine(self):
        # As keys espelham moveDirectives (engine/internal/content/spec.go). Vocabulário paralelo
        # vira key órfã e some em silêncio — se mudar lá, este teste tem que mudar junto.
        self.assertEqual(
            set(server.CAM_MOVES),
            {"static", "push_in", "pull_out", "pan_left", "pan_right", "tilt_up", "tilt_down"},
        )

    def test_direcoes_sao_opostas(self):
        # pan_left/pan_right e tilt_up/tilt_down só diferem pela inversão do progresso (1-e).
        for a, b in (("pan_left", "pan_right"), ("tilt_up", "tilt_down")):
            va = server.build_camera_vf(a, 1080, 1920, 120, 24)
            vb = server.build_camera_vf(b, 1080, 1920, 120, 24)
            self.assertNotEqual(va, vb, f"{a} e {b} não podem gerar o mesmo filtro")
            self.assertIn("(1-", va)      # o invertido carrega o (1-e)
            self.assertNotIn("(1-", vb)

    def test_zoom_cresce_no_push_e_cai_no_pull(self):
        self.assertIn("1+0.12*", server.build_camera_vf("push_in", 1080, 1920, 120, 24))
        self.assertIn("1.12-0.12*", server.build_camera_vf("pull_out", 1080, 1920, 120, 24))
        # static tem micro-zoom: sem ele o plano lê como foto parada
        self.assertIn("1+0.03*", server.build_camera_vf("static", 1080, 1920, 120, 24))

    def test_frames_minimo_nao_divide_por_zero(self):
        for n in (0, 1, 2):
            self.assertTrue(server.build_camera_vf("push_in", 1080, 1920, n, 24))


class TestFilmSceneScripts(unittest.TestCase):
    """Locução POR CENA no Filme (`scripts`). O console manda esse array desde 2026-07-26 e ele
    era DESCARTADO (nem o engine nem o serviço tinham o campo) — a narração virava um script
    corrido no segundo 0. Estes testes travam o contrato do campo e a COMPATIBILIDADE."""

    def test_sem_scripts_cai_no_script_unico(self):
        # Contrato de compatibilidade: sem `scripts`, None → o caminho antigo (script único).
        self.assertIsNone(server.film_scene_scripts({}, 3))
        self.assertIsNone(server.film_scene_scripts({"script": "roteiro inteiro"}, 3))
        self.assertIsNone(server.film_scene_scripts({"scripts": None}, 3))
        self.assertIsNone(server.film_scene_scripts({"scripts": "nao e lista"}, 3))
        self.assertIsNone(server.film_scene_scripts({"scripts": []}, 3))
        # lista só com vazios/brancos = nada a falar por cena → fallback, sem regressão.
        self.assertIsNone(server.film_scene_scripts({"scripts": ["", "   ", None]}, 3))

    def test_alinha_ao_numero_de_clipes(self):
        # menor que os clipes → cenas finais mudas (mesma política do `if i < len(beats)`)
        self.assertEqual(server.film_scene_scripts({"scripts": ["a"]}, 3), ["a", "", ""])
        # maior → sobra ignorada (mesma política do `sfx_list[:len(segs)]`)
        self.assertEqual(server.film_scene_scripts({"scripts": ["a", "b", "c", "d"]}, 2), ["a", "b"])
        # item vazio no meio = cena SEM fala, preservada na posição (não colapsa a lista)
        self.assertEqual(server.film_scene_scripts({"scripts": ["a", "  ", "c"]}, 3), ["a", "", "c"])
        # tipos estranhos viram "" em vez de explodir
        self.assertEqual(server.film_scene_scripts({"scripts": [1, "b"]}, 2), ["", "b"])


class TestSceneOffsets(unittest.TestCase):
    def test_soma_simples_sem_transicao(self):
        self.assertEqual(server.scene_offsets([4.0, 3.0, 5.0], None, 0.5), [0.0, 4.0, 7.0])

    def test_desconta_overlap_dos_xfades(self):
        # cada xfade encurta o filme em tr_dur → os trechos seguintes começam antes.
        offs = server.scene_offsets([4.0, 4.0, 4.0], ["zoomin", "zoomin"], 0.5)
        self.assertAlmostEqual(offs[0], 0.0)
        self.assertAlmostEqual(offs[1], 3.5)
        self.assertAlmostEqual(offs[2], 7.0)

    def test_fade_duracao_preservada_nao_desconta(self):
        # fadeblack é kind "seg" (não encurta) → offsets iguais aos do corte seco.
        self.assertEqual(server.scene_offsets([4.0, 4.0], ["fadeblack"], 0.5), [0.0, 4.0])


class TestAnchorVoiceStarts(unittest.TestCase):
    def test_ancora_no_inicio_da_cena(self):
        # o ponto do bug: cada fala começa junto com a SUA cena, não empilhada no segundo 0.
        self.assertEqual(server.anchor_voice_starts([0.0, 5.0, 10.0], [2.0, 2.0, 2.0]), [0.0, 5.0, 10.0])

    def test_cena_sem_fala_vira_none(self):
        starts = server.anchor_voice_starts([0.0, 5.0, 10.0], [2.0, 0.0, 2.0])
        self.assertEqual(starts, [0.0, None, 10.0])

    def test_fala_mais_longa_vaza_e_empurra_a_proxima(self):
        # política: não corta a fala nem estica o clipe (durações do filme são intocáveis);
        # a fala vaza pro trecho seguinte e a próxima é empurrada pra não haver duas vozes juntas.
        starts = server.anchor_voice_starts([0.0, 5.0], [8.0, 2.0], gap=0.15)
        self.assertEqual(starts[0], 0.0)
        self.assertAlmostEqual(starts[1], 8.15)

    def test_starts_sao_monotonos(self):
        starts = server.anchor_voice_starts([0.0, 2.0, 4.0, 6.0], [6.0, 3.0, 1.0, 1.0])
        vivos = [s for s in starts if s is not None]
        self.assertEqual(vivos, sorted(vivos))


class TestSubAnim(unittest.TestCase):
    """Legenda ANIMADA (pop/karaoke/bounce). Extraída do /shortform pra o /concat-clips (Filme)
    reusar: até 2026-08-01 o Filme recebia subtitle_anim do console e o ignorava — a legenda
    animada era um controle decorativo lá."""

    def test_preset_fora_da_allowlist_vira_queimada(self):
        for v in (None, "", "  ", "explode", "POP; drop", 7):
            self.assertEqual(server.parse_sub_anim({"subtitle_anim": v}), "")

    def test_presets_validos_case_insensitive(self):
        for v in ("pop", "KARAOKE", " Bounce "):
            self.assertEqual(server.parse_sub_anim({"subtitle_anim": v}), v.strip().lower())

    def test_body_sem_o_campo_e_queimada(self):
        self.assertEqual(server.parse_sub_anim({}), "")

    def test_estilo_default_do_filme(self):
        st = server.build_sub_anim_style({})
        self.assertEqual(st["pos"], "bottom")
        self.assertEqual(st["size"], 20)
        self.assertEqual(st["border"], 3)
        self.assertEqual(st["color"], "#FFFFFF")
        self.assertEqual(st["accentColor"], "#FFD700")  # realce dourado quando não escolhem
        self.assertEqual(st["bgOpacity"], 60)
        self.assertFalse(st["bg"])

    def test_estilo_respeita_escolhas_e_clampa(self):
        st = server.build_sub_anim_style({
            "subtitle_pos": "top", "subtitle_size": 999, "subtitle_border": -4,
            "subtitle_color": "#112233", "subtitle_accent_color": "#FF00AA",
            "subtitle_opacity": 500, "subtitle_font": "MONO", "subtitle_bg": True,
            "subtitle_bg_opacity": 10,
        })
        self.assertEqual(st["pos"], "top")
        self.assertEqual(st["size"], 72)      # clamp 10..72
        self.assertEqual(st["border"], 0)     # clamp 0..12
        self.assertEqual(st["opacity"], 90)   # clamp 0..90
        self.assertEqual(st["color"], "#112233")
        self.assertEqual(st["accentColor"], "#FF00AA")
        self.assertEqual(st["font"], "mono")
        self.assertTrue(st["bg"])
        self.assertEqual(st["bgOpacity"], 10)

    def test_cor_invalida_cai_no_default(self):
        st = server.build_sub_anim_style({"subtitle_color": "vermelho", "subtitle_accent_color": "#GGG"})
        self.assertEqual(st["color"], "#FFFFFF")
        self.assertEqual(st["accentColor"], "#FFD700")

    def test_posicao_desconhecida_vira_bottom(self):
        self.assertEqual(server.build_sub_anim_style({"subtitle_pos": "diagonal"})["pos"], "bottom")


class TestShotCuts(unittest.TestCase):
    """✂️ Recortes de montagem — o ritmo de corte dentro da cena (ver build_shot_cuts)."""

    def test_desligado_por_padrao(self):
        # shot_secs ausente/0 ⇒ plano único: toda peça que não pediu ritmo fica idêntica.
        self.assertEqual(server.build_shot_cuts(6.0, 0, 1080, 1920), "")
        self.assertEqual(server.build_shot_cuts(6.0, None, 1080, 1920), "")
        self.assertEqual(server.build_shot_cuts(0, 2.2, 1080, 1920), "")

    def test_cena_curta_nao_recorta(self):
        # 2,5s com alvo 2,2 daria 1 plano; e nada pode virar plano abaixo de SHOT_MIN_SECS,
        # senão o "corte" lê como falha de player, não como montagem.
        self.assertEqual(server.build_shot_cuts(2.5, 2.2, 1080, 1920), "")
        self.assertEqual(server.build_shot_cuts(2.0, 0.5, 1080, 1920), "")

    def test_arredonda_para_o_alvo(self):
        # 6s / 2,2s = 2,7 → 3 planos de 2s. Truncar daria 2 de 3s, que é o slideshow a evitar.
        self.assertIn("concat=n=3:v=1:a=0", server.build_shot_cuts(6.0, 2.2, 1080, 1920))
        self.assertIn("split=3", server.build_shot_cuts(6.0, 2.2, 1080, 1920))

    def test_teto_de_planos(self):
        # Cena longa não vira estroboscópio: o número de planos para no teto.
        self.assertIn("concat=n=%d:v=1:a=0" % server.SHOT_MAX,
                      server.build_shot_cuts(60.0, 2.2, 1080, 1920))

    def test_planos_cobrem_a_cena_inteira_sem_repetir(self):
        # INVARIANTE CRÍTICA: os trims são contíguos e somam D. Se sobrepusessem, o corte
        # rebobinaria a ação; se deixassem buraco, a cena encurtaria e a narração (que é POR
        # cena) sairia de sincronia com o vídeo daí pra frente.
        import re
        janelas = [(float(a), float(b)) for a, b in
                   re.findall(r"trim=([\d.]+):([\d.]+)", server.build_shot_cuts(6.0, 2.2, 1080, 1920))]
        self.assertEqual(janelas[0][0], 0.0)
        self.assertAlmostEqual(janelas[-1][1], 6.0, places=2)
        for (_, fim), (ini, _) in zip(janelas, janelas[1:]):
            self.assertAlmostEqual(fim, ini, places=3)

    def test_enquadramentos_sao_diferentes(self):
        # Se todos os planos tivessem o mesmo crop, o "corte" seria invisível — o recorte só
        # existe porque o enquadramento muda de um plano pro outro.
        import re
        crops = re.findall(r"crop=\d+:\d+:\d+:\d+", server.build_shot_cuts(6.0, 2.2, 1080, 1920))
        self.assertEqual(len(crops), len(set(crops)))

    def test_dimensoes_pares(self):
        # libx264/yuv420p exige largura e altura pares; ímpar aborta a montagem inteira.
        import re
        for w, h in re.findall(r"scale=(\d+):(\d+)", server.build_shot_cuts(9.0, 2.2, 1080, 1920)):
            self.assertEqual(int(w) % 2, 0)
            self.assertEqual(int(h) % 2, 0)

    def test_crop_nunca_sai_do_quadro(self):
        # Offset maior que a sobra ampliada geraria "Invalid argument" no ffmpeg.
        import re
        for w, h, cw, ch, x, y in re.findall(
                r"scale=(\d+):(\d+),crop=(\d+):(\d+):(\d+):(\d+)",
                server.build_shot_cuts(9.0, 2.2, 1080, 1920)):
            self.assertLessEqual(int(x) + int(cw), int(w))
            self.assertLessEqual(int(y) + int(ch), int(h))


class TestHeadTrim(unittest.TestCase):
    """✂️ Cabeça descartada do clipe i2v — ver head_trim_secs / voxHeadTrim no engine.

    O clipe abre com a imagem-base PARADA (o motor leva um instante pra engatar o movimento). Num
    explicativo cortado a cada 2s isso é imagem congelada em toda entrada de plano.
    """

    def test_desligado_por_padrao(self):
        # Ausente/0/negativo ⇒ 0: toda peça que não pediu trim continua idêntica, byte a byte.
        for v in (None, 0, 0.0, -1, -0.5, "", "lixo", [], {}):
            self.assertEqual(server.head_trim_secs(v), 0.0, "valor %r deveria desligar o trim" % (v,))

    def test_valor_valido_passa(self):
        self.assertAlmostEqual(server.head_trim_secs(0.35), 0.35)
        self.assertAlmostEqual(server.head_trim_secs("0.35"), 0.35)

    def test_teto(self):
        # Descartar mais de 1s já come conteúdo narrado, não só a parada inicial.
        self.assertEqual(server.head_trim_secs(5.0), server.HEAD_TRIM_MAX)
        self.assertLessEqual(server.HEAD_TRIM_MAX, 1.0)




class TestCutSting(unittest.TestCase):
    """🎬 Cut sting — a tracking transition do Vox nas bordas dos segmentos (ver cut_sting_vf)."""

    def test_desligado_por_padrao_e_casos_sem_efeito(self):
        # Sem corte de nenhum lado, ou segmento curto demais ⇒ "" (comportamento histórico).
        self.assertEqual(server.cut_sting_vf(6.0, 1080, 1920, 30, False, False), "")
        self.assertEqual(server.cut_sting_vf(0.8, 1080, 1920, 30, True, True), "")
        self.assertEqual(server.cut_sting_vf(None, 1080, 1920, 30, True, True), "")
        self.assertEqual(server.cut_sting_vf("lixo", 1080, 1920, 30, True, True), "")

    def test_bordas_certas(self):
        # Primeiro segmento: só a SAÍDA tem sting (não há corte antes dele).
        so_saida = server.cut_sting_vf(6.0, 1080, 1920, 30, False, True)
        self.assertIn("it-5.850", so_saida)          # rampa começa em D-0.15
        self.assertNotIn("1-it/", so_saida)          # sem rampa de entrada
        # Último segmento: só a ENTRADA.
        so_entrada = server.cut_sting_vf(6.0, 1080, 1920, 30, True, False)
        self.assertIn("1-it/", so_entrada)
        self.assertNotIn("it-5.850", so_entrada)

    def test_forma_do_filtro(self):
        vf = server.cut_sting_vf(6.0, 1080, 1920, 30, True, True)
        # crop por expressão + volta pro tamanho de saída + SAR fixo (concat -c copy exige) + blur binário.
        # zoompan por frame (crop nao aceita t em w/h) com upscale antes (mata o jitter),
        # saida no tamanho certo e SAR fixo (concat -c copy exige) + blur binario.
        self.assertIn("zoompan=z='1+", vf)
        self.assertLess(vf.index("scale=2160:-2"), vf.index("zoompan="))
        self.assertIn("s=1080x1920", vf)
        self.assertIn("setsar=1", vf)
        self.assertIn("gblur=sigma=5:enable=", vf)
        # duração NUNCA muda: o sting não pode ter trim/tpad/setpts.
        for proibido in ("trim=", "tpad=", "setpts="):
            self.assertNotIn(proibido, vf)


class TestSubAnimVox(unittest.TestCase):
    def test_vox_na_allowlist(self):
        # O preset da tipografia do formato passa; lixo continua caindo na legenda queimada.
        self.assertEqual(server.parse_sub_anim({"subtitle_anim": "vox"}), "vox")
        self.assertEqual(server.parse_sub_anim({"subtitle_anim": "hax"}), "")



class TestAlignmentWords(unittest.TestCase):
    """⏱️ Alignment por caractere → palavras — o relógio que o /tts?timestamps devolve."""

    def test_converte_e_preserva_texto(self):
        al = {"characters": list("ola bom dia"),
              "character_start_times_seconds": [0.0,0.1,0.2,0.3,0.4,0.5,0.6,0.7,0.8,0.9,1.0],
              "character_end_times_seconds":   [0.1,0.2,0.3,0.4,0.5,0.6,0.7,0.8,0.9,1.0,1.1]}
        w = server.alignment_words(al)
        self.assertEqual([x["word"] for x in w], ["ola","bom","dia"])
        # INVARIANTE: início da palavra = início do 1º caractere; fim = fim do último.
        self.assertEqual(w[0]["start"], 0.0); self.assertEqual(w[0]["end"], 0.3)
        self.assertEqual(w[2]["start"], 0.8); self.assertEqual(w[2]["end"], 1.1)
        # tempos monotônicos: palavra seguinte nunca começa antes da anterior terminar de começar
        for a, b in zip(w, w[1:]):
            self.assertLessEqual(a["start"], b["start"])

    def test_vazio_e_lixo_nao_explodem(self):
        self.assertEqual(server.alignment_words(None), [])
        self.assertEqual(server.alignment_words({}), [])
        self.assertEqual(server.alignment_words({"characters": ["a"]}), [{"word":"a","start":0.0,"end":0.0}])

if __name__ == "__main__":
    unittest.main()

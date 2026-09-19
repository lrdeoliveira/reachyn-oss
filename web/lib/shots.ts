// 🎬 Vocabulário de câmera e luz por cena — eixo INDEPENDENTE do estilo visual.
// Extraído de components/Story.tsx na F5 (remoção da tela clássica): as constantes são
// dados puros, sem React, e seguem em uso pelo wizard (Animacao.tsx) nos selects da cena.

// 🎬 Planos de câmera (enquadramento/composição) — eixo INDEPENDENTE do estilo visual; o texto
// (EN) é prependado ao prompt da cena antes do estilo. "" = nenhum (deixa livre).
export const SHOT_OPTIONS: [string, string, string][] = [
  ["medio", "🎥 Plano médio", "Medium shot:"],
  ["americano", "🎥 Plano americano", "American shot (cowboy shot, from mid-thigh up):"],
  ["closeup", "🎥 Close-up", "Close-up shot on the face:"],
  ["perfil", "🎥 Plano perfil", "Profile shot (side view):"],
  ["contraplongee", "🎥 Contra-plongée", "Low-angle shot (contra-plongée, camera looking up, emphasizing power and grandeur):"],
  ["plongee", "🎥 Plongée", "High-angle shot (plongée, camera looking down):"],
  ["overshoulder", "🎥 Over shoulder", "Over-the-shoulder shot:"],
  ["panoramico", "🎥 Plano panorâmico", "Wide establishing shot of the environment, subject small in the frame:"],
  ["heroshot", "🎥 Hero shot", "Hero shot, subject centered in an epic, powerful pose:"],
  ["overshoulder_aberto", "🎥 Over shoulder aberto", "Wide over-the-shoulder shot, subject small, environment dominating the frame:"],
  ["overshoulder_fechado", "🎥 Over shoulder fechado", "Tight over-the-shoulder shot, subject's back and head filling most of the frame:"],
  ["zenital", "🎥 Plano zenital", "Zenithal shot (straight top-down bird's-eye view):"],
  ["extreme_closeup", "🎥 Extreme close-up", "Extreme close-up, tightly framed on the eyes:"],
  ["holandes", "🎥 Plano holandês", "Dutch angle shot (tilted, diagonal horizon):"],
  ["aberto_final", "🎥 Plano aberto (final)", "Wide cinematic establishing shot, epic final wide frame:"],
];

// MOVE_OPTIONS — movimento de CÂMERA por cena: [key, rótulo PT, frase EN]. Espelha
// `moveDirectives` do engine (internal/content/spec.go) NA MESMA ORDEM de `moveKeysList`, com as
// frases EN idênticas às de lá.
//
// Por que a frase vem duplicada aqui: o engine só converte key→frase no PARSE do roteiro
// (`normalizeParsed`, animation.go). Depois disso o campo autoritativo é `video_prompt`, editável
// pelo usuário — então o seletor precisa ESCREVER a frase no campo, não só guardar a key (senão o
// usuário mexe no select e nada muda: bug silencioso).
//
// Ao adicionar um movimento: mexer PRIMEIRO em spec.go e refletir aqui, nunca o contrário — key
// que não existe lá é ignorada em silêncio no reparse.
// Só descreve a câmera (nunca o sujeito), por isso é seguro no i2v: não redesenha o personagem.
export const MOVE_OPTIONS: [string, string, string][] = [
  ["", "🎬 movimento…", ""],
  // ⚠️ Câmera parada ≠ cena parada. O texto antigo ("only the scene breathes") congelava o clipe:
  // medido no projeto 20, as cenas com este preset ficaram em 0,09–0,51 de movimento, contra 3,3
  // da cena com push-in. Trava a CÂMERA e pede que o sujeito e o ambiente sigam vivos no quadro.
  // Espelha `moveDirectives["static"]` do engine (spec.go) — as duas frases têm de ser idênticas.
  ["static", "🔒 Câmera fixa", "The camera is locked off on a tripod and does not move, pan or zoom; within that fixed frame the subject keeps moving and acting naturally, and the environment stays alive (fabric, hair, dust and light shifting)."],
  ["push_in", "➡️ Aproxima (push-in)", "Slow push-in: the camera glides forward toward the subject at a calm pace."],
  ["pull_out", "⬅️ Afasta (pull-out)", "Slow pull-out: the camera glides backward, gradually revealing the surroundings."],
  ["pan_left", "↔️ Giro à esquerda", "The camera pans left in one smooth continuous move."],
  ["pan_right", "↔️ Giro à direita", "The camera pans right in one smooth continuous move."],
  ["tilt_up", "↕️ Inclina pra cima", "The camera tilts up smoothly, lifting the gaze."],
  ["tilt_down", "↕️ Inclina pra baixo", "The camera tilts down smoothly."],
  ["orbit", "🔄 Orbita o sujeito", "The camera orbits around the subject in a smooth arc."],
  ["crane_up", "🏗️ Guindaste (sobe e abre)", "The camera cranes up and back, opening up the frame."],
  ["handheld", "🤝 Câmera na mão", "Subtle handheld motion, organic and alive, a gentle drift."],
  ["dolly", "🛤️ Travelling lateral", "Lateral dolly move across the scene at a calm, steady pace."],
];

// CAM_MOVE_KEYS — o subconjunto que a CÂMERA PROGRAMADA reproduz sem IA (ffmpeg /camclip, via
// zoompan). Espelha CAM_MOVES do media/ffmpeg-service/server.py. Os 4 que faltam (orbit, crane_up,
// handheld, dolly) precisam de paralaxe ou 3D real e seguem no i2v pago.
export const CAM_MOVE_KEYS: string[] = [
  "static", "push_in", "pull_out", "pan_left", "pan_right", "tilt_up", "tilt_down",
];

// LIGHT_OPTIONS — iluminação por cena (key, rótulo). Mesmo vocabulário do engine (lightDirectives).
export const LIGHT_OPTIONS: [string, string][] = [
  ["", "— sem escolha —"],
  ["low_key", "🕯️ Low-key / Chiaroscuro"],
  ["rim", "🌗 Contraluz / Rim"],
  ["volumetric", "🌫️ Volumétrica / Névoa"],
  ["golden", "🌅 Golden hour"],
  ["blue_hour", "🌆 Blue hour"],
  ["neon", "🌃 Neon noturno"],
  ["hard_sun", "☀️ Sol duro"],
  ["soft", "💡 Suave (softbox)"],
  ["firelight", "🔥 Fogo / vela"],
  ["overcast", "☁️ Difusa (nublado)"],
];

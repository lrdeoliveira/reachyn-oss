"use client";

// ➕ NOVO POST — o compositor da aba Publicar (substitui a antiga aba /postar, 2026-08-05).
//
// DIREÇÃO DE PRODUTO: quem SOBE a própria mídia já a aprovou por definição — mandá-la pra fila
// do Aprovar (como o commit 18a53d2 fazia) era burocracia sem revisor. Aqui o fluxo volta a
// publicar DIRETO (createBlank → upload/adopt → texto por rede → /api/studio/submit, o caminho
// que sempre funcionou), e a aba "Subir e publicar" morre por redundância.
//
// O que este compositor tem a mais que o /postar antigo: a legenda deixa de ser UM texto pra
// todas as redes — o prompt gera o texto POR REDE selecionada (mesmo POST /api/studio/text da
// aba Conteúdo), cada um editável e regenerável sozinho. O texto publicado em cada rede é o
// texto DAQUELA rede, como no fluxo completo.
//
// A fila do Aprovar continua existindo pras peças GERADAS (o publishApproval com meta.platforms
// + reddit do 18a53d2 fica) — o que morre é só a ida forçada do upload manual pra lá.

import { sfetch } from "@/lib/api";
import { useEffect, useRef, useState } from "react";
import { UploadCloud } from "lucide-react";
import { GalleryPicker } from "@/components/GalleryPicker";

type Account = { id: string; platform: string; name: string };
// PERFIL = 1 profile do conector social (um conjunto de contas conectadas). Um tenant tem N perfis e o
// submit já aceita `profiles` desde sempre — este compositor é que lia só o campo `accounts`
// (achatado, RETROCOMPAT, contas do perfil PADRÃO) e por isso publicava sempre no padrão, sem
// oferecer escolha. O Estúdio já tinha o seletor; aqui faltava ligar.
type Profile = { id: string; name: string; is_default: boolean; accounts: Account[] };

const PLATFORM_LABEL: Record<string, string> = {
  linkedin: "LinkedIn", instagram: "Instagram", facebook: "Facebook",
  x: "X / Twitter", twitter: "X / Twitter", youtube: "YouTube", threads: "Threads", tiktok: "TikTok",
  pinterest: "Pinterest", reddit: "Reddit", bluesky: "Bluesky", googlebusiness: "Google Business", blog: "Blog",
};

export function NovoPost({ onClose }: { onClose?: () => void }) {
  const [accounts, setAccounts] = useState<Account[]>([]);
  const [profiles, setProfiles] = useState<Profile[]>([]);
  // Perfis marcados. Default = o padrão (ou o primeiro), que é exatamente o que acontecia antes
  // deste seletor existir — ninguém muda de comportamento sem mexer no controle.
  const [selectedProfiles, setSelectedProfiles] = useState<string[]>([]);
  // TÍTULO nomeia a peça — é o que aparece nas listas (Publicações e Galeria exibem a keyword
  // do rascunho, e o Draft não tem coluna de título própria). O PROMPT é o assunto de que a IA
  // escreve a legenda (o /v1/text usa a keyword como tema e não aceita prompt avulso).
  // MAPEAMENTO: keyword = "Título — Prompt" (título na frente = identificador visível; prompt
  // junto = o assunto chega ao redator). Sem título, keyword = prompt. Nada escondido: o que a
  // lista mostra é exatamente o que a IA recebeu.
  const [title, setTitle] = useState("");
  const [prompt, setPrompt] = useState("");
  const [file, setFile] = useState<File | null>(null);
  // Mídia escolhida da GALERIA: fica como URL (o arquivo já é nosso), nunca como File. Baixar pro
  // navegador só pra reenviar era desperdício e esbarrava no CORS do storage.
  const [galeriaUrl, setGaleriaUrl] = useState("");
  const [galeriaKind, setGaleriaKind] = useState<"image" | "video">("image");
  const [preview, setPreview] = useState("");
  // ✍️ TEXTO POR REDE (o upgrade sobre o /postar antigo, que tinha uma legenda só): cada rede
  // selecionada ganha o seu texto — gerado pela IA, editável e regenerável individualmente.
  const [texts, setTexts] = useState<Record<string, string>>({});
  const [selected, setSelected] = useState<string[]>([]);
  const [redditSubreddit, setRedditSubreddit] = useState("");
  // r/ = comunidade · u/ = perfil do próprio usuário (mesmo contrato do Estúdio).
  const [redditAlvo, setRedditAlvo] = useState<"r" | "u">("r");
  // No Reddit um post é de um tipo só: imagem no feed (texto vira só o título) ou texto completo
  // (imagem vira link). Default = imagem, o comportamento histórico.
  const [redditFormato, setRedditFormato] = useState<"texto" | "imagem">("imagem");
  const [redditTitulo, setRedditTitulo] = useState("");
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState("");
  const [needsPub, setNeedsPub] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const draftRef = useRef<string | null>(null); // rascunho criado (memoizado) — reusado por gerar textos + publicar
  const [genBusy, setGenBusy] = useState<string | null>(null); // "all" | plataforma em regeneração
  const [galleryOpen, setGalleryOpen] = useState(false);

  useEffect(() => {
    sfetch("/api/connections").then((r) => r.json()).then((d) => {
      if (!d.ok) return;
      const profs: Profile[] = d.profiles ?? [];
      setProfiles(profs);
      const inicial = profs.filter((p) => p.is_default).map((p) => p.id);
      setSelectedProfiles(inicial.length ? inicial : profs.slice(0, 1).map((p) => p.id));
      // As redes ofertadas passam a vir dos PERFIS (união das contas de todos), não mais só do
      // perfil padrão: uma rede conectada apenas no perfil B ficava invisível aqui.
      setAccounts(profs.length ? profs.flatMap((p) => p.accounts ?? []) : (d.accounts ?? []));
    }).catch(() => {});
    const s = typeof window !== "undefined" ? localStorage.getItem("reachyn_reddit_subreddit") : null;
    if (s) {
      const m = /^([ru])\/(.+)$/i.exec(s.trim());
      if (m) { setRedditAlvo(m[1].toLowerCase() as "r" | "u"); setRedditSubreddit(m[2]); } else { setRedditSubreddit(s); }
    }
    const f = typeof window !== "undefined" ? localStorage.getItem("reachyn_reddit_formato") : null;
    if (f === "imagem" || f === "texto") setRedditFormato(f);
  }, []);

  // Redes (plataformas únicas) conectadas NOS PERFIS MARCADOS — não em todos. Oferecer uma rede
  // que só existe num perfil desmarcado é oferecer um destino que não vai receber nada: o submit
  // publica no cruzamento perfil×rede, então a tela tem que mostrar o mesmo cruzamento.
  const contasVisiveis = profiles.length
    ? profiles.filter((p) => selectedProfiles.includes(p.id)).flatMap((p) => p.accounts ?? [])
    : accounts;
  const platforms = Array.from(new Set(contasVisiveis.map((a) => a.platform)));

  // Marcar/desmarcar perfil PODA as redes na mesma ação. Desmarcar um perfil pode tirar uma rede
  // da lista; sem a poda ela continuava marcada em `selected` (invisível na tela) e o publicar
  // exigia o texto de uma rede que ninguém mais via. A poda vai aqui, e não num useEffect, pra
  // não disparar render em cascata a cada clique.
  function toggleProfile(id: string) {
    const novos = selectedProfiles.includes(id) ? selectedProfiles.filter((x) => x !== id) : [...selectedProfiles, id];
    setSelectedProfiles(novos);
    const redesVivas = new Set(profiles.filter((p) => novos.includes(p.id)).flatMap((p) => (p.accounts ?? []).map((a) => a.platform)));
    setSelected((s) => s.filter((p) => redesVivas.has(p)));
  }

  // Há mídia escolhida? Duas origens: do computador (`file`) ou da galeria (`galeriaUrl`).
  // Toda checagem usa isto — testar só `file` esquece metade dos caminhos (lição do /postar).
  const temMidia = !!file || !!galeriaUrl;

  function pick(f: File | null) {
    setFile(f);
    setGaleriaUrl(""); // escolher do computador substitui a da galeria
    setPreview(f ? URL.createObjectURL(f) : "");
    draftRef.current = null; // a mídia mudou → invalida o rascunho memoizado
  }

  // 📁 Mídia da GALERIA: não baixa nada. Guarda a URL e o servidor a anexa ao rascunho (adopt).
  function pickFromGallery(url: string) {
    setGalleryOpen(false);
    const ext = (url.split("?")[0].split(".").pop() || "").toLowerCase();
    setGaleriaKind(["mp4", "mov", "webm", "m4v"].includes(ext) ? "video" : "image");
    setFile(null);
    setGaleriaUrl(url);
    setPreview(url);
    draftRef.current = null;
    setMsg("");
  }

  // Cria (uma vez) o rascunho + sobe/adota a mídia; memoiza o draftId. O keyword do rascunho é o
  // PROMPT — é dele que o /v1/text tira o assunto. Reusado por gerar textos e publicar.
  async function ensureDraft(): Promise<string> {
    if (draftRef.current) return draftRef.current;
    if (!temMidia) throw new Error("Escolha uma imagem ou vídeo.");
    if (!prompt.trim()) throw new Error("Escreva o prompt/tema do post — é nele que a IA se apoia.");
    // keyword = "Título — Prompt" (ver comentário do estado): título identifica nas listas,
    // prompt guia a copy. Teto de 200 preservando o título inteiro na frente.
    const keyword = (title.trim() ? `${title.trim()} — ${prompt.trim()}` : prompt.trim()).slice(0, 200);
    const cb = await (await sfetch("/api/studio/draft", { method: "POST", body: JSON.stringify({ keyword }) })).json();
    if (!cb.ok) throw new Error(cb.error || "falha ao criar o rascunho");
    const draftId = String(cb.draftId);
    if (galeriaUrl) {
      const ad = await (await sfetch("/api/studio/adopt", { method: "POST", body: JSON.stringify({ draftId, url: galeriaUrl }) })).json();
      if (!ad.ok) throw new Error(ad.error || "não foi possível usar essa mídia da galeria");
      draftRef.current = draftId;
      return draftId;
    }
    const kind = file!.type.startsWith("video") ? "video" : "image";
    const fd = new FormData(); fd.append("file", file!); fd.append("draftId", draftId); fd.append("kind", kind);
    const up = await (await sfetch("/api/studio/upload", { method: "POST", body: fd })).json();
    if (!up.ok) throw new Error(up.error || "falha no upload da mídia");
    draftRef.current = draftId;
    return draftId;
  }

  // ✨ Gera o texto de UMA rede (mesmo POST /api/studio/text da aba Conteúdo — o servidor grava
  // em draft.texts[platform], que é exatamente o que o submit publica em cada rede).
  async function gerarTextoDe(draftId: string, platform: string): Promise<string> {
    const r = await sfetch("/api/studio/text", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ draftId, platform }) });
    const d = await r.json();
    if (!d.ok || !String(d.post || "").trim()) throw new Error(d.error || `não foi possível gerar o texto de ${PLATFORM_LABEL[platform] ?? platform}`);
    return String(d.post).trim();
  }

  // ✨ Gera os textos de TODAS as redes selecionadas (sequencial: cada geração é cobrada e o
  // throttle do endpoint é por minuto — rajada paralela só colecionaria 429).
  async function gerarTextos() {
    if (!temMidia) { setMsg("Escolha uma imagem ou vídeo primeiro."); return; }
    if (selected.length === 0) { setMsg("Escolha ao menos uma rede antes de gerar os textos."); return; }
    setGenBusy("all"); setMsg("✨ Gerando os textos por rede…");
    try {
      const draftId = await ensureDraft();
      for (const p of selected) {
        const post = await gerarTextoDe(draftId, p);
        setTexts((t) => ({ ...t, [p]: post }));
      }
      setMsg("✅ Textos gerados — revise cada rede antes de publicar (a IA inventa número com confiança).");
    } catch (e) { setMsg("❌ " + ((e as Error).message || "não foi possível gerar os textos")); }
    setGenBusy(null);
  }

  // ↻ Regenera SÓ uma rede — o resto fica como está (mesma unidade da aba Conteúdo).
  async function regerarTexto(p: string) {
    setGenBusy(p); setMsg(`✨ Regenerando ${PLATFORM_LABEL[p] ?? p}…`);
    try {
      const draftId = await ensureDraft();
      const post = await gerarTextoDe(draftId, p);
      setTexts((t) => ({ ...t, [p]: post }));
      setMsg("✅ Texto regenerado.");
    } catch (e) { setMsg("❌ " + (e as Error).message); }
    setGenBusy(null);
  }

  function toggle(p: string) {
    // Marcar o Reddit pré-preenche o título do post com o Título da peça (editável) — só quando
    // o campo ainda está vazio, pra nunca sobrescrever o que o cliente já escreveu.
    if (p === "reddit" && !selected.includes(p) && !redditTitulo.trim() && title.trim()) {
      setRedditTitulo(title.trim().slice(0, 300));
    }
    setSelected((s) => (s.includes(p) ? s.filter((x) => x !== p) : [...s, p]));
  }

  // 🚀 Publica DIRETO (sem fila): grava o texto de cada rede (PATCH — vale a edição da tela) e
  // dispara o /api/studio/submit. O servidor exige texto por rede → o compositor exige gerar ou
  // escrever antes, que é a mesma exigência dita mais cedo.
  async function publicar() {
    if (!temMidia) { setMsg("Escolha uma imagem ou vídeo pronto."); return; }
    if (selected.length === 0) { setMsg("Escolha ao menos uma rede."); return; }
    if (profiles.length > 0 && selectedProfiles.length === 0) { setMsg("Escolha ao menos um perfil onde publicar."); return; }
    const semTexto = selected.filter((p) => !String(texts[p] || "").trim());
    if (semTexto.length > 0) {
      setMsg(`❌ Falta o texto de: ${semTexto.map((p) => PLATFORM_LABEL[p] ?? p).join(", ")} — gere com ✨ ou escreva antes de publicar.`);
      return;
    }
    // Comunidade do Reddit é obrigatória quando a rede está marcada (sem ela o post caía num
    // "padrão da conta" que ninguém escolheu — e publicar no lugar errado não tem desfazer).
    if (selected.includes("reddit") && !redditSubreddit.trim()) {
      setMsg(`❌ Escreva o alvo do Reddit (${redditAlvo}/…) antes de publicar.`);
      return;
    }
    setBusy(true); setMsg(""); setNeedsPub(false);
    try {
      const draftId = await ensureDraft();
      // Texto FINAL de cada rede = o da tela (o cliente pode ter editado por cima do gerado).
      for (const p of selected) {
        await sfetch("/api/studio/text", { method: "PATCH", body: JSON.stringify({ draftId, platform: p, text: texts[p] }) });
      }
      const redditTarget = redditSubreddit.trim() ? `${redditAlvo}/${redditSubreddit.trim()}` : "";
      if (redditTarget) localStorage.setItem("reachyn_reddit_subreddit", redditTarget);
      localStorage.setItem("reachyn_reddit_formato", redditFormato);
      const sb = await sfetch("/api/studio/submit", { method: "POST", body: JSON.stringify({ draftId, platforms: selected, profiles: selectedProfiles, reddit_subreddit: redditTarget, reddit_formato: redditFormato, reddit_title: redditTitulo.trim() }) });
      const sj = await sb.json();
      if (sb.status === 402 && sj.error === "publishing_required") { setNeedsPub(true); setMsg(sj.message || "Ative a publicação para postar."); return; }
      if (!sj.ok) throw new Error(sj.error || "falha ao publicar");
      setMsg("✅ Publicação enviada! Acompanhe em Publicações.");
      pick(null); setGaleriaUrl(""); setTexts({}); setSelected([]); if (fileRef.current) fileRef.current.value = "";
      draftRef.current = null;
    } catch (e) {
      setMsg((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
        <div>
          <h1 className="h1">➕ Novo post</h1>
          <p className="sub">Suba (ou escolha da galeria) uma mídia pronta, gere a legenda de cada rede a partir do prompt e <b>publique direto</b> — mídia que você mesmo subiu já está aprovada por definição.</p>
        </div>
        {onClose && (
          <button type="button" className="btn edit" style={{ flex: "none", padding: "8px 14px" }} onClick={onClose}>← Voltar ao Publicar</button>
        )}
      </div>

      <article className="card" style={{ marginTop: 12 }}>
        <div className="body" style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(min(340px, 100%), 1fr))", gap: "16px 28px", alignItems: "start" }}>
          <div style={{ display: "flex", flexDirection: "column", gap: 14, minWidth: 0 }}>
            <label>
              <div className="txt" style={{ marginBottom: 4 }}>Título</div>
              <input value={title} onChange={(e) => setTitle(e.target.value.slice(0, 120))} placeholder="ex.: Lançamento de junho" style={inp} />
              <p className="txt" style={{ color: "var(--muted)", fontSize: ".72rem", marginTop: 4 }}>
                Nomeia a peça — é o que aparece na lista de Publicações e na Galeria. No Reddit vira a sugestão do título do post.
              </p>
            </label>
            <label>
              <div className="txt" style={{ marginBottom: 4 }}>Prompt / tema do post</div>
              <textarea value={prompt} onChange={(e) => setPrompt(e.target.value)} rows={3}
                placeholder="ex.: lançamento da linha de junho — tom comemorativo, foco no desconto de 20%"
                style={{ ...inp, resize: "vertical" }} />
              <p className="txt" style={{ color: "var(--muted)", fontSize: ".72rem", marginTop: 4 }}>
                É daqui que a IA escreve a legenda de cada rede. Quanto mais concreto, melhor o texto.
              </p>
            </label>

            <div>
              <div className="txt" style={{ marginBottom: 6 }}>Mídia pronta (imagem ou vídeo)</div>
              <label htmlFor="novopost-file" style={dropzone}>
                <UploadCloud size={24} style={{ color: "var(--peach)" }} />
                <span style={{ fontSize: ".9rem", color: "var(--text)", fontWeight: 600 }}>
                  {file ? file.name : "Clique para escolher um arquivo"}
                </span>
                <span style={{ fontSize: ".74rem", color: "var(--muted)" }}>
                  {file ? "Clique para trocar" : "JPG, PNG, WEBP, GIF · MP4, MOV (até 100MB)"}
                </span>
              </label>
              <input id="novopost-file" ref={fileRef} type="file" accept="image/*,video/mp4,video/quicktime"
                onChange={(e) => pick(e.target.files?.[0] ?? null)} style={{ display: "none" }} />
              <button type="button" className="btn edit" style={{ marginTop: 8, padding: "8px 12px", fontSize: ".85rem" }} disabled={busy} onClick={() => setGalleryOpen(true)}>📁 Escolher da galeria</button>
            </div>
            {preview && ((file?.type.startsWith("video") || (!file && galeriaKind === "video"))
              ? <video src={preview} controls style={{ maxWidth: "100%", borderRadius: 10, maxHeight: 300 }} />
              : <img src={preview} alt="" style={{ maxWidth: "100%", borderRadius: 10, maxHeight: 300, objectFit: "contain" }} />)}
          </div>

          <div style={{ display: "flex", flexDirection: "column", gap: 14, minWidth: 0 }}>
            {/* 👥 PERFIS — onde publicar. Vem ANTES das redes porque manda nelas: a lista de redes
                abaixo é a união das contas dos perfis marcados. */}
            {profiles.length > 0 && (
              <div>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 6, gap: 8, flexWrap: "wrap" }}>
                  <div className="txt">👥 Perfis — onde publicar</div>
                  <a className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }} href="/conexoes">Gerenciar</a>
                </div>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                  {profiles.map((prof) => (
                    <button key={prof.id} type="button" onClick={() => toggleProfile(prof.id)}
                      title={`${prof.accounts?.length ?? 0} conta(s)`}
                      className={selectedProfiles.includes(prof.id) ? "chip on" : "chip"}>
                      {prof.name}{prof.is_default ? " (padrão)" : ""}
                    </button>
                  ))}
                </div>
                {selectedProfiles.length === 0 && (
                  <p className="txt" style={{ color: "var(--muted)", fontSize: ".78rem", margin: "6px 0 0" }}>Marque ao menos um perfil — é nele que o post sai.</p>
                )}
              </div>
            )}

            <div>
              <div className="txt" style={{ marginBottom: 6 }}>Redes</div>
              {platforms.length === 0
                ? <p className="txt" style={{ color: "var(--muted)" }}>Nenhuma rede conectada. Vá em <a href="/conexoes" style={{ color: "var(--green)" }}>Conexões</a> para conectar.</p>
                : <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                    {platforms.map((p) => (
                      <button key={p} type="button" onClick={() => toggle(p)}
                        className={selected.includes(p) ? "chip on" : "chip"}>
                        {PLATFORM_LABEL[p] ?? p}
                      </button>
                    ))}
                  </div>}
            </div>

            {selected.includes("reddit") && (
              <div>
                <div className="txt" style={{ marginBottom: 6 }}>👽 Onde publicar no Reddit</div>
                <div style={{ display: "flex", alignItems: "center", gap: 4 }}>
                  <select value={redditAlvo} onChange={(e) => setRedditAlvo(e.target.value as "r" | "u")} style={{ ...inp, flex: "none", width: 64 }} title="r/ = comunidade · u/ = seu perfil">
                    <option value="r">r/</option>
                    <option value="u">u/</option>
                  </select>
                  <input value={redditSubreddit} onChange={(e) => setRedditSubreddit(e.target.value.replace(/^\/?([ru]\/)?/i, ""))} placeholder="RedFoxCode" style={inp} />
                </div>
                <p className="txt" style={{ color: "var(--muted)", fontSize: ".72rem", marginTop: 4 }}>
                  {redditAlvo === "u" ? "Publica no SEU perfil do Reddit (u/)." : "Publica na comunidade (r/), que tem regras próprias de moderação."}
                </p>
                <div className="txt" style={{ marginTop: 8, marginBottom: 6 }}>Formato do post</div>
                <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
                  {([["imagem", "🖼️ Imagem + texto no 1º comentário"], ["texto", "📝 Tudo no post (sem imagem)"]] as const).map(([v, rotulo]) => (
                    <label key={v} className="txt" style={{ display: "flex", alignItems: "center", gap: 5, fontSize: ".82rem", cursor: "pointer" }}>
                      <input type="radio" name="novopost-reddit-formato" checked={redditFormato === v} onChange={() => setRedditFormato(v)} />
                      {rotulo}
                    </label>
                  ))}
                </div>
                <div className="txt" style={{ marginTop: 8, marginBottom: 6 }}>
                  Título do post {redditFormato === "imagem" && <b>(é o texto que vai junto com a imagem)</b>}
                </div>
                {/* Pré-preenche com o Título da peça (dá pra editar) — em branco, cai na 1ª
                    linha do texto do Reddit, como sempre. */}
                <textarea value={redditTitulo} onChange={(e) => setRedditTitulo(e.target.value.slice(0, 300))} rows={2}
                  placeholder={title.trim() || (texts.reddit || "").split("\n").find((l) => l.trim()) || "Em branco = a 1ª linha do texto do Reddit"} style={{ ...inp, resize: "vertical" }} />
                <p className="txt" style={{ color: redditTitulo.length > 285 ? "#ff9b8a" : "var(--muted)", fontSize: ".72rem", marginTop: 4 }}>
                  {redditTitulo.length}/300 · o Reddit NÃO deixa editar o título depois de publicado.
                </p>
              </div>
            )}

            {/* ✍️ TEXTO POR REDE: um bloco por rede selecionada — gerado, editável, regenerável. */}
            {selected.length > 0 && (
              <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 10 }}>
                  <span className="txt"><b>Texto por rede</b></span>
                  <button type="button" className="btn edit" style={{ flex: "0 0 auto", padding: "5px 12px", fontSize: ".78rem", whiteSpace: "nowrap" }}
                    disabled={genBusy !== null || !temMidia || !prompt.trim()}
                    title={temMidia ? (prompt.trim() ? "Gera a legenda de cada rede selecionada a partir do prompt" : "Escreva o prompt primeiro") : "Escolha a mídia primeiro"}
                    onClick={gerarTextos}>{genBusy === "all" ? "Gerando…" : "✨ Gerar textos"}</button>
                </div>
                {selected.map((p) => (
                  <label key={p}>
                    <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8, marginBottom: 4 }}>
                      <span className="txt" style={{ fontSize: ".82rem" }}>{PLATFORM_LABEL[p] ?? p}</span>
                      <button type="button" className="btn edit" style={{ flex: "0 0 auto", padding: "3px 10px", fontSize: ".74rem", whiteSpace: "nowrap" }}
                        disabled={genBusy !== null || !temMidia || !prompt.trim()} onClick={() => regerarTexto(p)}
                        title="Regenera só o texto desta rede — as outras ficam como estão.">
                        {genBusy === p ? "…" : "↻"}
                      </button>
                    </div>
                    <textarea value={texts[p] ?? ""} onChange={(e) => setTexts((t) => ({ ...t, [p]: e.target.value }))} rows={4}
                      placeholder={`Texto do ${PLATFORM_LABEL[p] ?? p} — gere com ✨ ou escreva aqui`} style={{ ...inp, resize: "vertical" }} />
                  </label>
                ))}
              </div>
            )}

            {msg && <p className="txt" style={{ color: needsPub ? "#e3b341" : msg.startsWith("✅") ? "var(--green)" : "#ff9b8a" }}>{msg}</p>}

            <button className="btn ok" disabled={busy || genBusy !== null} onClick={publicar}>{busy ? "Publicando…" : "🚀 Publicar"}</button>
          </div>
        </div>
      </article>
      {galleryOpen && <GalleryPicker kind="all" onPick={pickFromGallery} onClose={() => setGalleryOpen(false)} />}
    </>
  );
}

const inp: React.CSSProperties = { width: "100%", background: "var(--bg2)", color: "var(--text)", border: "1px solid var(--line)", borderRadius: 8, padding: "9px 11px", fontSize: ".9rem" };
const dropzone: React.CSSProperties = { display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: 7, padding: "26px 18px", border: "1px dashed var(--line2)", borderRadius: 12, background: "var(--bg2)", cursor: "pointer", textAlign: "center", transition: ".16s" };

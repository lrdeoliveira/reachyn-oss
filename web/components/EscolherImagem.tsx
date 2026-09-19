"use client";

import { sfetch, Console } from "@/lib/api";
import { mediaUrl, isOwnMedia } from "@/lib/media";
import { useToast } from "@/components/ui/Toast";
import { Upload, X } from "lucide-react";
import { useEffect, useRef, useState } from "react";

/**
 * Escolher uma imagem do ACERVO (galeria + personagens + cenários) ou carregar uma do
 * computador. Existe porque a âncora de vídeo só entrava por upload: quem acabou de gerar
 * uma imagem aqui dentro tinha que baixá-la e subir de novo pra animá-la.
 *
 * Só lista imagem do NOSSO storage — a âncora vai pro modelo por URL, e o backend recusa
 * qualquer coisa fora do acervo (anti-SSRF).
 */

type ItemAcervo = { url: string; titulo: string };

export function EscolherImagem({ aberto, onFechar, onEscolher, titulo = "Escolher imagem" }: {
  aberto: boolean;
  onFechar: () => void;
  onEscolher: (url: string) => void;
  titulo?: string;
}) {
  const toast = useToast();
  const [itens, setItens] = useState<ItemAcervo[]>([]);
  const [carregando, setCarregando] = useState(true);
  const [enviando, setEnviando] = useState(false);
  const arquivo = useRef<HTMLInputElement>(null);

  // Carrega o acervo só quando o seletor abre — a lista muda a cada geração, então não vale
  // guardar de uma abertura pra outra.
  useEffect(() => {
    if (!aberto) return;
    setCarregando(true);
    // Só o acervo do console. Havia um segundo fetch("/api/image/list") aqui, herdado do
    // FoxAssets, que listaria imagens do DISCO local — mas essa rota Next nunca existiu neste
    // repo (só app/api/compose existe). Dentro do allSettled ela falhava calada: o acervo
    // "local" simplesmente nunca aparecia, sem erro visível. Removido em vez de mantido como
    // promessa quebrada; as imagens da CLI já entram no acervo normal, via engine.
    Promise.allSettled([sfetch("/api/media/list").then((r) => r.json())])
      .then(([remoto]) => {
        const doConsole: ItemAcervo[] =
          remoto.status === "fulfilled"
            ? (remoto.value?.items ?? [])
                .filter((i: { kind: string }) => i.kind === "image")
                .map((i: { url: string; keyword?: string | null }) => ({ url: mediaUrl(i.url), titulo: i.keyword || "imagem" }))
                .filter((i: ItemAcervo) => isOwnMedia(i.url))
            : [];
        setItens(doConsole);
      })
      .finally(() => setCarregando(false));
  }, [aberto]);

  // Fecha com Esc, igual ao visualizador da galeria.
  useEffect(() => {
    if (!aberto) return;
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") onFechar(); };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [aberto, onFechar]);

  // Imagem de fora entra pelo mesmo caminho do acervo (upload pro S3) — assim a URL que vai
  // pro modelo é sempre nossa, e a imagem fica guardada em vez de sumir depois da geração.
  async function subir(files: FileList | null) {
    const file = files?.[0];
    if (!file || enviando) return;
    setEnviando(true);
    try {
      const d = await Console.upload(file);
      const m = d.ok ? d.media?.[d.media.length - 1] : null;
      if (!d.ok || !m?.url) {
        toast.err(d.error || "Não foi possível carregar a imagem.");
        return;
      }
      onEscolher(mediaUrl(m.url));
      onFechar();
    } catch {
      toast.err("Erro ao carregar a imagem.");
    } finally {
      setEnviando(false);
      if (arquivo.current) arquivo.current.value = "";
    }
  }

  if (!aberto) return null;

  return (
    <div
      onClick={onFechar}
      style={{ position: "fixed", inset: 0, zIndex: 1100, background: "rgba(0,0,0,.86)", display: "flex", alignItems: "center", justifyContent: "center", padding: 20 }}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        style={{ background: "var(--panel)", border: "1px solid var(--line2)", borderRadius: 14, width: "min(880px, 100%)", maxHeight: "86vh", display: "flex", flexDirection: "column", overflow: "hidden" }}
      >
        <div style={{ display: "flex", alignItems: "center", gap: 10, padding: "14px 16px", borderBottom: "1px solid var(--line)" }}>
          <strong style={{ flex: 1, fontSize: ".98rem" }}>{titulo}</strong>
          <input ref={arquivo} type="file" accept="image/*" style={{ display: "none" }} onChange={(e) => subir(e.target.files)} />
          <button type="button" className="btn" onClick={() => arquivo.current?.click()} disabled={enviando} style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 12px" }}>
            {enviando ? <span className="spinner" style={{ width: 14, height: 14 }} /> : <Upload size={15} />}
            {enviando ? "Carregando…" : "Do computador"}
          </button>
          <button type="button" className="btn no" onClick={onFechar} style={{ flex: "0 0 auto", display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 12px" }}>
            <X size={15} /> Fechar
          </button>
        </div>

        <div style={{ overflow: "auto", padding: 16 }}>
          {carregando ? (
            <p className="txt" style={{ color: "var(--muted)" }}>Carregando o acervo…</p>
          ) : itens.length === 0 ? (
            <div className="empty">Nenhuma imagem no acervo ainda. Gere uma na aba Imagem ou carregue do computador.</div>
          ) : (
            <div style={{ display: "grid", gap: 12, gridTemplateColumns: "repeat(auto-fill, minmax(min(150px, 100%), 1fr))" }}>
              {itens.map((it) => (
                <button
                  key={it.url}
                  type="button"
                  onClick={() => { onEscolher(it.url); onFechar(); }}
                  title={it.titulo}
                  style={{ padding: 0, border: "1px solid var(--line2)", borderRadius: 10, overflow: "hidden", background: "var(--bg2)", cursor: "pointer", aspectRatio: "1 / 1" }}
                >
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img src={it.url} alt={it.titulo} loading="lazy" style={{ width: "100%", height: "100%", objectFit: "cover", display: "block" }} />
                </button>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

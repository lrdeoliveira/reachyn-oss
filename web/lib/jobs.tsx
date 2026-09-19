"use client";

// 🧰 CENTRAL DE TAREFAS (JobCenter) — S1 do PLANO-UX-INTERFACE.
// O problema nº1 de UX do Reachyn: gerações levam MINUTOS e o acompanhamento morria com a página
// (polling local que "desiste" e usuário sem saber se o job terminou). Aqui o acompanhamento vira
// GLOBAL: qualquer tela registra um job (registerJob) e o JobCenter — que vive no layout — segue
// poll-ando mesmo se o usuário navegar; persiste em localStorage (sobrevive a F5); dispara toast
// "✅ pronto" com link quando conclui; o sino no topo lista rodando/prontos.
//
// Tipos de job e como cada um sabe que terminou:
//  - draft-media:  GET /api/studio/draft?id →  media.length > baseline (mídia nova chegou)
//  - character:    GET /api/characters/{id} →  status saiu de base|sheet|edit
//  - animation:    GET /api/animation/{id}  →  status done|error
//  - film:         GET /api/studio/draft?id →  o CAMPO do filme mudou de valor (keyframe/trecho/
//                  filme-rápido) ou nova mídia (montagem). Por-item (index) → suporta gerar N
//                  keyframes/trechos EM PARALELO sem um concluir no lugar do outro.
// Polling: 8s nos primeiros 10 min, depois 30s; nunca "desiste" sozinho — job só sai da lista
// quando conclui ou quando o usuário limpa.

import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from "react";
import { getActiveTenant, sfetch } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";

export type JobType = "draft-media" | "character" | "animation" | "film";
export type JobStatus = "running" | "done" | "failed";
/** Sub-tipo do job de Filme — decide qual campo do draft.film sinaliza a conclusão.
 *  plan/board/block entraram com a aba Movies (mesmo draft.film; sinais: status/board_url/blocks). */
export type FilmKind = "keyframe" | "clip" | "quick" | "assemble" | "plan" | "board" | "block";

/**
 * Teto de idade por tipo — BACKSTOP contra job zumbi no localStorage (backend caiu sem deixar
 * rastro → o card pollaria pra sempre). NÃO é estimativa de duração: o sinal real de conclusão
 * é o status que vem do backend (done/error), checado a cada tick.
 *
 * Por tipo porque as escalas diferem em ordem de grandeza: `animation` é o projeto AUTOMÁTICO
 * inteiro (elementos → keyframes → cenas → montagem), não uma geração só. Medido em prod
 * (2026-07-15): 1h55 de ponta a ponta com 6 cenas; cada cena leva 8–14min e só há 2 workers,
 * então um projeto de 20 cenas passa de 3h com folga. O teto único de 3h que existia aqui
 * marcava "falhou" um projeto SAUDÁVEL — a mentira inversa da que motivou este arquivo.
 */
const MAX_AGE_MS: Record<JobType, number> = {
  animation: 8 * 3600_000,
  "draft-media": 3 * 3600_000,
  character: 3 * 3600_000,
  // Filme: trecho/filme-rápido levam ~10min e o job retenta até 3× com backoff (~45min no pior
  // caso). 2h de folga antes do backstop marcar zumbi.
  film: 2 * 3600_000,
};

export type Job = {
  id: string;
  type: JobType;
  label: string;          // ex.: "🎬 Vídeo — Lançamento de junho"
  href: string;           // pra onde o "Ver" leva
  status: JobStatus;
  createdAt: number;
  doneAt?: number;
  tenantId?: string;      // marca dona do job — poll/sino só na marca ativa (o backend escopa por X-Tenant-Id)
  // por tipo:
  draftId?: number;
  baseline?: number;      // draft-media / film(assemble): nº de mídias no momento do disparo
  charId?: number;
  projectId?: number;
  // film:
  filmKind?: FilmKind;    // qual campo sinaliza conclusão
  index?: number;         // keyframe/clip: índice do item (permite N em paralelo)
  filmBaseline?: string;  // valor ANTERIOR da URL do campo — conclui quando muda (re-gerar sobrescreve)
};

type RegisterInput = Omit<Job, "id" | "status" | "createdAt" | "doneAt" | "tenantId">;

type JobsApi = {
  jobs: Job[];
  running: number;
  registerJob: (j: RegisterInput) => string;
  clearFinished: () => void;
  dismiss: (id: string) => void;
};

const JobsCtx = createContext<JobsApi | null>(null);
const LS_KEY = "reachyn.jobs";
const MAX_AGE_DONE = 24 * 3600_000; // concluídos somem sozinhos depois de 24h

export function useJobs(): JobsApi {
  const ctx = useContext(JobsCtx);
  if (!ctx) throw new Error("useJobs fora do <JobCenterProvider>");
  return ctx;
}

function load(): Job[] {
  if (typeof window === "undefined") return [];
  try {
    const raw = window.localStorage.getItem(LS_KEY);
    const arr = raw ? (JSON.parse(raw) as Job[]) : [];
    const now = Date.now();
    return arr.filter((j) => j && j.id && (j.status === "running" || now - (j.doneAt ?? j.createdAt) < MAX_AGE_DONE));
  } catch { return []; }
}

export function JobCenterProvider({ children }: { children: React.ReactNode }) {
  const [jobs, setJobs] = useState<Job[]>([]);
  const toast = useToast();
  const jobsRef = useRef<Job[]>(jobs);
  jobsRef.current = jobs;

  // hidrata do localStorage no client (evita mismatch de SSR)
  useEffect(() => { setJobs(load()); }, []);
  // persiste toda mudança
  useEffect(() => {
    if (typeof window !== "undefined") window.localStorage.setItem(LS_KEY, JSON.stringify(jobs));
  }, [jobs]);

  const registerJob = useCallback((j: RegisterInput): string => {
    const id = `${j.type}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const job: Job = { ...j, id, status: "running", createdAt: Date.now(), tenantId: getActiveTenant() || undefined };
    setJobs((all) => [...all, job].slice(-30));
    // S2: pede permissão de notificação do BROWSER só depois do 1º job longo disparado
    // (momento em que o aviso tem valor óbvio) — nunca no load da página.
    if (typeof Notification !== "undefined" && Notification.permission === "default") {
      try { void Notification.requestPermission(); } catch { /* browsers antigos */ }
    }
    return id;
  }, []);

  const finish = useCallback((id: string, status: JobStatus, label: string, href: string) => {
    setJobs((all) => all.map((x) => (x.id === id ? { ...x, status, doneAt: Date.now() } : x)));
    if (status === "done") toast.ok(`${label} — pronto!`, { label: "Ver", href });
    else toast.err(`${label} — falhou. Abra a tela pra ver o detalhe.`, { label: "Abrir", href });
    // S2: aba em segundo plano → notificação do sistema (se o usuário permitiu).
    if (typeof document !== "undefined" && document.hidden
      && typeof Notification !== "undefined" && Notification.permission === "granted") {
      try {
        const n = new Notification(status === "done" ? "Reachyn — geração pronta 🎉" : "Reachyn — geração falhou", { body: label });
        n.onclick = () => { window.focus(); window.location.href = href; };
      } catch { /* sem suporte */ }
    }
  }, [toast]);

  // ⏱ UM loop global de verificação (não um por tela): checa cada job rodando no seu ritmo.
  // inFlight evita ticks sobrepostos (requests lentas > intervalo → finish/toast duplicado).
  const inFlight = useRef(false);
  useEffect(() => {
    const iv = window.setInterval(async () => {
      if (inFlight.current) return;
      inFlight.current = true;
      try {
      const now = Date.now();
      const at = getActiveTenant();
      const running = jobsRef.current.filter((j) => j.status === "running"
        // job de OUTRA marca fica dormente (o backend escopa por X-Tenant-Id — pollar daria 404
        // eterno); volta a ser checado quando o usuário voltar pra marca dona.
        && (!j.tenantId || !at || j.tenantId === at));
      for (const j of running) {
        const age = now - j.createdAt;
        // ⛔ backstop: sem NENHUM sinal do backend dentro do teto do tipo, desiste (ver MAX_AGE_MS).
        if (age > (MAX_AGE_MS[j.type] ?? 3 * 3600_000)) { finish(j.id, "failed", j.label, j.href); continue; }
        const cadence = age > 10 * 60_000 ? 30_000 : 8_000;
        // desalinha as checagens pela idade (cada tick só checa quem "venceu" a cadência)
        if ((now - j.createdAt) % cadence > 8_000) continue;
        try {
          if (j.type === "draft-media" && j.draftId != null) {
            const d = await sfetch(`/api/studio/draft?id=${j.draftId}`).then((r) => r.json()).catch(() => null);
            const n = d?.draft?.media?.length ?? -1;
            if (n >= 0 && n > (j.baseline ?? 0)) finish(j.id, "done", j.label, j.href);
          } else if (j.type === "character" && j.charId != null) {
            // ⚠️ GET /api/characters/{id} devolve o personagem DIRETO (sem envelope {ok, character}).
            // Exigir d.id evita "concluir" em cima de um corpo de erro ({message}) — ex.: personagem
            // de outra marca (X-Tenant-Id ativo diferente) responde 404 e o job só fica aguardando.
            const d = await sfetch(`/api/characters/${j.charId}`).then((r) => r.json()).catch(() => null);
            const st = String(d?.status ?? "");
            if (d?.id != null && !["base", "sheet", "edit"].includes(st)) finish(j.id, "done", j.label, j.href);
          } else if (j.type === "animation" && j.projectId != null) {
            const d = await sfetch(`/api/animation/${j.projectId}`).then((r) => r.json()).catch(() => null);
            const st = String(d?.project?.status ?? d?.status ?? "");
            if (st === "done") finish(j.id, "done", j.label, j.href);
            else if (st === "error") finish(j.id, "failed", j.label, j.href);
          } else if (j.type === "film" && j.draftId != null) {
            const d = await sfetch(`/api/studio/draft?id=${j.draftId}`).then((r) => r.json()).catch(() => null);
            const film = d?.draft?.film ?? null;
            if (film) {
              let done = false;
              let failed = false;
              if (j.filmKind === "keyframe") {
                const v = String((film.keyframes ?? [])[j.index ?? -1] ?? "");
                done = v !== "" && v !== (j.filmBaseline ?? "");
              } else if (j.filmKind === "clip") {
                const v = String((film.beats ?? [])[j.index ?? -1]?.clip_url ?? "");
                done = v !== "" && v !== (j.filmBaseline ?? "");
              } else if (j.filmKind === "quick") {
                const v = String(film.quick_clip_url ?? "");
                done = v !== "" && v !== (j.filmBaseline ?? "");
              } else if (j.filmKind === "assemble") {
                const n = d?.draft?.media?.length ?? -1;
                done = n >= 0 && n > (j.baseline ?? 0);
              } else if (j.filmKind === "plan") {
                // Plano de filmagem: o job do backend grava status ready/error no draft.film.
                const st = String(film.status ?? "");
                done = st === "ready";
                failed = st === "error";
              } else if (j.filmKind === "board") {
                const v = String(film.board_url ?? "");
                done = v !== "" && v !== (j.filmBaseline ?? "");
              } else if (j.filmKind === "block") {
                const v = String((film.blocks ?? [])[j.index ?? -1]?.url ?? "");
                done = v !== "" && v !== (j.filmBaseline ?? "");
              }
              if (failed) finish(j.id, "failed", j.label, j.href);
              else if (done) finish(j.id, "done", j.label, j.href);
            }
          }
        } catch { /* tenta no próximo tick */ }
      }
      } finally { inFlight.current = false; }
    }, 8_000);
    return () => window.clearInterval(iv);
  }, [finish]);

  const clearFinished = useCallback(() => setJobs((all) => all.filter((j) => j.status === "running")), []);
  const dismiss = useCallback((id: string) => setJobs((all) => all.filter((j) => j.id !== id)), []);

  const api = useMemo<JobsApi>(() => ({
    jobs,
    running: jobs.filter((j) => j.status === "running").length,
    registerJob,
    clearFinished,
    dismiss,
  }), [jobs, registerJob, clearFinished, dismiss]);

  return <JobsCtx.Provider value={api}>{children}</JobsCtx.Provider>;
}

/** Tempo decorrido humano ("há 3 min"). */
function ago(ts: number): string {
  const s = Math.max(1, Math.round((Date.now() - ts) / 1000));
  if (s < 60) return `há ${s}s`;
  const m = Math.round(s / 60);
  if (m < 60) return `há ${m} min`;
  return `há ${Math.round(m / 60)}h`;
}

/** 🔔 Sino da Central de Tarefas — vive na sidebar; badge com nº de jobs rodando. */
export function JobsBell() {
  const { jobs, running, clearFinished, dismiss } = useJobs();
  const [open, setOpen] = useState(false);
  if (jobs.length === 0) return null;

  return (
    <div className="jobs-bell">
      <button className="jobs-btn" onClick={() => setOpen((o) => !o)} aria-label="Central de tarefas">
        <span aria-hidden>⚙️</span> Tarefas
        {running > 0 && <span className="jobs-badge">{running}</span>}
        {running === 0 && jobs.some((j) => j.status === "done") && <span className="jobs-badge ok">✓</span>}
      </button>
      {open && (
        <div className="jobs-panel">
          <div className="jobs-head">
            <strong>Central de tarefas</strong>
            <button className="jobs-clear" onClick={clearFinished}>limpar concluídas</button>
          </div>
          {jobs.slice().reverse().map((j) => (
            <div key={j.id} className={`jobs-item ${j.status}`}>
              <span className="jobs-dot" aria-hidden />
              <div style={{ minWidth: 0, flex: 1 }}>
                <div className="jobs-label">{j.label}</div>
                <div className="jobs-meta">
                  {j.status === "running" ? `gerando… ${ago(j.createdAt)}` : j.status === "done" ? "pronto" : "falhou"}
                </div>
              </div>
              {j.status !== "running" && <a className="jobs-go" href={j.href}>Ver</a>}
              <button className="jobs-x" aria-label="Remover da lista" onClick={() => dismiss(j.id)}>×</button>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

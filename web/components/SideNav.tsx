"use client";

import { usePathname } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { Search, ScrollText, FileText, Image as ImageIcon, Video, CheckCircle2, Send, Plug, CreditCard, ShieldCheck, KeyRound, GalleryHorizontalEnd, Newspaper, UserRound, BookOpen, Lightbulb, CalendarDays, Rocket, Recycle, ListOrdered, Waypoints, Users, Map, Package, Gamepad2, Box, Palette, ChevronDown } from "lucide-react";
import { sfetch } from "@/lib/api";

const CONSOLE = process.env.NEXT_PUBLIC_CONSOLE_URL ?? "";

type NavItem = { href: string; label: string; icon: typeof Search; badge?: string; section?: string };
type Group = { title: string; items: NavItem[] };

// Sidebar por FLUXO: CONTEÚDO (pipeline de texto: pesquisa -> resumo -> texto) → ESTÚDIO
// (criação visual) → PUBLICAR → BIBLIOTECA (acervo) → CONTA.
//
// A barra lista o FLUXO — o que se percorre de ponta a ponta. Ferramenta pontual, que se usa
// de vez em quando e não é etapa de nada, vive no catálogo /ferramentas (que já as listava,
// duplicando a barra). Saíram daqui por isso: Ideias e Calendário (planejamento, não criação
// visual — estavam no Estúdio por acidente) e Otimizar/Reaproveitar (pós-produção pontual).
// As ROTAS seguem existindo e funcionando; só deixaram de ocupar linha no menu. Ferramentas
// virou o 1º item do Estúdio, que é a porta de entrada delas.
//
// `badge: "novo"` só no que é REALMENTE recente. Estava em 8 dos 24 itens — quando um terço da
// barra é "novo", o selo deixa de significar qualquer coisa.
const GROUPS: Group[] = [
  {
    // O ciclo do conteúdo num grupo só: planejar → pesquisar → escrever → gerar a mídia do
    // post → pós-produzir. A criação pesada (estúdio/animação/personagens/movies) foi pro
    // FoxAssets; aqui fica a Mídia do post (imagem/vídeo/gif/áudio pra publicação).
    title: "Conteúdo",
    items: [
      { href: "/ideias", label: "Ideias", icon: Lightbulb },
      { href: "/calendario", label: "Calendário", icon: CalendarDays },
      { href: "/", label: "Pesquisar", icon: Search },
      { href: "/resumo", label: "Resumo", icon: ScrollText },
      { href: "/editar", label: "Texto e legendas", icon: FileText },
      { href: "/midia", label: "Mídia", icon: ImageIcon },
      { href: "/otimizar", label: "Otimizar", icon: Rocket },
      { href: "/reaproveitar", label: "Reaproveitar", icon: Recycle },
    ],
  },
  {
    // ESTÚDIO — todo o pipeline de criação visual pesada num grupo só (era 3: Criar/Filme/
    // Games — 3 headers pra 9 itens inflavam a barra sem necessidade). Vem do fork FoxAssets,
    // fundido de volta em 2026-08-01. Ordem = o fluxo: peça avulsa primeiro (imagem/vídeo sem
    // passar por personagem/cena), depois o FILME (prompt → Roteiro cria as fichas que faltam
    // → Personagens/Cenários/Elementos dão rosto → Montagem produz), depois Games.
    title: "Estúdio",
    items: [
      // Motor de imagem local (mmx no host) e a aba ÚNICA de vídeo — o caminho curto pra quando
      // você quer UMA peça, não um filme com elenco.
      { href: "/imagem", label: "Imagem", icon: ImageIcon },
      // 🎬 Vídeo — absorveu a aba /vox em 2026-08-06: storyboard revisável cena a cena, e o Vox
      // (jornalismo explicativo em colagem de papel) virou um ESTILO do seletor. A rota /vox
      // continua viva como redirect, mas item de menu tem de ser UM só.
      { href: "/video", label: "Vídeo", icon: Video },
      // A ORDEM DO FILME (pedido do Luciano, 2026-08-30): roteiro → personagens → cenários →
      // elementos → montagem. Cenários e Elementos estavam na sub-seção "Assets", DEPOIS da
      // Montagem — a barra ensinava a montar antes de ter onde e com o quê, e eles são etapa do
      // filme, não acervo avulso. Sprites e 3D continuam em Assets: esses são acervo mesmo.
      // 1. O QUE: prompt do filme → cenas + fichas de quem/onde/o quê derivadas da história.
      { href: "/escaleta", label: "Roteiro", icon: ListOrdered },
      // 2, 3, 4. QUEM / ONDE / COM O QUÊ: dar rosto às fichas que o Roteiro criou — o botão único
      // do Roteiro gera as três de uma vez; estas abas são pra conferir e refinar uma a uma.
      { href: "/personagens", label: "Personagens", icon: Users },
      { href: "/cenarios", label: "Cenários", icon: Map },
      { href: "/elementos", label: "Elementos", icon: Package },
      // 5. PRODUZIR: cada cena vira quadro, narração e clipe — e no fim o filme montado.
      { href: "/roteiro", label: "Montagem", icon: Waypoints },
      // Sub-seção ASSETS: acervo de peças reutilizáveis fora do fluxo de uma história —
      // sprite 2D animado por spritesheet e malha 3D (.glb).
      { href: "/sprite", label: "Sprites", icon: Gamepad2, section: "Assets" },
      { href: "/3d", label: "3D", icon: Box, section: "Assets" },
    ],
  },
  {
    title: "Publicar",
    items: [
      // A aba "Subir arquivo" (/postar) morreu em 2026-08-05: mídia que o cliente sobe já está
      // aprovada por definição — o upload manual vive no "➕ Novo post" da aba Publicar.
      { href: "/aprovar", label: "Aprovar", icon: CheckCircle2 },
      { href: "/publicar", label: "Publicar", icon: Send },
      { href: "/publicacoes", label: "Histórico", icon: Newspaper },
      { href: "/conexoes", label: "Conexões", icon: Plug },
      { href: "/galeria", label: "Galeria", icon: GalleryHorizontalEnd },
    ],
  },
  {
    title: "Conta",
    items: [
      { href: "/plano", label: "Plano e uso", icon: CreditCard },
      { href: "/conta", label: "Minha conta", icon: UserRound },
      // Guia de referência rápida (psicologia das cores) pra dar identidade visual consistente
      // a personagem/cenário/marca — veio junto na fusão do Estúdio (2026-08-01).
      { href: "/paleta-de-cores", label: "Paleta de Cores", icon: Palette },
      { href: "/ajuda", label: "Manual", icon: BookOpen },
    ],
  },
];

// Grupo com a página atual aberto por padrão; "" quando nenhuma rota bate (ex.: /admin).
function grupoAtivo(path: string): string {
  return GROUPS.find((g) => g.items.some((i) => i.href === path))?.title ?? GROUPS[0].title;
}

export function SideNav() {
  const path = usePathname();
  // Operador (RedFoxCode) vê o link pro painel admin; cliente comum não.
  const [operator, setOperator] = useState(false);
  useEffect(() => {
    sfetch("/api/me").then((r) => r.json()).then((u) => {
      if (u?.role === "operator" || u?.role === "admin") setOperator(true);
    }).catch(() => {});
  }, []);

  // ACORDEÃO: 27 itens em 4 grupos abertos ao mesmo tempo é o que deixou a barra gigante depois
  // da fusão do Estúdio (2026-08-01) — só o grupo da página atual abre sozinho; o resto fica
  // recolhido (só o título) até o clique. `aberto` é o CONJUNTO de grupos abertos (pode abrir
  // mais de um a mão); recalcula o padrão quando a navegação troca de grupo.
  const padrao = useMemo(() => grupoAtivo(path ?? ""), [path]);
  const [aberto, setAberto] = useState<Set<string>>(() => new Set([padrao]));
  useEffect(() => { setAberto((s) => (s.has(padrao) ? s : new Set(s).add(padrao))); }, [padrao]);
  const toggle = (title: string) => setAberto((s) => {
    const n = new Set(s);
    if (n.has(title)) n.delete(title); else n.add(title);
    return n;
  });

  const link = ({ href, label, icon: Icon, badge }: NavItem) => (
    <a key={href} href={href} className={path === href ? "active" : ""}>
      <Icon size={17} /> {label}
      {badge && (
        <span style={{ marginLeft: "auto", fontSize: ".58rem", fontWeight: 700, letterSpacing: ".03em", textTransform: "uppercase", color: "#e8a48f", background: "rgba(226,74,49,.16)", border: "1px solid rgba(226,74,49,.32)", padding: "1px 6px", borderRadius: 999 }}>{badge}</span>
      )}
    </a>
  );

  return (
    <>
      {GROUPS.map((g) => {
        const open = aberto.has(g.title);
        return (
          <div key={g.title}>
            <button type="button" className="navgroup" onClick={() => toggle(g.title)}
              style={{ display: "flex", alignItems: "center", justifyContent: "space-between", width: "100%", background: "none", border: "none", cursor: "pointer", font: "inherit" }}>
              {g.title}
              <ChevronDown size={13} style={{ transition: "transform .15s", transform: open ? "rotate(0deg)" : "rotate(-90deg)" }} />
            </button>
            {open && (
              <nav className="nav">
                {g.items.map((item, idx) => {
                  const prevSection = idx > 0 ? g.items[idx - 1].section : undefined;
                  const enteringSection = item.section && item.section !== prevSection;
                  return (
                    <div key={item.href}>
                      {enteringSection && (
                        <div style={{ fontSize: ".62rem", fontWeight: 700, letterSpacing: ".04em", textTransform: "uppercase", opacity: 0.5, padding: "10px 12px 2px" }}>
                          {item.section}
                        </div>
                      )}
                      {link(item)}
                    </div>
                  );
                })}
              </nav>
            )}
          </div>
        );
      })}
      {operator && (
        <div>
          <div className="navgroup">Operador</div>
          <nav className="nav">
            {/* Chaves API: pesquisa + geração + publicação, num lugar só. Admin: painel de operação. */}
            <a href="/chaves-api" className={path === "/chaves-api" ? "active" : ""}><KeyRound size={17} /> Chaves API</a>
            <a href={`${CONSOLE}/admin`}><ShieldCheck size={17} /> Admin</a>
          </nav>
        </div>
      )}
    </>
  );
}

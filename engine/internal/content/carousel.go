// carousel.go — 🎠 CARROSSEL: o motor editorial de posts de slides (Instagram/LinkedIn).
//
// Um tema vira uma peça editorial pronta: headline vencedora + arquitetura narrativa de N slides
// + brief de imagem por slide. Texto puro e barato; a imagem sai depois (um /v1/image por slide,
// pilotado pelo console).
//
// A régua editorial daqui NÃO é invenção: veio destilada de um sistema de carrossel que rodava à
// mão (famílias de gancho medidas em 56 posts com >10k likes, os 7 parâmetros de qualidade e a
// arquitetura de 18 campos). O manual legível fica em docs/REGRAS-DO-CARROSSEL.md — quando o
// carrossel sair mediano, edita-se a RÉGUA, não o prompt de quem pediu.
//
// Três chamadas encadeadas, de propósito: headline, narrativa e brief visual são decisões de
// natureza diferente, e juntá-las numa só faz o modelo entregar as três medianas.
package content

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"
)

// carouselMinimaxModel — vazio de propósito: usa o modelo PADRÃO da linha de texto, não o de
// raciocínio pesado que o gerador de HISTÓRIAS escolheu. Copiar o modelo do vizinho porque o
// código era parecido custou dois deploys (2026-08-02): com o reasoning model as três etapas
// estouravam 200s cada e a geração morria em "context deadline exceeded". Um plano editorial é
// copy — está mais perto do gerador de ideias, que roda no default e responde em segundos, do
// que de um roteiro de 20 cenas. Modelo é decisão, não herança.
const carouselMinimaxModel = ""

// carouselSlideCounts — formatos aceitos. 9 é o padrão; fora da lista, cai no mais próximo.
var carouselSlideCounts = []int{5, 7, 9, 12}

// ImageBrief — a direção de arte da imagem de UM slide. Campos concretos de propósito: adjetivo
// vago ("imagem bonita de tecnologia") produz stock genérico. O `Avoid` existe porque proibir é
// o que mais move a agulha em geração de imagem.
type ImageBrief struct {
	Purpose        string `json:"purpose"`
	Subject        string `json:"subject"`
	Composition    string `json:"composition"`
	Lighting       string `json:"lighting"`
	ColorTreatment string `json:"color_treatment"`
	Style          string `json:"style"`
	Mood           string `json:"mood"`
	Metaphor       string `json:"metaphor"`
	Avoid          string `json:"avoid"`
}

// Prompt — achata o brief no bloco textual que entra no pedido de imagem do slide.
func (b ImageBrief) Prompt() string {
	if strings.TrimSpace(b.Subject) == "" {
		return ""
	}
	var sb strings.Builder
	sb.WriteString("Subject: " + b.Subject)
	for _, f := range []struct{ label, v string }{
		{"Composition", b.Composition},
		{"Lighting", b.Lighting},
		{"Color treatment", b.ColorTreatment},
		{"Style", b.Style},
		{"Mood", b.Mood},
		{"Metaphor", b.Metaphor},
	} {
		if v := strings.TrimSpace(f.v); v != "" {
			sb.WriteString(". " + f.label + ": " + v)
		}
	}
	if v := strings.TrimSpace(b.Avoid); v != "" {
		sb.WriteString(". AVOID: " + v)
	}

	return sb.String()
}

// CarouselSlide — um slide já editado. `Blocks` são os blocos de copy na ordem de leitura
// (a capa tem chapéu+headline; os internos, dois blocos). `Accent` são as palavras que recebem
// a cor da marca — nunca frases inteiras.
type CarouselSlide struct {
	Index  int        `json:"index"` // 1-based
	Role   string     `json:"role"`  // capa|hook|mecanismo|prova|expansao|aplicacao|direcao|assinatura
	Tag    string     `json:"tag"`   // rótulo funcional no topo (vazio na capa e na assinatura)
	Blocks []string   `json:"blocks"`
	Accent []string   `json:"accent"`
	Image  ImageBrief `json:"image"`
}

// CarouselPlan — a peça inteira, pronta pra revisão do usuário ANTES de gastar crédito de imagem.
type CarouselPlan struct {
	Headline string          `json:"headline"`
	Family   string          `json:"family"` // família de gancho da vencedora
	Axis     string          `json:"axis"`   // Mercado|Cases|Notícias|Cultura|Produto
	Caption  string          `json:"caption"`
	Slides   []CarouselSlide `json:"slides"`
}

// CarouselBrand — a marca interpolada na régua editorial. Vem do kit de marca do tenant.
type CarouselBrand struct {
	Name     string `json:"name"`
	Handle   string `json:"handle"`
	Audience string `json:"audience"`
	Niche    string `json:"niche"`
	Voice    string `json:"voice"`
	Primary  string `json:"primary"` // hex da cor de accent
	HasLogo  bool   `json:"has_logo"`
}

// CarouselInput — o pedido. `Topic` aceita tema curto OU texto colado; entrada simples não é
// briefing fraco, é sinal de que o motor faz o trabalho pesado.
type CarouselInput struct {
	Topic       string        `json:"topic"`
	Slides      int           `json:"slides"`
	Lang        string        `json:"lang"`
	Persona     string        `json:"persona"`
	Brand       CarouselBrand `json:"brand"`
	VisualBrief string        `json:"visual_brief"` // saída do /v1/visual-brief; vazio = defaults sóbrios
	GenLines    GenLines      `json:"gen_lines"`
}

// ── A régua editorial ────────────────────────────────────────────────────────────────────────

// carouselAntiSlop — as construções que reprovam um bloco. Lista fechada de propósito: "evite
// texto de IA" não move nada; nomear a construção move.
const carouselAntiSlop = `NEVER use these constructions (each one alone disqualifies the block):
- Binary frames: "não é X, é Y" / "not X, but Y", "sem X, sem Y", "menos X, mais Y". This one slips through most often, so check every block for it explicitly: if you wrote "isso não é ideologia — é física urbana", the fix is to state the claim directly ("a física urbana explica isso melhor que a ideologia"), not to reword the same binary.
- Tics: "e isso muda tudo", "no fim das contas", "a pergunta que fica", "spoiler:", "a real é que".
- Dead openers: "descubra", "saiba", "conheça", "você precisa saber", "prepare-se".
- Empty hype: "incrível", "revolucionário", "poderoso", "game-changer", "disruptivo", "vai mudar tudo".
- Worn frames: "quando X vira Y", "a ascensão de", "o impacto de", "por que X está mudando", "virou" as the main verb.
- Numeric anglicisms ("10+", "5x" as a headline device) and decorative emojis.
- Listicles ("5 dicas para..."), empty motivational lines, and corporate jargon where a plain word exists.
Write like a Brazilian newspaper reporter, not like a translated American blog post.

LANGUAGE PURITY: every block is written in ONE language, start to finish. Never mix an English word or phrase into a Portuguese sentence ("construiu identity around do automóvel" is broken text, not style). If a foreign term has no natural equivalent, keep it as the single technical noun it is — never as a fragment of English grammar inside a Portuguese clause. Reread each block for this before you output it.`

// carouselQuality — os 7 parâmetros. Nota mínima 8/10 em CADA um; um abaixo reprova o bloco e
// obriga reescrita antes de entregar. O modelo julga internamente e só entrega o que passou.
const carouselQuality = `Every copy block must score at least 8/10 on ALL SEVEN parameters before you output it. If any parameter scores below 8, REWRITE the block — do not output it and do not explain the rewrite:
1. GRAMMAR — full sentences with subject and verb. Missing articles are the most frequent defect ("em período inferior a 1 ano" is wrong; "em um período inferior a 1 ano" is right). A verbless fragment is not a sentence.
2. FLOW — read it aloud. Choppy telegraphic lines fail. Use connectives (porque, só que, por isso, enquanto, quando, mas, ainda assim).
3. ANTI-SLOP — the forbidden list below, in full.
4. VERIFIED FACTS — never invent a number, date, percentage, valuation or quote. If you do not actually know it, write the claim without the number instead of inventing one.
5. STRUCTURE — the promise rule: every count announced in the hook appears in the deck; every contradiction raised is resolved before the pivot slide.
6. DENSITY — strip the articles and adjectives; what remains must be substance. Substitution test: swap the subject for an unrelated one — if the block still works, it is generic. Rewrite.
7. VOICE — direct, provocative, slightly acid, culturally informed. Talks to an intelligent adult. Never a motivational guru, never a tech bro, never a tool salesman.`

// ── Etapa 1 · headline ───────────────────────────────────────────────────────────────────────

const defaultCarouselHeadlineRole = `You are a Brazilian editorial director who writes hooks for social carousels. You have studied which openings actually travel and which die in the feed, and you are ruthless: a headline that merely states a fact is a failed headline. You never dramatize with words the story does not earn.`

// carouselHeadlineSystem — 8 famílias com lift medido + 6 gatilhos + veredito. O modelo gera 10
// candidatas cobrindo ≥6 famílias e entrega UMA vencedora; as candidatas ficam internas.
const carouselHeadlineSystem = `Generate 10 candidate headlines for a carousel on the topic below, then pick the single best one.

FAMILIES (measured lift over baseline in Brazilian Instagram — cover at least 6 different families across the 10 candidates):
1. National context "[Fenômeno] no Brasil: [o que revela]" (+155%)
2. Generational question "Por que [geração] está [comportamento inesperado]?" (+119%)
3. Rupture / end of cycle "[Ruptura real]: [o que substitui]" (+119%)
4. Investigation "Investigando [fenômeno cultural]" (+99%)
5. Counterintuitive datum "O dado que [contraria o senso comum]: [implicação]" (+92%)
6. Named brand/person in contradiction (+88%)
7. Colon reframe "[Enquadramento provocativo]: [lacuna de curiosidade]" — sentence 1 redefines the phenomenon, sentence 2 opens a real gap
8. Before/after contrast "[Antes concreto]. Agora [novo comportamento]. [Tensão aberta]."
Two of the ten must use the Magnetic Narrative format: exactly three sentences — [concrete scene]. [mechanism]. [open tension].

EMOTIONAL TRIGGERS — the winner must fire at least TWO simultaneously: nostalgia, fear/alert, indignation, identity, curiosity, aspiration. Proven pairs score higher: nostalgia+identity, fear+generational, national+identity, curiosity+nostalgia.

REJECTION CHECKLIST — a candidate that hits any of these is rewritten, not kept:
- plain declaration that states without provoking; generic reveal ("descubra/saiba/conheça"); listicle; empty motivational line;
- "a morte de" / "o fim de" used as automatic drama. These two words may appear in AT MOST ONE of the ten candidates, and only when the topic genuinely involves loss, closure, collapse or replacement.

RANKING — pick the winner by: number of families/lift patterns active, number of emotional triggers (minimum 2), proven trigger pairs, specificity to the audience, and absence of cliché. Prefer the more specific candidate on a tie.

COVER VERSION — hard constraint, not a suggestion: "cover" must be AT MOST 12 WORDS. It is set in very large type and physically cannot exceed 5 lines on the slide; a paragraph there is a broken cover, however good the sentence is. Derive it by cutting, never by summarising into a new idea, and KEEP THE SAME FAMILY (a colon headline stays a colon headline; a question stays a question; a three-sentence Magnetic Narrative collapses to its sharpest single sentence). Count the words before you answer.

Output ONLY JSON, no markdown fences:
{"headline":"the winning headline, full","cover":"the short cover version (same as headline if it already fits)","family":"family name","axis":"Mercado|Cases|Notícias|Cultura|Produto","triggers":["..."]}`

// ── Etapa 2 · arquitetura narrativa ──────────────────────────────────────────────────────────

const defaultCarouselNarrativeRole = `You are a Brazilian editorial writer building the narrative architecture of a carousel. You know a carousel is a VISUAL piece, not a blog post squeezed into squares: the image stops the scroll, the text only steers. When in doubt between 15 and 20 words, you choose 15.`

// carouselRoles — o papel de cada slide por tamanho de carrossel. A regra invariável: os três
// últimos slides sempre fazem a transição narrativa pro CTA.
func carouselRoles(n int) []string {
	switch n {
	case 5:
		return []string{"capa", "hook", "prova", "aplicacao", "assinatura"}
	case 7:
		return []string{"capa", "hook", "mecanismo", "prova", "expansao", "direcao", "assinatura"}
	case 12:
		return []string{"capa", "hook", "contexto", "mecanismo", "mecanismo", "prova", "prova", "expansao", "aplicacao", "aplicacao", "direcao", "assinatura"}
	default: // 9 — o padrão
		return []string{"capa", "hook", "mecanismo", "mecanismo", "prova", "expansao", "aplicacao", "direcao", "assinatura"}
	}
}

// carouselNarrativeSystem — monta a instrução da arquitetura para N slides.
func carouselNarrativeSystem(n int, brand CarouselBrand) string {
	roles := carouselRoles(n)
	var spec strings.Builder
	for i, role := range roles {
		spec.WriteString(fmt.Sprintf("- Slide %d (%s): %s\n", i+1, role, carouselRoleSpec(role)))
	}
	logo := `The brand name "` + brand.Name + `" becomes a large typographic lockup — treat it as a graphic element, never as a small caption.`
	if brand.HasLogo {
		logo = `The brand logo dominates the composition, so this slide's text is ONLY the CTA.`
	}
	// O nome e o @ da marca já são desenhados pelo template. Sem dizer isso, o modelo entrega o
	// handle como se fosse o texto do slide e a peça termina sem chamada nenhuma — foi o que
	// aconteceu na primeira geração real (2026-08-02: o slide 5 voltou com "@redfoxcode").
	logo += ` The signature slide's ONLY text block is the CTA itself — an imperative sentence telling the reader what to do next. NEVER output the brand name, the @handle or a URL as that block: the template already draws them. "@marca" is not a CTA.`

	return `Write the full narrative architecture of a ` + fmt.Sprint(n) + `-slide carousel from the winning headline and the topic below.

SLIDE ROLES:
` + spec.String() + `
BLOCK COUNT — structural, not stylistic. Every inner slide has EXACTLY TWO blocks, never one:
- block A is the ANCHOR: the claim the eye lands on first.
- block B is the CONTEXT: what makes the anchor land. It advances the idea — it never restates block A.
A single long block collapses the slide's two reading levels into a wall of text and breaks the layout. If you find yourself writing one long sentence, split the idea: the sharp claim goes in A, the reason goes in B.
The cover has exactly two blocks (kicker + headline). The signature slide has exactly ONE (the CTA).

WORD BUDGET — hard caps, counted before answering. A block over the cap gets CUT, never justified:
- Cover: block 1 at most 6 words; block 2 at most 12 words.
- Inner slides: block A at most 18 words; block B at most 14 words. Never more, on any slide.
- Signature slide: a single CTA of at most 8 words, ONE action only (comment, DM, save, download, join, book).
Count the words in every block. A 26-word block is a defect, however well written.
Total across the whole carousel stays near ` + fmt.Sprint(n*26) + ` words. Less is more: a good slide PROMISES and leaves the reader curious — it is not self-sufficient.

THE PROMISE RULE — validate before you output: every count announced in the hook appears in the deck; every contradiction raised in the hook is resolved by the mechanism; every promise is kept before the direction slide.

THE PIVOT — the expansion slide is where the reader stops learning and starts being led. Valid pivots: amplified implication, reframing, resolved contradiction, inverted priority. Invalid: restating the mechanism in other words, announcing the CTA early, moralizing.

THE SIGNATURE SLIDE is brand signature, not editorial conclusion. ` + logo + ` No paragraph, no summary of the carousel, no rhetorical question.

TAGS — each inner slide carries a short uppercase functional tag at the top (e.g. "O PROBLEMA", "OS NÚMEROS", "NA PRÁTICA", "A REGRA FINAL"). Tags inform the type of content; they are not slogans. The cover and the signature slide have NO tag.

ACCENT — per slide, list at most 3 keywords from that slide's own text that get the brand colour. Never whole phrases.

CAPTION — write the post caption. It CONTEXTUALIZES, it does not repeat the slides.

Output ONLY JSON, no markdown fences:
{"caption":"...","slides":[{"index":1,"role":"capa","tag":"","blocks":["...","..."],"accent":["..."]}]}
Exactly ` + fmt.Sprint(n) + ` slides, in order.`
}

// carouselRoleSpec — o que cada papel de slide precisa fazer.
func carouselRoleSpec(role string) string {
	switch role {
	case "capa":
		return "cover. Kicker + the winning headline. Nothing else."
	case "hook":
		return "creates tension — raises a contradiction or a surprising fact, connected directly to the headline."
	case "contexto":
		return "grounds the reader in what is happening before the mechanism explains why."
	case "mecanismo":
		return "explains WHY — the engine behind the phenomenon. Two blocks that advance, never restate."
	case "prova":
		return "anchors in concrete, verifiable evidence. Explicit numbers or a comparable set."
	case "expansao":
		return "the pivot — deepens or flips the perspective. Never a summary of what came before."
	case "aplicacao":
		return "translates it to the reader's real world — practical cases, examples across niches."
	case "direcao":
		return "makes the final turn of the argument and prepares the CTA narratively. Can be one short impact line."
	case "assinatura":
		return "brand signature + short CTA. Not a content slide."
	}

	return "advances the argument."
}

// ── Etapa 3 · brief de imagem ────────────────────────────────────────────────────────────────

const defaultCarouselArtRole = `You are an art director briefing the image that lives inside each carousel slide. You never write "a beautiful image of technology" — you write the colour, the light, the camera angle and the concrete objects in frame, because the generator renders what is described and infers abstraction badly.`

// carouselImageSystem — a regra-mãe: as REFERÊNCIAS mandam, o assunto obedece. Os dois erros
// recorrentes (escurecer o que não é escuro; ilustrar o assunto ao pé da letra) entram nomeados.
func carouselImageSystem(n int, vb string, brand CarouselBrand) string {
	refs := strings.TrimSpace(vb)
	if refs == "" {
		refs = `No brand references were decoded for this run. Default to a sober editorial register: 35mm editorial photography, mid-to-light luminosity, muted palette with a single saturated accent, generous negative space. Do NOT default to dark backgrounds.`
	}

	return `Write the image brief for each of the ` + fmt.Sprint(n) + ` slides of this carousel.

═══ THE BRAND'S VISUAL REFERENCES — THESE COMMAND ═══
` + refs + `

MASTER RULE — the references command, the subject obeys. Every field below is filled from the reference register, NOT from a literal reading of the topic:
- "style" copies the KIND of image the references use. Never a style the references do not have.
- "color_treatment" and "lighting" respect the references' tonal register. If the references are light or mid, the images are light or mid. NEVER drift darker than the references.
- "subject" and "metaphor" translate the topic INTO the language of the references. If the references show people, the subject has people. If the references are editorial, the subject is not a screenshot or a diagram.
- When the topic is about software, data or an abstract idea and the references are human and photographic, the conflict resolves ALWAYS in favour of the references: show the human scene that carries the topic's impact, never the product interface.
- "avoid" always includes "any style, palette or luminosity that contradicts the brand reference images".

PER-SLIDE PURPOSE — the image serves the slide's narrative function: cover = maximum narrative tension, a single strong focal point, dramatic light (chiaroscuro, rim light, golden hour or hard side light), selective saturation with the focal point in ` + orDefault(brand.Primary, "the brand colour") + `, and something happening in the captured instant; hook = a detail loaded with conflict; mechanism = revelation, looking inside, before/after; proof = the materiality of data (paper, screen, a printed number); expansion = scale and wider context; application = human, a gesture, an ordinary moment; signature = quiet composition with generous negative space.

SPECIFICITY — never an adjective where a noun works. Not "a scene" but "a pair of hands typing on an old silver laptop on a dark wooden table with a coffee cup to the left".

ABSOLUTE NO-GO in every brief: corporate handshakes, groups smiling at camera, generic hands typing on a laptop, lightbulb-as-idea, gears-as-strategy, stock photo aesthetic, glossy 3D, neon glow, Y2K, decorative icons, uncanny AI faces, and any text, lettering, watermark or logo rendered inside the image.

Output ONLY JSON, no markdown fences:
{"briefs":[{"index":1,"purpose":"...","subject":"...","composition":"...","lighting":"...","color_treatment":"...","style":"...","mood":"...","metaphor":"...","avoid":"..."}]}
Exactly ` + fmt.Sprint(n) + ` briefs, one per slide, in order.`
}

// ── O motor ──────────────────────────────────────────────────────────────────────────────────

// GenerateCarouselPlan — tema (ou texto colado) → headline vencedora + arquitetura narrativa +
// brief de imagem por slide. Texto puro: NÃO gera imagem nenhuma e não gasta crédito de imagem.
// O console mostra o plano pra revisão antes do render, que é a parte cara.
func (s *Service) GenerateCarouselPlan(ctx context.Context, in CarouselInput) (CarouselPlan, error) {
	topic := strings.TrimSpace(in.Topic)
	if topic == "" {
		return CarouselPlan{}, fmt.Errorf("carrossel: informe o tema ou cole o conteúdo")
	}
	n := normalizeCarouselSlides(in.Slides)
	gl := in.GenLines.WithDefaults()

	// Persona da aba Prompts substitui o PAPEL (quem escreve), nunca a régua (o que reprova).
	// Foi decisão explícita: a régua é o produto; a persona é o sotaque.
	role := func(def string) string {
		if p := strings.TrimSpace(in.Persona); p != "" {
			return clip(p, 4000)
		}

		return def
	}
	brandBlock := carouselBrandBlock(in.Brand)
	langRule := "Write ALL copy in BRAZILIAN PORTUGUESE (PT-BR)."
	if in.Lang == "en-US" {
		langRule = "Write ALL copy in English."
	}
	userTopic := "Topic or pasted content:\n<<<USER_INPUT>>>\n" + clip(topic, 8000) + "\n<<<END_USER_INPUT>>>"

	// 1 · headline.
	// 200s como no gerador de histórias, pelo mesmo motivo: com orçamento de raciocínio maior o
	// modelo demora mais de 90s, e o timeout curto matava a chamada logo depois de ela parar de
	// voltar vazia (2026-08-02, segundo smoke em produção). O job do console espera 400s.
	hctx, hcancel := context.WithTimeout(ctx, 200*time.Second)
	sys := role(defaultCarouselHeadlineRole) + "\n\n" + carouselHeadlineSystem + "\n\n" + brandBlock + "\n" + carouselAntiSlop + "\n" + langRule + " " + antiInjectionRule + whiteLabelRule
	// Orçamento de tokens GENEROSO, não o tamanho da resposta. O modelo de texto é de raciocínio:
	// ele pensa antes de responder, e o pensamento consome o mesmo orçamento. Com teto apertado
	// ele gasta tudo raciocinando e devolve resposta VAZIA — foi exatamente o que aconteceu no
	// primeiro smoke em produção (2026-08-02: "M3 vazio (só raciocínio)" com 2000 tokens). O
	// gerador de histórias já tinha aprendido isso e fixou piso de 8192 em `storyMaxTokens`.
	raw, err := s.genTextOrdered(hctx, gl.Text, sys, userTopic, carouselMaxTokens(10), true, carouselMinimaxModel)
	hcancel()
	if err != nil {
		return CarouselPlan{}, err
	}
	var head struct {
		Headline string `json:"headline"`
		Cover    string `json:"cover"`
		Family   string `json:"family"`
		Axis     string `json:"axis"`
	}
	if err := json.Unmarshal([]byte(extractJSON(raw)), &head); err != nil {
		return CarouselPlan{}, fmt.Errorf("carrossel: a headline não voltou em JSON válido")
	}
	head.Headline = stripCJK(strings.TrimSpace(head.Headline))
	head.Cover = stripCJK(strings.TrimSpace(head.Cover))
	if head.Headline == "" {
		return CarouselPlan{}, fmt.Errorf("carrossel: nenhuma headline aprovada")
	}
	if head.Cover == "" {
		head.Cover = head.Headline
	}

	// 2 · arquitetura narrativa.
	nctx, ncancel := context.WithTimeout(ctx, 200*time.Second)
	sys = role(defaultCarouselNarrativeRole) + "\n\n" + carouselNarrativeSystem(n, in.Brand) + "\n\n" + brandBlock + "\n" + carouselQuality + "\n" + carouselAntiSlop + "\n" + langRule + " " + antiInjectionRule + whiteLabelRule
	user := "Winning headline: " + head.Headline + "\nCover version of the headline (use it verbatim as the cover's second block): " + head.Cover + "\n\n" + userTopic
	raw, err = s.genTextOrdered(nctx, gl.Text, sys, user, carouselMaxTokens(n), true, carouselMinimaxModel)
	ncancel()
	if err != nil {
		return CarouselPlan{}, err
	}
	var narr struct {
		Caption string `json:"caption"`
		Slides  []struct {
			Index  int      `json:"index"`
			Role   string   `json:"role"`
			Tag    string   `json:"tag"`
			Blocks []string `json:"blocks"`
			Accent []string `json:"accent"`
		} `json:"slides"`
	}
	if err := json.Unmarshal([]byte(extractJSON(raw)), &narr); err != nil {
		return CarouselPlan{}, fmt.Errorf("carrossel: a arquitetura narrativa não voltou em JSON válido")
	}

	plan := CarouselPlan{
		Headline: head.Headline,
		Family:   stripCJK(strings.TrimSpace(head.Family)),
		Axis:     stripCJK(strings.TrimSpace(head.Axis)),
		Caption:  stripCJK(strings.TrimSpace(narr.Caption)),
	}
	roles := carouselRoles(n)
	for i, sl := range narr.Slides {
		if i >= n {
			break
		}
		blocks := make([]string, 0, len(sl.Blocks))
		for _, b := range sl.Blocks {
			if b = stripCJK(strings.TrimSpace(b)); b != "" {
				blocks = append(blocks, b)
			}
		}
		if len(blocks) == 0 {
			continue
		}
		role := strings.ToLower(strings.TrimSpace(sl.Role))
		if role == "" || i >= len(roles) {
			role = roles[min(i, len(roles)-1)]
		}
		accent := make([]string, 0, 3)
		for _, a := range sl.Accent {
			if a = stripCJK(strings.TrimSpace(a)); a != "" && len(accent) < 3 {
				accent = append(accent, a)
			}
		}
		plan.Slides = append(plan.Slides, CarouselSlide{
			Index: i + 1, Role: role,
			// Capa e assinatura nunca levam tag funcional — o modelo às vezes inventa uma.
			Tag:    tagFor(role, stripCJK(strings.TrimSpace(sl.Tag))),
			Blocks: blocks, Accent: accent,
		})
	}
	if len(plan.Slides) < 3 {
		return CarouselPlan{}, fmt.Errorf("carrossel: a arquitetura narrativa voltou incompleta")
	}

	// A CAPA tem conserto determinístico e de graça: a etapa 1 já produziu uma versão curta da
	// headline, validada contra o teto. Quando o redator da narrativa devolve a capa mais longa do
	// que cabe (aconteceu em produção: 16 palavras num teto de 12), repor pela versão curta é
	// melhor que pedir ao modelo — não gasta chamada e não corre o risco de virar outra ideia.
	if len(plan.Slides) > 0 && plan.Slides[0].Role == "capa" && len(plan.Slides[0].Blocks) == 2 {
		if palavras(plan.Slides[0].Blocks[1]) > capCapaHeadine && palavras(head.Cover) <= capCapaHeadine {
			plan.Slides[0].Blocks[1] = head.Cover
		}
	}

	// 2b · CONFERÊNCIA + CONSERTO DIRIGIDO. Instrução de prompt sozinha não segura contagem: em
	// produção saíram blocos de 26-29 palavras e slides com um bloco só, com a régua mandando o
	// contrário em letras maiúsculas. O que é CONTÁVEL a gente conta aqui, em código, e devolve ao
	// modelo só o que quebrou — uma chamada, cirúrgica, em vez de reescrever o carrossel inteiro
	// e torcer. Se o conserto falhar, o plano segue como está: copy fora do teto é defeito de
	// acabamento, não motivo pra jogar fora um plano que o usuário ainda vai revisar na tela.
	if faltas := conferirNarrativa(plan); len(faltas) > 0 {
		if fixed, ferr := s.consertarNarrativa(ctx, plan, faltas, role(defaultCarouselNarrativeRole), brandBlock, langRule, gl); ferr == nil {
			plan = fixed
		}
	}

	// 3 · brief de imagem por slide. Falhar aqui NÃO derruba o plano: a copy é o que o usuário
	// revisa, e o brief pode ser regerado sozinho depois. Degradar, nunca explodir.
	if briefs, berr := s.carouselImageBriefs(ctx, plan, in, n, role(defaultCarouselArtRole), brandBlock, gl); berr == nil {
		for i := range plan.Slides {
			if b, ok := briefs[plan.Slides[i].Index]; ok {
				plan.Slides[i].Image = b
			}
		}
	}

	return plan, nil
}

// carouselImageBriefs — etapa 3 isolada, indexada pelo número do slide.
func (s *Service) carouselImageBriefs(ctx context.Context, plan CarouselPlan, in CarouselInput, n int, role, brandBlock string, gl GenLines) (map[int]ImageBrief, error) {
	ctx, cancel := context.WithTimeout(ctx, 200*time.Second)
	defer cancel()

	var deck strings.Builder
	deck.WriteString("Headline: " + plan.Headline + "\n\nThe deck:\n")
	for _, sl := range plan.Slides {
		deck.WriteString(fmt.Sprintf("- Slide %d (%s): %s\n", sl.Index, sl.Role, strings.Join(sl.Blocks, " / ")))
	}
	sys := role + "\n\n" + carouselImageSystem(n, in.VisualBrief, in.Brand) + "\n\n" + brandBlock +
		"\nWrite every brief in ENGLISH (it is a prompt for an image generator, not copy for the reader). " + antiInjectionRule + whiteLabelRule
	raw, err := s.genTextOrdered(ctx, gl.Text, sys, deck.String(), carouselMaxTokens(n), true, carouselMinimaxModel)
	if err != nil {
		return nil, err
	}
	var out struct {
		Briefs []struct {
			Index int `json:"index"`
			ImageBrief
		} `json:"briefs"`
	}
	if err := json.Unmarshal([]byte(extractJSON(raw)), &out); err != nil {
		return nil, fmt.Errorf("carrossel: o brief visual não voltou em JSON válido")
	}
	briefs := make(map[int]ImageBrief, len(out.Briefs))
	for _, b := range out.Briefs {
		if b.Index > 0 && strings.TrimSpace(b.Subject) != "" {
			briefs[b.Index] = b.ImageBrief
		}
	}
	if len(briefs) == 0 {
		return nil, fmt.Errorf("carrossel: nenhum brief visual aproveitável")
	}

	return briefs, nil
}

// ── Brief visual: ler as referências da marca com visão ──────────────────────────────────────

const carouselVisualBriefPrompt = `You are decoding a brand's visual references so that generated slides look like they belong to the same feed.

Look at the reference image(s) and describe what is ACTUALLY there. Never prescribe what you think a good brand should look like — report what you see.

If a reference is a GRID of several slides (regular spacing, repeated borders, similar rectangles side by side), read each cell as a separate reference. One good grid is enough.

Answer as plain text under exactly these headings:

Color palette:
- the dominant colours you actually see, as hex approximations with a name.

Tonal register / luminosity (CRITICAL):
- Is the dominant register light, mid or dark? Describe the background luminosity you observe.
- State explicitly whether dark slides are present at all. If the references are light or mid, say so in a sentence a generator cannot misread.

Typography:
- The display face (headlines): describe its character — condensed, serif, geometric, its weight, its case, its kerning.
- The text face (body, tags, small print): the same.
- Give ONE fixed size relationship per role, never a range.

Imagery style (what KIND of image the refs use):
- editorial photography / people and gesture / object stills / illustration / abstract texture / mixed — describe it.
- State the level of literalness: literal depiction, metaphorical, or editorial.

Composition:
- text alignment, where content sits on the canvas, how much negative space, the image-to-text ratio you observe.

Texture and finish:
- grain, paper, flatness, gloss — what is actually present.

Use of gradient:
- observed in the refs, or NOT observed. Say which.

Detail signature:
- the small element repeated across the references (a thin footer line with a date and handle, a recurring mini-graphic, a tiny corner tag). Describe it precisely — this is what makes a feed look curated instead of nine loose images.

What to avoid:
- anything that would contradict these references.

Be concrete and short. No preamble, no markdown fences, no bullet decoration beyond the dashes.`

// GenerateVisualBrief — lê as imagens de referência da marca e devolve o briefing textual que
// comanda todos os briefs de imagem do carrossel. As refs entram INLINE (base64): o provedor de
// visão não baixa do nosso acervo (403), erro que já custou caro em outras features.
//
// Refs ilegíveis não são erro fatal: sem brief, o motor cai no registro sóbrio default.
func (s *Service) GenerateVisualBrief(ctx context.Context, refURLs []string) (string, error) {
	if len(refURLs) == 0 {
		return "", fmt.Errorf("brief visual: nenhuma referência enviada")
	}
	if len(refURLs) > 6 {
		refURLs = refURLs[:6] // teto: 6 refs já saturam a leitura e mantêm o custo previsível
	}
	var imgs []string
	for _, u := range refURLs {
		if data := buscarBytes(ctx, strings.TrimSpace(u)); len(data) > 0 {
			imgs = append(imgs, "data:"+sniffImageMime(data)+";base64,"+base64.StdEncoding.EncodeToString(data))
		}
	}
	if len(imgs) == 0 {
		return "", fmt.Errorf("brief visual: nenhuma referência pôde ser lida")
	}
	ctx, cancel := context.WithTimeout(ctx, 120*time.Second)
	defer cancel()
	out, err := s.image.VisionDescribeMany(ctx, "", carouselVisualBriefPrompt, imgs)
	if err != nil {
		return "", err
	}
	brief := stripCJK(strings.TrimSpace(out))
	if brief == "" {
		return "", fmt.Errorf("brief visual: leitura vazia")
	}

	return brief, nil
}

// ── Conferência determinística da copy ───────────────────────────────────────────────────────

// Tetos por papel de bloco. São os mesmos números da régua em docs/REGRAS-DO-CARROSSEL.md —
// mudou lá, muda aqui.
const (
	capCapaChapeu  = 6
	capCapaHeadine = 12
	capBlocoA      = 18
	capBlocoB      = 14
	capCTA         = 8
)

// palavras — contagem simples por espaços. Boa o bastante pra teto de copy: a diferença entre
// 18 e 19 palavras não muda a decisão, a diferença entre 18 e 29 muda.
func palavras(s string) int {
	return len(strings.Fields(s))
}

// conferirNarrativa — o que dá pra medir sem opinião: quantos blocos cada slide tem e quantas
// palavras cada bloco tem. Devolve as violações em linguagem que o modelo consegue agir.
func conferirNarrativa(plan CarouselPlan) []string {
	var faltas []string
	for _, sl := range plan.Slides {
		switch sl.Role {
		case "capa":
			if len(sl.Blocks) != 2 {
				faltas = append(faltas, fmt.Sprintf("slide %d (capa): tem %d bloco(s), precisa de exatamente 2 (chapéu + headline curta)", sl.Index, len(sl.Blocks)))
				continue
			}
			if n := palavras(sl.Blocks[0]); n > capCapaChapeu {
				faltas = append(faltas, fmt.Sprintf("slide %d bloco 1 (chapéu da capa): %d palavras, teto %d — corte", sl.Index, n, capCapaChapeu))
			}
			if n := palavras(sl.Blocks[1]); n > capCapaHeadine {
				faltas = append(faltas, fmt.Sprintf("slide %d bloco 2 (headline da capa): %d palavras, teto %d — corte sem virar outra ideia", sl.Index, n, capCapaHeadine))
			}
		case "assinatura":
			if len(sl.Blocks) != 1 {
				faltas = append(faltas, fmt.Sprintf("slide %d (assinatura): tem %d bloco(s), precisa de exatamente 1 (o CTA)", sl.Index, len(sl.Blocks)))
				continue
			}
			if n := palavras(sl.Blocks[0]); n > capCTA {
				faltas = append(faltas, fmt.Sprintf("slide %d (CTA): %d palavras, teto %d — uma ação só, no imperativo", sl.Index, n, capCTA))
			}
			// Pergunta retórica no lugar do CTA é proibida pela régua e é detectável: CTA é ordem,
			// não pergunta. Saiu "Sua cidade está pronta para isso?" numa geração real — dentro do
			// teto de palavras e mesmo assim sem chamada nenhuma.
			if strings.HasSuffix(strings.TrimSpace(sl.Blocks[0]), "?") {
				faltas = append(faltas, fmt.Sprintf("slide %d (CTA): é uma pergunta, não uma chamada — troque por uma ação no imperativo (comenta, salva, baixa, chama no direct)", sl.Index))
			}
		default:
			if len(sl.Blocks) != 2 {
				faltas = append(faltas, fmt.Sprintf("slide %d (%s): tem %d bloco(s), precisa de exatamente 2 — A é a âncora, B é o contexto que avança a ideia", sl.Index, sl.Role, len(sl.Blocks)))
				continue
			}
			if n := palavras(sl.Blocks[0]); n > capBlocoA {
				faltas = append(faltas, fmt.Sprintf("slide %d bloco A: %d palavras, teto %d — corte", sl.Index, n, capBlocoA))
			}
			if n := palavras(sl.Blocks[1]); n > capBlocoB {
				faltas = append(faltas, fmt.Sprintf("slide %d bloco B: %d palavras, teto %d — corte", sl.Index, n, capBlocoB))
			}
		}
	}

	return faltas
}

// consertarNarrativa — devolve ao modelo APENAS os blocos que quebraram a régua, com o defeito
// nomeado. Reescrever o carrossel inteiro seria mais caro e traria defeito novo nos blocos que
// já estavam bons.
func (s *Service) consertarNarrativa(ctx context.Context, plan CarouselPlan, faltas []string, role, brandBlock, langRule string, gl GenLines) (CarouselPlan, error) {
	ctx, cancel := context.WithTimeout(ctx, 200*time.Second)
	defer cancel()

	atual, err := json.Marshal(plan.Slides)
	if err != nil {
		return plan, err
	}
	sys := role + `

You are fixing an ALREADY APPROVED carousel. Below are the slides and a list of DEFECTS measured mechanically (word counts and block counts do not lie). Fix EXACTLY those defects and nothing else.

Rules for the fix:
- Keep every idea, fact and number that is already there. Cutting words is not cutting meaning: remove filler, not substance.
- A slide that needs a second block gets one that ADVANCES the idea — never a restatement of the first.
- The signature slide's single block is an imperative CTA of at most ` + fmt.Sprint(capCTA) + ` words with ONE action. The brand name and @handle are drawn by the template — they are NOT the CTA.
- Do not touch blocks that were not listed as defective.
- Reread every block you rewrite: it must be a complete, grammatical sentence in one single language, with no missing word and no word from another language. A truncated fragment is a defect, not a style.

Output ONLY JSON, no markdown fences, with the FULL corrected slide list in the original order:
{"slides":[{"index":1,"role":"capa","tag":"","blocks":["...","..."],"accent":["..."]}]}
` + brandBlock + "\n" + carouselAntiSlop + "\n" + langRule + whiteLabelRule

	user := "DEFECTS TO FIX:\n- " + strings.Join(faltas, "\n- ") + "\n\nCURRENT SLIDES:\n" + string(atual)
	raw, err := s.genTextOrdered(ctx, gl.Text, sys, user, carouselMaxTokens(len(plan.Slides)), true, carouselMinimaxModel)
	if err != nil {
		return plan, err
	}
	var out struct {
		Slides []struct {
			Index  int      `json:"index"`
			Role   string   `json:"role"`
			Tag    string   `json:"tag"`
			Blocks []string `json:"blocks"`
			Accent []string `json:"accent"`
		} `json:"slides"`
	}
	if jerr := json.Unmarshal([]byte(extractJSON(raw)), &out); jerr != nil {
		return plan, fmt.Errorf("carrossel: o conserto não voltou em JSON válido")
	}
	// Casa pelo índice: o conserto REPÕE blocos, nunca reordena nem cria slide. Slide que o
	// modelo devolveu fora de forma é ignorado — fica o original, que ao menos está coerente.
	porIndice := make(map[int][]string, len(out.Slides))
	for _, sl := range out.Slides {
		var blocks []string
		for _, b := range sl.Blocks {
			if b = stripCJK(strings.TrimSpace(b)); b != "" {
				blocks = append(blocks, b)
			}
		}
		if len(blocks) > 0 {
			porIndice[sl.Index] = blocks
		}
	}
	fixed := plan
	fixed.Slides = make([]CarouselSlide, len(plan.Slides))
	copy(fixed.Slides, plan.Slides)
	for i := range fixed.Slides {
		if b, ok := porIndice[fixed.Slides[i].Index]; ok {
			fixed.Slides[i].Blocks = b
		}
	}
	// O conserto entra SÓ se realmente melhorou. Sem esta guarda, um modelo que "consertou"
	// criando defeito novo entregaria um carrossel pior que o original, em silêncio.
	if len(conferirNarrativa(fixed)) >= len(conferirNarrativa(plan)) {
		return plan, nil
	}

	return fixed, nil
}

// ── Utilidades ───────────────────────────────────────────────────────────────────────────────

// carouselBrandBlock — a marca interpolada na régua. Campo vazio é OMITIDO em vez de virar
// placeholder: "{brand_audience}" cru dentro do prompt vaza pra copy.
func carouselBrandBlock(b CarouselBrand) string {
	var sb strings.Builder
	sb.WriteString("THE BRAND:\n")
	sb.WriteString("- Name: " + orDefault(b.Name, "the brand") + "\n")
	for _, f := range []struct{ label, v string }{
		{"Handle", b.Handle},
		{"Niche", b.Niche},
		{"Audience", b.Audience},
		{"Voice", b.Voice},
		{"Accent colour", b.Primary},
	} {
		if v := strings.TrimSpace(f.v); v != "" {
			sb.WriteString("- " + f.label + ": " + v + "\n")
		}
	}
	sb.WriteString("The brand does not talk about its subject as a product. It talks about quality, method and criteria WITHIN that subject: the subject is the context, quality is the theme.\n")

	return sb.String()
}

// tagFor — capa e assinatura nunca levam tag funcional, mesmo quando o modelo inventa uma.
func tagFor(role, tag string) string {
	if role == "capa" || role == "assinatura" {
		return ""
	}

	return strings.ToUpper(tag)
}

// carouselMaxTokens — orçamento por chamada, em função de quantas peças a resposta carrega.
// O piso de 8192 NÃO é folga: o modelo raciocina dentro do mesmo orçamento, e teto apertado faz
// ele gastar tudo pensando e devolver vazio. Mesmo piso que `storyMaxTokens` adotou pelo mesmo
// motivo — a lição já tinha sido paga uma vez.
func carouselMaxTokens(pecas int) int {
	t := 6000 + pecas*1100
	if t < 8192 {
		t = 8192
	}
	if t > 26000 {
		t = 26000
	}

	return t
}

// normalizeCarouselSlides — 5|7|9|12; qualquer outro valor cai no mais próximo (0 = padrão 9).
func normalizeCarouselSlides(n int) int {
	if n <= 0 {
		return 9
	}
	best, dist := 9, 1<<30
	for _, c := range carouselSlideCounts {
		d := c - n
		if d < 0 {
			d = -d
		}
		if d < dist {
			best, dist = c, d
		}
	}

	return best
}

func orDefault(v, def string) string {
	if v = strings.TrimSpace(v); v != "" {
		return v
	}

	return def
}

// sniffImageMime — o tipo real pelos bytes mágicos. Mandar PNG rotulado como JPEG faz o provedor
// de visão recusar a imagem inteira.
func sniffImageMime(data []byte) string {
	if mime := http.DetectContentType(data); strings.HasPrefix(mime, "image/") {
		return mime
	}

	return "image/jpeg"
}

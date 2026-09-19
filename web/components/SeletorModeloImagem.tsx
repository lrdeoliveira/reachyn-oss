"use client";

import { marcaMotor, nomeModelo, type MotorLugar } from "@/lib/motor";

/** O mínimo que o seletor precisa saber de um modelo. Estrutural de propósito: o `ImageModel`
 *  completo vive no Studio e carrega campos que não interessam aqui. */
type ModeloDeImagem = {
  slug: string;
  display_name: string;
  cost_credits: number | null;
  real_name?: string;
  qualities?: unknown[];
  runs_on?: MotorLugar;
  origem?: string;
};

/**
 * Seletor de MODELO DE IMAGEM — o mesmo controle que o card de Imagem já tinha, extraído para
 * poder viver também no Carrossel, no Vox e no Motion.
 *
 * Existe como componente porque esses cards GERAM IMAGEM e não deixavam escolher o motor: quem
 * queria a peça num modelo melhor (ou mais barato) tinha de gerar pela aba Imagem e trazer o
 * arquivo na mão. Copiar o bloco em quatro lugares garantiria quatro comportamentos divergindo no
 * primeiro ajuste — o mesmo raciocínio do StoryboardService.
 *
 * ORIGEM na frente ("☁️ nuvem · …", "✨ assinatura · …"): é o que separa de qual conta sai a peça
 * quando vários motores cobram de bolsos diferentes. O cliente recebe só o ícone — o campo
 * `origem` não chega nele (white-label #6).
 *
 * `desabilitadoPor`: quando a geração parte de uma imagem, quem manda é o motor especialista em
 * transformação (i2i) e esta escolha não se aplica — o seletor esmaece e explica no title, em vez
 * de sumir e deixar o usuário procurando.
 */
export function SeletorModeloImagem({ modelos, valor, onChange, desabilitadoPor, estilo, rotulo = "Modelo" }: {
  modelos: ModeloDeImagem[];
  valor: string;
  onChange: (slug: string) => void;
  desabilitadoPor?: string;
  estilo?: React.CSSProperties;
  rotulo?: string;
}) {
  // Um motor só = não há escolha a fazer; o seletor seria ruído na tela.
  if (modelos.length <= 1) return null;
  const travado = Boolean(desabilitadoPor);

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
      <label className="txt" style={{ color: "var(--muted)", fontSize: ".78rem" }}>{rotulo}</label>
      <select
        value={valor}
        onChange={(e) => onChange(e.target.value)}
        disabled={travado}
        title={desabilitadoPor || "Modelo de IA da imagem — cada um tem qualidade e custo diferentes"}
        style={{ ...(estilo ?? {}), opacity: travado ? 0.5 : 1 }}
      >
        {modelos.map((m) => (
          <option key={m.slug} value={m.slug}>
            {marcaMotor(m)} · {nomeModelo(m)}
            {!m.qualities?.length && m.cost_credits != null ? ` · ${m.cost_credits} créd` : ""}
          </option>
        ))}
      </select>
    </div>
  );
}

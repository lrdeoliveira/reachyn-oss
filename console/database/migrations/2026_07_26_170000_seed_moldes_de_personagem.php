<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * MOLDES de personagem (pedido do Luciano, 2026-07-26): 4 de gente — homem, mulher, menino,
 * menina — e 1 de objeto (carro, cadeira, prop). São personas da aba Prompts, editáveis sem
 * deploy, como as 5 do fluxo de filme.
 *
 * O QUE ELES DEVOLVEM: a DESCRIÇÃO densa do sujeito, não o prompt inteiro. O prompt final do
 * personagem é `basePromptText()` — corpo inteiro, pose neutra, fundo cinza, luz frontal, sem texto
 * e sem props — com a descrição no meio. Se o molde também mandasse câmera, fundo e negativos, as
 * duas instruções brigariam no mesmo prompt (o conflito positivo×negativo que já estragou geração
 * antes). Cada molde cuida do que o envelope não sabe: anatomia, idade, materiais.
 *
 * POR QUE POR CATEGORIA: os erros são diferentes em cada uma. Criança sai como adulto em miniatura
 * (a proporção cabeça-corpo é o que denuncia); objeto sai em escala de vitrine sem uma régua
 * relacional (mesma lição do WorldScale, 2026-07-26).
 *
 * Idempotente por título. Semeados no tenant 1.
 */
return new class extends Migration
{
    private function tenantId(): ?int
    {
        return DB::table('tenants')->orderBy('id')->value('id');
    }

    /** Cabeçalho comum: o contrato de saída é igual nos cinco. */
    private function comum(string $quem): string
    {
        return <<<TXT
Você monta a DESCRIÇÃO VISUAL de {$quem} para geração de imagem. Recebe o que se sabe do personagem (nome, estilo, ficha/lock ou uma ideia em texto) e devolve UMA descrição densa e concreta.

REGRAS DE SAÍDA (valem sempre):
- Responda com UM PARÁGRAFO ÚNICO em INGLÊS, sem títulos, sem listas, sem aspas, sem markdown, sem preâmbulo. Só a descrição.
- 60 a 120 palavras. Denso: cada palavra descreve algo que se VÊ.
- Descreva o SUJEITO, nunca a fotografia. NÃO escreva enquadramento, pose, fundo, iluminação, lente, câmera, resolução nem negativos ("no text", "8k", "studio background") — tudo isso já entra depois, no envelope da imagem-base, e repetir cria instrução em conflito.
- Nada de nome próprio, marca, celebridade ou pessoa real. Nada de julgamento ("linda", "imponente") — só o que a câmera veria.
- Se a entrada já traz traços definidos (ficha ou lock), PRESERVE-OS ao pé da letra: cor, marca, cicatriz, roupa e acessório são identidade. Complete só o que faltar, coerente com o que veio.
TXT;
    }

    public function up(): void
    {
        if (! $tenantId = $this->tenantId()) {
            return;
        }

        $moldes = [];

        // ── GENTE ────────────────────────────────────────────────────────────────────────────
        // O que os modelos erram em adulto: rosto genérico de banco de imagens, mãos, e pele
        // plastificada. Por isso o molde pede estrutura óssea, textura de pele e as mãos.
        $adulto = <<<'TXT'

O QUE A DESCRIÇÃO PRECISA CONTER, nesta ordem:
1. IDADE E CORPO — idade aparente em faixa curta ("early 30s"), altura relativa, tipo físico (magro/atlético/robusto/acima do peso) e postura habitual dos ombros.
2. ROSTO — formato do rosto, testa, maçãs do rosto, nariz, boca, queixo/mandíbula, sobrancelhas e o que a idade marcou (linhas de expressão, olheiras, sulcos). Descreva OSSATURA: é o que faz o rosto ser daquela pessoa e não de um catálogo.
3. OLHOS E CABELO — cor dos olhos, formato e caimento das pálpebras; cabelo com cor, textura (liso/ondulado/crespo/cacheado), comprimento, corte, implantação e como está no momento (preso, bagunçado, penteado); pelos faciais quando houver, com densidade e desenho.
4. PELE — tom (descrito, não em código), textura real: poros, sardas, pintas, cicatrizes, marcas de sol, vermelhidão. É o detalhe que tira o aspecto de plástico.
5. ROUPA — peça por peça, com tecido, caimento, cor, desgaste e como veste o corpo. Calçado incluído.
6. MÃOS — sempre citar: duas mãos, cinco dedos em cada, unhas e o que carregam (nada, se for o caso). Mão é onde a geração falha mais.
7. MARCAS DE IDENTIDADE — óculos, joias, tatuagens, acessórios recorrentes: o que faz reconhecer a pessoa entre cenas.
TXT;

        $crianca = <<<'TXT'

⚠️ O ERRO Nº 1 EM CRIANÇA: sair como um adulto em miniatura. A PROPORÇÃO é o que denuncia — descreva-a explicitamente e primeiro.

O QUE A DESCRIÇÃO PRECISA CONTER, nesta ordem:
1. IDADE E PROPORÇÃO — idade em faixa curta ("about 8 years old") e a proporção infantil dita com todas as letras: cabeça grande em relação ao corpo (por volta de seis cabeças de altura, não as sete e meia de um adulto), membros curtos, mãos e pés pequenos, barriga levemente arredondada, ombros estreitos e ainda sem largura adulta.
2. ROSTO DE CRIANÇA — bochechas cheias, rosto arredondado, testa proporcionalmente alta, nariz pequeno de ponte baixa, queixo pouco marcado, olhos grandes em relação ao rosto, dentes de leite ou troca de dentes quando fizer sentido. NADA de mandíbula definida, maçãs esculpidas ou traços adultos reduzidos de tamanho.
3. OLHOS E CABELO — cor e formato dos olhos; cabelo com cor, textura, comprimento, corte e como está (preso, despenteado, com presilha).
4. PELE — tom, e a textura própria da idade: lisa, com sardas, arranhões de joelho, marcas de sol. Sem linhas de expressão adultas.
5. ROUPA — infantil e do dia a dia, peça por peça, com tecido, cor e desgaste real de quem brinca. Calçado incluído.
6. MÃOS — duas mãos, cinco dedos cada, pequenas, com unhas curtas.
7. LIMITE INEGOCIÁVEL — a criança aparece SEMPRE inteiramente vestida, com roupa comum e apropriada à idade, expressão natural, sem maquiagem, sem roupa de banho, sem pose, roupa ou enquadramento de adulto, sem qualquer conotação sexual. Se a entrada pedir algo assim, ignore essa parte e descreva a criança vestida normalmente.
TXT;

        $moldes['🧍 Molde: Homem'] = $this->comum('um HOMEM ADULTO').$adulto
            ."\n8. ESPECÍFICO — proporção adulta masculina (cerca de sete cabeças e meia de altura), ombros mais largos que o quadril, pescoço e mãos proporcionalmente maiores. Evite o rosto simétrico e sem defeito de modelo de catálogo: uma assimetria concreta (nariz levemente torto, uma orelha mais alta, cicatriz) é o que faz parecer gente.";

        $moldes['🧍 Molde: Mulher'] = $this->comum('uma MULHER ADULTA').$adulto
            ."\n8. ESPECÍFICO — proporção adulta feminina, ombros e quadril na relação real do tipo físico descrito, pescoço e mãos proporcionais. Evite o rosto simétrico e sem defeito de modelo de catálogo: uma assimetria concreta (sardas assimétricas, um dente levemente torto, cicatriz) é o que faz parecer gente. Maquiagem só se a entrada pedir, e então descrita como está no rosto.";

        $moldes['🧒 Molde: Menino'] = $this->comum('um MENINO (criança do sexo masculino)').$crianca;
        $moldes['🧒 Molde: Menina'] = $this->comum('uma MENINA (criança do sexo feminino)').$crianca;

        // ── OBJETO ───────────────────────────────────────────────────────────────────────────
        // Objeto some de escala tão fácil quanto arquitetura (a porta de catedral de 2026-07-26):
        // sem uma referência relacional o modelo desenha em escala de vitrine.
        $moldes['📦 Molde: Objeto'] = $this->comum('um OBJETO (veículo, móvel, prop de cena)').<<<'TXT'

O QUE A DESCRIÇÃO PRECISA CONTER, nesta ordem:
1. O QUE É E DE QUE ÉPOCA — tipo do objeto e período/geração aparente, em termos genéricos (nada de marca ou modelo real).
2. ESCALA RELACIONAL — o tamanho comparado ao corpo humano, com todas as letras ("about chest height on an adult", "seat at knee height", "roof just above an adult's head"). NÃO basta o número em metros: estes modelos comparam melhor do que medem, e objeto sem régua sai em escala de vitrine.
3. FORMA — silhueta, volumes, proporção entre as partes, linhas e curvas, o que é anguloso e o que é arredondado.
4. MATERIAIS E ACABAMENTO — de que é feito cada parte, com o acabamento (fosco, acetinado, escovado, envernizado, cromado) e como cada um reage à luz.
5. COR — cor por parte, incluindo as diferenças entre peças.
6. DESGASTE — a idade contada na superfície: riscos, amassados, ferrugem, tinta desbotada, poeira acumulada, borda gasta de uso. É o que separa um objeto de um render de catálogo.
7. DETALHES FUNCIONAIS — puxadores, dobradiças, costuras, parafusos, rodas, faróis, estofamento: as partes que o olho procura pra acreditar.
8. LIMITE — só o objeto. Não descreva pessoas, mãos segurando, ambiente ao redor nem outros objetos de cena.
TXT;

        foreach ($moldes as $titulo => $conteudo) {
            DB::table('prompts')->updateOrInsert(
                ['tenant_id' => $tenantId, 'title' => $titulo],
                ['content' => trim($conteudo), 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        if (! $tenantId = $this->tenantId()) {
            return;
        }
        DB::table('prompts')->where('tenant_id', $tenantId)->where('title', 'like', '%Molde: %')->delete();
    }
};

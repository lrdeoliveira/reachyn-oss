import { redirect } from "next/navigation";

// A aba Vox foi ABSORVIDA pela aba Vídeo em 2026-08-06 (o Vox virou um ESTILO dentro do fluxo de
// vídeo, com o layout de storyboard dele como base). Rota preservada por redirect — links já
// enviados, favoritos e a Central de Tarefas continuam funcionando. Mesmo padrão do /filme →
// /movies (a69638e).
export default function Page() { redirect("/video"); }

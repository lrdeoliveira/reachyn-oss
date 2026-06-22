import { redirect } from "next/navigation";
// O passo "Conteúdo" agora é a página de edição dedicada (/editar).
export default function Page() { redirect("/editar"); }

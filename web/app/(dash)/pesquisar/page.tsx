import { redirect } from "next/navigation";
// Compat: a etapa "Pesquisar" agora vive na raiz (app.example.com/). Mantém a URL antiga.
export default function Page() { redirect("/"); }

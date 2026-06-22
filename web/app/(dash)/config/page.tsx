import { redirect } from "next/navigation";
// Chaves de pesquisa foram unificadas em Chaves API (operador). Mantém a URL antiga.
export default function Page() { redirect("/chaves-api"); }

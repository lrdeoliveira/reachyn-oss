import { Studio } from "@/components/Studio";
import { OnboardingChecklist } from "@/components/OnboardingChecklist";

// Home do dashboard (app.example.com/): primeira etapa do fluxo (Pesquisar).
// É aqui que o cliente cai ao logar. O checklist de primeiro uso (S3) aparece em cima
// enquanto os 4 passos-chave não foram completados (some sozinho ou pelo ×).
export default function Page() {
  return (
    <>
      <OnboardingChecklist />
      <Studio step="research" />
    </>
  );
}

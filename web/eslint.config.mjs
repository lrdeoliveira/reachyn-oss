import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  {
    rules: {
      // `set-state-in-effect` entrou junto com o react-hooks v6 (React Compiler) e passou a
      // reprovar, de uma vez, as 28 cargas iniciais do app — `useEffect(() => { load(); }, [])`.
      //
      // Aqui isso NÃO é descuido: toda request leva `X-Tenant-Id` lido do localStorage
      // (`getActiveTenant` em lib/api.ts), e localStorage não existe no servidor. Enquanto a
      // marca ativa morar ali, os dados TÊM que ser buscados no cliente, e buscar no cliente é
      // um efeito. As saídas de verdade são trocar o tenant para cookie (aí a busca sobe para
      // Server Component) ou adotar React Query/SWR — decisões de arquitetura, não ajuste local.
      //
      // Fica em `warn`, não `off`: continua visível para não virar dívida invisível, mas para
      // de reprovar código que só tem essa forma por causa de onde o tenant mora. Revisitar se
      // o tenant migrar para cookie.
      "react-hooks/set-state-in-effect": "warn",
    },
  },
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
]);

export default eslintConfig;

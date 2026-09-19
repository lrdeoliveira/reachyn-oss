// Gera o bundle do Remotion em build-time (imagem self-contained — GUIDELINE 12):
// o server.mjs só CONSOME ./bundle; nenhum bundling acontece em produção.
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {bundle} from '@remotion/bundler';

const DIR = path.dirname(fileURLToPath(import.meta.url));
const out = await bundle({
  entryPoint: path.join(DIR, '..', 'remotion', 'index.ts'),
  outDir: path.join(DIR, '..', 'bundle'),
});
console.log('bundle →', out);

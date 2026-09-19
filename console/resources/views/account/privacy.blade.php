<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Privacidade & Meus Dados — Reachyn</title>
<meta name="robots" content="noindex" />
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root{--bg:#141110;--panel:rgba(255,255,255,.03);--line:rgba(255,255,255,.08);--txt:#efe7e2;--muted:#9d938b;--red:#e24a31;--red-l:#ffb4a6}
  *{box-sizing:border-box;margin:0;padding:0}
  body{background:var(--bg);color:var(--txt);font-family:Inter,system-ui,sans-serif;line-height:1.6}
  a{color:var(--red-l)}
  main{max-width:720px;margin:0 auto;padding:48px 24px 72px}
  h1{font-size:1.7rem;font-weight:800;margin-bottom:.2em}
  h2{font-size:1.15rem;font-weight:700;margin:0 0 .5em}
  p{color:#d8cfc8;margin:.6em 0}
  .card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:20px 22px;margin:18px 0}
  .danger{border-color:rgba(226,74,49,.4)}
  label{display:block;font-size:.85rem;color:var(--muted);margin:12px 0 4px}
  input[type=text],input[type=password]{width:100%;padding:10px 12px;background:#0e0c0b;border:1px solid var(--line);border-radius:8px;color:var(--txt);font:inherit}
  .btn{display:inline-block;padding:10px 16px;border-radius:8px;border:1px solid var(--line);background:transparent;color:var(--txt);font:inherit;font-weight:600;cursor:pointer;text-decoration:none}
  .btn.primary{background:var(--red);border-color:var(--red);color:#fff}
  .btn.danger{background:var(--red);border-color:var(--red);color:#fff}
  .muted{color:var(--muted);font-size:.88rem}
  .ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.4);border-radius:8px;padding:10px 12px;margin:12px 0;color:#bbf7d0}
  .err{background:rgba(226,74,49,.12);border:1px solid rgba(226,74,49,.4);border-radius:8px;padding:10px 12px;margin:12px 0;color:#ffb4a6}
  .chk{display:flex;gap:8px;align-items:flex-start;margin:12px 0}
  .chk input{margin-top:4px}
</style>
</head>
<body>
<main>
  <h1>Privacidade & Meus Dados</h1>
  <p class="muted">Conta: <strong>{{ $org->name ?? $org->slug }}</strong> · seus direitos sob a LGPD (Lei nº 13.709/2018).</p>

  @if (session('status'))<div class="ok">{{ session('status') }}</div>@endif
  @if ($errors->any())<div class="err">{{ $errors->first() }}</div>@endif

  <div class="card">
    <h2>Exportar meus dados (portabilidade)</h2>
    <p>Baixe uma cópia dos seus dados e conteúdos em formato JSON. Credenciais e segredos são omitidos por segurança.</p>
    <a class="btn primary" href="{{ route('account.export') }}">Baixar export (JSON)</a>
  </div>

  <div class="card">
    <h2>Exercer outros direitos</h2>
    <p>Para acesso, correção, anonimização, oposição ou dúvidas sobre o tratamento dos seus dados, fale com nosso Encarregado de Dados (DPO):</p>
    <p><a href="mailto:contato@redfoxcode.com.br">contato@redfoxcode.com.br</a> · você também pode peticionar à ANPD.</p>
  </div>

  <div class="card danger">
    <h2>Excluir minha conta</h2>
    @if ($pending)
      <p>⚠️ A exclusão está <strong>agendada para {{ $scheduledFor }}</strong>. Após essa data, todos os dados e mídias serão eliminados de forma irreversível.</p>
      <form method="POST" action="{{ route('account.deletion.cancel.panel') }}">
        @csrf
        <button class="btn" type="submit">Cancelar exclusão</button>
      </form>
    @else
      <p>Exclui <strong>definitivamente</strong> sua conta, todos os tenants, conteúdos e mídias, após uma carência de 7 dias (cancelável). Exporte seus dados antes, se quiser.</p>
      <form method="POST" action="{{ route('account.deletion.request') }}">
        @csrf
        <label for="password">Confirme sua senha</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
        <label for="slug_confirm">Digite o identificador da conta para confirmar: <strong>{{ $org->slug }}</strong></label>
        <input type="text" id="slug_confirm" name="slug_confirm" autocomplete="off" required>
        <div class="chk">
          <input type="checkbox" id="acknowledge" name="acknowledge" value="1" required>
          <label for="acknowledge" style="margin:0">Entendo que esta ação é irreversível após a carência de 7 dias.</label>
        </div>
        <button class="btn danger" type="submit">Agendar exclusão da conta</button>
      </form>
    @endif
  </div>

  <p class="muted"><a href="/">← Voltar</a></p>
</main>
</body></html>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Exclusão de conta — Reachyn</title>
<meta name="robots" content="noindex" />
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
<style>
  body{background:#141110;color:#efe7e2;font-family:Inter,system-ui,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;text-align:center;padding:24px}
  .box{max-width:480px}
  h1{font-size:1.5rem;margin:0 0 .4em}
  p{color:#d8cfc8}
  a{color:#ffb4a6}
</style>
</head>
<body>
<div class="box">
  @if ($ok)
    <h1>✅ Exclusão cancelada</h1>
    <p>Sua conta no Reachyn <strong>não</strong> será excluída. O pedido de exclusão foi cancelado com sucesso.</p>
  @else
    <h1>Link inválido ou expirado</h1>
    <p>Não encontramos um pedido de exclusão pendente para este link. Ele pode já ter sido usado, cancelado ou expirado.</p>
  @endif
  <p><a href="https://app.reachyn.agency/app/login">Ir para o login</a></p>
  <p style="font-size:.85rem;color:#9d938b">Dúvidas? contato@redfoxcode.com.br</p>
</div>
</body></html>

@component('mail::message')
# Sua criação ficou pronta 🎉

{{ $what }} terminou de ser gerado e já está disponível no seu Studio.

@component('mail::button', ['url' => $url])
{{ $cta }}
@endcomponent

Você recebe este aviso porque uma geração longa foi concluída enquanto você não estava
na tela. Para deixar de receber, ajuste as preferências de aviso em **Plano &amp; Uso**.

Obrigado,<br>
Reachyn
@endcomponent

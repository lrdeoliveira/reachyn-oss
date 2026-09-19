@component('mail::message')
# {{ $headline }}

{{ $body }}

@component('mail::button', ['url' => $url])
{{ $cta }}
@endcomponent

Para deixar de receber este tipo de aviso, ajuste as preferências em **Plano &amp; Uso**.

Obrigado,<br>
Reachyn
@endcomponent

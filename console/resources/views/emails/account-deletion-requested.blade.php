@component('mail::message')
# Exclusão de conta agendada

Recebemos um pedido para **excluir definitivamente** a conta **{{ $organizationName }}** no Reachyn.

A exclusão está agendada para **{{ $scheduledFor }}** (carência de 7 dias). Após essa data, todos os dados, conteúdos e mídias serão **eliminados de forma irreversível**.

Se **não** foi você, ou mudou de ideia, cancele agora:

@component('mail::button', ['url' => $cancelUrl])
Cancelar exclusão
@endcomponent

Se você não fizer nada, a exclusão seguirá automaticamente na data agendada.

Dúvidas? Fale com nosso Encarregado de Dados: contato@redfoxcode.com.br

Obrigado,<br>
Reachyn
@endcomponent

{if $conekta_order.valid == 1 }
	<div class="conf confirmation">{l s='Pago Exitoso, el pago ha sido aprobado y el pedido se ha guardado con la referencia ' mod='conektatarjeta'} <b>{$conekta_order.reference|escape:html:'UTF-8'}</b>.</div>
{else}
	{if $order_pending}
		<div class="conf confirmation">{l s='Congratulations, your payment has been received and your order has been saved under the reference' mod='conektatarjeta'} <b>{$conekta_order.reference|escape:html:'UTF-8'}</b>.</div>
		<div class="conf confirmation">{l s='We will review and process your order shortly.' mod='conektatarjeta'}</div>
	{else}
		<div class="error">{l s='Sorry, unfortunately an error occured during the transaction.' mod='conektatarjeta'}<br /><br />
		{l s='Please double-check your credit card details and try again or feel free to contact us to resolve this issue.' mod='conektatarjeta'}<br /><br />
		({l s='Your Order\'s Reference:' mod='conektatarjeta'} <b>{$conekta_order.reference|escape:html:'UTF-8'}</b>)</div>
	{/if}
{/if}

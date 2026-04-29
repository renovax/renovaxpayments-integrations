{*
 * RENOVAX Payments — Awaiting confirmation page (front controller "return").
 *}
{extends file='page.tpl'}

{block name='page_title'}
  {l s='Awaiting payment confirmation' d='Modules.Renovaxpayments.Shop'}
{/block}

{block name='page_content'}
  <div class="rnx-await">
    <p>{l s='Thanks — we are awaiting payment confirmation. Your order will be updated automatically once RENOVAX confirms the transaction.' d='Modules.Renovaxpayments.Shop'}</p>
    <p>{l s='You can close this page; you will receive an email when the payment is confirmed.' d='Modules.Renovaxpayments.Shop'}</p>
  </div>
{/block}

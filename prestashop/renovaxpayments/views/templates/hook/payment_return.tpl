{*
 * RENOVAX Payments — Order confirmation page hook.
 *}
<div class="rnx-payment-return">
  <p>
    {l s='Thanks — we are awaiting payment confirmation. Your order will be updated automatically once RENOVAX confirms the transaction.' d='Modules.Renovaxpayments.Shop'}
  </p>
  <p>
    {l s='If you have any question, please contact %shop%.' sprintf=['%shop%' => $shop_name] d='Modules.Renovaxpayments.Shop'}
  </p>
</div>

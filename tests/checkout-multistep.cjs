/* Run with jsdom/jquery on NODE_PATH and checkout-render-fixture.php HTML as argv[2]. */
const {JSDOM}=require('jsdom');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'..');
(async()=>{
const html=fs.readFileSync(process.argv[2],'utf8');
const dom=new JSDOM(html,{url:'http://localhost/checkout/',runScripts:'outside-only',pretendToBeVisual:true});
const w=dom.window,d=w.document;
const $=require('jquery')(w); w.jQuery=$;
// jsdom has no layout. Visibility here follows hidden panels/ancestors.
$.expr.pseudos.visible=el=>!el.closest('[hidden]')&&!el.closest('.shipping_address');
w.HTMLElement.prototype.scrollIntoView=function(){};
w.koopoCheckout={nextPayment:'Continue to Payment',nextReview:'Continue to Review',required:'Complete required fields',shipping:'Choose shipping for every package',payment:'Choose payment',busy:'Updating',failed:'Update failed',deliveryCopy:'Delivery',paymentCopy:'Payment',reviewCopy:'Review',noPayment:'No payment required',noShipping:'No delivery required',backPayment:'Back to Payment',backDelivery:'Back to Delivery',changed:'Order changed',checkMessage:'Check your order message'};
const q=s=>d.querySelector(s),all=s=>[...d.querySelectorAll(s)],form=q('form.checkout');
const modal=process.argv.includes('--modal');
if(modal){const dialog=d.createElement('dialog');d.body.append(dialog);dialog.append(form);}
const rawPayment=q('#payment').outerHTML,rawTable=q('.woocommerce-checkout-review-order-table').outerHTML;
w.eval(fs.readFileSync(root+'/includes/commerce/checkout/checkout.js','utf8'));
d.dispatchEvent(new w.Event('DOMContentLoaded')); await new Promise(r=>setTimeout(r,20));
assert.equal(d.body.classList.contains('kc-enhanced-page'),!modal);
assert.equal(form.dataset.step,'delivery');
assert.equal(all('#place_order').length,1);
assert.equal(all('[name="woocommerce-process-checkout-nonce"]').length,1);
assert.equal(q('#billing_email').closest('.kc-contact-fields')!==null,true);
assert.equal(q('.kc-payment-slot #payment')!==null,true);
assert.equal(q('#terms').closest('.kc-sidebar')!==null,true);
console.log('PASS one native form, nonce, payment node and submit button');
q('#billing_email').value='invalid';q('.kc-next').click();assert.equal(form.dataset.step,'delivery');assert.equal(d.activeElement.id,'billing_email');
q('#billing_email').value='preview@example.test';
if(form.dataset.needsShipping==='1'){
 assert.equal(all('.kc-shipping-table tr.shipping').length,2);
 q('#fixture_shipping_0').checked=false;q('.kc-next').click();assert.equal(form.dataset.step,'delivery');
 q('#fixture_shipping_0').checked=true;
 console.log('PASS every seller package needs a selected shipping option');
}
q('.kc-next').click();assert.equal(form.dataset.step,'payment');
const iframe=q('.payment_box iframe'),payment=q('#payment');
q('.kc-next').click();assert.equal(form.dataset.step,'review');
assert.equal(q('.kc-native-action').type,'submit');
q('[data-kc-edit="payment"]').click();assert.equal(form.dataset.step,'payment');
assert.equal(q('#payment'),payment);assert.equal(q('.payment_box iframe'),iframe);assert.equal(q('#fixture-payment-state').value,'retained');
q('.kc-next').click();assert.equal(form.dataset.step,'review');
console.log('PASS back/edit retain the same secure-field DOM and native submit');
q('#terms').checked=true;
$(d.body).trigger('update_checkout');assert.equal(q('.kc-next').disabled,true);assert.equal($(form).triggerHandler('checkout_place_order'),false);
q('#payment').outerHTML=rawPayment;q('.woocommerce-checkout-review-order-table').outerHTML=rawTable;
$(d.body).trigger('updated_checkout');
assert.equal(all('#place_order').length,1);assert.equal(all('[name="woocommerce-process-checkout-nonce"]').length,1);
assert.equal(all('#terms').length,1);assert.equal(q('.kc-sidebar #terms').checked,true);
assert.equal($(form).serializeArray().filter(x=>x.name==='terms').length,1);
console.log('PASS sidebar terms remain checked and serialize once after payment refresh');
if(form.dataset.needsShipping==='1')assert.equal(all('input.shipping_method').length,2);
assert.equal($(form).serializeArray().filter(x=>x.name==='koopo_checkout')[0].value,'1');
console.log('PASS AJAX replacements preserve unique controls and complete form serialization');
q('.order-total .woocommerce-Price-amount').textContent='$99.00';$(d.body).trigger('updated_checkout');assert.equal(form.dataset.step,'payment');
console.log('PASS changed totals invalidate the final review');
q('input[name="payment_method"]').checked=false;q('.kc-next').click();assert.equal(form.dataset.step,'payment');
q('input[name="payment_method"]').checked=true;q('.kc-next').click();assert.equal(form.dataset.step,'review');
$(d.body).trigger('checkout_error');assert.equal(form.dataset.step,'payment');assert.equal(d.activeElement.className,'kc-status kc-js-only');
$(d.body).trigger('update_checkout');$(d).trigger('ajaxError',[{statusText:'error'},{url:'/?wc-ajax=update_order_review'}]);assert.equal(q('.kc-next').disabled,true);
$(d.body).trigger('updated_checkout');assert.equal(q('.kc-next').disabled,false);
console.log('PASS unavailable payment, server errors, transport errors and refresh recovery');
q('[data-kc-edit="delivery"]').click();q('#billing_city').value='Changed';$(q('#billing_city')).trigger('change');assert.equal(q('[data-kc-step="review"]').disabled,true);
q('.kc-summary-toggle').click();assert.equal(q('.kc-summary-toggle').getAttribute('aria-expanded'),'false');
console.log('PASS delivery edits invalidate later steps; summary disclosure is accessible');
const notice=d.createElement('ul');notice.className='woocommerce-error';notice.innerHTML='<li data-id="terms">Accept terms</li>';form.prepend(notice);
$(d.body).trigger('checkout_error');assert.equal(form.dataset.step,'review');
notice.innerHTML='<li data-id="billing_first_name">First name is required</li>';
$(d.body).trigger('checkout_error');assert.equal(form.dataset.step,'delivery');assert.equal(q('[data-kc-step="review"]').disabled,true);notice.remove();
console.log('PASS server errors return to the correct field or terms step');
if(form.dataset.needsShipping==='1') {
 const nextTable=d.createElement('div'); nextTable.innerHTML=rawTable;
 nextTable.querySelectorAll('tr.shipping').forEach(row=>row.remove());
 q('.woocommerce-checkout-review-order-table').outerHTML=nextTable.innerHTML;
 $(d.body).trigger('updated_checkout');
 assert.equal(all('.kc-shipping-table input.shipping_method').length,0);
 console.log('PASS refreshed address without rates clears stale shipping controls');
}
dom.window.close();
})().catch(e=>{console.error(e);process.exit(1)});

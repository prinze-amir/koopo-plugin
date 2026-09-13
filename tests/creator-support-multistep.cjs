const {JSDOM}=require('jsdom'); const fs=require('fs');const assert=require('node:assert/strict');
// Run with jsdom installed and a rendered live-support HTML fixture as argv[2].
const root=require('path').resolve(__dirname,'..');
const html=fs.readFileSync(process.argv[2],'utf8');
const source=fs.readFileSync(root+'/includes/creator-support/assets/koopo-creator-support.js','utf8');
(async()=>{
const dom=new JSDOM(html,{url:'https://example.test/live',runScripts:'outside-only',pretendToBeVisual:true});const w=dom.window,d=w.document;let calls=[],fail=false,confirmCalls=0;
w.KoopoCreatorSupport={restBase:'/wp-json/koopo/v1/creator-support',loggedIn:true,nonce:'test'};
w.fetch=async(url,options)=>{calls.push(JSON.parse(options.body));return {ok:!fail,json:async()=>fail?{message:'Chat is unavailable for this stream.'}:url.endsWith('/confirm')?{paid:true,chat_state:'posted',receipt_url:'/receipt'}:{order_id:calls.length,client_secret:'test',publishable_key:'test',currency:'USD',amount:JSON.parse(options.body).amount,billing:{}}}};
w.Stripe=()=>({elements:()=>({create:()=>{let ready;return {on:(event,fn)=>{if(event==='ready')ready=fn},destroy:()=>{},mount:host=>{host.textContent='Secure card form';setTimeout(ready,5)}}}}),confirmPayment:async()=>{confirmCalls++;return {}}});
w.eval(source);d.dispatchEvent(new w.Event('DOMContentLoaded'));const q=s=>d.querySelector(s);const settle=()=>new Promise(r=>setTimeout(r,30));const submit=async()=>{q('[data-kcs-form]').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));if(q('[data-kcs-amount]').value){assert.equal(q('[data-kcs-form]').getAttribute('aria-busy'),'true');assert(q('[data-kcs-modal]').classList.contains('is-processing'));assert.equal(q('[data-kcs-submit]').disabled,true);const before=calls.length;q('[data-kcs-form]').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));assert.equal(calls.length,before);}await settle();assert.equal(q('[data-kcs-submit]').disabled,false);assert(!q('[data-kcs-modal]').classList.contains('is-processing'))};
assert(q('[data-kcs-form]'),'rendered live form');
assert.equal(q('[data-kcs-custom-field]').hidden,true);
q('[data-kcs-quick="10"]').click();assert.equal(q('[data-kcs-custom-field]').hidden,true);assert.equal(q('[data-kcs-quick="10"]').getAttribute('aria-pressed'),'true');
q('[data-kcs-custom]').click();assert.equal(q('[data-kcs-custom-field]').hidden,false);assert.equal(d.activeElement,q('[data-kcs-amount]'));
q('[data-kcs-amount]').value='25';q('[data-kcs-amount]').dispatchEvent(new w.Event('input'));assert.equal(q('[data-kcs-custom-field]').hidden,false);assert.equal(q('[data-kcs-custom]').getAttribute('aria-pressed'),'true');
q('[data-kcs-quick="5"]').click();assert.equal(q('[data-kcs-custom-field]').hidden,true);assert.equal(d.activeElement,q('[data-kcs-quick="5"]'));assert.equal(q('[data-kcs-step-label]'),null);
q('[data-kcs-amount]').value='';console.log('PASS manual amount only appears for Custom; preset selection hides it; no step row');
q('[data-kcs-open]').click();await settle();await submit();assert.equal(calls.length,0);assert.match(q('[data-kcs-modal-status]').textContent,/valid/);console.log('PASS invalid amount shows error');
q('[data-kcs-amount]').value='12.50';q('[data-kcs-message]').value='Thanks for the stream';fail=true;await submit();assert.match(q('[data-kcs-modal-status]').textContent,/Chat is unavailable/);assert.equal(q('[data-kcs-submit]').disabled,false);console.log('PASS server error visible and retry enabled');
fail=false;await submit();assert.equal(q('[data-kcs-details]').hidden,true);assert.equal(q('[data-kcs-payment]').hidden,false);assert.equal(q('[data-kcs-step-label]'),null);assert.equal(calls.at(-1).message,'Thanks for the stream');console.log('PASS amount and message advance to payment');
const n=calls.length;q('[data-kcs-back]').click();assert.equal(q('[data-kcs-details]').hidden,false);await submit();assert.equal(calls.length,n);console.log('PASS back preserves session without duplicate order');
q('[data-kcs-back]').click();q('[data-kcs-amount]').value='25';await submit();assert.equal(calls.length,n+1);assert.notEqual(calls.at(-1).request_key,calls.at(-2).request_key);console.log('PASS changed amount starts distinct checkout');
await submit();assert.equal(confirmCalls,1);assert.equal(q('[data-kcs-receipt]').hidden,false);assert.match(q('[data-kcs-modal-status]').textContent,/now in the chat/);console.log('PASS payment confirmation shows chat success and receipt');
w.dispatchEvent(new w.Event('pagehide')); dom.window.close();
})().catch(e=>{console.error(e);process.exit(1)});

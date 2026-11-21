'use strict';
window.loyaltyPayPalLoaded=async function(){
 const settings=JSON.parse(document.getElementById('paypal-config').textContent),message=document.getElementById('paypal-message'),button=document.getElementById('paypal-subscribe'),select=document.getElementById('paypal-plan');
 const post=async data=>fetch('/billing/paypal',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({...data,csrf:settings.csrf})});
 try{
  const sdk=await window.paypal.createInstance({clientId:settings.clientId,components:['paypal-subscriptions'],pageType:'checkout'});
  const session=sdk.createPayPalSubscriptionPaymentSession({
   async onApprove(){message.textContent='Zustimmung erhalten. Der Server prüft den tatsächlichen Abostatus.';await post({action:'refresh'});window.location.assign('/billing/paypal');},
   onCancel(){message.textContent='Zustimmung abgebrochen. Kein Zugriff allein durch das geöffnete Zahlungsfenster. Ein bereits angelegter Vorgang bleibt zur sicheren Wiederaufnahme erhalten.';},
   onError(){message.textContent='PayPal konnte den Vorgang nicht bestätigen. Status prüfen, nicht mehrfach neue Abos anlegen.';}
  });
  let ready=false;
  async function eligible(){ready=false;button.hidden=true;const p=settings.plans[select.value];if(!p)return;const methods=await sdk.findEligibleMethods({paymentFlow:'RECURRING_PAYMENT',currencyCode:p.currency});ready=methods.isEligible('paypal');button.hidden=!ready;message.textContent=ready?'PayPal öffnet den Preis und die Laufzeit zur ausdrücklichen Zustimmung.':'PayPal-Abos sind in dieser Sitzung nicht verfügbar.';}
  select.addEventListener('change',()=>eligible().catch(()=>{message.textContent='Zahlungsoption konnte nicht geprüft werden.';}));
  button.addEventListener('click',async()=>{if(!ready)return;button.setAttribute('disabled','');try{const promise=(async()=>{const r=await post({action:'create',plan:select.value});const data=await r.json();if(!r.ok||!data.subscriptionId)throw new Error(data.error||'Vorgang nicht bestätigt.');return {subscriptionId:data.subscriptionId};})();await session.start({presentationMode:'auto'},promise);}catch(e){message.textContent=e.message||'PayPal konnte nicht gestartet werden.';}finally{button.removeAttribute('disabled');}});
  await eligible();
 }catch{message.textContent='PayPal konnte nicht geladen werden. Bitte später versuchen oder andere Abrechnung nutzen.';}
};

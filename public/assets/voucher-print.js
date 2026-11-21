'use strict';
(() => {
 const cards=[...document.querySelectorAll('.voucher-sheet-card')],status=document.getElementById('print-status'),print=document.getElementById('print');
 try {
  if(!window.QRCode)throw Error('QR library unavailable');
  cards.forEach(card=>{
   const holder=card.querySelector('.voucher-qr'),code=card.dataset.code;
   new QRCode(holder,{text:'V-'+code,width:224,height:224,colorDark:'#000000',colorLight:'#ffffff',correctLevel:QRCode.CorrectLevel.M});
   const canvas=holder.querySelector('canvas');if(!canvas)throw Error('QR not rendered');
   // At least four modules of white quiet zone for this fixed payload, independent of background.
   const padded=document.createElement('canvas');padded.width=320;padded.height=320;const ctx=padded.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,320,320);ctx.drawImage(canvas,48,48);holder.replaceChildren(padded);
   const download=card.querySelector('.png');download.disabled=false;download.addEventListener('click',()=>{const a=document.createElement('a');a.href=padded.toDataURL('image/png');a.download='voucher-'+code+'.png';a.click();});
  });
  status.textContent=cards.length+' QR-Grafiken lokal bereit. Vor Verteilung die Auswahl und Bedingungen prüfen.';print.disabled=cards.length===0;print.addEventListener('click',()=>window.print());
 } catch(error){
  document.querySelectorAll('.voucher-qr').forEach(e=>e.replaceChildren());document.querySelectorAll('.png').forEach(e=>e.disabled=true);print.disabled=true;status.textContent='QR-Vorbereitung fehlgeschlagen. Nicht drucken. Seite online neu laden.';
 }
})();

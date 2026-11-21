'use strict';
const CACHE='loyalty-public-shell-v1';
const PUBLIC=['/offline.html','/assets/offline.css','/assets/pwa.js','/assets/qrcode.min.js','/assets/pwa-192.png','/assets/pwa-512.png','/manifest.webmanifest'];
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(PUBLIC)).then(()=>self.skipWaiting())));
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('loyalty-public-shell-')&&k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',e=>{
 const r=e.request,u=new URL(r.url);if(r.method!=='GET'||u.origin!==self.location.origin)return;
 // Never cache HTML sessions, APIs, cards, exports, images or POST operations.
 if(PUBLIC.includes(u.pathname)&&!u.search){e.respondWith(fetch(r).then(res=>{if(res.ok){const copy=res.clone();return caches.open(CACHE).then(c=>c.put(r,copy)).then(()=>res);}return res;}).catch(()=>caches.match(u.pathname)));return;}
 if(r.mode==='navigate')e.respondWith(fetch(r).catch(()=>caches.match('/offline.html')));
});

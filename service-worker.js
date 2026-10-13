const CACHE='ankh-shell-v20-mobile-audit';
const SHELL=['./style.css','./style.min.css','./style.core.min.css','./manifest.webmanifest','./icon.svg','./splash.svg','./offline.html'];
self.addEventListener('install',event=>{event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(SHELL)).then(()=>self.skipWaiting()))});
self.addEventListener('activate',event=>{event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()))});
self.addEventListener('fetch',event=>{
 const req=event.request;
 if(req.method!=='GET')return;
 const url=new URL(req.url);
 if(url.origin!==self.location.origin)return;
 if(/\/style(?:\.core)?(?:\.min)?\.css$/.test(url.pathname)){
  event.respondWith(fetch(req).then(res=>{const copy=res.clone();caches.open(CACHE).then(cache=>cache.put(req,copy));return res}).catch(()=>caches.match(req,{ignoreSearch:true}).then(cached=>cached||caches.match('./style.css'))));
  return;
 }
 if(url.pathname.endsWith('/manifest.webmanifest')||url.pathname.endsWith('/icon.svg')||url.pathname.endsWith('/splash.svg')){
  event.respondWith(fetch(req).then(res=>{const copy=res.clone();caches.open(CACHE).then(cache=>cache.put(req,copy));return res}).catch(()=>caches.match(req,{ignoreSearch:true})));
  return;
 }
 if(req.mode==='navigate'){
  event.respondWith(fetch(req).catch(()=>caches.match('./offline.html')));
 }
});

/* ANKH customer basket. Explicitly approved accessories may be requested for
   staff review; other visible research items remain a NO-ORDER demonstration.
   All commerce eligibility and prices are revalidated on the server. */
(() => {
'use strict';
const key='ankh-approved-accessory-cart-v1';
const dialog=document.getElementById('sf-cart-drawer');
if(!dialog)return;
const products=new Map([...document.querySelectorAll('[data-approved-id]')].map(el=>{
  const id=Number(el.dataset.approvedId),price=Number(el.dataset.approvedPrice);
  return [id,{id,name:el.dataset.approvedName||'',price,live:el.dataset.approvedLive==='1',
    photo:el.dataset.approvedPhoto||'',strength:el.dataset.approvedStrength||'',
    format:el.dataset.approvedFormat||''}];
}).filter(([id,p])=>Number.isInteger(id)&&id>0&&(!p.live||(Number.isInteger(p.price)&&p.price>0))));
const money=p=>'£'+(p/100).toLocaleString('en-GB',{minimumFractionDigits:2,maximumFractionDigits:2});
const qty=v=>{const x=Number(v);return Number.isInteger(x)&&x>0&&x<=99?x:0;};
const read=()=>{try{const obj=JSON.parse(localStorage.getItem(key)||'{}');return obj&&typeof obj==='object'&&!Array.isArray(obj)?obj:{};}catch(_){return {};}};
let cart=read();
const toast=document.createElement('div');
toast.className='sf-cart-feedback';toast.setAttribute('role','status');
toast.setAttribute('aria-live','polite');toast.setAttribute('aria-atomic','true');
document.body.append(toast);
let toastTimer=null,peekTimer=null;
const peek=document.getElementById('sf-cart-peek');
const buttonStates=new WeakMap();
const announce=message=>{
  if(toastTimer!==null)clearTimeout(toastTimer);
  toast.textContent=message;toast.classList.remove('is-visible');
  void toast.offsetWidth;toast.classList.add('is-visible');
  toastTimer=setTimeout(()=>{toast.classList.remove('is-visible');toastTimer=null;},2400);
};
const confirmAdded=(button,name)=>{
  let state=buttonStates.get(button);
  if(!state){state={originalNodes:Array.from(button.childNodes,child=>child.cloneNode(true)),
    originalLabel:button.getAttribute('aria-label'),timer:null};buttonStates.set(button,state);}
  if(state.timer!==null)clearTimeout(state.timer);
  button.classList.remove('is-added');void button.offsetWidth;
  button.replaceChildren(document.createTextNode('✓ Added'));button.classList.add('is-added');
  button.setAttribute('aria-label',name+' added to basket');
  state.timer=setTimeout(()=>{
    button.replaceChildren(...state.originalNodes.map(child=>child.cloneNode(true)));
    button.classList.remove('is-added');
    if(state.originalLabel!==null)button.setAttribute('aria-label',state.originalLabel);
    else button.removeAttribute('aria-label');state.timer=null;
  },1600);
};
const pulseBasketCount=()=>document.querySelectorAll('[data-cart-count]').forEach(node=>{
  node.classList.remove('sf-count-pop');void node.offsetWidth;node.classList.add('sf-count-pop');
});
const sync=()=>{
  const clean={};for(const [id]of products)if(qty(cart[id]))clean[id]=qty(cart[id]);
  cart=clean;try{localStorage.setItem(key,JSON.stringify(cart));}catch(_){}
};
const textById=(id,value)=>{const el=document.getElementById(id);if(el)el.textContent=value;};
const element=(tag,className,text)=>{
  const el=document.createElement(tag);
  if(className)el.className=className;
  if(text!==undefined)el.textContent=text;
  return el;
};
const closePeek=()=>{
  if(!peek)return;
  if(peekTimer!==null){clearTimeout(peekTimer);peekTimer=null;}
  peek.classList.remove('is-visible');peek.hidden=true;
  document.body.classList.remove('sf-cart-peek-open');
};
const showPeek=item=>{
  if(!peek || dialog.open)return;
  closePeek();
  const name=document.getElementById('sf-peek-name');
  const detail=document.getElementById('sf-peek-detail');
  const status=document.getElementById('sf-peek-status');
  const image=document.getElementById('sf-peek-image');
  const fallback=document.getElementById('sf-peek-fallback');
  if(name)name.textContent=item.name;
  if(status)status.textContent=item.live?'ADDED TO ACCESSORY REQUEST':'ADDED TO DEMO PREVIEW · NO ORDER';
  if(detail)detail.textContent=[item.strength,item.format==='Standard'?'':item.format,
    item.live?money(item.price)+' each':'Research demonstration only'].filter(Boolean).join(' · ');
  if(image){
    if(item.photo){image.src=item.photo;image.hidden=false;if(fallback)fallback.hidden=true;}
    else{image.removeAttribute('src');image.hidden=true;if(fallback)fallback.hidden=false;}
  }
  peek.hidden=false;peek.classList.add('is-visible');
  document.body.classList.add('sf-cart-peek-open');
  peekTimer=setTimeout(closePeek,6500);
};
const addControls=(line,id,q,item)=>{
  const editor=element('div','sf-cart-quantity');
  const decrease=element('button','', '−');decrease.type='button';
  decrease.setAttribute('aria-label','Reduce '+item.name+' quantity');
  decrease.addEventListener('click',()=>{if(q<=1)delete cart[id];else cart[id]=q-1;draw();});
  const number=element('span','sf-cart-qty-value',String(q));
  number.setAttribute('aria-label',q+' items');
  const increase=element('button','', '+');increase.type='button';increase.disabled=q>=99;
  increase.setAttribute('aria-label','Increase '+item.name+' quantity');
  increase.addEventListener('click',()=>{cart[id]=Math.min(99,q+1);draw();});
  const remove=element('button','sf-cart-remove','Remove');remove.type='button';
  remove.setAttribute('aria-label','Remove '+item.name);
  remove.addEventListener('click',()=>{delete cart[id];draw();});
  editor.append(decrease,number,increase,remove);line.append(editor);
};
const makeLine=(id,q,item)=>{
  const line=element('article','sf-cart-line '+(item.live?'sf-line-eligible':'sf-line-demo'));
  const media=element('div','sf-cart-line-media');
  if(item.photo){
    const img=element('img','sf-cart-photo');
    img.src=item.photo;img.alt='';img.loading='lazy';img.width=62;img.height=62;
    media.append(img);
  }else{media.append(element('span','sf-cart-photo-fallback','☥'));}
  const info=element('div','sf-cart-line-info');
  const name=element('strong','',item.name);
  const variant=[item.strength,item.format==='Standard'?'':item.format].filter(Boolean).join(' · ');
  const meta=element('small','sf-cart-variant',variant||(item.live?'Accessory':'Research reference'));
  const status=element('small','sf-cart-line-status',item.live
    ? money(item.price)+' each · Eligible for staff-reviewed request'
    : (item.price>0?'Preview '+money(item.price)+' each · Demo only':'Research demo only · No payable price'));
  info.append(name,meta,status);line.append(media,info);
  addControls(line,id,q,item);
  return line;
};
const draw=()=>{
  sync();
  const groups={live:[],demo:[]};
  let liveCount=0,demoCount=0,liveTotal=0,demoTotal=0;
  for(const [idText,q]of Object.entries(cart)){
    const item=products.get(Number(idText));if(!item)continue;
    if(item.live){liveCount+=q;liveTotal+=item.price*q;groups.live.push([idText,q,item]);}
    else{demoCount+=q;demoTotal+=Math.max(0,item.price)*q;groups.demo.push([idText,q,item]);}
  }
  const count=liveCount+demoCount;
  document.querySelectorAll('[data-cart-count]').forEach(el=>el.textContent=String(count));
  textById('sf-cart-live-count',liveCount+' eligible accessory '+(liveCount===1?'item':'items'));
  textById('sf-cart-demo-count',demoCount+' demo '+(demoCount===1?'item':'items'));
  const list=document.getElementById('sf-cart-lines');
  list.replaceChildren();
  if(!count){
    const empty=element('div','sf-cart-empty');
    empty.append(element('span','sf-cart-empty-icon','☥'),
      element('strong','', 'Your basket is empty'),
      element('p','','Explore ANKH products or save an item to your research list.'));
    list.append(empty);
  }
  const appendGroup=(title,description,entries,kind)=>{
    if(!entries.length)return;
    const group=element('section','sf-cart-group sf-cart-group-'+kind);
    const heading=element('div','sf-cart-group-heading');
    heading.append(element('strong','',title),element('small','',description));
    group.append(heading);
    for(const [id,q,item]of entries)group.append(makeLine(id,q,item));
    list.append(group);
  };
  appendGroup('Eligible accessory requests',
    'A request is not a purchase. ANKH must review it first.',groups.live,'eligible');
  appendGroup('Research demonstration',
    'Shopping preview only. No request, stock reservation or payment.',groups.demo,'demo');
  const mixed=liveCount>0&&demoCount>0;
  const subtotal=document.getElementById('sf-cart-subtotal');
  if(subtotal)subtotal.textContent=liveCount?money(liveTotal)
    :(demoCount?(demoTotal>0?'Preview '+money(demoTotal):'Demo basket'):money(0));
  textById('sf-cart-total-label',liveCount?'Eligible accessories subtotal':demoCount?'Demo preview subtotal':'Product subtotal');
  const demoRow=document.getElementById('sf-cart-demo-total-row');
  if(demoRow)demoRow.hidden=!mixed;
  textById('sf-cart-demo-total',demoTotal>0?'Preview '+money(demoTotal):'Demo only');
  const next=document.getElementById('sf-cart-checkout');
  if(next){
    next.href=liveCount?'./shop-checkout.php':'./shop-demo.php';
    next.textContent=liveCount?'Review accessory request ↗':'Preview demo basket ↗';
    next.setAttribute('aria-disabled',count?'false':'true');
  }
  const demoLink=document.getElementById('sf-cart-demo-link');
  if(demoLink)demoLink.hidden=!mixed;
  const note=dialog.querySelector('.sf-cart-description');
  if(note)note.textContent=mixed
    ?'Your accessory request and research demo are separate. Only explicitly approved accessories can be submitted.'
    :liveCount?'Your accessories can be sent to ANKH for review. Availability, delivery and payment details are confirmed separately.'
    :demoCount?'Your selection is a research-interface demonstration. It cannot be submitted as an order or enquiry.'
    :'Approved accessories can be requested; research-only products are demonstrations.';
  textById('sf-cart-footnote',liveCount
    ?'No payment is taken online. ANKH confirms availability and delivery before accepting any accessory request.'
    :'Demo totals are illustrative only. No order, reservation or payment is created.');
};
document.querySelectorAll('[data-cart-id]').forEach(button=>button.addEventListener('click',()=>{
  const id=Number(button.dataset.cartId),item=products.get(id);
  if(!item)return;
  if(!cart[id]&&Object.keys(cart).length>=20){announce('Your basket can hold up to 20 different products.');return;}
  if(qty(cart[id])>=99){announce('Maximum quantity of 99 reached for '+item.name+'.');return;}
  cart[id]=qty(cart[id])+1;
  draw();confirmAdded(button,item.name);pulseBasketCount();showPeek(item);
  announce(item.name+' added to your '+(item.live?'accessory request basket.':'demo basket. No order placed.'));
  // Keep browsing. The full basket opens only after a deliberate tap.
}));
const openBasket=()=>{
  draw();closePeek();if(!dialog.open)dialog.showModal();
};
document.querySelectorAll('[data-open-cart]').forEach(button=>button.addEventListener('click',openBasket));
document.querySelectorAll('[data-cart-peek-dismiss]').forEach(button=>button.addEventListener('click',closePeek));
document.querySelectorAll('[data-cart-peek-view]').forEach(button=>button.addEventListener('click',openBasket));
const closeButtons=dialog.querySelectorAll ? [...dialog.querySelectorAll('[data-close-cart]')] : [dialog.querySelector('[data-close-cart]')].filter(Boolean);
closeButtons.forEach(button=>button.addEventListener('click',()=>dialog.close()));
dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
document.getElementById('sf-cart-checkout')?.addEventListener('click',event=>{
  if(!Object.keys(cart).length)event.preventDefault();
});
window.addEventListener('storage',event=>{if(event.key===key){cart=read();draw();}});
document.addEventListener?.('keydown',event=>{if(event.key==='Escape'&&!dialog.open)closePeek();});
draw();
})();

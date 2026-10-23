/* ANKH public test basket: all published demo products are available to try.
   Only separately approved accessories may open the real staff-reviewed request page. */
(() => {
'use strict';
const key='ankh-approved-accessory-cart-v1';
const dialog=document.getElementById('sf-cart-drawer');
if(!dialog)return;
const products=new Map([...document.querySelectorAll('[data-approved-id]')].map(el=>{
    const id=Number(el.dataset.approvedId),price=Number(el.dataset.approvedPrice);
    return [id,{id,name:el.dataset.approvedName||'',price,live:el.dataset.approvedLive==='1'}];
}).filter(([id,p])=>Number.isInteger(id)&&id>0&&(!p.live||Number.isInteger(p.price)&&p.price>0)));
const read=()=>{try{const obj=JSON.parse(localStorage.getItem(key)||'{}');
    return obj&&typeof obj==='object'&&!Array.isArray(obj)?obj:{};}catch(_){return {};}};
let cart=read();
const money=p=>'£'+(p/100).toLocaleString('en-GB',{minimumFractionDigits:2,maximumFractionDigits:2});
const qty=v=>{const x=Number(v);return Number.isInteger(x)&&x>0&&x<=99?x:0;};
const sync=()=>{
    const clean={};
    for(const [id]of products)if(qty(cart[id]))clean[id]=qty(cart[id]);
    cart=clean;
    try{localStorage.setItem(key,JSON.stringify(cart));}catch(_){}
};
const draw=()=>{
    sync();
    let count=0,total=0,hasDemo=false;
    for(const [id,q]of Object.entries(cart)){
       const item=products.get(Number(id));if(!item)continue;
       count+=q;
       if(item.live)total+=item.price*q;else hasDemo=true;
    }
    document.querySelectorAll('[data-cart-count]').forEach(el=>el.textContent=String(count));
    const subtotal=document.getElementById('sf-cart-subtotal');
    if(subtotal)subtotal.textContent=hasDemo?'Demo basket':money(total);
    const list=document.getElementById('sf-cart-lines');list.replaceChildren();
    if(!count){const msg=document.createElement('p');msg.className='sf-cart-empty';
        msg.textContent='Your basket is empty. Add a product to try the shopping experience.';list.append(msg);}
    for(const [idText,q]of Object.entries(cart)){
        const item=products.get(Number(idText));if(!item)continue;
        const line=document.createElement('article');line.className='sf-cart-line';
        const info=document.createElement('div'),name=document.createElement('strong'),status=document.createElement('small');
        name.textContent=item.name;status.textContent=item.live?money(item.price)+' each':'Demo item · Checkout simulation only';
        info.append(name,status);
        const editor=document.createElement('div');editor.className='sf-cart-quantity';
        const decrement=document.createElement('button');decrement.type='button';decrement.textContent='−';decrement.setAttribute('aria-label','Reduce '+item.name+' quantity');
        decrement.addEventListener('click',()=>{if(q<=1)delete cart[idText];else cart[idText]=q-1;draw();});
        const quantity=document.createElement('span');quantity.textContent=String(q);quantity.setAttribute('aria-label',q+' items');
        const increment=document.createElement('button');increment.type='button';increment.textContent='+';increment.setAttribute('aria-label','Increase '+item.name+' quantity');increment.disabled=q>=99;
        increment.addEventListener('click',()=>{cart[idText]=Math.min(99,q+1);draw();});
        const remove=document.createElement('button');remove.type='button';remove.className='sf-cart-remove';remove.textContent='Remove';
        remove.addEventListener('click',()=>{delete cart[idText];draw();});
        editor.append(decrement,quantity,increment,remove);
        line.append(info,editor);list.append(line);
    }
    const next=document.getElementById('sf-cart-checkout');
    if(next){
       next.href=count>0&&!hasDemo?'./shop-checkout.php':'./shop-demo.php';
       next.textContent=hasDemo?'Review test basket ↗':'Review & send request ↗';
       next.setAttribute('aria-disabled',count?'false':'true');
    }
    const note=dialog.querySelector('.sf-cart-description');
    if(note)note.textContent=hasDemo?'Testing only: your basket includes demonstration items. No payment, order or enquiry can be submitted for them.'
      :'Your selections are saved on this device. Eligible accessory requests are reviewed by ANKH before acceptance.';
};
document.querySelectorAll('[data-cart-id]').forEach(button=>button.addEventListener('click',()=>{
    const id=Number(button.dataset.cartId);
    if(!products.has(id))return;
    if(!cart[id]&&Object.keys(cart).length>=20){dialog.showModal();return;}
    cart[id]=Math.min(99,qty(cart[id])+1);draw();if(!dialog.open)dialog.showModal();
}));
document.querySelectorAll('[data-open-cart]').forEach(button=>button.addEventListener('click',()=>{
    draw();if(!dialog.open)dialog.showModal();
}));
dialog.querySelector('[data-close-cart]')?.addEventListener('click',()=>dialog.close());
dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
document.getElementById('sf-cart-checkout')?.addEventListener('click',event=>{if(!Object.keys(cart).length)event.preventDefault();});
window.addEventListener('storage',event=>{if(event.key===key){cart=read();draw();}});
draw();
})();

/* ANKH progressive accessory basket: device-only draft, never authoritative pricing. */
(() => {
'use strict';
const key='ankh-approved-accessory-cart-v1';
const dialog=document.getElementById('sf-cart-drawer');
if(!dialog)return;
const records=new Map([...document.querySelectorAll('[data-approved-id]')].map(el=>{
    const id=Number(el.dataset.approvedId),price=Number(el.dataset.approvedPrice);
    return [id,{id,name:el.dataset.approvedName||'',price}];
}).filter(([id,p])=>Number.isInteger(id)&&id>0&&Number.isInteger(p.price)&&p.price>0));
const read=()=>{try{const parsed=JSON.parse(localStorage.getItem(key)||'{}');
    return parsed&&typeof parsed==='object'&&!Array.isArray(parsed)?parsed:{};}catch(_){return {};}};
let cart=read();
const format=p=>'£'+(p/100).toLocaleString('en-GB',{minimumFractionDigits:2,maximumFractionDigits:2});
const quantity=(v)=>{const x=Number(v);return Number.isInteger(x)&&x>0&&x<=99?x:0;};
const sync=()=>{
    const next={};
    for(const [id,p]of records)if(quantity(cart[id]))next[id]=quantity(cart[id]);
    cart=next;
    try{localStorage.setItem(key,JSON.stringify(cart));}catch(_){}
};
const view=()=>{
    sync();
    const count=Object.values(cart).reduce((sum,v)=>sum+v,0);
    const amount=Object.entries(cart).reduce((sum,[id,q])=>sum+records.get(Number(id)).price*q,0);
    document.querySelectorAll('[data-cart-count]').forEach(el=>{el.textContent=String(count);});
    document.getElementById('sf-cart-subtotal').textContent=format(amount);
    const list=document.getElementById('sf-cart-lines');
    list.replaceChildren();
    if(count===0){const p=document.createElement('p');p.className='sf-cart-empty';p.textContent='Your accessory basket is empty. Browse approved accessories to add your first item.';list.append(p);}
    for(const [idText,q]of Object.entries(cart)){
        const item=records.get(Number(idText));if(!item)continue;
        const article=document.createElement('article');article.className='sf-cart-line';
        const name=document.createElement('div');
        const heading=document.createElement('strong');heading.textContent=item.name;
        const price=document.createElement('small');price.textContent=format(item.price)+' each';
        name.append(heading,price);
        const editor=document.createElement('div');editor.className='sf-cart-quantity';
        const minus=document.createElement('button');minus.type='button';minus.textContent='−';minus.setAttribute('aria-label','Remove one '+item.name);
        minus.addEventListener('click',()=>{if(q<=1)delete cart[idText];else cart[idText]=q-1;view();});
        const qty=document.createElement('span');qty.textContent=String(q);qty.setAttribute('aria-label',String(q)+' in basket');
        const plus=document.createElement('button');plus.type='button';plus.textContent='+';plus.setAttribute('aria-label','Add one '+item.name);
        plus.disabled=q>=99;plus.addEventListener('click',()=>{cart[idText]=Math.min(99,q+1);view();});
        const remove=document.createElement('button');remove.type='button';remove.className='sf-cart-remove';remove.textContent='Remove';remove.addEventListener('click',()=>{delete cart[idText];view();});
        editor.append(minus,qty,plus,remove);article.append(name,editor);list.append(article);
    }
    const checkout=document.getElementById('sf-cart-checkout');
    checkout.setAttribute('aria-disabled',count===0?'true':'false');
};
document.querySelectorAll('[data-cart-id]').forEach(button=>button.addEventListener('click',()=>{
    const id=Number(button.dataset.cartId);
    if(!records.has(id))return;
    if(!cart[id] && Object.keys(cart).length>=20){dialog.showModal();return;}
    cart[id]=Math.min(99,quantity(cart[id])+1);
    view();dialog.showModal();
}));
document.querySelectorAll('[data-open-cart]').forEach(button=>button.addEventListener('click',()=>{view();dialog.showModal();}));
dialog.querySelector('[data-close-cart]')?.addEventListener('click',()=>dialog.close());
dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
document.getElementById('sf-cart-checkout')?.addEventListener('click',event=>{if(!Object.keys(cart).length)event.preventDefault();});
window.addEventListener('storage',event=>{if(event.key===key){cart=read();view();}});
view();
})();

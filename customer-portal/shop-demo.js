(() => {
'use strict';
const key='ankh-approved-accessory-cart-v1';
const allowed=new Map([...document.querySelectorAll('[data-demo-id]')].map(el=>[el.dataset.demoId,{name:el.dataset.demoName||'Product',price:Number(el.dataset.demoPrice)||0}]));
const list=document.getElementById('sf-demo-lines'),finish=document.getElementById('sf-demo-finish'),confirmation=document.getElementById('sf-demo-confirmed');
let cart={};
try{const saved=JSON.parse(localStorage.getItem(key)||'{}');if(saved&&typeof saved==='object'&&!Array.isArray(saved))cart=saved;}catch(_){}
list.replaceChildren();
let count=0,subtotal=0;
const money=p=>'£'+(p/100).toLocaleString('en-GB',{minimumFractionDigits:2,maximumFractionDigits:2});
for(const [id,rawQty] of Object.entries(cart)){
  const q=Number(rawQty);if(!allowed.has(id)||!Number.isInteger(q)||q<1||q>99)continue;
  const product=allowed.get(id);
  count+=q;if(Number.isSafeInteger(product.price)&&product.price>0)subtotal+=q*product.price;
  const row=document.createElement('div');row.className='sf-checkout-line';
  const name=document.createElement('strong');name.textContent=product.name;
  const units=document.createElement('span');units.textContent='× '+q+' · '+(product.price>0?money(q*product.price)+' preview':'Demo only');
  row.append(name,units);list.append(row);
}
if(!count){const empty=document.createElement('p');empty.textContent='Your test basket is empty. Add any published product on the collection page.';list.append(empty);}
const subtotalLabel=document.getElementById('sf-demo-subtotal');
if(subtotalLabel)subtotalLabel.textContent=subtotal>0?money(subtotal):'Not available';
finish.disabled=count===0;
finish.addEventListener('click',()=>{if(!count)return;confirmation.hidden=false;finish.disabled=true;finish.textContent='Demo checkout completed';});
})();

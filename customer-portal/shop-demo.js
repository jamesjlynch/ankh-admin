(() => {
'use strict';
const key='ankh-approved-accessory-cart-v1';
const allowed=new Map([...document.querySelectorAll('[data-demo-id]')].map(el=>[el.dataset.demoId,el.dataset.demoName||'Product']));
const list=document.getElementById('sf-demo-lines'),finish=document.getElementById('sf-demo-finish'),confirmation=document.getElementById('sf-demo-confirmed');
let cart={};
try{const saved=JSON.parse(localStorage.getItem(key)||'{}');if(saved&&typeof saved==='object'&&!Array.isArray(saved))cart=saved;}catch(_){}
list.replaceChildren();
let count=0;
for(const [id,rawQty] of Object.entries(cart)){
  const q=Number(rawQty);if(!allowed.has(id)||!Number.isInteger(q)||q<1||q>99)continue;
  count+=q;const row=document.createElement('div');row.className='sf-checkout-line';
  const name=document.createElement('strong');name.textContent=allowed.get(id);
  const units=document.createElement('span');units.textContent='× '+q+' · Demo only';
  row.append(name,units);list.append(row);
}
if(!count){const empty=document.createElement('p');empty.textContent='Your test basket is empty. Add any published product on the collection page.';list.append(empty);}
finish.disabled=count===0;
finish.addEventListener('click',()=>{if(!count)return;confirmation.hidden=false;finish.disabled=true;finish.textContent='Demo checkout completed';});
})();

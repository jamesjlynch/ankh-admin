/* ANKH accessory request screen. The browser keeps demo selections separate;
   the server validates all prices, quantities and request eligibility. */
(() => {
'use strict';
const key='ankh-approved-accessory-cart-v1';
const storage=()=>{
  try{const value=JSON.parse(window.localStorage.getItem(key)||'{}');
    return value&&typeof value==='object'&&!Array.isArray(value)?value:{};}catch(_){return {};}
};
const saved=storage();
const success=document.querySelector('[data-successful-request-ids]');
if(success){
  const completed=(success.getAttribute('data-successful-request-ids')||'').split(',').filter(id=>/^[1-9][0-9]*$/.test(id));
  if(completed.length){
    const after=storage();completed.forEach(id=>delete after[id]);
    try{window.localStorage.setItem(key,JSON.stringify(after));}catch(_){}
  }
  return;
}
const form=document.getElementById('sf-checkout-form');if(!form)return;
const items=[...form.querySelectorAll('[data-checkout-qty]')];
const total=document.getElementById('sf-guest-total');
const expand=document.getElementById('sf-checkout-show-all');
const selectedNote=document.getElementById('sf-checkout-selected-count');
const format=p=>'£'+(p/100).toLocaleString('en-GB',{minimumFractionDigits:2,maximumFractionDigits:2});
const selectedIds=new Set();
for(const item of items){
  const id=item.dataset.checkoutQty,quantity=Number(saved[id]);
  if(Number.isInteger(quantity)&&quantity>0&&quantity<=99){
    item.value=String(quantity);selectedIds.add(id);
  }
}
let expanded=selectedIds.size===0;
const showRows=()=>{
  for(const item of items){
    const row=item.closest('[data-item-row]');
    if(row)row.hidden=!expanded&&!selectedIds.has(item.dataset.checkoutQty)&&Number(item.value)===0;
  }
  if(expand){
    expand.hidden=expanded||items.length===selectedIds.size;
    expand.textContent='Show other eligible accessories ↗';
  }
};
if(expand)expand.addEventListener('click',()=>{expanded=true;showRows();});
const calc=()=>{
  let pennies=0,quantity=0;
  for(const el of items){
    const q=Number(el.value),p=Number(el.dataset.unitPrice);
    if(!Number.isInteger(q)||q<0||q>99||!Number.isSafeInteger(p)||p<0)continue;
    quantity+=q;pennies+=q*p;
  }
  if(total)total.textContent=format(pennies);
  if(selectedNote){
    selectedNote.textContent=quantity>0?quantity+' accessory '+(quantity===1?'item':'items')+
      ' selected. No payment is taken online.'
      :'Choose eligible accessories below. No research demo products can be submitted.';
  }
  return quantity;
};
items.forEach(item=>item.addEventListener('input',()=>{if(Number(item.value)>0)selectedIds.add(item.dataset.checkoutQty);calc();showRows();}));
form.addEventListener('submit',event=>{
  if(calc()<1){
    event.preventDefault();
    expanded=true;showRows();
    items[0]?.focus();
    window.alert('Choose at least one eligible accessory to request.');
    return;
  }
  // Preserve the separate demo selections instead of replacing the whole
  // device basket with accessory-only items. Clear only on server success.
  const next=storage();
  items.forEach(el=>{
    const q=Number(el.value);
    if(Number.isInteger(q)&&q>0&&q<=99)next[el.dataset.checkoutQty]=q;
    else delete next[el.dataset.checkoutQty];
  });
  try{window.localStorage.setItem(key,JSON.stringify(next));}catch(_){}
});
showRows();calc();
})();

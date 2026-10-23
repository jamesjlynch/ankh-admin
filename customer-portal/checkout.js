(()=>{
'use strict';
const key='ankh-approved-accessory-cart-v1';
const storage=()=>{
 try{const v=JSON.parse(window.localStorage.getItem(key)||'{}');
 return v && typeof v==='object'&&!Array.isArray(v)?v:{};}catch(_){return {};}
};
const form=document.getElementById('sf-checkout-form');if(!form)return;
const items=[...form.querySelectorAll('[data-checkout-qty]')],total=document.getElementById('sf-guest-total');
const saved=storage();
for(const item of items){
 const id=item.dataset.checkoutQty;
 if(Object.hasOwn(saved,id)&&Number.isInteger(Number(saved[id]))&&Number(saved[id])>0&&Number(saved[id])<=99){
   item.value=String(saved[id]);
 }
}
const format=p=>'£'+(p/100).toLocaleString('en-GB',{minimumFractionDigits:2,maximumFractionDigits:2});
const calc=()=>{
 let pennies=0,quantity=0;
 items.forEach(el=>{
   const q=Number(el.value),p=Number(el.dataset.unitPrice);
   if(!Number.isInteger(q)||q<0||q>99)return;
   quantity+=q;pennies+=q*p;
 });
 if(total)total.textContent=format(pennies);
 return quantity;
};
items.forEach(item=>item.addEventListener('input',calc));
form.addEventListener('submit',e=>{
 if(calc()<1){e.preventDefault();items[0]?.focus();alert('Choose at least one eligible accessory to request.');return;}
 const next={};items.forEach(el=>{const q=Number(el.value);if(Number.isInteger(q)&&q>0&&q<=99)next[el.dataset.checkoutQty]=q;});
 try{window.localStorage.setItem(key,JSON.stringify(next));}catch(_){}
});
calc();
})();

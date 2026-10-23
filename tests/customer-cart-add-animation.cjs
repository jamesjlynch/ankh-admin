'use strict';
/**
 * No dependency on a browser or third-party packages. Exercises the real
 * storefront-cart.js click handlers against a lightweight fake document.
 */
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
class FakeText {
    constructor(text){this.textContent=String(text);}
    cloneNode(){return new FakeText(this.textContent);}
}
class FakeElement {
    constructor(name='div'){
        this.name=name;this.dataset={};this._text='';this.childNodes=[];this.handlers=new Map();
        this.classes=new Set();this.classList={
            add:(...names)=>names.forEach(n=>this.classes.add(n)),
            remove:(...names)=>names.forEach(n=>this.classes.delete(n)),
            contains:name=>this.classes.has(name)
        };
        this.attributes={};this.open=false;this.disabled=false;this.href='';
        this.localSelectors={};
    }
    get textContent(){return this.childNodes.length?this.childNodes.map(x=>x.textContent).join(''):this._text;}
    set textContent(text){this._text=String(text);this.childNodes=[];}
    get offsetWidth(){return 100;}
    append(...nodes){this.childNodes.push(...nodes);}
    replaceChildren(...nodes){this.childNodes=nodes;this._text='';}
    cloneNode(deep){const e=new FakeElement(this.name);e._text=this._text;
        e.childNodes=deep?this.childNodes.map(n=>n.cloneNode(true)):[];return e;}
    setAttribute(key,val){this.attributes[key]=String(val);}
    getAttribute(key){return this.attributes[key]??null;}
    removeAttribute(key){delete this.attributes[key];}
    addEventListener(kind,callback){this.handlers.set(kind,callback);}
    click(){this.handlers.get('click')?.({target:this,preventDefault(){}});}
    querySelector(selector){return this.localSelectors[selector]??null;}
    showModal(){this.open=true;this.showModalCalls=(this.showModalCalls||0)+1;}
    close(){this.open=false;}
}
const dialog=new FakeElement('dialog'),closeBtn=new FakeElement('button'),description=new FakeElement('p');
dialog.localSelectors['[data-close-cart]']=closeBtn;
dialog.localSelectors['.sf-cart-description']=description;
const product=new FakeElement('span');
product.dataset={approvedId:'7',approvedName:'Fixture research profile',approvedPrice:'0',approvedLive:'0'};
const add=new FakeElement('button');
add.dataset={cartId:'7'};
add.setAttribute('aria-label','Add fixture to test basket');
add.childNodes=[new FakeText('Add to basket '),new FakeText('＋')];
const basket=new FakeElement('button'),badge=new FakeElement('span'),mobileBadge=new FakeElement('span');
const list=new FakeElement('section'),subtotal=new FakeElement('strong'),checkout=new FakeElement('a'),body=new FakeElement('body');
const all={
    '[data-approved-id]':[product],'[data-cart-id]':[add],
    '[data-open-cart]':[basket],'[data-cart-count]':[badge,mobileBadge]
};
const ids={'sf-cart-drawer':dialog,'sf-cart-subtotal':subtotal,'sf-cart-lines':list,'sf-cart-checkout':checkout};
const document={
    body,getElementById:id=>ids[id]??null,querySelectorAll:q=>all[q]??[],
    createElement:tag=>new FakeElement(tag),createTextNode:message=>new FakeText(message)
};
const storage=new Map();
const localStorage={getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v)};
const listeners={};
const window={localStorage,addEventListener:(key,callback)=>{listeners[key]=callback;}};
const timers=new Map();let tid=0;
const setTimeoutFake=callback=>{tid++;timers.set(tid,callback);return tid;};
const clearTimeoutFake=id=>timers.delete(id);
const code=fs.readFileSync('customer-portal/storefront-cart.js','utf8');
vm.runInNewContext(code,{document,window,localStorage,setTimeout:setTimeoutFake,clearTimeout:clearTimeoutFake}, {filename:'storefront-cart.js'});
assert.equal(badge.textContent,'0');
assert.equal(dialog.showModalCalls??0,0);
add.click();
assert.equal(JSON.parse(storage.get('ankh-approved-accessory-cart-v1'))['7'],1,'Item not saved');
assert.equal(badge.textContent,'1','Header badge not updated');
assert.equal(mobileBadge.textContent,'1','Mobile badge not updated');
assert.equal(add.textContent,'✓ Added','Button success animation text missing');
assert.equal(add.classList.contains('is-added'),true);
assert.equal(badge.classList.contains('sf-count-pop'),true);
assert.equal(dialog.showModalCalls??0,0,'Add click must never open basket');
assert.equal(checkout.href,'./shop-demo.php','Research profile must never trigger real checkout');
const toast=body.childNodes.find(node=>node.classList.contains('sf-cart-feedback'));
assert.ok(toast&&toast.textContent.includes('added to your demo basket'),'Accessible toast missing');
assert.equal(toast.getAttribute('role'),'status');
add.click();
assert.equal(badge.textContent,'2','Repeated add should increment without leaving page');
assert.equal(dialog.showModalCalls??0,0);
basket.click();
assert.equal(dialog.showModalCalls,1,'Only an intentional Basket click should open drawer');
closeBtn.click();
assert.equal(dialog.open,false,'Basket must close on close button');
// Original labels and markup return cleanly after the confirmation.
for(const callback of Array.from(timers.values()))callback();
assert.equal(add.textContent,'Add to basket ＋');
assert.equal(add.getAttribute('aria-label'),'Add fixture to test basket');
assert.equal(add.classList.contains('is-added'),false);
console.log('Premium basket interaction passed: animated add, badge, toast, stay-on-page, explicit drawer, keyboard labels and demo routing.');

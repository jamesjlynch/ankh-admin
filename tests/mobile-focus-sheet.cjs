// Exercise the production enhancement with DOM stand-ins. No live requests or data.
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const source=fs.readFileSync('index.php','utf8');
const start=source.indexOf("(()=>{\n const sheet=document.getElementById('focus-action-sheet')");
assert(start>=0,'Production sheet handler is present');
const script=source.slice(start,source.indexOf('</script>',start));
function node(){return {listeners:{},children:[],addEventListener(name,fn){this.listeners[name]=fn},append(child){if(child.parent)child.parent.children=child.parent.children.filter(x=>x!==child);this.children.push(child);child.parent=this;}};}
function setup(mobile=true,supported=true){
 const sheet=node(),body=node(),title=node(),close=node(),details=node();
 const formState={amount:'12.34',method:'Bank Transfer'};
 const content=node();content.formState=formState;details.append(content);
 const opener={textContent:'View order',focus(opts){this.focused=opts}};
 details.open=false;details.classList={contains:name=>name==='focus-order'};
 details.querySelector=selector=>selector===':scope > .focus-sheet-content'?content:selector===':scope > summary'?opener:{textContent:'Lee'};
 const classes=new Set(),media={matches:mobile,addEventListener(name,fn){this.change=fn}};
 sheet.open=false;sheet.getBoundingClientRect=()=>({left:0,right:390,top:400,bottom:844});
 if(supported)sheet.showModal=()=>{sheet.open=true};
 sheet.close=()=>{sheet.open=false;sheet.listeners.close()};
 const elements={'focus-action-sheet':sheet,'focus-sheet-body':body,'focus-sheet-title':title,'focus-sheet-close':close};
 vm.runInNewContext(script,{document:{getElementById:id=>elements[id],querySelectorAll:()=>[details],documentElement:{classList:{add:x=>classes.add(x),remove:x=>classes.delete(x)}}},window:{matchMedia:()=>media}});
 return {sheet,body,title,close,details,content,opener,media,classes};
}
let ui=setup();ui.details.open=true;ui.details.listeners.toggle();
assert.equal(ui.sheet.open,true);assert.equal(ui.details.open,false);
assert.equal(ui.body.children[0],ui.content,'Move original form nodes into sheet');
assert.equal(ui.title.textContent,'Lee');assert(ui.classes.has('focus-sheet-open'));
ui.content.formState.amount='23.45';ui.close.listeners.click();
assert.equal(ui.details.children[0],ui.content,'Closing returns same form nodes');
assert.equal(ui.content.formState.amount,'23.45','Unsaved local field values survive closing');
assert.equal(ui.content.formState.method,'Bank Transfer');assert.equal(ui.opener.focused.preventScroll,true);
assert.equal(ui.classes.size,0);
ui.details.open=true;ui.details.listeners.toggle();ui.sheet.listeners.click({target:ui.sheet,clientX:100,clientY:100});assert.equal(ui.sheet.open,false,'Backdrop closes sheet');
ui.details.open=true;ui.details.listeners.toggle();ui.media.change({matches:false});assert.equal(ui.sheet.open,false,'Returning to desktop restores forms');
ui=setup(false);ui.details.open=true;ui.details.listeners.toggle();assert.equal(ui.sheet.open,false);assert.equal(ui.details.open,true,'Desktop keeps inline disclosure');
ui=setup(true,false);assert.equal(ui.details.listeners.toggle,undefined,'Unsupported dialog keeps native fallback');
console.log('PASS: mobile sheet opens, preserves forms and values, restores focus, closes on backdrop/desktop resize, and keeps disclosure fallback.');

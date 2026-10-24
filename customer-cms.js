'use strict';
(()=>{const editor=document.querySelector('#cms-description'),toggle=document.querySelector('#cms-preview-toggle'),preview=document.querySelector('#cms-preview');
if(!editor||!toggle||!preview)return;
function render(){preview.replaceChildren();const lines=editor.value.split(/\r?\n/);let ul=null;let p=null;
const reset=()=>{ul=null;p=null;};
for(const raw of lines){const line=raw.trim();if(!line){reset();continue;}
const heading=line.match(/^#{1,3}\s+(.+)$/);
if(heading){reset();const h=document.createElement('h3');h.textContent=heading[1];preview.append(h);continue;}
const item=line.match(/^[-*]\s+(.+)$/);
if(item){p=null;if(!ul){ul=document.createElement('ul');preview.append(ul);}const li=document.createElement('li');li.textContent=item[1].replace(/\*\*/g,'');ul.append(li);continue;}
ul=null;if(!p){p=document.createElement('p');preview.append(p);}else p.append(document.createTextNode(' '));
p.append(document.createTextNode(line.replace(/\*\*/g,'')));
}}
toggle.addEventListener('click',()=>{const open=preview.hidden;preview.hidden=!open;toggle.setAttribute('aria-expanded',String(open));if(open)render();});
editor.addEventListener('input',()=>{if(!preview.hidden)render();});
})();

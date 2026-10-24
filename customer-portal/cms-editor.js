(function(){
'use strict';
const search=document.getElementById('ankh-cms-search');
if(search){const items=[...document.querySelectorAll('[data-cms-name]')];search.addEventListener('input',()=>{
 const query=search.value.trim().toLowerCase();
 for(const item of items)item.hidden=!item.dataset.cmsName.includes(query);
});}
const source=document.querySelector('[data-cms-text]'),preview=document.getElementById('ankh-cms-preview');
function updatePreview(){if(preview&&source)preview.textContent=source.value.trim()||'Your product description preview will appear here.';}
if(source&&preview){source.addEventListener('input',updatePreview);updatePreview();}
document.querySelectorAll('[data-cms-format]').forEach(btn=>btn.addEventListener('click',()=>{
 if(!source)return;
 const start=source.selectionStart,end=source.selectionEnd,selected=source.value.slice(start,end);
 const kind=btn.dataset.cmsFormat;
 let left='',right='';
 if(kind==='heading')left='## ';
 else if(kind==='bold'){left='**';right='**';}
 else if(kind==='list')left='- ';
 else if(kind==='link'){left='[';right='](https://example.com)';}
 const replacement=left+(selected|| (kind==='heading'?'Heading':kind==='list'?'List item':'Text'))+right;
 source.setRangeText(replacement,start,end,'select');source.focus();updatePreview();
}));
})();

(function(){
'use strict';
const source=document.querySelector('[data-cms-text]'),preview=document.getElementById('ankh-cms-preview');
function appendInline(parent,text){
 for(const token of text.split(/(\*\*[^*\n]+\*\*|\[[^\]\n]+\]\(https:\/\/[^\s)]+\))/g)){
  if(/^\*\*[^*]+\*\*$/.test(token)){const b=document.createElement('strong');b.textContent=token.slice(2,-2);parent.append(b);continue;}
  const link=token.match(/^\[([^\]]+)\]\((https:\/\/[^\s)]+)\)$/);
  if(link){try{const url=new URL(link[2]);if(!url.username&&!url.password){const a=document.createElement('a');a.href=url.href;a.textContent=link[1];a.target='_blank';a.rel='noopener noreferrer';parent.append(a);continue;}}catch{}}
  parent.append(document.createTextNode(token));
 }
}
function updatePreview(){
 if(!preview||!source)return;preview.replaceChildren();let p=null,list=null;
 for(const raw of source.value.split(/\r?\n/)){
  const line=raw.trim();if(!line){p=null;list=null;continue;}
  const heading=line.match(/^#{1,3}\s+(.+)$/),bullet=line.match(/^[-*]\s+(.+)$/);
  if(heading){p=null;list=null;const h=document.createElement('h3');appendInline(h,heading[1]);preview.append(h);}
  else if(bullet){p=null;if(!list){list=document.createElement('ul');preview.append(list);}const li=document.createElement('li');appendInline(li,bullet[1]);list.append(li);}
  else{list=null;if(!p){p=document.createElement('p');preview.append(p);}else p.append(document.createElement('br'));appendInline(p,line);}
 }
 if(!source.value.trim())preview.textContent='Your description preview will appear here.';
}
const search=document.getElementById('ankh-cms-search');
if(search){const items=[...document.querySelectorAll('[data-cms-name]')],empty=document.querySelector('.cms-library-empty');search.addEventListener('input',()=>{const query=search.value.trim().toLowerCase();for(const item of items)item.hidden=!item.dataset.cmsName.includes(query);if(empty)empty.hidden=items.some(item=>!item.hidden);});}
if(source){source.addEventListener('input',updatePreview);updatePreview();}
const form=document.getElementById('ankh-cms-editor'),status=document.getElementById('cms-save-state');let dirty=false;
function changed(){dirty=true;if(status)status.textContent='Unsaved changes';updatePreview();}
if(form){form.addEventListener('input',changed);form.addEventListener('change',changed);form.addEventListener('submit',()=>{dirty=false;if(status)status.textContent='Saving…';});window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});}
document.querySelectorAll('[data-cms-format]').forEach(button=>button.addEventListener('click',()=>{
 if(!source)return;const start=source.selectionStart,end=source.selectionEnd,selection=source.value.slice(start,end),kind=button.dataset.cmsFormat;
 let text=selection||'Text';if(kind==='heading')text='## '+(selection||'Heading');else if(kind==='bold')text='**'+text+'**';else if(kind==='list')text=(selection||'List item').split('\n').map(line=>'- '+line).join('\n');else if(kind==='link')text='['+text+'](https://example.com)';
 source.setRangeText(text,start,end,'select');source.focus();changed();
}));
const upload=form?.querySelector('input[type=file]'),image=form?.querySelector('.cms-upload-preview'),uploadStatus=form?.querySelector('.cms-upload-status');let objectUrl='';
if(upload&&image)upload.addEventListener('change',()=>{if(objectUrl)URL.revokeObjectURL(objectUrl);const file=upload.files[0];if(!file){image.hidden=true;return;}if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>8*1024*1024){image.hidden=true;if(uploadStatus)uploadStatus.textContent='Choose a JPG, PNG or WebP image smaller than 8 MB.';return;}objectUrl=URL.createObjectURL(file);image.src=objectUrl;image.hidden=false;if(uploadStatus)uploadStatus.textContent=file.name+' · '+(file.size/1024/1024).toFixed(1)+' MB';});
const disclosure=document.querySelector('.ankh-staff-menu-disclosure');if(disclosure&&window.matchMedia('(max-width:980px)').matches)disclosure.open=false;
})();

// Basket settings: filter and bulk selection only affect this form, never visibility.
(function(){
'use strict';
const form=document.querySelector('.pc-demo-manager-form');if(!form)return;
const items=[...form.querySelectorAll('[data-settings-product]')];
const inputs=items.map(item=>item.querySelector('input[type=checkbox]'));
const search=document.getElementById('pc-basket-search'),status=document.querySelector('[data-settings-selected]'),empty=document.getElementById('pc-basket-no-results');
function update(){if(status)status.textContent=inputs.filter(input=>input.checked).length+' selected';}
if(search)search.addEventListener('input',()=>{const query=search.value.trim().toLowerCase();for(const item of items)item.hidden=!item.dataset.settingsProduct.includes(query);if(empty)empty.hidden=!items.length||items.some(item=>!item.hidden);});
for(const input of inputs)input.addEventListener('change',update);
form.querySelectorAll('[data-settings-select]').forEach(button=>button.addEventListener('click',()=>{for(const input of inputs)input.checked=button.dataset.settingsSelect==='all';update();}));
update();
})();

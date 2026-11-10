/* No user data is inserted as HTML. All requests remain on the authenticated origin. */
(() => {
  'use strict';
  const root=document.querySelector('#sqm'), form=root.querySelector('#storage-form'), message=root.querySelector('#sqm-message');
  const resizeForm=root.querySelector('#resize-form');let resizing=null;
  const moreUsers=root.querySelector('#more-users');let expandedUsers=false;
  function updateUserList(){
    const rows=[...root.querySelectorAll('.sqm-user-row')];let selected=0,hiddenSelected=0;
    rows.forEach((row,index)=>{row.hidden=!expandedUsers&&index>=10;const checked=row.querySelector('input').checked;if(checked){selected++;if(row.hidden)hiddenSelected++;}});
    moreUsers.hidden=rows.length<=10;moreUsers.textContent=expandedUsers?'Less':`More (${Math.max(0,rows.length-10)})`;moreUsers.setAttribute('aria-expanded',String(expandedUsers));
    root.querySelector('#user-selection-summary').textContent=`${selected} selected${hiddenSelected?` · ${hiddenSelected} in More`:''}. Only checked users have access.`;
  }
  moreUsers.onclick=()=>{expandedUsers=!expandedUsers;updateUserList();};
  let state, editing=null, busy=false;
  const field=n=>form.elements.namedItem(n);
  function tell(text,error=false){message.textContent=text;message.className=error?'sqm-error':'';}
  async function api(action,data={}){
    const response=await fetch('/plugins/quota-manager/api.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':root.dataset.token},body:new URLSearchParams({csrf_token:root.dataset.token,sqm_csrf_token:root.dataset.token,action,data:JSON.stringify(data)})});
    const result=await response.json();if(!result.ok)throw Error(result.error);return result.result;
  }
  function size(n){return n===null?'—':`${(n/1e9).toFixed(2)} GB`;}
  function option(value,text){const el=document.createElement('option');el.value=value;el.textContent=text;return el;}
  async function refresh(){
    state=await api('status');
    const body=root.querySelector('#storages');body.replaceChildren();
    if(!state.storages.length){const tr=body.insertRow();const td=tr.insertCell();td.colSpan=7;td.textContent='No storages yet. Create one to reserve capacity and enable private network access.';}
    for(const s of state.storages){
      const tr=body.insertRow();
      for(const value of [s.name,`${s.location} (${s.filesystem??'ext4'})`,size(s.bytes),`${size(s.used)} / ${size(s.available)}`,s.users.map(u=>`${u}: ${s.permissions?.[u]==='read'?'Read':'Read/Write'}`).join(', '),s.status])tr.insertCell().textContent=value;
      tr.cells[5].title=s.status_detail??'';
      const actions=tr.insertCell();
      for(const [label,action] of [['Edit','edit'],['Mount','mount'],['Unmount','unmount'],['Resize','resize'],['Remove Share','remove-share'],['Detach','detach'],['Delete','delete'],['Copy Path','copy']]){
        const b=document.createElement('button');b.type='button';b.textContent=label;b.setAttribute('aria-label',action==='delete'?`Delete entire virtual disk ${s.name} and all its files`:`${label}: ${s.name}`);if(action==='delete'){b.className='sqm-danger';b.title='Permanently delete this entire virtual disk and all files inside it';}b.onclick=()=>handle(action,s);actions.append(b);
      }
    }
  }
  function open(s=null){
    resizeForm.hidden=true;
    editing=s;form.reset();form.hidden=false;root.querySelector('#form-title').textContent=s?'Edit Storage':'Create Storage';root.querySelector('#save-storage').textContent=s?'Save':'Create';
    field('location').replaceChildren(...state.locations.map(p=>option(p,p.startsWith('/mnt/disks/')?`${p} (External / USB)`:p)),option('custom','Custom Path…'));
    const list=root.querySelector('#user-permissions');list.replaceChildren();
    expandedUsers=false;
    // No account timestamps are provided: last entries in the system list first,
    // not an invented last-login/activity ranking.
    for(const [index,user] of [...state.users].reverse().entries()){
      const row=document.createElement('div');row.className='sqm-user-row';
      const label=document.createElement('label');label.className='sqm-check';
      const check=document.createElement('input');check.type='checkbox';check.name='selected-user';check.value=user;check.checked=!!s?.users.includes(user);
      const name=document.createElement('span');name.textContent=user;label.append(check,name);
      const select=document.createElement('select');select.id=`sqm-permission-${index}`;select.setAttribute('aria-label',`Permissions for ${user}`);select.append(option('read','Read'),option('rw','Read/Write'));
      select.value=s?.permissions?.[user]??(s?.users.includes(user)?'rw':'read');select.disabled=!check.checked;
      check.onchange=()=>{select.disabled=!check.checked;updateUserList();};row.append(label,select);list.append(row);
    }
    if(!state.users.length){const p=document.createElement('p');p.textContent='No eligible users. Create an SMB user in Unraid Users first.';list.append(p);}
    updateUserList();
    field('filesystem').value=s?.filesystem??'ext4';
    for(const n of ['name','location','capacity','unit','access','filesystem'])field(n).disabled=!!s;
    if(s){field('name').value=s.name;field('auto').checked=s.auto;field('share').checked=s.share;}
    root.querySelector('#share-field').hidden=!s;root.querySelector('#custom-field').hidden=true;field('name').focus();form.scrollIntoView({behavior:'smooth',block:'nearest'});
  }
  async function operation(work){if(busy)return;busy=true;root.querySelectorAll('button').forEach(b=>b.disabled=true);tell('Operation in progress. Keep this page open.');try{await work();await refresh();tell('Operation completed.');}catch(e){tell(e.message,true);}finally{busy=false;root.querySelectorAll('button').forEach(b=>b.disabled=false);}}
  async function handle(action,s){
    if(action==='edit'){open(s);return;}
    if(action==='resize'){
      resizing=s;form.hidden=true;resizeForm.hidden=false;
      resizeForm.elements.namedItem('storage').value=s.name;
      resizeForm.elements.namedItem('capacity').value=Math.floor(s.bytes/1e9)+1;
      resizeForm.elements.namedItem('unit').value='GB';
      resizeForm.elements.namedItem('capacity').focus();resizeForm.scrollIntoView({behavior:'smooth',block:'nearest'});return;
    }
    if(action==='copy'){
      const path=`\\\\${root.dataset.server}\\${s.name}`;
      try{await navigator.clipboard.writeText(path);tell(`Copied ${path}`);}catch{window.prompt('Copy network path:',path);}return;
    }
    let confirmation;
    if(action==='delete'){confirmation=window.prompt(`DELETE ENTIRE VIRTUAL DISK "${s.name}" (${size(s.bytes)})?\n\nThe image and ALL files inside it will be permanently deleted. This cannot be undone.\n\nType DELETE ${s.name} to confirm.`);if(confirmation!==`DELETE ${s.name}`)return;}
    else if(['detach','remove-share','unmount'].includes(action)&&!window.confirm(`${action} ${s.name}? Active SMB connections will be closed. Image data will be retained.`))return;
    await operation(()=>api(action,{name:s.name,confirmation}));
  }
  root.querySelector('#new-storage').onclick=()=>{if(!state){tell('Storage information is unavailable. Refresh the page and resolve the displayed error before creating storage.',true);return;}open();};root.querySelector('#cancel-storage').onclick=()=>{form.hidden=true;};
  field('location').onchange=()=>{root.querySelector('#custom-field').hidden=field('location').value!=='custom';};
  form.onsubmit=e=>{e.preventDefault();if(!form.reportValidity())return;
    const users=[],permissions={};for(const row of root.querySelectorAll('.sqm-user-row')){const check=row.querySelector('input');if(check.checked){users.push(check.value);permissions[check.value]=row.querySelector('select').value;}}
    if(!users.length){tell('Select at least one authorized user.',true);root.querySelector('[name="selected-user"]')?.focus();return;}
    const data={name:field('name').value,location:field('location').value==='custom'?field('custom').value:field('location').value,capacity:field('capacity').value,unit:field('unit').value,filesystem:field('filesystem').value,users,permissions,access:'private',auto:field('auto').checked,share:field('share').checked};operation(async()=>{await api(editing?'edit':'create',data);form.hidden=true;});};
  async function logs(){try{root.querySelector('#log-text').textContent=(await api('logs')).join('\n')||'No quota-manager log entries.';}catch(e){tell(e.message,true);}}
  root.querySelector('#logs').ontoggle=e=>{if(e.target.open)logs();};root.querySelector('#refresh-logs').onclick=logs;
  root.querySelector('#cancel-resize').onclick=()=>{resizeForm.hidden=true;};
  resizeForm.onsubmit=e=>{e.preventDefault();if(!resizeForm.reportValidity()||!resizing)return;
    const capacity=resizeForm.elements.namedItem('capacity').value,unit=resizeForm.elements.namedItem('unit').value;
    const bytes=Number(capacity)*(unit==='GB'?1e9:1073741824);
    if(bytes<=resizing.bytes){tell('Choose a total capacity larger than the current image. Shrinking is prohibited.',true);return;}
    const name=resizing.name;operation(async()=>{await api('resize',{name,capacity,unit});resizeForm.hidden=true;});
  };
  refresh().then(()=>{if(window.location.hash==='#create')open();}).catch(e=>{tell(e.message,true);const body=root.querySelector('#storages');body.replaceChildren();const td=body.insertRow().insertCell();td.colSpan=7;td.textContent='Unable to load storages. Refresh the page after resolving the error above.';});
})();

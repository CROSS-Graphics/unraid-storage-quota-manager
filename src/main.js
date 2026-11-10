(() => {
  'use strict';
  const root=document.querySelector('#sqm-main');if(!root)return;
  const body=root.querySelector('#sqm-main-disks'),message=root.querySelector('#sqm-main-message');
  // Unraid already supplies a section title in non-tabbed Main. Reuse it instead
  // of rendering a second header. Tabbed Main keeps the compact fallback toolbar.
  const toolbar=root.querySelector('.sqm-main-title');
  if(toolbar){let header=root.previousElementSibling;
    while(header&&!header.classList.contains('title'))header=header.previousElementSibling;
    if(header){header.classList.add('sqm-native-title');const tools=toolbar.querySelector('.sqm-main-tools');if(tools)header.append(tools);toolbar.remove();}
  }
  const updateLink=document.querySelector('#sqm-main-update');
  let busy=false,loading=false,hasRows=false;
  const size=value=>value===null||value===undefined?'—':`${(value/1e9).toFixed(2)} GB`;
  function tell(text,error=false){message.textContent=text;message.className=error?'sqm-main-error':'';}
  function buttons(disabled){root.querySelectorAll('button').forEach(b=>{b.disabled=disabled||b.dataset.unavailable==='true';});}
  async function request(action,data={}){
    const response=await fetch('/plugins/quota-manager/api.php',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':root.dataset.token},body:new URLSearchParams({csrf_token:root.dataset.token,sqm_csrf_token:root.dataset.token,action,data:JSON.stringify(data)})});
    const value=await response.json();if(!response.ok||!value.ok)throw Error(value.error||`Request failed (${response.status})`);return value.result;
  }
  function render(storages){
    body.replaceChildren();hasRows=true;
    if(!storages.length){const cell=body.insertRow().insertCell();cell.colSpan=8;cell.textContent='No virtual disks. Use + to add a new disk.';return;}
    for(const disk of storages){
      const row=body.insertRow();const name=row.insertCell();
      const link=document.createElement('a');link.href='/Settings/QuotaManager';link.textContent=disk.name;name.append(link);
      row.insertCell().textContent=disk.location;row.insertCell().textContent=disk.filesystem??'ext4';
      const status=row.insertCell(),dot=document.createElement('span');dot.className=`sqm-main-status ${disk.status==='Online'?'online':disk.phase!=='ready'?'recovery':''}`;dot.setAttribute('aria-hidden','true');status.append(dot,document.createTextNode(disk.status));status.title=disk.status_detail??'';
      for(const bytes of [disk.bytes,disk.used,disk.available])row.insertCell().textContent=size(bytes);
      const cell=row.insertCell(),button=document.createElement('button');button.type='button';button.className='sqm-main-action';
      const mounted=disk.status==='Online';button.textContent=mounted?'Unmount':'Mount';button.setAttribute('aria-label',`${button.textContent} ${disk.name}`);
      button.dataset.unavailable=String(!mounted&&disk.phase!=='ready');button.disabled=busy||button.dataset.unavailable==='true';
      button.onclick=()=>operate(mounted?'unmount':'mount',disk.name);cell.append(button);
    }
  }
  async function refresh(){
    if(loading)return;loading=true;
    try{const data=await request('status');render(data.storages);
      if(updateLink){updateLink.hidden=data.update?.available!==true;updateLink.title=data.update?.available?`Update available: ${data.update.version}`:'Plugin update available';}
      if(!busy)tell('');return true;}
    catch(error){tell(`Unable to refresh virtual disks: ${error.message}. Displayed values may be out of date.`,true);if(!hasRows){body.replaceChildren();const cell=body.insertRow().insertCell();cell.colSpan=8;cell.textContent='Virtual disk status unavailable. Use Refresh to retry.';}return false;}
    finally{loading=false;}
  }
  async function operate(action,name){
    if(busy||loading)return;
    if(action==='unmount'&&!window.confirm(`Unmount virtual disk ${name}? SMB connections will be closed. Disk data will be retained.`))return;
    busy=true;buttons(true);tell(`${action==='mount'?'Mounting':'Unmounting'} ${name}…`);
    try{await request(action,{name});if(await refresh())tell(`${name}: ${action==='mount'?'mounted':'unmounted'}.`);}
    catch(error){tell(error.message,true);}
    finally{busy=false;buttons(false);}
  }
  document.querySelector('#sqm-main-refresh').onclick=()=>{if(!busy)refresh();};
  refresh();
  // Visible-page polling only; never issue duplicate or overlapping status calls.
  const timer=setInterval(()=>{if(!root.isConnected){clearInterval(timer);return;}if(!document.hidden&&!busy&&!loading)refresh();},15000);
  window.addEventListener('pagehide',()=>clearInterval(timer),{once:true});
})();

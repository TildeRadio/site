const {JSDOM}=require('jsdom');
const https=require('https');
const fs=require('fs');
const assert=require('node:assert/strict');
const origin=process.env.DJ_TEST_ORIGIN;
let cookie=''; let posts=0;
async function fetchPage(url, options={}) {
  const target=new URL(url);
  assert.equal(target.origin,origin);
  const method=options.method||'GET';
  if(method==='POST') posts++;
  const data=options.body ? options.body.toString() : '';
  const result=await new Promise((resolve,reject)=>{
    const headers={...options.headers};
    if(cookie && target.pathname.startsWith('/dj/')) headers.Cookie=cookie;
    if(method==='POST') {
      headers['Content-Type']='application/x-www-form-urlencoded;charset=UTF-8';
      headers['Content-Length']=Buffer.byteLength(data);
      headers.Origin=origin;
    }
    const req=https.request(target,{method,headers,rejectUnauthorized:false},res=>{
      let body='';res.setEncoding('utf8');res.on('data',chunk=>body+=chunk);
      res.on('end',()=>resolve({status:res.statusCode,headers:res.headers,body}));
    });
    req.on('error',reject);req.end(data);
  });
  if(result.headers['set-cookie']) cookie=result.headers['set-cookie'][0].split(';')[0];
  if(result.status===303) return fetchPage(result.headers.location);
  target.hash='';
  return {url:target.href,headers:new Headers(result.headers),text:async()=>result.body};
}
function nextNavigation(win) {
  return new Promise((resolve,reject)=> {
    const deadline=setTimeout(()=>reject(new Error('Navigation timed out')),5000);
    win.addEventListener('tilderadio:after-navigate',()=>{clearTimeout(deadline);resolve();},{once:true});
  });
}
(async()=>{
 const initial=await fetchPage(origin+'/dj/login.php');
 const dom=new JSDOM(await initial.text(),{url:initial.url,runScripts:'outside-only',pretendToBeVisual:true});
 const win=dom.window;win.fetch=fetchPage;win.scrollTo=()=>{};
 let scrolledTo='';win.HTMLElement.prototype.scrollIntoView=function(){scrolledTo=this.id;};
 const audio=win.document.getElementById('tr-audio');
 Object.defineProperty(audio,'paused',{get:()=>false});
 audio.play=()=>Promise.resolve();audio.pause=()=>{};audio.load=()=>{};
 win.eval(fs.readFileSync(require('node:path').join(__dirname,'../js/site-player.js'),'utf8'));
 async function submit(password,duplicate=false) {
   win.document.getElementById('dj-username').value='deepend';
   win.document.getElementById('dj-password').value=password;
   const form=win.document.querySelector('[data-tr-dj-auth]');
   const done=nextNavigation(win);
   assert.equal(form.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true})),false);
   if(duplicate) form.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true}));
   await done;
   assert.equal(win.document.getElementById('tr-audio'),audio);
 }
 await submit('wrong');
 assert.ok(win.document.querySelector('[role=alert]').textContent.includes('not accepted'));
 assert.equal(win.document.activeElement,win.document.querySelector('[role=alert]'));
 assert.equal(win.document.getElementById('dj-password').value,'');
 const oldPosts=posts;
 await submit('test-correct-password',true);
 assert.equal(posts,oldPosts+1);
 assert.ok(win.document.querySelector('main').textContent.includes('Administrator'));
 assert.equal(win.location.pathname,'/dj/');
 async function go(path) {
   const anchor=win.document.createElement('a');anchor.href=path;anchor.textContent='Test navigation';
   win.document.querySelector('main').appendChild(anchor);
   const done=nextNavigation(win);anchor.click();await done;
   assert.equal(win.document.getElementById('tr-audio'),audio);
 }
 async function save(form,duplicate=false) {
   const oldPosts=posts, done=nextNavigation(win);
   assert.equal(form.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true})),false);
   if(duplicate) form.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true}));
   await done;assert.equal(posts,oldPosts+1);assert.equal(win.document.getElementById('tr-audio'),audio);
 }
 await go('/dj/admin/');
 assert.ok(win.document.querySelector('main').textContent.includes('Station administration'));
 assert.ok(win.document.querySelector('link[href$="/css/dj-admin.css"]'));
 await go('/dj/admin/profiles.php');
 await go('/dj/admin/profile.php');
 let profileForm=win.document.querySelector('[data-tr-dj-auth]');
 profileForm.elements.slug.value='player-test';profileForm.elements.name.value='Player profile';
 await save(profileForm,true);
 assert.equal(win.location.search,'?slug=player-test');
 assert.ok(win.document.querySelector('main').textContent.includes('DJ profile saved'));
 profileForm=win.document.querySelector('[data-tr-dj-auth]');
 profileForm.elements.name.value='Edited while playing';await save(profileForm);
 assert.equal(win.document.querySelector('[name=name]').value,'Edited while playing');
 await go('/dj/admin/account.php?station=1&streamer=163');
 const accountForm=win.document.querySelector('[data-tr-dj-auth]');accountForm.elements.label.value='Website Deepend';
 await save(accountForm);
 assert.equal(win.document.querySelector('[name=label]').value,'Website Deepend');
 await go('/dj/admin/account.php');
 const pickerForm=win.document.querySelector('[data-tr-dj-auth]');
 assert.equal(pickerForm.elements.streamer_id.tagName,'SELECT');
 pickerForm.elements.streamer_id.value='4';
 pickerForm.elements.label.value='Cat selected by name';
 await save(pickerForm,true);
 assert.equal(win.location.search,'?station=1&streamer=4');
 assert.equal(win.document.querySelector('[name=label]').value,'Cat selected by name');
 assert.equal(win.document.querySelector('[name="target_streamers[1]"]').value,'4');
 await go('/dj/admin/schedule.php?source_station=1&source_streamer=163&station=1');
 const scheduleForm=win.document.querySelector('[data-tr-dj-auth]');
 scheduleForm.elements.start_time.value='16:00';scheduleForm.elements.end_time.value='17:00';
 await save(scheduleForm,true);
 assert.ok(win.document.querySelector('main').textContent.includes('AzuraCast schedule saved'));
 await go('/dj/broadcast.php');
 let broadcastForm=win.document.querySelector('[data-tr-dj-auth]');
 broadcastForm.elements.owner_slug.value='player-test';
 broadcastForm.elements.show_episode.value='Created while listening';
 await save(broadcastForm,true);
 assert.ok(win.document.querySelector('main').textContent.includes('Broadcast listing saved'));
 const broadcastPath=win.location.pathname+win.location.search;
 broadcastForm=win.document.querySelector('[data-tr-dj-auth]');
 broadcastForm.elements.show_episode.value='Corrected while listening';
 await save(broadcastForm);
 assert.equal(win.document.querySelector('[name=show_episode]').value,'Corrected while listening');
 await go('/episodes/'+win.location.search);
 assert.ok(win.document.querySelector('main').textContent.includes('Corrected while listening'));
 assert.equal(win.document.getElementById('tr-audio'),audio);
 await go(broadcastPath);
 const deleteBroadcast=[...win.document.querySelectorAll('[data-tr-dj-auth]')].find(form=>form.elements.action.value==='delete');
 deleteBroadcast.elements.confirm.value='DELETE';
 await save(deleteBroadcast);
 assert.ok(win.document.querySelector('main').textContent.includes('Listing deleted'));
 await go('/help/');
 assert.ok(win.document.querySelector('link[href$="/css/help.css"]'));
 const helpForm=win.document.querySelector('[data-tr-help-search]');
 helpForm.elements.q.value='!songs';helpForm.elements.audience.value='dj';
 const beforeHelpPosts=posts,helpDone=nextNavigation(win);
 assert.equal(helpForm.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true})),false);
 await helpDone;
 assert.equal(posts,beforeHelpPosts);
 assert.equal(win.document.getElementById('tr-audio'),audio);
 assert.equal(win.location.pathname,'/help/');
 assert.equal(new URL(win.location.href).searchParams.get('q'),'!songs');
 assert.ok(win.document.querySelector('#help-content').textContent.includes('Song announcements'));
 assert.equal(win.document.querySelector('.site-nav [aria-current=page]').textContent,'help');
 const result=win.document.querySelector('.tr-help-card h3 a');
 assert.equal(new URL(result.href).searchParams.get('q'),'!songs');
 await go(result.href);
 assert.ok(win.document.querySelector('#help-content').textContent.includes('!songs on'));
 await go('/djinfo/#testing');
 assert.ok(win.document.getElementById('testing'));
 assert.equal(win.location.hash,'#testing');assert.equal(scrolledTo,'testing');
 assert.equal(win.document.querySelector('.site-nav [aria-current=page]').textContent,'help');
 await go('/community/carrier/#station');
 assert.ok(win.document.getElementById('station'));
 assert.equal(win.location.hash,'#station');assert.equal(scrolledTo,'station');
 await go('/dj/');
 assert.equal(win.document.querySelector('link[href$="/css/help.css"]'),null);
 assert.equal(win.document.querySelector('link[href$="/css/dj-admin.css"]'),null);
 const logout=win.document.querySelector('[data-tr-dj-auth]');
 const done=nextNavigation(win);
 logout.dispatchEvent(new win.Event('submit',{bubbles:true,cancelable:true}));await done;
 assert.equal(win.location.pathname,'/dj/login.php');
 assert.equal(win.document.getElementById('tr-audio'),audio);
 assert.ok(win.document.getElementById('dj-password'));
 const back=nextNavigation(win);win.dispatchEvent(new win.PopStateEvent('popstate'));await back;
 assert.equal(win.document.getElementById('tr-audio'),audio);
 dom.window.close();
 console.log('Player DOM integration passed: login, error focus, duplicate submit, administrator/profile/account/schedule edits, broadcast CRUD/public navigation, help search/article/legacy links, logout and history preserve the original audio element.');
})().catch(err=>{console.error(err);process.exit(1);});

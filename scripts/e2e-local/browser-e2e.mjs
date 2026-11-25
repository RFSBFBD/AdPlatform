import {writeFileSync} from 'node:fs';
const list=await (await fetch('http://127.0.0.1:9222/json/list')).json();const tab=list.find(t=>t.type==='page');
const ws=new WebSocket(tab.webSocketDebuggerUrl);await new Promise(r=>ws.onopen=r);let id=0;const pending=new Map();
ws.onmessage=e=>{const m=JSON.parse(e.data);if(m.id&&pending.has(m.id)){pending.get(m.id)(m);pending.delete(m.id);}};
const send=(method,params={})=>new Promise(r=>{const i=++id;pending.set(i,r);ws.send(JSON.stringify({id:i,method,params}));});
const ev=async e=>(await send('Runtime.evaluate',{expression:e,awaitPromise:true,returnByValue:true})).result?.result?.value;
const wait=async e=>{for(let i=0;i<100;i++){if(await ev(e))return;await new Promise(r=>setTimeout(r,100));}throw Error('wait '+e);};
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
await send('Page.enable');await send('Runtime.enable');

const BASE='http://127.0.0.1:4300';
const setv=(sel,v)=>`(()=>{const e=document.querySelector(${JSON.stringify(sel)});e.value=${JSON.stringify(v)};e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return true})()`;
const clickText=(root,re)=>`(()=>{const b=[...document.querySelector(${JSON.stringify(root)}).querySelectorAll('button')].find(b=>${re}.test(b.textContent));if(!b)return false;b.click();return true})()`;
const results=[];const log=(k,v)=>{results.push([k,v]);console.log(k,'=>',typeof v==='string'?v.slice(-420):JSON.stringify(v).slice(0,260));};
const shotEl=async(sel,name,w)=>{
 await send('Emulation.setDeviceMetricsOverride',{width:w,height:1100,deviceScaleFactor:1,mobile:w<500});await sleep(300);
 const o=JSON.parse(await ev(`(()=>{const d=document.querySelector(${JSON.stringify(sel)});d.scrollIntoView({block:'start'});const b=d.getBoundingClientRect();return JSON.stringify({h:b.height,ov:document.documentElement.scrollWidth>innerWidth})})()`));
 await send('Emulation.setDeviceMetricsOverride',{width:w,height:Math.min(2400,Math.ceil(o.h)+40),deviceScaleFactor:1,mobile:w<500});await sleep(300);
 await ev(`document.querySelector(${JSON.stringify(sel)}).scrollIntoView({block:'start'})`);await sleep(200);
 const shot=await send('Page.captureScreenshot',{format:'png'});writeFileSync(`/downloads/e2e-${name}-${w<500?'mobile':'desktop'}.png`,Buffer.from(shot.result.data,'base64'));
 log('overflow '+name+' '+(w<500?'mobile':'desktop'),o.ov);};
const login=async(email)=>{
 await send('Page.navigate',{url:BASE+'/'});await wait('!!document.querySelector("app-root")');await ev(`localStorage.clear();true`);await send('Page.navigate',{url:BASE+'/login'});await wait('!!document.querySelector("input[type=email]")');
 await ev(setv('input[type=email]',email));await ev(setv('input[type=password]','password'));
 await ev('document.querySelector("button[type=submit]").click()');
 await wait('location.pathname!=="/login"');await sleep(500);return await ev('location.pathname');};
// unauthenticated visit to a protected page redirects to login
await send('Page.navigate',{url:BASE+'/'});await wait('!!document.querySelector("app-root")');await ev('localStorage.clear();true');
await send('Page.navigate',{url:BASE+'/analytics-lab'});await sleep(1500);log('unauth /analytics-lab lands on',await ev('location.pathname'));
// wrong password
await ev(`localStorage.clear();true`);await send('Page.navigate',{url:BASE+'/login'});await wait('!!document.querySelector("input[type=email]")');
await ev(setv('input[type=email]','owner@e2e.invalid'));await ev(setv('input[type=password]','wrong-password'));await ev('document.querySelector("button[type=submit]").click()');await sleep(1200);
log('wrong password stays on',await ev('location.pathname'));log('wrong password text',await ev('document.body.innerText.includes("credentials")||document.body.innerText.toLowerCase().includes("invalid")'));
// real login as owner
log('owner login lands on',await login('owner@e2e.invalid'));
await send('Page.navigate',{url:BASE+'/analytics-lab'});await wait('!!document.querySelector("app-audience-saturation")');await sleep(800);
log('token stored',await ev('!!localStorage.getItem("ad_platform_auth_token")'));
// saturation, own entity
await ev(setv('#sat-account','2'));await ev(setv('#sat-entity','2'));await ev(clickText('app-audience-saturation',/Estimate/));await sleep(1500);
log('saturation own',await ev('document.querySelector("app-audience-saturation").innerText.replace(/\\s+/g," ")'));
for(const w of [1440,390])await shotEl('app-audience-saturation','saturation-own',w);
// saturation foreign (other user's account)
await ev(setv('#sat-account','4'));await ev(setv('#sat-entity','4'));await ev(clickText('app-audience-saturation',/Estimate/));await sleep(1500);
log('saturation foreign',await ev('document.querySelector("app-audience-saturation").innerText.replace(/\\s+/g," ").slice(-300)'));
for(const w of [1440,390])await shotEl('app-audience-saturation','saturation-foreign',w);
// comparison own
await ev(setv('#pc-ids','2,3'));await ev(clickText('app-platform-comparison',/Show gates/));await sleep(1500);
log('comparison own',await ev('document.querySelector("app-platform-comparison").innerText.replace(/\\s+/g," ")'));
for(const w of [1440,390])await shotEl('app-platform-comparison','comparison-own',w);
await ev(setv('#pc-ids','2,4'));await ev(clickText('app-platform-comparison',/Show gates/));await sleep(1500);
log('comparison foreign',await ev('document.querySelector("app-platform-comparison").innerText.replace(/\\s+/g," ").slice(-260)'));
for(const w of [1440,390])await shotEl('app-platform-comparison','comparison-foreign',w);
// decision evidence list, open, analyst
await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1100,deviceScaleFactor:1,mobile:false});
await wait('!!document.querySelector("app-decision-evidence button")');log('decision-evidence buttons',await ev('[...document.querySelectorAll("app-decision-evidence button")].map(b=>b.textContent.trim()).join("|")'));
await ev('document.querySelector("app-decision-evidence button").click()');
await sleep(1500);log('decision-evidence buttons after',await ev('[...document.querySelectorAll("app-decision-evidence button")].map(b=>b.textContent.trim()).join("|")'));
await ev('[...document.querySelectorAll("app-decision-evidence button")].find(b=>b.textContent==="Open")?.click()');
await wait('!!document.querySelector("app-decision-evidence textarea")');
await ev('const d=[...document.querySelectorAll("app-decision-evidence details")].find(x=>x.querySelector("textarea"));d.open=true;true');
const ask=async(q,ai)=>{await ev(`(()=>{const d=[...document.querySelectorAll("app-decision-evidence details")].find(x=>x.querySelector("textarea"));const t=d.querySelector("textarea");t.value=${JSON.stringify(q)};t.dispatchEvent(new Event('input',{bubbles:true}));const c=d.querySelector("input[type=checkbox]");if(c.checked!==${ai})c.click();[...d.querySelectorAll("button")].find(b=>/Show evidence/i.test(b.textContent))?.click();return true})()`);await sleep(2000);
 return await ev(`[...document.querySelectorAll("app-decision-evidence details")].find(x=>x.querySelector("textarea")).innerText.replace(/\\s+/g," ")`);};
log('analyst no-AI',await ask('كم الإنفاق؟ how much spend',false));
for(const w of [1440,390])await shotEl('app-decision-evidence details[open]','analyst-noai',w);
log('analyst AI requested no config',await ask('كم الإنفاق؟ how much spend',true));
for(const w of [1440,390])await shotEl('app-decision-evidence details[open]','analyst-ai-requested',w);
// other user cannot see owner's evidence
log('other login lands on',await login('other@e2e.invalid'));
await send('Page.navigate',{url:BASE+'/analytics-lab'});await wait('!!document.querySelector("app-decision-evidence button")');await ev('document.querySelector("app-decision-evidence button").click()');await sleep(1500);
log('other user Open buttons',await ev('[...document.querySelectorAll("app-decision-evidence button")].filter(b=>b.textContent==="Open").length'));
// logout/expired token
await ev(`localStorage.setItem('ad_platform_auth_token','invalid');true`);await send('Page.navigate',{url:BASE+'/analytics-lab'});await sleep(1500);await ev('document.querySelector("app-decision-evidence button")?.click()');await sleep(2000);log('invalid token lands on',await ev('location.pathname'));
ws.close();

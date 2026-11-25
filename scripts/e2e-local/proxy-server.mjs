import http from 'node:http';
import {readFile} from 'node:fs/promises';
import {extname,join} from 'node:path';
const root='/tmp/w/adplatform/frontend/dist/frontend/browser';
http.createServer(async(req,res)=>{
 if(req.url.startsWith('/api/')){
  const chunks=[];for await(const c of req)chunks.push(c);
  const r=await fetch('http://127.0.0.1:8000'+req.url,{method:req.method,headers:Object.fromEntries(Object.entries(req.headers).filter(([k])=>!['host','connection','content-length'].includes(k))),body:['GET','HEAD'].includes(req.method)?undefined:Buffer.concat(chunks)});
  const b=Buffer.from(await r.arrayBuffer());res.writeHead(r.status,{'content-type':r.headers.get('content-type')||'application/json'});res.end(b);return;}
 try{const p=join(root,new URL(req.url,'http://x').pathname);const d=await readFile(p);res.writeHead(200,{'content-type':{'.js':'text/javascript','.css':'text/css','.html':'text/html'}[extname(p)]||'application/octet-stream'});res.end(d);}
 catch{const d=await readFile(join(root,'index.html'));res.writeHead(200,{'content-type':'text/html'});res.end(d);}
}).listen(4300,'127.0.0.1');

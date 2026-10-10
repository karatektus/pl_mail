import http from 'node:http';
const calls=[];
http.createServer((req,res)=>{
 res.setHeader('Content-Type','application/json');
 if(req.url==='/log'){res.end(JSON.stringify(calls));return;}
 calls.push({url:req.url,authorized:req.headers.authorization==='Bearer synthetic-browser-secret'});
 res.end(JSON.stringify({data:[{id:'synthetic-browser-model'}]}));
}).listen(8000,'0.0.0.0');

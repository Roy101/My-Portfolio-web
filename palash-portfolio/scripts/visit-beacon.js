/* Visitor-country beacon for the static pages (privacy-first, aggregate only; see cpanel-backend/api/visit.php). Same rules as useVisitBeacon in src/App.tsx: once per browser session, only the time zone name, never for automated browsers, bots, Do Not Track / Global Privacy Control or /admin; sent after load in idle time. */
(function(){var n=navigator,w=window;try{if(n.webdriver||n.globalPrivacyControl===true||n.doNotTrack==="1"||w.doNotTrack==="1"||/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|facebookexternalhit|embedly/i.test(n.userAgent||"")||/^\/admin(\/|$)/.test(location.pathname))return}catch(e){return}
function send(){try{if(sessionStorage.getItem("pr_visit")==="1")return;sessionStorage.setItem("pr_visit","1")}catch(e){return}
var tz="";try{tz=Intl.DateTimeFormat().resolvedOptions().timeZone||""}catch(e){}
if(!tz||/^(Etc\/|UTC$|GMT)/.test(tz))return;var b=JSON.stringify({tz:tz}),u="/api/visit.php";
try{if(n.sendBeacon&&n.sendBeacon(u,new Blob([b],{type:"text/plain"})))return}catch(e){}
try{fetch(u,{method:"POST",body:b,keepalive:true,credentials:"omit",headers:{"Content-Type":"text/plain"}}).catch(function(){})}catch(e){}}
function go(){if(w.requestIdleCallback)w.requestIdleCallback(send,{timeout:5000});else setTimeout(send,1500)}
if(document.readyState==="complete")go();else w.addEventListener("load",go,{once:true})})();

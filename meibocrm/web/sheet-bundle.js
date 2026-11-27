let WD="日月火水木金土";
let TW=367.1;
let U=[52,42,44,200,48,52,104,80,226,242,268,0,190,90,100,64,55,180,114];
let MED=[["nextMorning","翌朝"],["morning","朝"],["patch","貼り薬"],["noon","昼"],["evening","夕"],["bedtime","寝る前"]];
/* ACT: not found */
/* MEDS: not found */
const blank=()=>({present:[],overnight:[],staff:{dawn:[],day:[],night:[]},other:''});
const pd=s=>{const[a,b,c]=s.split('-').map(Number);return new Date(a,b-1,c)};
const dkey=x=>x.getFullYear()+'-'+pad(x.getMonth()+1)+'-'+pad(x.getDate());
const esc=s=>String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const sp=e=>{e=String(e||'');const i=e.indexOf('\t');return i<0?['',e]:[e.slice(0,i),e.slice(i+1)]};
const stxt=e=>{const[a,b]=sp(e);return a?a+' '+b:b};
const dv=s=>typeof s.delivery==='string'?{[s.delivery]:1}:(s.delivery||{});
const S=(p,dt=date)=>{let s=p.v[0].s;for(const v of p.v)if(v.from<=dt)s=v.s;return s};
const off=(p,dt=date)=>(p.off||[]).some(([a,b])=>dt>=a&&(b==null||dt<b));
const vis=(p,dt=date)=>p.v[0].from<=dt&&!off(p,dt);
const modeOf=(p,dd,dt)=>{if(!dd.present.includes(p.id))return null;const m=(dd.modes||{})[p.id];if(m)return m;return S(p,dt).kind==='visit'?'visit':(dd.overnight.includes(p.id)?'night':'day')};
const mealsOf=(p,m,dd,dt)=>{const s=S(p,dt);if(m==='visit')return s.mealsV||(s.kind==='visit'?(s.meals||{}):{});return s.meals||{}};
const prevNight=dt=>{const y=pd(dt);y.setDate(y.getDate()-1);const pr=DB.days[dkey(y)];return pr?pr.staff.night.filter(Boolean):[]};
const byOrd=(a,b)=>(a.ord||1e9)-(b.ord||1e9);
const sheetInner=function sheetInner(){
 const d=day(),P=DB.people.filter(p=>vis(p)),md=p=>modeOf(p,d,date),ov=p=>md(p)==='night';
 const grpOf=p=>{if(md(p)==='night')return 1;const m=mealsOf(p,md(p),d,date);if(m.b)return 2;if(m.l&&!m.d)return 2;if(m.d||m.l)return 3;return 4};
 const reg=P.filter(p=>['day','night'].includes(md(p))).sort((a,b)=>grpOf(a)-grpOf(b)||byOrd(a,b)),vs=P.filter(p=>md(p)==='visit').sort(byOrd);
 const bl1=reg.length>16?3:5,N=reg.length+vs.length+bl1+2,AV=264,BF=[9.2,7.1,8.5,7.8],BM=[6,4.5,8.5,4.5],sm_=a=>a.reduce((x,y)=>x+y,0);let r=7.76,bh=BF.slice();
 if(N*r+sm_(BF)>AV){const room=AV-N*r;if(room>=sm_(BM)){const kk=(room-sm_(BM))/(sm_(BF)-sm_(BM));bh=BF.map((v,i)=>BM[i]+(v-BM[i])*kk)}else{bh=BM.slice();r=Math.max(5.2,(AV-sm_(BM))/N)}}
 const hasRin=[...reg,...vs].some(p=>S(p).meds&&S(p).meds.abx),U2=U.slice();U2[13]=hasRin?90:24;{const L=Math.max(0,...[...reg,...vs].map(p=>(S(p).note||'').length));U2[8]=Math.min(440,Math.max(170,60+L*14));}const f=(TW-r)/U2.reduce((a,b)=>a+b,0),W=U2.map(u=>u?u*f:r);
 const X=[0];W.forEach(w=>X.push(X[X.length-1]+w));
 const cnt=[0,0,0];[...reg,...vs].forEach(p=>['b','l','d'].forEach((k,j)=>{if(mealsOf(p,md(p),d,date)[k])cnt[j]++}));
 const nd=reg.length,nn=reg.filter(p=>md(p)==='night').length;
 const vt=t=>`<th><span class="vt">${t}</span></th>`,h=(t,c)=>`<th class="${c||''}">${t}</th>`;
 const hd=[vt('帳面<br>記入'),vt('利用'),vt('番号'),h('利用者氏名'),vt('泊まり'),vt('送迎時<br>訪問等'),h('最終排便'),h('排便－<br>日数'),h('注意点'),h('バイタル'),
  `<th class="h13">食事</th>`,vt('おやつ'),h('服薬','h13'),(hasRin?h('臨薬','h13'):vt('臨薬')),h('口腔<br>ケア'),h('整容','h13'),vt('入浴'),h('アクト'),
  `<th><div class="bt">請求関係</div><div class="bb"><i>電気器具</i><i>洗濯</i></div></th>`].join('');
 const T=(x,c)=>`<td${c?` class="${c}"`:''}>${x}</td>`,E=T(''),M='<td colspan="2"></td>';
 const mc=(s,ml)=>`<div class="sub">${['b','l','d'].map(k=>`<i>${s&&ml&&ml[k]?'<b class="ov"></b>':''}${s&&dv(s)[k]?'<b class="dl">配</b>':''}</i>`).join('')}</div>`;
 const bill=T('<div class="sub2"><i></i><i></i></div>');
 const SL=[['morning','朝','m'],['noon','昼','h'],['evening','夕','e'],['nextMorning','翌朝','o1'],['patch','貼り薬','o2 sm'],['bedtime','寝る前','b']];
 const medsCell=s=>T('<div class="g6">'+SL.map(q=>`<i>${s&&s.meds&&s.meds[q[0]]?`<span class="q ${q[2]}">${q[1]}</span>`:''}</i>`).join('')+'</div>');
 const abx=s=>T(s&&s.meds&&s.meds.abx?'<div class="ab"><span>抗生物質</span></div>':''),ocell=()=>T('<div class="oc"><span>朝<i class="cb"></i></span><span>昼<i class="cb"></i></span><span>夕<i class="cb"></i></span></div>');
 const vit=s=>1?'<b class="v1" style="left:3%">BP</b><b class="v1" style="left:47%">P</b><b class="v1" style="left:67.2%">KT</b>':'';
 const rows=[];let i=0;const z=()=>i++%2?'':'z';
 reg.forEach((p,n)=>{const s=S(p);rows.push(`<tr class="${z()}">`+E+E+T(n+1,'nm')+T(esc(s.name),'n')+T(ov(p)?'○':'','st')+E+E+E+T('<div class="ntw">'+esc(s.note)+'</div>','nt')+T(vit(s))+T(mc(s,mealsOf(p,md(p),d,date)))+E+medsCell(s)+abx(s)+ocell()+T('<div>髭剃り<i class="cb"></i></div><div>爪切り<i class="cb"></i></div>','gr')+E+T('1・2・3・4・5・6・7','ac')+bill+'</tr>')});
 for(let k=0;k<bl1;k++)rows.push(`<tr class="${z()}">`+E.repeat(10)+T(mc(null))+E+medsCell(null)+E.repeat(5)+bill+'</tr>');
 vs.forEach(p=>{const s=S(p);rows.push(`<tr class="${z()}">`+E+E+T('訪問','nm v')+T(esc(s.name),'n')+M+T('／（　）','ex')+E+T('<div class="ntw">'+esc(s.note)+'</div>','nt')+T(vit(s))+T(mc(s,mealsOf(p,md(p),d,date)))+E+medsCell(s)+abx(s)+E+E+E+E+bill+'</tr>')});
 for(let k=0;k<2;k++)rows.push(`<tr class="${z()}">`+E.repeat(4)+M+E.repeat(4)+T(mc(null))+E+medsCell(null)+E.repeat(5)+bill+'</tr>');
 const b2=reg.length+bl1;rows[b2]=rows[b2].replace('<tr class="','<tr class="sp ');rows[rows.length-1]=rows[rows.length-1].replace('<tr class="','<tr class="en ');
 const st=d.staff,dg=d.seq||DB.counter%4+1,dn=DB.days[date]?st.dawn:prevNight(date);
 const fs=(t,tg)=>{let em=0;for(const c of t)em+=c.charCodeAt(0)>255?1:.55;return Math.min(tg,84/Math.max(em,.5))};
 const top=i=>`top:calc(11mm + ${i}*var(--r));height:var(--r);line-height:var(--r)`;
 const pr=(i,lab,t,tg)=>`<div style="${top(i)}">${lab?`<span class="lb">${lab}</span>`:''}<span style="position:absolute;left:${lab?9.2:8.5}mm;font-size:${fs(t||'',tg)}pt">${esc(t||'')}</span></div>`;
 let pn=`<div style="top:0;height:11mm;line-height:11mm;left:9.2mm;width:auto;font-size:22pt">${dg}</div>`+pr(0,'明',dn[0],22)+pr(1,'',dn[1],22)+pr(2,'日勤','',16);
 const dyn=st.day.filter(Boolean);dyn.forEach((t,j)=>pn+=pr(3+j,'',stxt(t),16));const n=dyn.length+2;
 pn+=pr(3+n,'夜勤',st.night[0],22)+pr(4+n,'',st.night[1],22)+`<div style="${top(6+n)}"><span class="lb" style="font-size:9pt">アクト</span></div>`;
 ['①朝レク','②体操','③台所作業','④洗濯物','⑤午後レク','⑥食器拭き','⑦リハビリ'].forEach((t,j)=>pn+=`<div style="${top(7+n+j)}"><span style="position:absolute;left:.7mm;font-size:10pt">${t}</span></div>`);
 const [y,m,dd]=date.split('-').map(Number),wd=WD[pd(date).getDay()],lw=`width:${X[4]-X[2]}mm`;
 const bot=`<div class="bot" style="margin-left:${X[2]}mm;width:${TW-X[2]}mm"><div style="height:${bh[0]}mm"><span class="lc" style="${lw};font-size:9pt">その他${bh[0]>=8?'<br>':''}<span style="font-size:7pt">（往診、訪リハなど）</span></span></div><div style="height:${bh[1]}mm"><span class="lc" style="${lw};font-size:12pt;line-height:6.4mm">${esc(d.other)}</span></div><div style="height:${bh[2]}mm"><span style="position:absolute;left:${X[11]-13.4-X[2]}mm;transform:translateX(-50%);top:1.5mm;font-size:14pt;white-space:pre">${y}年　　${m}月　　${dd}日　　（${wd}）</span></div><div style="height:${bh[3]}mm"></div></div>`;
 const ftr=`<div class="ft"><span id="ftt" style="left:0">小規模多機能型居宅介護事業所　北本庄　利用者名簿（登録者：　　　名、通い：${nd}名、泊まり：${nn}名、訪問：　　　名、支援　　名、利用者：（朝食(${cnt[0]})　　　名、昼食(${cnt[1]})　　　、夕食(${cnt[2]})　　　名）おやつ${'　'.repeat(10)}名）</span><span id="d1">昼配食：</span><span id="d2">夕配食：</span></div>`;
 return {html:`<table class="t"><colgroup>${W.map(w=>`<col style="width:${w}mm">`).join('')}</colgroup><thead><tr>${hd}</tr></thead><tbody>${rows.join('')}</tbody></table>${bot}${ftr}<div class="pn">${pn}</div>`,r,x15:X[15],pb:11+(14+n)*r};
};
const place=function place(el,o){const l=Math.max(o.x15-2.1,el.querySelector('#ftt').offsetWidth*25.4/96+4.2);el.querySelector('#d1').style.left=l+'mm';el.querySelector('#d2').style.left=(l+31.8)+'mm'};
/* pr: not found */
/* top: not found */
let DB={"people":[],"days":{},"staff":[],"counter":0,"log":[]};
const day=()=>DB.days[date]||blank();
let date="2026-11-27";
/* stretch: not found */

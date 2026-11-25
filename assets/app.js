document.addEventListener('DOMContentLoaded',()=>{
 const selector=document.getElementById('liveCompetition');
 const board=document.getElementById('liveBoard');
 if(!board||!window.SWIMPRO_LIVE)return;
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const fmtStatus=s=>s==='OK'?'Classé':s==='DNS'?'Absent (DNS)':s==='DSQ'?'Disqualifié':'En attente';
 async function refresh(){
  const id=selector?selector.value:window.SWIMPRO_LIVE.competition;
  try{
   const res=await fetch(`${window.SWIMPRO_LIVE.url}?competition_id=${encodeURIComponent(id)}&_=${Date.now()}`,{cache:'no-store'});
   if(!res.ok)throw new Error('Réponse serveur invalide');
   const data=await res.json();
   document.getElementById('statResults').textContent=data.stats.results;
   document.getElementById('statEvents').textContent=data.stats.events;
   document.getElementById('statUpdated').textContent=data.updated;
   if(!data.events.length){board.innerHTML='<section class="panel"><p class="muted">Aucune épreuve disponible pour cette compétition.</p></section>';return;}
   board.innerHTML=data.events.map(ev=>`<section class="panel live-event"><div class="panel-head"><div><span class="eyebrow">${esc(ev.stroke)} · ${esc(ev.distance)} m · ${esc(ev.sex)}</span><h3>${esc(ev.name)}${ev.phase==='finale'?' <span class="pill good">Finale</span>':''}</h3></div><span class="pill">${ev.rows.length} ${ev.relay==1?'équipe(s)':'nageur(s)'}</span></div><div class="table-wrap"><table><thead><tr><th>Rang</th><th>Couloir</th><th>${ev.relay==1?'Équipe':'Nageur'}</th><th>Club</th><th>Temps</th><th>Statut</th></tr></thead><tbody>${ev.rows.map(r=>`<tr><td>${r.rank??'—'}</td><td>${r.lane_no??'—'}</td><td>${esc(r.name||(r.first_name+' '+r.last_name))}</td><td>${esc(r.club||'—')}</td><td class="time">${esc(r.time_display)}</td><td><span class="pill ${r.status==='OK'?'good':r.status?'':'pending'}">${r.status?fmtStatus(r.status):'En attente'}</span></td></tr>`).join('')}</tbody></table></div></section>`).join('');
  }catch(err){board.innerHTML='<section class="panel alert error">Impossible de charger les résultats. Vérifiez la connexion à la base de données.</section>';}
 }
 if(selector)selector.addEventListener('change',refresh);
 refresh();setInterval(refresh,3000);
});
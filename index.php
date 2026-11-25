<?php
require_once __DIR__.'/config.php';
if (!logged_in()) { require __DIR__.'/landing.php'; exit; }
require_login();
$u=current_user(); $role=$u['role'];
$allowed=['admin','organisateur','club','officiel','nageur','entraineur'];
if(!in_array($role,$allowed,true)) { http_response_code(403); exit('Rôle inconnu'); }
$page=$_GET['page']??'dashboard';
$admin=['dashboard','competitions','clubs','swimmers','events','entries','relays','heats','results','rankings','users','settings','supervision'];
$organizer=['dashboard','competitions','events','entries','relays','heats','results','rankings','settings','supervision'];
$official=['dashboard','heats','results','rankings','supervision'];
$club=['dashboard','my_swimmers','entries','relays','results','rankings'];
$swimmer=['dashboard','my_profile','results','heats','rankings'];
$coach=['dashboard','my_swimmers','entries','relays','results','heats','rankings'];
$permissions=['admin'=>$admin,'organisateur'=>$organizer,'officiel'=>$official,'club'=>$club,'nageur'=>$swimmer,'entraineur'=>$coach];
if(!in_array($page,$permissions[$role],true)) { http_response_code(403); $page='dashboard'; flash('Vous ne pouvez pas accéder à cette page.','error'); }

function count_query(string $sql,array $args=[]): int { $s=db()->prepare($sql);$s->execute($args);return (int)$s->fetchColumn(); }
function page_title($p){$a=['dashboard'=>'Tableau de bord','competitions'=>'Compétitions','clubs'=>'Clubs','swimmers'=>'Nageurs','events'=>'Épreuves','entries'=>'Engagements','heats'=>'Séries et couloirs','results'=>'Résultats','users'=>'Utilisateurs','supervision'=>'Supervision — Résultats live','my_swimmers'=>'Mes nageurs','my_profile'=>'Mon profil','relays'=>'Relais','rankings'=>'Classements et points','settings'=>'Paramètres'];return $a[$p]??'SwimPro';}
$flash=take_flash();
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e(page_title($page))?> — SwimPro</title><link rel="stylesheet" href="assets/style.css"><script defer src="assets/app.js"></script></head>
<body><div class="layout"><aside class="sidebar"><a class="brand" href="index.php"><span class="brand-mark">SP</span><span>SwimPro<small>Gestion de natation</small></span></a><div class="user-mini"><b><?=e($u['name'])?></b><small><?=e(ucfirst($role))?></small></div><nav>
<a class="<?= $page==='dashboard'?'active':''?>" href="index.php">⌂ Tableau de bord</a>
<?php foreach($permissions[$role] as $p): if($p==='dashboard')continue; ?><a class="<?=$page===$p?'active':''?>" href="index.php?page=<?=e($p)?>"><?=e(page_title($p))?></a><?php endforeach; ?>
<a href="public.php">Résultats publics</a></nav><a class="logout" href="logout.php">↪ Déconnexion</a></aside>
<main class="main"><header class="topbar"><div><span class="eyebrow">ESPACE <?=e(strtoupper($role))?></span><h1><?=e(page_title($page))?></h1></div><div class="top-user"><?=e($u['name'])?> <span class="avatar"><?=e(strtoupper(substr($u['name'],0,1)))?></span></div></header>
<?php if($flash): ?><div class="alert <?=e($flash[1])?>"><?=e($flash[0])?></div><?php endif; ?>
<?php
if($page==='dashboard'):
  $clubId=(int)($u['club_id']??0);
  $swId=(int)($u['swimmer_id']??0);
  $cards=[];
  if($role==='admin'||$role==='organisateur') $cards=[['Compétitions',count_query('SELECT COUNT(*) FROM competitions')],['Clubs',count_query('SELECT COUNT(*) FROM clubs')],['Nageurs',count_query('SELECT COUNT(*) FROM swimmers')],['Engagements',count_query('SELECT COUNT(*) FROM entries')],['Résultats saisis',count_query('SELECT COUNT(*) FROM results')]];
  elseif($role==='officiel') $cards=[['Compétitions en cours',count_query("SELECT COUNT(*) FROM competitions WHERE status='running'")],['Séries',count_query('SELECT COUNT(*) FROM heats')],['Résultats saisis',count_query('SELECT COUNT(*) FROM results')]];
  elseif($role==='nageur') $cards=[['Mes engagements',count_query('SELECT COUNT(*) FROM entries WHERE swimmer_id=?',[$swId])],['Mes résultats',count_query('SELECT COUNT(*) FROM results r JOIN entries e ON e.id=r.entry_id WHERE e.swimmer_id=?',[$swId])]];
  else $cards=[['Mes nageurs',count_query('SELECT COUNT(*) FROM swimmers WHERE club_id=?',[$clubId])],['Engagements',count_query('SELECT COUNT(*) FROM entries e JOIN swimmers s ON s.id=e.swimmer_id WHERE s.club_id=?',[$clubId])],['Résultats',count_query('SELECT COUNT(*) FROM results r JOIN entries e ON e.id=r.entry_id JOIN swimmers s ON s.id=e.swimmer_id WHERE s.club_id=?',[$clubId])]];
?>
<section class="welcome"><div><span class="eyebrow">BIENVENUE SUR SWIMPRO</span><h2>Suivez chaque course, de l'engagement au podium.</h2><p>Une gestion claire des compétitions, des séries et des performances.</p><a class="btn primary" href="index.php?page=<?=in_array($role,['admin','organisateur'])?'competitions':($role==='nageur'?'my_profile':(in_array($role,['club','entraineur'])?'my_swimmers':'supervision'))?>">Accéder aux activités</a></div><div class="pool-art">〰<br><span>SWIMPRO LIVE</span></div></section>
<div class="stats"><?php foreach($cards as $c): ?><div class="stat"><span><?=e($c[0])?></span><strong><?=e($c[1])?></strong></div><?php endforeach; ?></div>
<div class="grid2"><section class="panel"><h3>Prochaines compétitions</h3><?php $q=db()->query("SELECT * FROM competitions WHERE event_date>=CURDATE() ORDER BY event_date LIMIT 5");$rows=$q->fetchAll();if(!$rows)echo '<p class="muted">Aucune compétition programmée pour le moment.</p>';foreach($rows as $r):?><div class="list-row"><div><b><?=e($r['name'])?></b><small><?=e($r['venue'])?> · <?=e($r['event_date'])?></small></div><span class="pill"><?=e($r['status'])?></span></div><?php endforeach;?></section><section class="panel"><h3>Accès rapide</h3><div class="quick-links"><?php if(in_array($role,['admin','organisateur','officiel'])):?><a href="index.php?page=supervision">Supervision live</a><?php endif;?><a href="public.php">Résultats publics</a><?php if(in_array($role,['admin','organisateur'])):?><a href="index.php?page=entries">Gestion des engagements</a><a href="index.php?page=heats">Générer les séries</a><a href="index.php?page=relays">Composer les relais</a><?php endif;?><a href="index.php?page=rankings">Classements et points</a></div></section></div>
<?php elseif($page==='competitions'): require __DIR__.'/pages/competitions.php';
elseif($page==='clubs'): require __DIR__.'/pages/clubs.php';
elseif($page==='swimmers'||$page==='my_swimmers'): require __DIR__.'/pages/swimmers.php';
elseif($page==='events'): require __DIR__.'/pages/events.php';
elseif($page==='entries'): require __DIR__.'/pages/entries.php';
elseif($page==='relays'): require __DIR__.'/pages/relays.php';
elseif($page==='rankings'): require __DIR__.'/pages/rankings.php';
elseif($page==='settings'): require __DIR__.'/pages/settings.php';
elseif($page==='heats'): require __DIR__.'/pages/heats.php';
elseif($page==='results'): require __DIR__.'/pages/results.php';
elseif($page==='supervision'): require __DIR__.'/pages/supervision.php';
elseif($page==='users'): require __DIR__.'/pages/users.php';
elseif($page==='my_profile'):
  $sid=(int)($u['swimmer_id']??0);
  $q=db()->prepare('SELECT s.*,c.name club FROM swimmers s LEFT JOIN clubs c ON c.id=s.club_id WHERE s.id=?');$q->execute([$sid]);$s=$q->fetch();
  if($s):
  $h=db()->prepare('SELECT h.joined_on,h.left_on,c.name club,c.city FROM swimmer_club_history h LEFT JOIN clubs c ON c.id=h.club_id WHERE h.swimmer_id=? ORDER BY h.joined_on DESC,h.id DESC');$h->execute([$sid]);$history=$h->fetchAll();
  $q=db()->prepare("SELECT co.name competition,co.event_date,co.venue,ev.name event_name,ev.distance,ev.stroke,en.seed_time,r.time_sec,r.status result_status,en.status entry_status,(SELECT 1+COUNT(*) FROM results r2 JOIN entries e2 ON e2.id=r2.entry_id WHERE e2.event_id=en.event_id AND r2.status='OK' AND r2.time_sec IS NOT NULL AND r2.time_sec<r.time_sec) rank_no FROM entries en JOIN events ev ON ev.id=en.event_id JOIN competitions co ON co.id=ev.competition_id LEFT JOIN results r ON r.entry_id=en.id WHERE en.swimmer_id=? ORDER BY co.event_date DESC,ev.name");$q->execute([$sid]);$historyResults=$q->fetchAll();
  $best=count(array_filter($historyResults,fn($x)=>$x['time_sec']!==null));
  ?>
  <div class="profile-hero"><div class="profile-avatar"><?=e(strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1)))?></div><div><span class="eyebrow">MON PARCOURS SPORTIF</span><h2><?=e($s['first_name'].' '.$s['last_name'])?></h2><p>Licence : <?=e($s['license_no'])?> · Né(e) le <?=e($s['birth_date'])?> · Club actuel : <?=e($s['club']??'Indépendant')?></p></div></div>
  <div class="stats"><div class="stat"><span>Participations / engagements</span><strong><?=count($historyResults)?></strong></div><div class="stat"><span>Résultats chronométrés</span><strong><?=$best?></strong></div><div class="stat"><span>Clubs dans mon parcours</span><strong><?=count($history)?></strong></div></div>
  <section class="panel"><h3>Historique de mes compétitions et performances</h3><div class="table-wrap"><table><tr><th>Compétition</th><th>Date</th><th>Épreuve</th><th>Temps d'engagement</th><th>Temps réalisé</th><th>Classement</th><th>Statut</th></tr><?php foreach($historyResults as $r):?><tr><td><?=e($r['competition'])?></td><td><?=e($r['event_date'])?></td><td><?=e($r['event_name'].' · '.$r['distance'].' m '.$r['stroke'])?></td><td><?=e(fmt_time($r['seed_time']))?></td><td class="time"><?=e(fmt_time($r['time_sec']))?></td><td><?=($r['rank_no']!==null&&$r['time_sec']!==null)?'#'.e($r['rank_no']):'—'?></td><td><span class="pill"><?=e($r['result_status']??$r['entry_status'])?></span></td></tr><?php endforeach;?></table><?php if(!$historyResults):?><p class="muted">Aucune participation enregistrée pour le moment. Vos compétitions apparaîtront ici.</p><?php endif;?></div></section>
  <section class="panel"><h3>Historique de mes clubs</h3><div class="table-wrap"><table><tr><th>Club</th><th>Ville</th><th>Date d'adhésion</th><th>Date de départ</th><th>Situation</th></tr><?php foreach($history as $r):?><tr><td><?=e($r['club']??'Club supprimé')?></td><td><?=e($r['city']??'—')?></td><td><?=e($r['joined_on'])?></td><td><?=e($r['left_on']??'—')?></td><td><span class="pill <?=empty($r['left_on'])?'good':''?>"><?=empty($r['left_on'])?'Actuel':'Ancien club'?></span></td></tr><?php endforeach;?></table><?php if(!$history):?><p class="muted">Aucun historique de club enregistré.</p><?php endif;?></div></section>
  <?php else:?><section class="panel"><p>Votre compte n'est pas encore associé à un profil nageur. Contactez l'administrateur.</p></section><?php endif;?>
<?php endif; ?>
<footer>SwimPro · Gestion des compétitions de natation</footer></main></div></body></html>
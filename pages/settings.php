<?php
require_role(['admin','organisateur']);$base='index.php?page=settings';
if($_SERVER['REQUEST_METHOD']==='POST'){verify_csrf();$action=$_POST['action']??'save';$id=(int)($_POST['id']??0);
 if($action==='delete'){
  $q=db()->prepare('SELECT COUNT(*) FROM events WHERE category_id=?');$q->execute([$id]);$n=(int)$q->fetchColumn();
  if($n>0)flash('Cette catégorie est réservée par '.$n.' épreuve(s). Modifiez d\'abord ces épreuves (page Épreuves) avant de la supprimer.','error');
  else{db()->prepare('DELETE FROM age_categories WHERE id=?')->execute([$id]);flash('Catégorie supprimée.');}
  redirect($base);
 }
 if($action==='reset'){
  $q=db()->query('SELECT COUNT(*) FROM events WHERE category_id IS NOT NULL');
  if((int)$q->fetchColumn()>0){flash('Des épreuves sont réservées à une catégorie : impossible de réinitialiser la liste.','error');redirect($base);}
  $pdo=db();$pdo->exec('DELETE FROM age_categories');$pdo->exec("INSERT INTO age_categories(name,min_age,max_age,sort_order) VALUES('Poussins',0,9,1),('Benjamins',10,11,2),('Minimes',12,13,3),('Cadets',14,15,4),('Juniors',16,17,5),('Seniors',18,99,6)");
  flash('Catégories par défaut rétablies (Poussins à Seniors).');redirect($base);
 }
 $name=trim($_POST['name']??'');$min=(int)($_POST['min_age']??-1);$max=(int)($_POST['max_age']??-1);$order=(int)($_POST['sort_order']??0);
 if($name===''||$min<0||$max<$min||$max>120)flash('Vérifiez la catégorie : nom obligatoire, âge minimum ≤ âge maximum.','error');
 else{
  // chevauchement avec une autre catégorie ?
  $q=db()->prepare('SELECT name FROM age_categories WHERE id<>? AND NOT(max_age<? OR min_age>?) LIMIT 1');$q->execute([$id,$min,$max]);
  if($o=$q->fetchColumn())flash('Les âges se chevauchent avec la catégorie « '.$o.' ».','error');
  elseif($id){db()->prepare('UPDATE age_categories SET name=?,min_age=?,max_age=?,sort_order=? WHERE id=?')->execute([$name,$min,$max,$order,$id]);
   db()->prepare('UPDATE events SET category=? WHERE category_id=?')->execute([$name,$id]);flash('Catégorie modifiée.');}
  else{db()->prepare('INSERT INTO age_categories(name,min_age,max_age,sort_order) VALUES(?,?,?,?)')->execute([$name,$min,$max,$order]);flash('Catégorie ajoutée.');}
 }
 redirect($base);
}
$edit=null;if(isset($_GET['edit'])){$q=db()->prepare('SELECT * FROM age_categories WHERE id=?');$q->execute([(int)$_GET['edit']]);$edit=$q->fetch();}
$cats=age_categories(true);
$used=db()->query('SELECT category_id,COUNT(*) n FROM events WHERE category_id IS NOT NULL GROUP BY category_id')->fetchAll(PDO::FETCH_KEY_PAIR);
$nextOrder=$cats?((int)max(array_column($cats,'sort_order'))+1):1;
?>
<div class="grid2"><section class="panel"><h3><?=$edit?'Modifier la catégorie':'Ajouter une catégorie d\'âge'?></h3>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=e($edit['id']??'')?>">
<label>Nom</label><input name="name" required value="<?=e($edit['name']??'')?>" placeholder="Benjamins"><div class="form-grid"><div><label>Âge minimum</label><input type="number" name="min_age" min="0" max="120" required value="<?=e($edit['min_age']??'')?>"></div><div><label>Âge maximum</label><input type="number" name="max_age" min="0" max="120" required value="<?=e($edit['max_age']??'')?>"></div></div><label>Ordre d'affichage</label><input type="number" name="sort_order" value="<?=e($edit['sort_order']??$nextOrder)?>">
<button class="btn primary"><?=$edit?'Enregistrer les modifications':'Ajouter la catégorie'?></button><?php if($edit):?> <a class="btn light" href="<?=$base?>">Annuler</a><?php endif;?></form>
<p class="small muted">L'âge d'un nageur est <b>l'année de la compétition moins son année de naissance</b>. Une épreuve peut être réservée à une catégorie (page Épreuves) : les engagements et les relais sont alors contrôlés automatiquement. Pour « Seniors », utilisez 99 comme âge maximum.</p></section>
<section class="panel"><h3>Catégories d'âge</h3><div class="table-wrap"><table><tr><th>Ordre</th><th>Catégorie</th><th>Âges</th><th>Épreuves</th><th>Actions</th></tr><?php foreach($cats as $c):?><tr><td><?=e($c['sort_order'])?></td><td><?=e($c['name'])?></td><td><?=e(category_range_label($c))?></td><td><?=e($used[$c['id']]??0)?></td><td class="actions"><a class="link" href="<?=$base?>&edit=<?=$c['id']?>">Modifier</a><form class="inline" method="post" onsubmit="return confirm('Supprimer cette catégorie ?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="link danger">Supprimer</button></form></td></tr><?php endforeach;?></table><?php if(!$cats):?><p class="muted">Aucune catégorie définie.</p><?php endif;?></div>
<form method="post" onsubmit="return confirm('Remplacer toutes les catégories par la liste par défaut (Poussins à Seniors) ?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="reset"><button class="btn light">Rétablir les catégories par défaut</button></form></section></div>
<section class="panel"><h3>Barème des points</h3><p class="muted">Le barème des points se modifie dans la page <a href="index.php?page=rankings">Classements et points</a>.</p></section>

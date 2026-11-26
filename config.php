<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('DB_NAME') ?: 'swimpro');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
function db(): PDO { static $pdo=null; if($pdo instanceof PDO)return $pdo; $pdo=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]); return $pdo; }
function e($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function current_user(): ?array { return isset($_SESSION['user'])&&is_array($_SESSION['user'])?$_SESSION['user']:null; }
function logged_in(): bool { return current_user()!==null; }
function require_login(): void { if(!logged_in()){header('Location: login.php');exit;} }
function require_role(array $roles): void { require_login(); if(!in_array(current_user()['role']??'', $roles, true)){http_response_code(403);exit('Accès interdit : vous ne disposez pas des autorisations nécessaires.');} }
function csrf_token(): string { if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf']; }
function verify_csrf(): void { $a=$_SESSION['csrf']??'';$b=(string)($_POST['csrf']??'');if($a===''||!hash_equals($a,$b)){http_response_code(419);exit('Session expirée. Rechargez la page et réessayez.');} }
function flash(string $message,string $type='success'): void { $_SESSION['flash']=[$message,$type]; }
function take_flash(): ?array { $f=$_SESSION['flash']??null;unset($_SESSION['flash']);return $f; }
function redirect(string $url='index.php'): never { header('Location: '.$url);exit; }
function is_staff(): bool { return logged_in()&&in_array(current_user()['role']??'', ['admin','organisateur','officiel'],true); }
function fmt_time($seconds): string { if($seconds===null||$seconds==='')return '—';$s=(float)$seconds;$m=(int)floor($s/60);$r=$s-$m*60;return $m>0?sprintf('%02d:%05.2f',$m,$r):sprintf('%05.2f',$r); }
function parse_time(string $value): ?float { $value=trim($value);if($value==='')return null;if(!preg_match('/^(?:(\d{1,2}):)?([0-5]?\d)\.(\d{1,2})$/',$value,$m))return null;$min=($m[1]??'')!==''?(int)$m[1]:0;return $min*60+(int)$m[2]+((int)str_pad($m[3],2,'0')/100); }
function can_access_club(int $clubId): bool { $u=current_user();if(!$u)return false;if(in_array($u['role']??'', ['admin','organisateur','officiel'],true))return true;return (int)($u['club_id']??0)>0&&(int)$u['club_id']===$clubId; }
function can_access_swimmer(int $swimmerId): bool { $u=current_user();if(!$u)return false;if(in_array($u['role']??'', ['admin','organisateur','officiel'],true))return true;if(($u['role']??'')==='nageur')return (int)($u['swimmer_id']??0)===$swimmerId;if(in_array($u['role']??'', ['club','entraineur'],true)){$q=db()->prepare('SELECT club_id FROM swimmers WHERE id=?');$q->execute([$swimmerId]);$cid=$q->fetchColumn();return $cid!==false&&$cid!==null&&(int)($u['club_id']??0)>0&&(int)$cid===(int)$u['club_id'];}return false; }
function record_club_membership(PDO $pdo,int $swimmerId,?int $newClubId): void { $q=$pdo->prepare('SELECT id,club_id FROM swimmer_club_history WHERE swimmer_id=? AND left_on IS NULL ORDER BY joined_on DESC,id DESC LIMIT 1');$q->execute([$swimmerId]);$current=$q->fetch();$old=$current?(int)$current['club_id']:null;if($old===$newClubId)return;if($current){$pdo->prepare('UPDATE swimmer_club_history SET left_on=CURDATE() WHERE id=?')->execute([$current['id']]);}if($newClubId!==null&&$newClubId>0){$pdo->prepare('INSERT INTO swimmer_club_history(swimmer_id,club_id,joined_on) VALUES(?,?,CURDATE())')->execute([$swimmerId,$newClubId]);} }

// Fonctions ajoutées : migration automatique de la base, catégories, séries, finales, points, exports.
require_once __DIR__.'/lib/migrate.php';
require_once __DIR__.'/lib/swimpro.php';
require_once __DIR__.'/lib/export.php';
ensure_schema();

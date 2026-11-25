<?php
/* SwimPro — fonctions métier ajoutées : catégories d'âge, séries (individuel + relais), finales, points et classements. */

// ---------------------------------------------------------------- Catégories d'âge
function age_categories(bool $refresh = false): array {
    static $cache = null;
    if ($cache === null || $refresh) {
        try { $cache = db()->query('SELECT * FROM age_categories ORDER BY sort_order,min_age,id')->fetchAll(); }
        catch (Throwable $e) { $cache = []; }
    }
    return $cache;
}
/** Âge sportif = année de la compétition − année de naissance. */
function swimmer_age(string $birthDate, string $competitionDate): int {
    return (int)substr($competitionDate, 0, 4) - (int)substr($birthDate, 0, 4);
}
function category_by_id(?int $id): ?array {
    if (!$id) return null;
    foreach (age_categories() as $c) if ((int)$c['id'] === $id) return $c;
    return null;
}
function category_for_age(int $age): ?array {
    foreach (age_categories() as $c) if ($age >= (int)$c['min_age'] && $age <= (int)$c['max_age']) return $c;
    return null;
}
function category_name_for(string $birthDate, string $competitionDate): string {
    $c = category_for_age(swimmer_age($birthDate, $competitionDate));
    return $c ? $c['name'] : '—';
}
function category_range_label(array $c): string {
    if ((int)$c['max_age'] >= 99) return $c['min_age'] . ' ans et plus';
    if ((int)$c['min_age'] <= 0) return $c['max_age'] . ' ans et moins';
    return $c['min_age'] . ' à ' . $c['max_age'] . ' ans';
}
/** Retourne un message d'erreur si le nageur n'est pas dans la catégorie réservée de l'épreuve, sinon null. */
function category_error(?int $categoryId, string $birthDate, string $competitionDate, string $who = 'Le nageur'): ?string {
    $c = category_by_id($categoryId);
    if (!$c) return null;
    $age = swimmer_age($birthDate, $competitionDate);
    if ($age < (int)$c['min_age'] || $age > (int)$c['max_age'])
        return $who . ' a ' . $age . ' ans (année de la compétition − année de naissance) : l\'épreuve est réservée à la catégorie ' . $c['name'] . ' (' . category_range_label($c) . ').';
    return null;
}

// ---------------------------------------------------------------- Séries
/** Ordre des couloirs du centre vers l'extérieur : 4,5,3,6,2,7,1,8 pour 8 couloirs. */
function lane_order(int $lanes): array {
    $center = (int)ceil($lanes / 2);
    $order = [$center];
    for ($step = 1; count($order) < $lanes; $step++) {
        $right = $center + $step; $left = $center - $step;
        if ($right <= $lanes) $order[] = $right;
        if ($left >= 1) $order[] = $left;
    }
    return array_values(array_unique($order));
}
/** $items triés du meilleur au moins bon. Retourne des groupes (du plus rapide au plus lent).
    La série la plus lente évite de n'avoir qu'un seul nageur quand c'est possible. */
function split_heats(array $items, int $lanes): array {
    if (!$items) return [];
    $chunks = array_chunk($items, max(1, $lanes));
    $n = count($chunks);
    if ($n >= 2 && count($chunks[$n - 1]) === 1 && count($chunks[$n - 2]) >= 3) {
        array_unshift($chunks[$n - 1], array_pop($chunks[$n - 2]));
    }
    return $chunks;
}
function sort_by_seed(array $items): array {
    usort($items, function ($a, $b) {
        $x = $a['seed_time']; $y = $b['seed_time'];
        if ($x === null && $y === null) return $a['id'] <=> $b['id'];
        if ($x === null) return 1;
        if ($y === null) return -1;
        $c = (float)$x <=> (float)$y;
        return $c !== 0 ? $c : $a['id'] <=> $b['id'];
    });
    return $items;
}
/** Supprime les séries de l'épreuve puis les recrée. Les meilleurs au centre. $items = [['id'=>..,'seed_time'=>..]] (entry ou team). */
function create_heats(PDO $pdo, int $eventId, array $items, int $lanes, bool $relay): int {
    $old = $pdo->prepare('SELECT id FROM heats WHERE event_id=?'); $old->execute([$eventId]);
    $oldIds = $old->fetchAll(PDO::FETCH_COLUMN);
    if ($oldIds) {
        $in = implode(',', array_fill(0, count($oldIds), '?'));
        $pdo->prepare("DELETE FROM heat_lanes WHERE heat_id IN ($in)")->execute($oldIds);
        $pdo->prepare('DELETE FROM heats WHERE event_id=?')->execute([$eventId]);
    }
    $chunks = split_heats(sort_by_seed($items), $lanes);
    $order = lane_order($lanes);
    $col = $relay ? 'team_id' : 'entry_id';
    $insHeat = $pdo->prepare('INSERT INTO heats(event_id,heat_no) VALUES(?,?)');
    $insLane = $pdo->prepare("INSERT INTO heat_lanes(heat_id,lane_no,$col) VALUES(?,?,?)");
    foreach ($chunks as $i => $chunk) {
        $insHeat->execute([$eventId, count($chunks) - $i]);
        $hid = (int)$pdo->lastInsertId();
        foreach ($chunk as $j => $it) $insLane->execute([$hid, $order[$j] ?? ($j + 1), $it['id']]);
    }
    return count($chunks);
}
/** Génère les séries d'une épreuve (individuelle ou relais). Retourne [ok, message]. */
function generate_heats_for_event(PDO $pdo, int $eventId): array {
    $q = $pdo->prepare('SELECT e.id,e.relay,e.relay_size,c.lanes FROM events e JOIN competitions c ON c.id=e.competition_id WHERE e.id=?');
    $q->execute([$eventId]); $ev = $q->fetch();
    if (!$ev || !(int)$ev['lanes']) return [false, 'Épreuve inconnue.'];
    if ((int)$ev['relay'] === 1) {
        $q = $pdo->prepare("SELECT rt.id,rt.seed_time FROM relay_teams rt WHERE rt.event_id=? AND rt.status='engagé' AND (SELECT COUNT(*) FROM relay_members m WHERE m.team_id=rt.id)=?");
        $q->execute([$eventId, (int)$ev['relay_size']]);
        $items = $q->fetchAll();
        if (!$items) return [false, 'Aucune équipe de relais complète et engagée pour cette épreuve.'];
    } else {
        $q = $pdo->prepare("SELECT id,seed_time FROM entries WHERE event_id=? AND status='engagé'");
        $q->execute([$eventId]); $items = $q->fetchAll();
        if (!$items) return [false, 'Aucun engagement valide pour cette épreuve.'];
    }
    $pdo->beginTransaction();
    try { create_heats($pdo, $eventId, $items, (int)$ev['lanes'], (int)$ev['relay'] === 1); $pdo->commit(); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return [false, 'Erreur pendant la génération des séries : ' . $e->getMessage()]; }
    return [true, 'Séries générées. Vérifiez le placement des nageurs avant le départ.'];
}

// ---------------------------------------------------------------- Finales
function final_event_of(PDO $pdo, int $eventId): ?array {
    $q = $pdo->prepare('SELECT * FROM events WHERE source_event_id=? ORDER BY id DESC LIMIT 1');
    $q->execute([$eventId]);
    return $q->fetch() ?: null;
}
/** Crée (ou recrée) une finale avec les N meilleurs temps. Retourne [ok, message]. */
function create_final(PDO $pdo, int $eventId, int $n): array {
    $q = $pdo->prepare('SELECT e.*,c.lanes FROM events e JOIN competitions c ON c.id=e.competition_id WHERE e.id=?');
    $q->execute([$eventId]); $ev = $q->fetch();
    if (!$ev) return [false, 'Épreuve inconnue.'];
    if ($ev['phase'] === 'finale') return [false, 'Cette épreuve est déjà une finale.'];
    $lanes = (int)$ev['lanes'];
    $n = max(1, min($n, $lanes));
    $relay = (int)$ev['relay'] === 1;
    if ($relay) {
        $q = $pdo->prepare("SELECT rt.id,rt.name,rt.club_id,r.time_sec FROM relay_teams rt JOIN results r ON r.team_id=rt.id WHERE rt.event_id=? AND r.status='OK' AND r.time_sec IS NOT NULL ORDER BY r.time_sec ASC,rt.id ASC LIMIT $n");
    } else {
        $q = $pdo->prepare("SELECT en.id,en.swimmer_id,r.time_sec FROM entries en JOIN results r ON r.entry_id=en.id WHERE en.event_id=? AND r.status='OK' AND r.time_sec IS NOT NULL ORDER BY r.time_sec ASC,en.id ASC LIMIT $n");
    }
    $q->execute([$eventId]); $qualified = $q->fetchAll();
    if (!$qualified) return [false, 'Aucun résultat chronométré (statut OK) pour cette épreuve : impossible de qualifier des finalistes.'];

    $old = final_event_of($pdo, $eventId);
    if ($old) {
        $c1 = $pdo->prepare('SELECT COUNT(*) FROM results r JOIN entries en ON en.id=r.entry_id WHERE en.event_id=?'); $c1->execute([$old['id']]);
        $c2 = $pdo->prepare('SELECT COUNT(*) FROM results r JOIN relay_teams rt ON rt.id=r.team_id WHERE rt.event_id=?'); $c2->execute([$old['id']]);
        if ((int)$c1->fetchColumn() + (int)$c2->fetchColumn() > 0)
            return [false, 'Une finale existe déjà et contient des résultats : elle ne peut pas être recréée. Supprimez d\'abord ses résultats ou l\'épreuve « ' . $old['name'] . ' ».'];
    }
    $pdo->beginTransaction();
    try {
        if ($old) $pdo->prepare('DELETE FROM events WHERE id=?')->execute([$old['id']]);
        $pdo->prepare('INSERT INTO events(competition_id,name,stroke,distance,sex,category,relay,phase,max_entries,category_id,relay_size,source_event_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$ev['competition_id'], 'Finale — ' . $ev['name'], $ev['stroke'], $ev['distance'], $ev['sex'], $ev['category'], $ev['relay'], 'finale', max($n, count($qualified)), $ev['category_id'], $ev['relay_size'], $eventId]);
        $fid = (int)$pdo->lastInsertId();
        $items = [];
        if ($relay) {
            $insT = $pdo->prepare('INSERT INTO relay_teams(event_id,club_id,name,seed_time,status) VALUES(?,?,?,?,\'engagé\')');
            $mem = $pdo->prepare('SELECT swimmer_id,leg_no FROM relay_members WHERE team_id=? ORDER BY leg_no');
            $insM = $pdo->prepare('INSERT INTO relay_members(team_id,event_id,swimmer_id,leg_no) VALUES(?,?,?,?)');
            foreach ($qualified as $t) {
                $insT->execute([$fid, $t['club_id'], $t['name'], $t['time_sec']]);
                $tid = (int)$pdo->lastInsertId();
                $mem->execute([$t['id']]);
                foreach ($mem->fetchAll() as $m) $insM->execute([$tid, $fid, $m['swimmer_id'], $m['leg_no']]);
                $items[] = ['id' => $tid, 'seed_time' => $t['time_sec']];
            }
        } else {
            $insE = $pdo->prepare('INSERT INTO entries(event_id,swimmer_id,seed_time,status) VALUES(?,?,?,\'engagé\')');
            foreach ($qualified as $t) {
                $insE->execute([$fid, $t['swimmer_id'], $t['time_sec']]);
                $items[] = ['id' => (int)$pdo->lastInsertId(), 'seed_time' => $t['time_sec']];
            }
        }
        create_heats($pdo, $fid, $items, $lanes, $relay);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return [false, 'Création de la finale impossible : ' . $e->getMessage()];
    }
    return [true, 'Finale créée avec ' . count($qualified) . ($relay ? ' équipe(s)' : ' nageur(s)') . ' qualifié(s) et leurs couloirs (le meilleur au centre).'];
}

// ---------------------------------------------------------------- Points et classements
function point_scale(): array {
    try { return db()->query('SELECT place_no,points FROM point_scale ORDER BY place_no')->fetchAll(PDO::FETCH_KEY_PAIR); }
    catch (Throwable $e) { return []; }
}
/** Résultats d'une compétition, groupés par épreuve, classés, avec points. Les points sont attribués par la finale quand elle existe. */
function competition_rankings(int $competitionId): array {
    $pdo = db();
    $scale = point_scale();
    $evq = $pdo->prepare('SELECT e.*,(SELECT COUNT(*) FROM events f WHERE f.source_event_id=e.id) has_final FROM events e WHERE e.competition_id=? ORDER BY e.id');
    $evq->execute([$competitionId]);
    $events = [];
    foreach ($evq->fetchAll() as $e) { $e['rows'] = []; $e['counts'] = ((int)$e['has_final'] === 0); $events[(int)$e['id']] = $e; }
    if (!$events) return [];

    $q = $pdo->prepare("SELECT en.event_id,r.id result_id,r.status,r.time_sec,s.id swimmer_id,s.first_name,s.last_name,s.birth_date,s.sex,s.club_id,cl.name club,h.heat_no,hl.lane_no,co.event_date
        FROM entries en JOIN events ev ON ev.id=en.event_id JOIN competitions co ON co.id=ev.competition_id JOIN results r ON r.entry_id=en.id JOIN swimmers s ON s.id=en.swimmer_id
        LEFT JOIN clubs cl ON cl.id=s.club_id LEFT JOIN heat_lanes hl ON hl.entry_id=en.id LEFT JOIN heats h ON h.id=hl.heat_id WHERE ev.competition_id=?");
    $q->execute([$competitionId]);
    foreach ($q->fetchAll() as $r) {
        $events[(int)$r['event_id']]['rows'][] = ['kind' => 'swimmer', 'result_id' => $r['result_id'], 'swimmer_id' => (int)$r['swimmer_id'], 'team_id' => null,
            'name' => $r['first_name'] . ' ' . $r['last_name'], 'club_id' => $r['club_id'] !== null ? (int)$r['club_id'] : null, 'club' => $r['club'],
            'status' => $r['status'], 'time_sec' => $r['time_sec'], 'heat_no' => $r['heat_no'], 'lane_no' => $r['lane_no'],
            'category' => category_name_for($r['birth_date'], $r['event_date']), 'sex' => $r['sex']];
    }
    $q = $pdo->prepare("SELECT rt.event_id,r.id result_id,r.status,r.time_sec,rt.id team_id,rt.name,rt.club_id,cl.name club,h.heat_no,hl.lane_no
        FROM relay_teams rt JOIN events ev ON ev.id=rt.event_id JOIN results r ON r.team_id=rt.id LEFT JOIN clubs cl ON cl.id=rt.club_id
        LEFT JOIN heat_lanes hl ON hl.team_id=rt.id LEFT JOIN heats h ON h.id=hl.heat_id WHERE ev.competition_id=?");
    $q->execute([$competitionId]);
    foreach ($q->fetchAll() as $r) {
        $events[(int)$r['event_id']]['rows'][] = ['kind' => 'relay', 'result_id' => $r['result_id'], 'swimmer_id' => null, 'team_id' => (int)$r['team_id'],
            'name' => $r['name'], 'club_id' => $r['club_id'] !== null ? (int)$r['club_id'] : null, 'club' => $r['club'],
            'status' => $r['status'], 'time_sec' => $r['time_sec'], 'heat_no' => $r['heat_no'], 'lane_no' => $r['lane_no'], 'category' => '', 'sex' => ''];
    }
    foreach ($events as &$e) {
        $times = [];
        foreach ($e['rows'] as $r) if ($r['status'] === 'OK' && $r['time_sec'] !== null) $times[] = (float)$r['time_sec'];
        foreach ($e['rows'] as &$r) {
            if ($r['status'] === 'OK' && $r['time_sec'] !== null) {
                $t = (float)$r['time_sec'];
                $r['rank'] = 1 + count(array_filter($times, fn($x) => $x < $t));   // ex æquo = même place
                $r['points'] = $e['counts'] ? (int)($scale[$r['rank']] ?? 0) : 0;
            } else { $r['rank'] = null; $r['points'] = 0; }
        }
        unset($r);
        usort($e['rows'], function ($a, $b) {
            $ra = $a['rank'] ?? 9999; $rb = $b['rank'] ?? 9999;
            if ($ra !== $rb) return $ra <=> $rb;
            return strcmp($a['name'], $b['name']);
        });
    }
    unset($e);
    return array_values($events);
}
function club_standings(array $rankings): array {
    $c = [];
    foreach ($rankings as $e) {
        if (!$e['counts']) continue;
        foreach ($e['rows'] as $r) {
            if (!$r['rank']) continue;
            $key = $r['club_id'] ?? 0;
            if (!isset($c[$key])) $c[$key] = ['club_id' => $key, 'club' => $r['club'] ?? 'Sans club', 'points' => 0, 'gold' => 0, 'silver' => 0, 'bronze' => 0, 'results' => 0];
            $c[$key]['points'] += $r['points']; $c[$key]['results']++;
            if ($r['rank'] === 1) $c[$key]['gold']++; elseif ($r['rank'] === 2) $c[$key]['silver']++; elseif ($r['rank'] === 3) $c[$key]['bronze']++;
        }
    }
    return rank_standings(array_values($c));
}
function swimmer_standings(array $rankings): array {
    $c = [];
    foreach ($rankings as $e) {
        if (!$e['counts']) continue;
        foreach ($e['rows'] as $r) {
            if (!$r['rank'] || $r['kind'] !== 'swimmer') continue;
            $key = $r['swimmer_id'];
            if (!isset($c[$key])) $c[$key] = ['swimmer_id' => $key, 'name' => $r['name'], 'club' => $r['club'] ?? '—', 'club_id' => $r['club_id'], 'category' => $r['category'], 'sex' => $r['sex'], 'points' => 0, 'gold' => 0, 'silver' => 0, 'bronze' => 0, 'results' => 0];
            $c[$key]['points'] += $r['points']; $c[$key]['results']++;
            if ($r['rank'] === 1) $c[$key]['gold']++; elseif ($r['rank'] === 2) $c[$key]['silver']++; elseif ($r['rank'] === 3) $c[$key]['bronze']++;
        }
    }
    return rank_standings(array_values($c));
}
function rank_standings(array $list): array {
    usort($list, function ($a, $b) {
        foreach (['points', 'gold', 'silver', 'bronze'] as $k) if ($a[$k] !== $b[$k]) return $b[$k] <=> $a[$k];
        return strcmp((string)($a['club'] ?? $a['name'] ?? ''), (string)($b['club'] ?? $b['name'] ?? ''));
    });
    $prev = null; $place = 0;
    foreach ($list as $i => &$r) {
        $sig = $r['points'] . '/' . $r['gold'] . '/' . $r['silver'] . '/' . $r['bronze'];
        if ($sig !== $prev) { $place = $i + 1; $prev = $sig; }
        $r['place'] = $place;
    }
    unset($r);
    return $list;
}

// ---------------------------------------------------------------- Divers
function competitions_list(): array {
    return db()->query('SELECT id,name,event_date,status FROM competitions ORDER BY event_date DESC,id DESC')->fetchAll();
}
function relay_members_of(int $teamId): array {
    $q = db()->prepare('SELECT m.leg_no,s.id,s.first_name,s.last_name,s.sex,s.birth_date FROM relay_members m JOIN swimmers s ON s.id=m.swimmer_id WHERE m.team_id=? ORDER BY m.leg_no');
    $q->execute([$teamId]);
    return $q->fetchAll();
}
/** Barre d'export (PDF / Excel / CSV) — formulaire GET vers export.php. */
function export_bar(string $type, array $competitions, int $selected = 0, string $label = 'Exporter', bool $public = false): string {
    $h = '<form method="get" action="export.php" class="export-bar"><span class="export-label">' . e($label) . '</span><input type="hidden" name="type" value="' . e($type) . '">';
    if ($public) $h .= '<input type="hidden" name="public" value="1">';
    $h .= '<select name="competition_id" class="export-comp">';
    foreach ($competitions as $c) $h .= '<option value="' . (int)$c['id'] . '"' . ((int)$c['id'] === $selected ? ' selected' : '') . '>' . e($c['name']) . '</option>';
    $h .= '</select><button class="btn light" name="format" value="pdf">PDF</button><button class="btn light" name="format" value="xlsx">Excel</button><button class="btn light" name="format" value="csv">CSV</button></form>';
    return $h;
}

<?php
/* SwimPro — exports PDF, Excel (.xlsx) et CSV sans bibliothèque externe. */

// ================================================================= Constructeurs de données
/** Chaque constructeur retourne ['title','subtitle','columns'=>[..],'weights'=>[..],'rows'=>[ [..] | 'Titre de section' ]]. */

function export_scope(bool $public): array {
    $u = current_user();
    if ($public || !$u) return ['club' => null, 'swimmer' => null];
    if (in_array($u['role'], ['club', 'entraineur'], true)) return ['club' => (int)($u['club_id'] ?? 0), 'swimmer' => null];
    if ($u['role'] === 'nageur') return ['club' => null, 'swimmer' => (int)($u['swimmer_id'] ?? 0)];
    return ['club' => null, 'swimmer' => null];
}
function export_competition(int $cid): ?array {
    $q = db()->prepare('SELECT * FROM competitions WHERE id=?'); $q->execute([$cid]);
    return $q->fetch() ?: null;
}
function export_sub(array $comp): string {
    return $comp['name'] . ' — ' . $comp['venue'] . ' — ' . $comp['event_date'];
}
function export_event_label(array $e): string {
    return $e['name'] . ' (' . $e['distance'] . ' m ' . $e['stroke'] . ($e['sex'] !== 'Mixte' ? ', ' . ($e['sex'] === 'F' ? 'Dames' : 'Messieurs') : ', Mixte') . ')';
}
function status_label(?string $s): string {
    return ['OK' => 'Classé', 'DNS' => 'DNS', 'DSQ' => 'DSQ', 'engagé' => 'Engagé'][$s] ?? (string)$s;
}

function export_results(array $comp, array $scope): array {
    $rows = [];
    foreach (competition_rankings((int)$comp['id']) as $e) {
        $part = [];
        foreach ($e['rows'] as $r) {
            if ($scope['club'] !== null && (int)$r['club_id'] !== $scope['club']) continue;
            if ($scope['swimmer'] !== null && (int)$r['swimmer_id'] !== $scope['swimmer']) continue;
            $part[] = [$r['rank'] ?? '—', $r['name'], $r['club'] ?? '—', ($r['heat_no'] ?: '—') . ' / ' . ($r['lane_no'] ?: '—'), fmt_time($r['time_sec']), status_label($r['status']), $e['counts'] && $r['rank'] ? (int)$r['points'] : ''];
        }
        if ($part) { $rows[] = export_event_label($e) . ($e['phase'] === 'finale' ? ' — FINALE' : ''); foreach ($part as $p) $rows[] = $p; }
    }
    return ['title' => 'Résultats', 'subtitle' => export_sub($comp), 'columns' => ['Rang', 'Nageur / équipe', 'Club', 'Série / couloir', 'Temps', 'Statut', 'Points'], 'weights' => [1, 4, 3.5, 2, 2, 2, 1.3], 'rows' => $rows];
}
function export_club_ranking(array $comp, array $scope): array {
    $rows = [];
    foreach (club_standings(competition_rankings((int)$comp['id'])) as $c) $rows[] = [$c['place'], $c['club'], $c['points'], $c['gold'], $c['silver'], $c['bronze'], $c['results']];
    return ['title' => 'Classement des clubs', 'subtitle' => export_sub($comp), 'columns' => ['Rang', 'Club', 'Points', 'Or', 'Argent', 'Bronze', 'Résultats classés'], 'weights' => [1, 5, 2, 1.2, 1.2, 1.2, 2.4], 'rows' => $rows];
}
function export_swimmer_ranking(array $comp, array $scope): array {
    $rows = [];
    foreach (swimmer_standings(competition_rankings((int)$comp['id'])) as $s) {
        if ($scope['club'] !== null && (int)$s['club_id'] !== $scope['club']) continue;
        if ($scope['swimmer'] !== null && (int)$s['swimmer_id'] !== $scope['swimmer']) continue;
        $rows[] = [$s['place'], $s['name'], $s['club'], $s['category'], $s['points'], $s['gold'], $s['silver'], $s['bronze']];
    }
    return ['title' => 'Classement des nageurs', 'subtitle' => export_sub($comp), 'columns' => ['Rang', 'Nageur', 'Club', 'Catégorie', 'Points', 'Or', 'Argent', 'Bronze'], 'weights' => [1, 4, 3.5, 2, 1.5, 1, 1.2, 1.2], 'rows' => $rows];
}
function export_entries(array $comp, array $scope): array {
    $pdo = db(); $rows = []; $cid = (int)$comp['id'];
    $q = $pdo->prepare('SELECT ev.id,ev.name,ev.distance,ev.stroke,ev.sex,ev.relay FROM events ev WHERE ev.competition_id=? ORDER BY ev.id'); $q->execute([$cid]);
    foreach ($q->fetchAll() as $ev) {
        $part = [];
        if ((int)$ev['relay'] === 1) {
            $t = $pdo->prepare('SELECT rt.*,cl.name club FROM relay_teams rt LEFT JOIN clubs cl ON cl.id=rt.club_id WHERE rt.event_id=? ORDER BY rt.id'); $t->execute([$ev['id']]);
            foreach ($t->fetchAll() as $team) {
                if ($scope['club'] !== null && (int)$team['club_id'] !== $scope['club']) continue;
                $m = relay_members_of((int)$team['id']);
                if ($scope['swimmer'] !== null && !in_array($scope['swimmer'], array_map(fn($x) => (int)$x['id'], $m), true)) continue;
                $part[] = [$team['name'], implode(', ', array_map(fn($x) => $x['first_name'] . ' ' . $x['last_name'], $m)), $team['club'] ?? '—', fmt_time($team['seed_time']), status_label($team['status'])];
            }
        } else {
            $t = $pdo->prepare('SELECT en.seed_time,en.status,s.id sid,s.first_name,s.last_name,s.license_no,s.club_id,cl.name club FROM entries en JOIN swimmers s ON s.id=en.swimmer_id LEFT JOIN clubs cl ON cl.id=s.club_id WHERE en.event_id=? ORDER BY s.last_name,s.first_name'); $t->execute([$ev['id']]);
            foreach ($t->fetchAll() as $r) {
                if ($scope['club'] !== null && (int)$r['club_id'] !== $scope['club']) continue;
                if ($scope['swimmer'] !== null && (int)$r['sid'] !== $scope['swimmer']) continue;
                $part[] = [$r['last_name'] . ' ' . $r['first_name'], 'Licence ' . $r['license_no'], $r['club'] ?? '—', fmt_time($r['seed_time']), status_label($r['status'])];
            }
        }
        if ($part) { $rows[] = export_event_label($ev) . ((int)$ev['relay'] === 1 ? ' — Relais' : ''); foreach ($part as $p) $rows[] = $p; }
    }
    return ['title' => 'Engagements', 'subtitle' => export_sub($comp), 'columns' => ['Nageur / équipe', 'Licence / membres', 'Club', 'Temps d\'engagement', 'Statut'], 'weights' => [3.5, 6, 3, 2.2, 1.5], 'rows' => $rows];
}
/** Lignes de séries (individuels + relais) d'une compétition, avec identifiants pour les actions. */
function lane_rows(?int $cid, array $scope = ['club' => null, 'swimmer' => null]): array {
    $pdo = db(); $out = [];
    $w = ''; $args = [];
    if ($cid) { $w .= ' AND e.competition_id=?'; $args[] = $cid; }
    $sql = 'SELECT hl.id lane_id,hl.lane_no,h.id heat_id,h.heat_no,e.id event_id,e.name event_name,e.distance,e.stroke,e.sex,e.phase,e.relay,c.id competition_id,c.name competition,c.event_date,c.lanes,
        (SELECT COUNT(*) FROM events f WHERE f.source_event_id=e.id) has_final,en.seed_time,s.id swimmer_id,s.first_name,s.last_name,s.club_id,cl.name club
        FROM heats h JOIN events e ON e.id=h.event_id JOIN competitions c ON c.id=e.competition_id JOIN heat_lanes hl ON hl.heat_id=h.id JOIN entries en ON en.id=hl.entry_id JOIN swimmers s ON s.id=en.swimmer_id LEFT JOIN clubs cl ON cl.id=s.club_id WHERE 1=1' . $w;
    $q = $pdo->prepare($sql); $q->execute($args);
    foreach ($q->fetchAll() as $r) {
        if ($scope['club'] !== null && (int)$r['club_id'] !== $scope['club']) continue;
        if ($scope['swimmer'] !== null && (int)$r['swimmer_id'] !== $scope['swimmer']) continue;
        $r['name'] = $r['first_name'] . ' ' . $r['last_name']; $r['team_id'] = null; $r['members'] = ''; $out[] = $r;
    }
    $sql = 'SELECT hl.id lane_id,hl.lane_no,h.id heat_id,h.heat_no,e.id event_id,e.name event_name,e.distance,e.stroke,e.sex,e.phase,e.relay,c.id competition_id,c.name competition,c.event_date,c.lanes,
        (SELECT COUNT(*) FROM events f WHERE f.source_event_id=e.id) has_final,rt.seed_time,rt.id team_id,rt.name,rt.club_id,cl.name club
        FROM heats h JOIN events e ON e.id=h.event_id JOIN competitions c ON c.id=e.competition_id JOIN heat_lanes hl ON hl.heat_id=h.id JOIN relay_teams rt ON rt.id=hl.team_id LEFT JOIN clubs cl ON cl.id=rt.club_id WHERE 1=1' . $w;
    $q = $pdo->prepare($sql); $q->execute($args);
    foreach ($q->fetchAll() as $r) {
        $m = relay_members_of((int)$r['team_id']);
        if ($scope['club'] !== null && (int)$r['club_id'] !== $scope['club']) continue;
        if ($scope['swimmer'] !== null && !in_array($scope['swimmer'], array_map(fn($x) => (int)$x['id'], $m), true)) continue;
        $r['swimmer_id'] = null; $r['members'] = implode(', ', array_map(fn($x) => $x['first_name'] . ' ' . $x['last_name'], $m)); $out[] = $r;
    }
    usort($out, fn($a, $b) => [$a['event_date'], $a['competition_id'], $a['event_name'], $a['event_id'], $a['heat_no'], $a['lane_no']] <=> [$b['event_date'], $b['competition_id'], $b['event_name'], $b['event_id'], $b['heat_no'], $b['lane_no']]);
    return $out;
}
function export_heats(array $comp, array $scope): array {
    $rows = []; $last = '';
    foreach (lane_rows((int)$comp['id'], $scope) as $r) {
        $key = $r['event_id'] . '|' . $r['heat_no'];
        if ($key !== $last) { $rows[] = export_event_label($r) . ($r['phase'] === 'finale' ? ' — FINALE' : '') . ' — Série ' . $r['heat_no']; $last = $key; }
        $rows[] = [$r['lane_no'], $r['name'] . ($r['members'] !== '' ? ' (' . $r['members'] . ')' : ''), $r['club'] ?? '—', fmt_time($r['seed_time'])];
    }
    return ['title' => 'Séries et couloirs', 'subtitle' => export_sub($comp), 'columns' => ['Couloir', 'Nageur / équipe', 'Club', 'Temps d\'engagement'], 'weights' => [1.4, 7, 3.5, 2.5], 'rows' => $rows];
}

// ================================================================= CSV
function export_csv(array $d, string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    echo "\xEF\xBB\xBF";
    $o = fopen('php://output', 'w');
    fputcsv($o, [$d['title']], ';'); fputcsv($o, [$d['subtitle']], ';'); fputcsv($o, [], ';');
    fputcsv($o, $d['columns'], ';');
    foreach ($d['rows'] as $r) fputcsv($o, is_array($r) ? $r : [$r], ';');
    fclose($o);
}

// ================================================================= XLSX (OOXML minimal, via ZipArchive)
function xlsx_col(int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; }
function xlsx_esc($v): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$v), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function export_xlsx(array $d, string $filename): void {
    $ncol = count($d['columns']);
    $sw = array_sum($d['weights']);
    $cols = '';
    foreach ($d['weights'] as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . round(max(8, 110 * $w / $sw), 1) . '" customWidth="1"/>';
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"/></sheetViews><cols>' . $cols . '</cols><sheetData>';
    $rn = 1;
    $cell = function (int $c, int $r, $v, int $style) {
        $ref = xlsx_col($c) . $r;
        if ($v === '' || $v === null) return '<c r="' . $ref . '" s="' . $style . '"/>';
        if (is_int($v) || is_float($v)) return '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
        return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc($v) . '</t></is></c>';
    };
    $xml .= '<row r="' . $rn . '">' . $cell(0, $rn, $d['title'], 3) . '</row>'; $rn++;
    $xml .= '<row r="' . $rn . '">' . $cell(0, $rn, $d['subtitle'], 0) . '</row>'; $rn += 2;
    $xml .= '<row r="' . $rn . '">'; foreach ($d['columns'] as $i => $c) $xml .= $cell($i, $rn, $c, 1); $xml .= '</row>'; $rn++;
    $merges = [];
    foreach ($d['rows'] as $r) {
        if (is_array($r)) { $xml .= '<row r="' . $rn . '">'; foreach ($r as $i => $v) $xml .= $cell($i, $rn, $v, 0); $xml .= '</row>'; }
        else { $xml .= '<row r="' . $rn . '">' . $cell(0, $rn, $r, 2) . '</row>'; $merges[] = 'A' . $rn . ':' . xlsx_col($ncol - 1) . $rn; }
        $rn++;
    }
    $xml .= '</sheetData>';
    if ($merges) $xml .= '<mergeCells count="' . count($merges) . '">' . implode('', array_map(fn($m) => '<mergeCell ref="' . $m . '"/>', $merges)) . '</mergeCells>';
    $xml .= '</worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF1769C2"/><name val="Calibri"/></font></fonts><fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1769C2"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF6FF"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>';
    $sheetName = preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', mb_substr($d['title'], 0, 31));
    $parts = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . xlsx_esc($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $styles,
        'xl/worksheets/sheet1.xml' => $xml,
    ];
    if (!class_exists('ZipArchive')) { export_xlsx_fallback($d, $filename); return; }
    $tmp = tempnam(sys_get_temp_dir(), 'swp');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    foreach ($parts as $n => $c) $zip->addFromString($n, $c);
    $zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp); @unlink($tmp);
}
/** Secours si l'extension zip de PHP est absente : SpreadsheetML (ouvrable dans Excel). */
function export_xlsx_fallback(array $d, string $filename): void {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    echo '<?xml version="1.0" encoding="UTF-8"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="' . xlsx_esc(mb_substr($d['title'], 0, 31)) . '"><Table>';
    $row = function (array $cells) { $s = '<Row>'; foreach ($cells as $v) $s .= '<Cell><Data ss:Type="' . (is_int($v) || is_float($v) ? 'Number' : 'String') . '">' . xlsx_esc($v) . '</Data></Cell>'; return $s . '</Row>'; };
    echo $row([$d['title']]), $row([$d['subtitle']]), $row([]), $row($d['columns']);
    foreach ($d['rows'] as $r) echo $row(is_array($r) ? $r : [$r]);
    echo '</Table></Worksheet></Workbook>';
}

// ================================================================= PDF (générateur minimal, polices standard Helvetica)
class SwimPdf {
    private const W = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    private array $pages = [];
    private string $buf = '';
    private float $pw = 841.89, $ph = 595.28, $m = 34;
    private float $y = 0;
    private int $pageNo = 0;
    private string $title, $subtitle;
    private array $cols = [];
    private array $colX = [];

    public function __construct(string $title, string $subtitle, bool $landscape = true) {
        $this->title = $title; $this->subtitle = $subtitle;
        if (!$landscape) { [$this->pw, $this->ph] = [595.28, 841.89]; }
    }
    private function enc(string $s): string {
        $s = str_replace(["\u{2192}", "\u{2019}", "\u{2018}", "\u{202F}", "\u{00A0}"], ['->', "'", "'", ' ', ' '], $s);
        $c = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        if ($c === false) $c = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        return $c;
    }
    private function esc(string $s): string { return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s); }
    private function width(string $enc, float $size, bool $bold): float {
        $w = 0;
        for ($i = 0, $n = strlen($enc); $i < $n; $i++) { $o = ord($enc[$i]); $w += ($o >= 32 && $o <= 126) ? self::W[$o - 32] : 600; }
        return $w * $size / 1000 * ($bold ? 1.06 : 1);
    }
    private function fit(string $s, float $maxW, float $size, bool $bold): string {
        $e = $this->enc($s);
        if ($this->width($e, $size, $bold) <= $maxW) return $e;
        while (strlen($e) > 1 && $this->width($e . '...', $size, $bold) > $maxW) $e = substr($e, 0, -1);
        return rtrim($e) . '...';
    }
    private function text(float $x, float $y, string $enc, float $size, bool $bold = false, array $rgb = [0.09, 0.2, 0.3]): void {
        $this->buf .= sprintf("BT /%s %.2f Tf %.3f %.3f %.3f rg %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $this->ph - $y, $this->esc($enc));
    }
    private function rect(float $x, float $y, float $w, float $h, array $rgb): void {
        $this->buf .= sprintf("%.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f\n", $rgb[0], $rgb[1], $rgb[2], $x, $this->ph - $y - $h, $w, $h);
    }
    private function line(float $x1, float $y, float $x2, array $rgb): void {
        $this->buf .= sprintf("%.3f %.3f %.3f RG 0.4 w %.2f %.2f m %.2f %.2f l S\n", $rgb[0], $rgb[1], $rgb[2], $x1, $this->ph - $y, $x2, $this->ph - $y);
    }
    private function newPage(): void {
        if ($this->pageNo > 0) $this->pages[] = $this->buf;
        $this->buf = ''; $this->pageNo++;
        $this->rect(0, 0, $this->pw, 8, [0.09, 0.41, 0.76]);
        $this->text($this->m, 34, $this->enc('SwimPro'), 9, true, [0.09, 0.41, 0.76]);
        $this->text($this->m, 56, $this->enc($this->title), 17, true);
        $this->text($this->m, 72, $this->fit($this->subtitle, $this->pw - 2 * $this->m, 9.5, false), 9.5, false, [0.44, 0.52, 0.6]);
        $this->y = 88;
        if ($this->cols) $this->headerRow();
    }
    private function headerRow(): void {
        $this->rect($this->m, $this->y, $this->pw - 2 * $this->m, 18, [0.09, 0.41, 0.76]);
        foreach ($this->cols as $i => $c) $this->text($this->colX[$i] + 4, $this->y + 12.5, $this->fit($c, $this->colW[$i] - 8, 8.5, true), 8.5, true, [1, 1, 1]);
        $this->y += 18;
    }
    private array $colW = [];
    public function table(array $columns, array $weights, array $rows): void {
        $this->cols = $columns;
        $total = $this->pw - 2 * $this->m; $sum = array_sum($weights); $x = $this->m;
        foreach ($weights as $i => $w) { $this->colW[$i] = $total * $w / $sum; $this->colX[$i] = $x; $x += $this->colW[$i]; }
        $this->newPage();
        $limit = $this->ph - 40; $zebra = false;
        if (!$rows) { $this->text($this->m, $this->y + 20, $this->enc('Aucune donnée à afficher.'), 10); }
        foreach ($rows as $r) {
            $isSection = !is_array($r);
            if ($this->y + ($isSection ? 36 : 17) > $limit) { $this->newPage(); }
            if ($isSection) {
                $this->y += 6;
                $this->rect($this->m, $this->y, $total, 17, [0.918, 0.965, 1]);
                $this->text($this->m + 4, $this->y + 12, $this->fit((string)$r, $total - 8, 9, true), 9, true, [0.09, 0.41, 0.76]);
                $this->y += 17; $zebra = false; continue;
            }
            if ($zebra) $this->rect($this->m, $this->y, $total, 16, [0.965, 0.98, 0.995]);
            foreach ($r as $i => $v) $this->text($this->colX[$i] + 4, $this->y + 11.5, $this->fit((string)$v, $this->colW[$i] - 8, 8.5, false), 8.5);
            $this->line($this->m, $this->y + 16, $this->m + $total, [0.88, 0.92, 0.96]);
            $this->y += 16; $zebra = !$zebra;
        }
    }
    public function output(string $filename): void {
        $this->pages[] = $this->buf;
        $n = count($this->pages);
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        for ($i = 0; $i < $n; $i++) $kids[] = (5 + $i * 2) . ' 0 R';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        foreach ($this->pages as $i => $content) {
            $footer = sprintf("BT /F1 8 Tf 0.44 0.52 0.6 rg %.2f 22 Td (%s) Tj ET\nBT /F1 8 Tf 0.44 0.52 0.6 rg %.2f 22 Td (Page %d / %d) Tj ET\n", $this->m, $this->esc($this->enc('SwimPro — ' . date('d/m/Y H:i'))), $this->pw - $this->m - 46, $i + 1, $n);
            $content .= $footer;
            $p = 5 + $i * 2; $c = $p + 1;
            $objs[$p] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $this->pw . ' ' . $this->ph . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $c . ' 0 R >>';
            $objs[$c] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        }
        $out = "%PDF-1.4\n"; $off = [];
        ksort($objs);
        foreach ($objs as $id => $body) { $off[$id] = strlen($out); $out .= $id . " 0 obj\n" . $body . "\nendobj\n"; }
        $xref = strlen($out); $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) $out .= sprintf("%010d 00000 n \n", $off[$i]);
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
        header('Content-Length: ' . strlen($out));
        echo $out;
    }
}
function export_pdf(array $d, string $filename): void {
    $pdf = new SwimPdf($d['title'], $d['subtitle'], count($d['columns']) > 4);
    $pdf->table($d['columns'], $d['weights'], $d['rows']);
    $pdf->output($filename);
}

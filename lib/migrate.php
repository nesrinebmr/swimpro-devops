<?php
/* SwimPro — migration automatique et idempotente de la base de données.
   Ajoute uniquement : nouvelles tables / nouvelles colonnes. Aucune donnée existante n'est supprimée. */
const SWIMPRO_SCHEMA_VERSION = 2;

function mg_table_exists(PDO $p, string $t): bool {
    $q = $p->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $q->execute([$t]);
    return (int)$q->fetchColumn() > 0;
}
function mg_column_exists(PDO $p, string $t, string $c): bool {
    $q = $p->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $q->execute([$t, $c]);
    return (int)$q->fetchColumn() > 0;
}
function mg_constraint_exists(PDO $p, string $t, string $name): bool {
    $q = $p->prepare('SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema=DATABASE() AND table_name=? AND constraint_name=?');
    $q->execute([$t, $name]);
    return (int)$q->fetchColumn() > 0;
}
function mg_index_exists(PDO $p, string $t, string $name): bool {
    $q = $p->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
    $q->execute([$t, $name]);
    return (int)$q->fetchColumn() > 0;
}
function mg_column_nullable(PDO $p, string $t, string $c): bool {
    $q = $p->prepare('SELECT is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $q->execute([$t, $c]);
    return $q->fetchColumn() === 'YES';
}

/** Retourne la liste des opérations effectuées. Lève une exception en cas d'échec. */
function run_migrations(PDO $p): array {
    $log = [];
    $p->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        // Tables de base (au cas où schema.sql d'une ancienne version n'aurait pas tout créé)
        if (!mg_table_exists($p, 'swimmer_club_history')) {
            $p->exec("CREATE TABLE swimmer_club_history (id INT AUTO_INCREMENT PRIMARY KEY, swimmer_id INT NOT NULL, club_id INT NULL, joined_on DATE NOT NULL, left_on DATE NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT fk_history_swimmer FOREIGN KEY(swimmer_id) REFERENCES swimmers(id) ON DELETE CASCADE, CONSTRAINT fk_history_club FOREIGN KEY(club_id) REFERENCES clubs(id) ON DELETE SET NULL, INDEX idx_history_swimmer(swimmer_id), INDEX idx_history_club(club_id)) ENGINE=InnoDB");
            $log[] = 'Table swimmer_club_history créée';
        }
        if (!mg_table_exists($p, 'point_scale')) {
            $p->exec('CREATE TABLE point_scale (place_no INT PRIMARY KEY, points INT NOT NULL) ENGINE=InnoDB');
            $p->exec('INSERT IGNORE INTO point_scale(place_no,points) VALUES(1,20),(2,17),(3,15),(4,13),(5,11),(6,9),(7,7),(8,5)');
            $log[] = 'Table point_scale créée';
        }

        // Paramètres
        if (!mg_table_exists($p, 'settings')) {
            $p->exec('CREATE TABLE settings (k VARCHAR(60) PRIMARY KEY, v VARCHAR(255) NOT NULL) ENGINE=InnoDB');
            $log[] = 'Table settings créée';
        }

        // Catégories d'âge
        if (!mg_table_exists($p, 'age_categories')) {
            $p->exec('CREATE TABLE age_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(60) NOT NULL, min_age INT NOT NULL, max_age INT NOT NULL, sort_order INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
            $log[] = 'Table age_categories créée';
        }
        if ((int)$p->query('SELECT COUNT(*) FROM age_categories')->fetchColumn() === 0) {
            $p->exec("INSERT INTO age_categories(name,min_age,max_age,sort_order) VALUES('Poussins',0,9,1),('Benjamins',10,11,2),('Minimes',12,13,3),('Cadets',14,15,4),('Juniors',16,17,5),('Seniors',18,99,6)");
            $log[] = 'Catégories par défaut ajoutées (Poussins à Seniors)';
        }

        // Épreuves : catégorie d'âge, taille de relais, lien vers l'épreuve d'origine (finales)
        if (!mg_column_exists($p, 'events', 'category_id')) {
            $p->exec('ALTER TABLE events ADD COLUMN category_id INT NULL');
            $log[] = 'events.category_id ajoutée';
        }
        if (!mg_constraint_exists($p, 'events', 'fk_event_category')) {
            $p->exec('ALTER TABLE events ADD CONSTRAINT fk_event_category FOREIGN KEY(category_id) REFERENCES age_categories(id) ON DELETE SET NULL');
        }
        if (!mg_column_exists($p, 'events', 'relay_size')) {
            $p->exec('ALTER TABLE events ADD COLUMN relay_size TINYINT NOT NULL DEFAULT 4');
            $log[] = 'events.relay_size ajoutée';
        }
        if (!mg_column_exists($p, 'events', 'source_event_id')) {
            $p->exec('ALTER TABLE events ADD COLUMN source_event_id INT NULL');
            $log[] = 'events.source_event_id ajoutée';
        }
        if (!mg_constraint_exists($p, 'events', 'fk_event_source')) {
            $p->exec('ALTER TABLE events ADD CONSTRAINT fk_event_source FOREIGN KEY(source_event_id) REFERENCES events(id) ON DELETE SET NULL');
        }

        // Relais
        if (!mg_table_exists($p, 'relay_teams')) {
            $p->exec("CREATE TABLE relay_teams (id INT AUTO_INCREMENT PRIMARY KEY, event_id INT NOT NULL, club_id INT NULL, name VARCHAR(160) NOT NULL, seed_time DECIMAL(8,2) NULL, status ENUM('engagé','DNS','DSQ') NOT NULL DEFAULT 'engagé', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_relay_event(event_id), CONSTRAINT fk_relay_event FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE, CONSTRAINT fk_relay_club FOREIGN KEY(club_id) REFERENCES clubs(id) ON DELETE SET NULL) ENGINE=InnoDB");
            $log[] = 'Table relay_teams créée';
        }
        if (!mg_table_exists($p, 'relay_members')) {
            $p->exec('CREATE TABLE relay_members (id INT AUTO_INCREMENT PRIMARY KEY, team_id INT NOT NULL, event_id INT NOT NULL, swimmer_id INT NOT NULL, leg_no INT NOT NULL, UNIQUE KEY uq_relay_event_swimmer(event_id,swimmer_id), UNIQUE KEY uq_relay_team_leg(team_id,leg_no), CONSTRAINT fk_rm_team FOREIGN KEY(team_id) REFERENCES relay_teams(id) ON DELETE CASCADE, CONSTRAINT fk_rm_event FOREIGN KEY(event_id) REFERENCES events(id) ON DELETE CASCADE, CONSTRAINT fk_rm_swimmer FOREIGN KEY(swimmer_id) REFERENCES swimmers(id) ON DELETE CASCADE) ENGINE=InnoDB');
            $log[] = 'Table relay_members créée';
        }

        // Séries : un couloir peut être occupé par une équipe de relais
        if (!mg_column_nullable($p, 'heat_lanes', 'entry_id')) {
            $p->exec('ALTER TABLE heat_lanes MODIFY entry_id INT NULL');
        }
        if (!mg_column_exists($p, 'heat_lanes', 'team_id')) {
            $p->exec('ALTER TABLE heat_lanes ADD COLUMN team_id INT NULL');
            $log[] = 'heat_lanes.team_id ajoutée';
        }
        if (!mg_constraint_exists($p, 'heat_lanes', 'fk_lane_team')) {
            $p->exec('ALTER TABLE heat_lanes ADD CONSTRAINT fk_lane_team FOREIGN KEY(team_id) REFERENCES relay_teams(id) ON DELETE CASCADE');
        }
        if (!mg_index_exists($p, 'heat_lanes', 'uq_team_heat')) {
            $p->exec('ALTER TABLE heat_lanes ADD UNIQUE KEY uq_team_heat(heat_id,team_id)');
        }

        // Résultats : une équipe de relais peut avoir un résultat
        if (!mg_column_nullable($p, 'results', 'entry_id')) {
            $p->exec('ALTER TABLE results MODIFY entry_id INT NULL');
        }
        if (!mg_column_exists($p, 'results', 'team_id')) {
            $p->exec('ALTER TABLE results ADD COLUMN team_id INT NULL');
            $log[] = 'results.team_id ajoutée';
        }
        if (!mg_constraint_exists($p, 'results', 'fk_result_team')) {
            $p->exec('ALTER TABLE results ADD CONSTRAINT fk_result_team FOREIGN KEY(team_id) REFERENCES relay_teams(id) ON DELETE CASCADE');
        }
        if (!mg_index_exists($p, 'results', 'uq_result_team')) {
            $p->exec('ALTER TABLE results ADD UNIQUE KEY uq_result_team(team_id)');
        }

        $s = $p->prepare('INSERT INTO settings(k,v) VALUES(\'schema_version\',?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
        $s->execute([(string)SWIMPRO_SCHEMA_VERSION]);
    } finally {
        $p->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    return $log;
}

/** Appelée automatiquement : vérifie une seule fois par session que la base est à jour. */
function ensure_schema(): void {
    if (!empty($_SESSION['schema_ok']) && (int)$_SESSION['schema_ok'] >= SWIMPRO_SCHEMA_VERSION) return;
    try {
        $p = db();
        $ok = false;
        if (mg_table_exists($p, 'settings')) {
            $q = $p->query("SELECT v FROM settings WHERE k='schema_version'");
            $ok = (int)$q->fetchColumn() >= SWIMPRO_SCHEMA_VERSION;
        }
        if (!$ok) run_migrations($p);
        $_SESSION['schema_ok'] = SWIMPRO_SCHEMA_VERSION;
    } catch (Throwable $e) {
        // Base absente ou droits insuffisants : on laisse l'application afficher ses erreurs habituelles.
    }
}

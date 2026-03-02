<?php
/**
 * Plugin Name: HSG Turniermanager
 * Description: Turnierplanung für Handballvereine mit Spielplan-Generator und Live-Tabelle.
 * Version: 1.0
 * Author: HSG
 */

if (!defined('ABSPATH')) {
    exit;
}

class HSG_Turniermanager {

    private $version = '1.0';

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'install']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_shortcode('hsg_turnierplan', [$this, 'shortcode_turnierplan']);
        add_shortcode('hsg_live_tabelle', [$this, 'shortcode_tabelle']);
    }

    public function install() {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta("CREATE TABLE {$wpdb->prefix}hsg_altersklassen (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            spielzeit INT NOT NULL,
            spieleranzahl INT NOT NULL
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}hsg_mannschaften (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            altersklasse_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(150) NOT NULL
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}hsg_hallen (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL
        ) $charset;");

        dbDelta("CREATE TABLE {$wpdb->prefix}hsg_spiele (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            altersklasse_id BIGINT UNSIGNED NOT NULL,
            team1_id BIGINT UNSIGNED NOT NULL,
            team2_id BIGINT UNSIGNED NOT NULL,
            tore1 INT DEFAULT 0,
            tore2 INT DEFAULT 0,
            halle_id BIGINT UNSIGNED,
            spielzeit DATETIME
        ) $charset;");
    }

    public function admin_menu() {
        add_menu_page(
            'Turniermanager',
            'Turniermanager',
            'manage_options',
            'hsg_turniermanager',
            [$this, 'admin_page'],
            'dashicons-awards'
        );
    }

 public function admin_page() {

    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;

    // Spielplan generieren
    if (isset($_POST['generate_schedule'])) {

    check_admin_referer('hsg_generate_schedule');

    $altersklasse_id = intval($_POST['generate_altersklasse_id']);
    $this->generate_schedule($altersklasse_id);

    echo '<div class="updated"><p>Spielplan erfolgreich generiert!</p></div>';
    }

    echo '<div class="wrap">';
    echo '<h1>HSG Turniermanager</h1>';

    // Altersklasse speichern
    if (isset($_POST['save_altersklasse'])) {
        check_admin_referer('hsg_save_altersklasse');

        $name = sanitize_text_field($_POST['name']);
        $spielzeit = intval($_POST['spielzeit']);
        $spieleranzahl = intval($_POST['spieleranzahl']);

        $wpdb->insert(
            $wpdb->prefix . 'hsg_altersklassen',
            [
                'name' => $name,
                'spielzeit' => $spielzeit,
                'spieleranzahl' => $spieleranzahl
            ],
            ['%s','%d','%d']
        );

        echo '<div class="updated"><p>Altersklasse gespeichert.</p></div>';
    }

    // Mannschaft speichern
    if (isset($_POST['save_team'])) {
        check_admin_referer('hsg_save_team');

        $teamname = sanitize_text_field($_POST['teamname']);
        $altersklasse_id = intval($_POST['altersklasse_id']);

        $wpdb->insert(
            $wpdb->prefix . 'hsg_mannschaften',
            [
                'name' => $teamname,
                'altersklasse_id' => $altersklasse_id
            ],
            ['%s','%d']
        );

        echo '<div class="updated"><p>Mannschaft gespeichert.</p></div>';
    }

    // Formular Altersklasse
    echo '<h2>Neue Altersklasse anlegen</h2>';
    echo '<form method="post">';
    wp_nonce_field('hsg_save_altersklasse');
    echo '<input type="text" name="name" placeholder="Name (z.B. mE-Jugend)" required />';
    echo '<input type="number" name="spielzeit" placeholder="Spielzeit (Minuten)" required />';
    echo '<input type="number" name="spieleranzahl" placeholder="Spieleranzahl" required />';
    echo '<button class="button button-primary" name="save_altersklasse">Speichern</button>';
    echo '</form>';

    // Bestehende Altersklassen
    $klassen = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hsg_altersklassen");

    echo '<h2>Mannschaft anlegen</h2>';
    echo '<form method="post">';
    wp_nonce_field('hsg_save_team');
    echo '<input type="text" name="teamname" placeholder="Teamname" required />';
    echo '<select name="altersklasse_id" required>';
    foreach ($klassen as $klasse) {
        echo '<option value="'.$klasse->id.'">'.esc_html($klasse->name).'</option>';
    }
    echo '</select>';
    echo '<button class="button button-primary" name="save_team">Speichern</button>';
    echo '</form>';

    echo '</div>';

    echo '<hr>';
echo '<h2>Turnierübersicht</h2>';

foreach ($klassen as $klasse) {

    echo '<h3>'.esc_html($klasse->name).'</h3>';

    // Teams laden
    $teams = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hsg_mannschaften WHERE altersklasse_id = %d",
            $klasse->id
        )
    );

    if ($teams) {
        echo '<ul>';
        foreach ($teams as $team) {
            echo '<li>'.esc_html($team->name).'</li>';
        }
        echo '</ul>';
    } else {
        echo '<p><em>Noch keine Teams angelegt.</em></p>';
    }

    // Prüfen ob Spiele existieren
    $spiele = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hsg_spiele WHERE altersklasse_id = %d",
            $klasse->id
        )
    );

    if ($spiele == 0 && count($teams) >= 2) {

        echo '<form method="post" style="margin-bottom:20px;">';
        wp_nonce_field('hsg_generate_schedule');
        echo '<input type="hidden" name="generate_altersklasse_id" value="'.$klasse->id.'">';
        echo '<button class="button button-primary" name="generate_schedule">Spielplan generieren</button>';
        echo '</form>';

    } elseif ($spiele > 0) {

        echo '<p><strong>Spielplan bereits generiert ('.$spiele.' Spiele)</strong></p>';

    } else {

        echo '<p><em>Mindestens 2 Teams notwendig.</em></p>';
    }

    $spiele_liste = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT s.*, t1.name as team1, t2.name as team2
         FROM {$wpdb->prefix}hsg_spiele s
         JOIN {$wpdb->prefix}hsg_mannschaften t1 ON s.team1_id = t1.id
         JOIN {$wpdb->prefix}hsg_mannschaften t2 ON s.team2_id = t2.id
         WHERE s.altersklasse_id = %d",
        $klasse->id
    )
);

if ($spiele_liste) {
    echo '<table class="widefat striped">';
    echo '<thead><tr><th>Spiel</th><th>Ergebnis</th></tr></thead>';
    echo '<tbody>';

    foreach ($spiele_liste as $spiel) {
        echo '<tr>';
        echo '<td>'.esc_html($spiel->team1).' vs '.esc_html($spiel->team2).'</td>';
        echo '<td>'.esc_html($spiel->tore1).' : '.esc_html($spiel->tore2).'</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    }
}
}

    private function render_altersklassen() {
        global $wpdb;
        $klassen = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}hsg_altersklassen");

        echo '<h2>Altersklassen</h2>';
        echo '<ul>';
        foreach ($klassen as $klasse) {
            echo '<li>' . esc_html($klasse->name) . '</li>';
        }
        echo '</ul>';
    }

    private function generate_schedule($altersklasse_id) {
        global $wpdb;

        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}hsg_mannschaften WHERE altersklasse_id = %d",
            $altersklasse_id
        ));

        if (count($teams) < 2) return;

        for ($i = 0; $i < count($teams); $i++) {
            for ($j = $i + 1; $j < count($teams); $j++) {

                $wpdb->insert(
                    "{$wpdb->prefix}hsg_spiele",
                    [
                        'altersklasse_id' => $altersklasse_id,
                        'team1_id' => $teams[$i]->id,
                        'team2_id' => $teams[$j]->id
                    ],
                    ['%d','%d','%d']
                );
            }
        }
    }

    public function shortcode_turnierplan($atts) {
        global $wpdb;

        $atts = shortcode_atts([
            'altersklasse' => 0
        ], $atts);

        $spiele = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, t1.name as team1, t2.name as team2
             FROM {$wpdb->prefix}hsg_spiele s
             JOIN {$wpdb->prefix}hsg_mannschaften t1 ON s.team1_id = t1.id
             JOIN {$wpdb->prefix}hsg_mannschaften t2 ON s.team2_id = t2.id
             WHERE s.altersklasse_id = %d",
            $atts['altersklasse']
        ));

        ob_start();
        echo '<table class="hsg-turnierplan">';
        echo '<tr><th>Spiel</th><th>Ergebnis</th></tr>';
        foreach ($spiele as $spiel) {
            echo '<tr>';
            echo '<td>' . esc_html($spiel->team1) . ' - ' . esc_html($spiel->team2) . '</td>';
            echo '<td>' . esc_html($spiel->tore1) . ':' . esc_html($spiel->tore2) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        return ob_get_clean();
    }

    public function shortcode_tabelle($atts) {
        global $wpdb;

        $atts = shortcode_atts([
            'altersklasse' => 0
        ], $atts);

        $teams = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}hsg_mannschaften WHERE altersklasse_id = %d",
            $atts['altersklasse']
        ));

        $tabelle = [];

        foreach ($teams as $team) {
            $tabelle[$team->id] = [
                'name' => $team->name,
                'punkte' => 0,
                'tore' => 0,
                'geg' => 0
            ];
        }

        $spiele = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hsg_spiele WHERE altersklasse_id = %d",
            $atts['altersklasse']
        ));

        foreach ($spiele as $spiel) {
            if ($spiel->tore1 == $spiel->tore2) {
                $tabelle[$spiel->team1_id]['punkte'] += 1;
                $tabelle[$spiel->team2_id]['punkte'] += 1;
            } elseif ($spiel->tore1 > $spiel->tore2) {
                $tabelle[$spiel->team1_id]['punkte'] += 2;
            } else {
                $tabelle[$spiel->team2_id]['punkte'] += 2;
            }

            $tabelle[$spiel->team1_id]['tore'] += $spiel->tore1;
            $tabelle[$spiel->team1_id]['geg'] += $spiel->tore2;
            $tabelle[$spiel->team2_id]['tore'] += $spiel->tore2;
            $tabelle[$spiel->team2_id]['geg'] += $spiel->tore1;
        }

        usort($tabelle, function($a, $b) {
            return $b['punkte'] <=> $a['punkte'];
        });

        ob_start();
        echo '<table class="hsg-tabelle">';
        echo '<tr><th>Team</th><th>Punkte</th><th>Tore</th></tr>';
        foreach ($tabelle as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row['name']) . '</td>';
            echo '<td>' . esc_html($row['punkte']) . '</td>';
            echo '<td>' . esc_html($row['tore']) . ':' . esc_html($row['geg']) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        return ob_get_clean();
    }

}

new HSG_Turniermanager();
<?php
/*
Plugin Name: Studio Map Locator & Directory
Description: V11.2: Guaranteed "Blind Skip" Geo-Logic with delay & BETA Header.
Version: 11.2.4
Author: Dein Coding-Assistent
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// ----------------------------------------------------------------
// 1. CPT & TAX
// ----------------------------------------------------------------
function sml_register_cpt_and_tax() {
    register_post_type('studio', array(
        'labels' => array('name' => 'Studios', 'singular_name' => 'Studio', 'add_new_item' => 'Neues Studio hinzufügen', 'edit_item' => 'Studio bearbeiten', 'all_items' => 'Alle Studios'),
        'public' => true, 'has_archive' => false, 'supports' => array('title', 'editor', 'thumbnail'), 'menu_icon' => 'dashicons-microphone', 'show_in_menu' => true
    ));
    register_taxonomy('studio_category', 'studio', array('labels' => array('name' => 'Kategorien', 'singular_name' => 'Kategorie'), 'hierarchical' => true, 'show_admin_column' => true, 'show_in_rest' => true));

// Feedback (Ideen / Fehler) – nur im Backend sichtbar
register_post_type('sml_feedback', array(
    'labels' => array(
        'name'          => 'Feedback',
        'singular_name' => 'Feedback',
        'add_new_item'  => 'Neues Feedback',
        'edit_item'     => 'Feedback bearbeiten',
        'all_items'     => 'Alle Feedbacks',
        'menu_name'     => 'Feedback'
    ),
    'public'             => false,
    'show_ui'            => true,
    'show_in_menu'       => 'edit.php?post_type=studio',
    'exclude_from_search'=> true,
    'publicly_queryable' => false,
    'show_in_nav_menus'  => false,
    'show_in_rest'       => false,
    'supports'           => array('title', 'editor'),
    'capability_type'    => 'post',
    'map_meta_cap'       => true,
));

}
add_action('init', 'sml_register_cpt_and_tax');

function sml_add_admin_menu() { add_submenu_page('edit.php?post_type=studio', 'Geocoding-Tools', 'Geocoding-Tools', 'manage_options', 'sml-tools', 'sml_tools_page_html'); add_submenu_page('edit.php?post_type=studio', 'Import/Export', 'Import/Export', 'manage_options', 'sml-import-export', 'sml_import_export_page_html'); }
add_action('admin_menu', 'sml_add_admin_menu');

// ----------------------------------------------------------------
// 2. LIVE CHECK AJAX
// ----------------------------------------------------------------
add_action('wp_ajax_sml_live_check_duplicate', 'sml_live_check_duplicate_handler');
add_action('wp_ajax_nopriv_sml_live_check_duplicate', 'sml_live_check_duplicate_handler');

/**
 * Fast, exact duplicate check for Studio titles.
 * Returns the matching post ID or 0.
 */
function sml_find_studio_by_exact_title( $title, $exclude_id = 0 ) {
    global $wpdb;
    $title = wp_unslash( $title );
    if ( $title === '' ) return 0;

    // Limit to the same statuses used by the UI checks.
    $statuses = "('publish','pending','draft')";
    if ( $exclude_id ) {
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='studio' AND post_title=%s AND post_status IN {$statuses} AND ID <> %d LIMIT 1",
            $title,
            (int) $exclude_id
        );
    } else {
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='studio' AND post_title=%s AND post_status IN {$statuses} LIMIT 1",
            $title
        );
    }

    $found = (int) $wpdb->get_var( $sql );
    return $found > 0 ? $found : 0;
}

function sml_live_check_duplicate_handler() {
    // CSRF protection (works for both logged-in and public requests)
    check_ajax_referer('sml_live_check', 'security');

    $type = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
    $value = isset($_POST['value']) ? sanitize_text_field(wp_unslash($_POST['value'])) : '';
    $exclude_id = isset($_POST['exclude_id']) ? intval($_POST['exclude_id']) : 0;

    if (empty($value)) {
        wp_send_json_success(['exists' => false, 'message' => '']);
    }

    $exists = false;
    $msg = '';

    if ($type === 'title') {
        $found_id = sml_find_studio_by_exact_title($value, $exclude_id);
        if ($found_id) {
            $exists = true;
            $msg = 'Ein Studio mit diesem Namen existiert bereits.';
        }
    } elseif ($type === 'address') {
        $query = new WP_Query([
            'post_type'      => 'studio',
            'post_status'    => ['publish', 'pending', 'draft'],
            'meta_query'     => [[
                'key'     => '_sml_address',
                'value'   => $value,
                'compare' => '=',
            ]],
            'post__not_in'   => [$exclude_id],
            'fields'         => 'ids',
            'posts_per_page' => 1,
        ]);

        if ($query->have_posts()) {
            $exists = true;
            $msg = 'Diese Adresse ist bereits registriert.';
        }
    }

    wp_send_json_success(['exists' => $exists, 'message' => $msg]);
}

// ----------------------------------------------------------------
// 3. ADMIN NOTICES & META BOXEN
// ----------------------------------------------------------------
add_action('admin_notices', function() {
    if($msg = get_transient('sml_admin_error_' . get_current_user_id())) {
        echo '<div class="notice notice-error is-dismissible"><p><strong>Studio Map Warnung:</strong> '.$msg.'</p></div>';
        delete_transient('sml_admin_error_' . get_current_user_id());
    }
});
add_action('admin_footer', 'sml_admin_live_check_script');
function sml_admin_live_check_script() {
    $screen = get_current_screen();
    if ($screen->post_type !== 'studio') return;
    ?>
    <script>
    jQuery(document).ready(function($){
        let timer;
        const currentId = <?php echo get_the_ID() ? get_the_ID() : 0; ?>;
        const smlLiveNonce = '<?php echo wp_create_nonce('sml_live_check'); ?>';
        function runCheck(input, type) {
            const val = input.val(); const wrapper = input.parent();
            wrapper.find('.sml-admin-error').remove(); input.css('border-color', '');
            if(val.length < 3) return;
            $.post(ajaxurl, { action: 'sml_live_check_duplicate', type: type, value: val, exclude_id: currentId, security: smlLiveNonce }, function(res) {
                if(res.success && res.data.exists) {
                    input.css('border-color', '#dc3232');
                    wrapper.append('<div class="sml-admin-error" style="color:#dc3232; margin-top:5px; font-weight:bold;">'+res.data.message+'</div>');
                }
            });
        }
        $('#title').on('input', function() { clearTimeout(timer); const el=$(this); timer=setTimeout(()=>runCheck(el,'title'), 500); });
        $('input[name="sml_address"]').on('input', function() { clearTimeout(timer); const el=$(this); timer=setTimeout(()=>runCheck(el,'address'), 500); });
    });
    </script>
    <?php
}

function sml_add_meta_boxes() { add_meta_box('sml_details', 'Studio Daten, Leistungen & Technik', 'sml_details_callback', 'studio', 'normal', 'high'); }
add_action('add_meta_boxes', 'sml_add_meta_boxes');

function sml_get_standard_tech_options() {
    return [
        'SessionLinkPRO', 'SourceConnect Standard', 'SourceConnect Pro', 'SourceConnect Now', 
        'ISDN / Musiktaxi', 'ipDTL', 'APT-X', 'Mayah', 'Zoom / Teams',
        'Neumann U87', 'Avalon Preamp', 'Brauner Mic', 'Schoeps',
        'Gesangskabine', 'Video-Sync', 'TV-Mischung', 'Kino-Mischung', 
        'Barrierefrei', 'Kunden-Parkplatz', 'WLAN / Gäste-Netz'
    ];
}

function sml_get_service_options() {
    return [
        'ADR / Sprachsynchron', 'Film & TV Audio', 'German Dubbing', 'Mixing / Mischung', 
        'Music Composition', 'Recordings / Aufnahme', 'Sound Design', 'Video Editing',
        'Voiceover / Sprachaufnahme', 'Mastering', 'Podcast Production', 'Foley / Geräuschemacher',
        'Remote Recording', 'Localization / Lokalisierung', 'Audio Books / Hörbuch'
    ];
}

function sml_details_callback($post) {
    $vals = array_map(function($k) use ($post){ return get_post_meta($post->ID, $k, true); }, ['_sml_address'=>'_sml_address', '_sml_phone'=>'_sml_phone', '_sml_email'=>'_sml_email', '_sml_website'=>'_sml_website', '_sml_lat'=>'_sml_lat', '_sml_lng'=>'_sml_lng']);
    $saved_tech = get_post_meta($post->ID, '_sml_tech_array', true);
    if(empty($saved_tech)) {
        $old_string = get_post_meta($post->ID, '_sml_tech', true);
        $saved_tech = $old_string ? array_map('trim', explode(',', $old_string)) : [];
    }
    $saved_services = get_post_meta($post->ID, '_sml_services_array', true);
    if(!is_array($saved_services)) $saved_services = [];

    $tech_options = sml_get_standard_tech_options();
    $service_options = sml_get_service_options();

    wp_nonce_field('sml_save_meta_action', 'sml_nonce_field');
    ?>
    <style>.sml-row{display:flex;gap:20px;margin-bottom:15px}.sml-col{flex:1}.sml-col input[type="text"], .sml-col input[type="email"], .sml-col textarea{width:100%;padding:6px}label{font-weight:bold;display:block;margin-bottom:5px}.sml-tech-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; background: #fdfdfd; padding: 15px; border: 1px solid #ddd; border-radius: 4px; max-height:200px; overflow-y:auto; }.sml-tech-item label { font-weight: normal; display: inline-block; margin-left: 5px; cursor: pointer; }</style>
    <div class="sml-row"><div class="sml-col"><label>Adresse:</label><input type="text" name="sml_address" value="<?php echo esc_attr($vals['_sml_address']); ?>" placeholder="Straße, PLZ Stadt" /></div></div>
    <div class="sml-row"><div class="sml-col"><label>Leistungen:</label><div class="sml-tech-grid"><?php foreach($service_options as $opt): ?><div class="sml-tech-item"><label><input type="checkbox" name="sml_services_array[]" value="<?php echo esc_attr($opt); ?>" <?php checked(in_array($opt, $saved_services)); ?> /> <?php echo esc_html($opt); ?></label></div><?php endforeach; ?></div></div></div>
    <div class="sml-row"><div class="sml-col"><label>Technik & Ausstattung:</label><div class="sml-tech-grid"><?php foreach($tech_options as $opt): ?><div class="sml-tech-item"><label><input type="checkbox" name="sml_tech_array[]" value="<?php echo esc_attr($opt); ?>" <?php checked(in_array($opt, $saved_tech)); ?> /> <?php echo esc_html($opt); ?></label></div><?php endforeach; ?></div></div></div>
    <div class="sml-row"><div class="sml-col"><label>Telefon:</label><input type="text" name="sml_phone" value="<?php echo esc_attr($vals['_sml_phone']); ?>" /></div><div class="sml-col"><label>E-Mail:</label><input type="email" name="sml_email" value="<?php echo esc_attr($vals['_sml_email']); ?>" /></div><div class="sml-col"><label>Webseite:</label><input type="text" name="sml_website" value="<?php echo esc_attr($vals['_sml_website']); ?>" /></div></div>
    <hr><div class="sml-row" style="background:#f5f5f5;padding:10px"><div class="sml-col"><label>Lat:</label><input type="text" name="sml_lat" value="<?php echo esc_attr($vals['_sml_lat']); ?>" readonly /></div><div class="sml-col"><label>Lng:</label><input type="text" name="sml_lng" value="<?php echo esc_attr($vals['_sml_lng']); ?>" readonly /></div></div>
    <?php
}

function sml_clean_address_for_geo($addr) {
    $clean = preg_replace('/(Hinterhof|Gebäude|Haus|Aufgang|Stiege|Etage|Wohnung|Appartement|EG|OG|DG)\s*\d*[\.,]?\s*/iu', '', $addr);
    return trim($clean);
}

function sml_fetch_geo_data($query) {
    $admin_email = get_option('admin_email');
    $args = array(
        'headers' => array('User-Agent' => 'WordPress StudioMapPlugin/11.2 ('.$admin_email.')')
    );
    $url = "https://nominatim.openstreetmap.org/search?format=json&limit=1&email=".urlencode($admin_email)."&q=".urlencode($query);
    $r = wp_remote_get($url, $args);
    if (!is_wp_error($r)) {
        $body = wp_remote_retrieve_body($r);
        $d = json_decode($body);
        if(!empty($d) && isset($d[0]->lat)) {
            return $d[0];
        }
    }
    return false;
}

function sml_save_meta($post_id) {
    if (get_post_type($post_id) !== 'studio') return;
    if (!isset($_POST['sml_nonce_field']) || !wp_verify_nonce($_POST['sml_nonce_field'], 'sml_save_meta_action') || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !current_user_can('edit_post', $post_id)) return;
    
    $post_title = get_the_title($post_id); $addr = isset($_POST['sml_address']) ? sanitize_text_field($_POST['sml_address']) : '';
    if(!empty($addr)) {
        $addr_query = new WP_Query(['post_type'=>'studio','post_status'=>'any','post__not_in'=>[$post_id],'meta_query'=>[['key'=>'_sml_address','value'=>$addr,'compare'=>'=']],'fields'=>'ids']);
        if($addr_query->have_posts()) set_transient('sml_admin_error_'.get_current_user_id(), 'Diese Adresse existiert bereits bei Studio ID: '.$addr_query->posts[0], 45);
    }
    if(!empty($post_title)) {
        $found_id = sml_find_studio_by_exact_title($post_title, $post_id);
        if($found_id) {
            set_transient('sml_admin_error_'.get_current_user_id(), 'Ein Studio mit dem Namen "'.$post_title.'" existiert bereits.', 45);
        }
    }

    if (isset($_POST['sml_address'])) update_post_meta($post_id, '_sml_address', sanitize_text_field(wp_unslash($_POST['sml_address'])));
    if (isset($_POST['sml_phone'])) update_post_meta($post_id, '_sml_phone', sanitize_text_field(wp_unslash($_POST['sml_phone'])));
    if (isset($_POST['sml_email'])) update_post_meta($post_id, '_sml_email', sanitize_email(wp_unslash($_POST['sml_email'])));
    if (isset($_POST['sml_website'])) update_post_meta($post_id, '_sml_website', esc_url_raw(wp_unslash($_POST['sml_website'])));
    
    if(isset($_POST['sml_tech_array'])) { $t = array_map('sanitize_text_field', $_POST['sml_tech_array']); update_post_meta($post_id, '_sml_tech_array', $t); update_post_meta($post_id, '_sml_tech', implode(', ', $t)); } else { delete_post_meta($post_id, '_sml_tech_array'); delete_post_meta($post_id, '_sml_tech'); }
    if(isset($_POST['sml_services_array'])) { $s = array_map('sanitize_text_field', $_POST['sml_services_array']); update_post_meta($post_id, '_sml_services_array', $s); } else { delete_post_meta($post_id, '_sml_services_array'); }

    $addr = isset($_POST['sml_address']) ? sanitize_text_field($_POST['sml_address']) : ''; $lat = get_post_meta($post_id, '_sml_lat', true);
    
    if (!empty($addr)) { 
        if(empty($lat)) {
            $clean_addr = sml_clean_address_for_geo($addr);
            $result = sml_fetch_geo_data($clean_addr);
            
            if(!$result) {
                $fallback_1 = preg_replace('/\d{5}/', '', $clean_addr);
                $result = sml_fetch_geo_data($fallback_1);
            }

            if(!$result) {
                if(preg_match('/\d{5}\s+\D+/', $clean_addr, $matches)) {
                    $result = sml_fetch_geo_data($matches[0]);
                }
            }

            if ($result) { 
                update_post_meta($post_id,'_sml_lat',$result->lat); 
                update_post_meta($post_id,'_sml_lng',$result->lon); 
            } 
        }
    }
}
add_action('save_post_studio', 'sml_save_meta');

// ----------------------------------------------------------------
// 4. ADMIN TOOL
// ----------------------------------------------------------------
function sml_tools_page_html() { ?>
    <div class="wrap"><h1>Batch Geocoding</h1><p>Versucht Koordinaten für Studios ohne Lat/Lng zu finden.</p><div style="background:#fff;padding:20px;border:1px solid #ccc;max-width:600px;"><button id="sml-start-batch" class="button button-primary">Starten</button><ul id="sml-log" style="height:150px;overflow-y:scroll;background:#f9f9f9;border:1px solid #ddd;padding:10px;margin-top:10px;font-size:11px;font-family:monospace;"></ul></div></div>
    <script>jQuery(document).ready(function($){ var q=[],t=0; var smlToolsNonce = '<?php echo wp_create_nonce('sml_tools_nonce'); ?>'; $('#sml-start-batch').click(function(){ $(this).prop('disabled',true); $.post(ajaxurl,{action:'sml_get_studios_no_coords', security: smlToolsNonce},function(r){ q=r; t=q.length; if(t===0)$('#sml-log').append('<li>Fertig.</li>'); else nxt(); }); }); function nxt(){ if(q.length===0)return; var i=q.shift(); $.post(ajaxurl,{action:'sml_process_single_geo',id:i.id,address:i.address, security: smlToolsNonce},function(j){ $('#sml-log').prepend('<li>'+i.title+' ('+j.search_term+'): '+(j.success?'OK':'Fehler')+'</li>'); setTimeout(nxt,1200); }); } });</script>
<?php }
add_action('wp_ajax_sml_get_studios_no_coords', 'sml_get_studios_no_coords_handler');
add_action('wp_ajax_sml_process_single_geo', 'sml_process_single_geo_handler');

function sml_get_studios_no_coords_handler() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Zugriff verweigert'], 403);
    }
    check_ajax_referer('sml_tools_nonce', 'security');

    $q = new WP_Query([
        'post_type'      => 'studio',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            ['key' => '_sml_lat', 'compare' => 'NOT EXISTS'],
            ['key' => '_sml_lat', 'value' => '', 'compare' => '='],
        ],
    ]);

    $l = [];
    foreach ($q->posts as $studio_id) {
        $a = get_post_meta($studio_id, '_sml_address', true);
        if ($a) {
            $l[] = [
                'id'      => (int) $studio_id,
                'title'   => get_the_title($studio_id),
                'address' => $a,
            ];
        }
    }

    wp_send_json($l);
}

function sml_process_single_geo_handler() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Zugriff verweigert'], 403);
    }
    check_ajax_referer('sml_tools_nonce', 'security');

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $a  = isset($_POST['address']) ? sanitize_text_field(wp_unslash($_POST['address'])) : '';

    if (!$id || empty($a)) {
        wp_send_json_error(['success' => false, 'search_term' => ''], 400);
    }

    $clean_a = sml_clean_address_for_geo($a);
    $result = sml_fetch_geo_data($clean_a);

    if (!$result) {
        $fallback_1 = preg_replace('/\d{5}/', '', $clean_a);
        $result = sml_fetch_geo_data($fallback_1);
    }

    if ($result) {
        update_post_meta($id, '_sml_lat', $result->lat);
        update_post_meta($id, '_sml_lng', $result->lon);
        wp_send_json(['success' => true, 'search_term' => $clean_a]);
    }

    wp_send_json(['success' => false, 'search_term' => $clean_a]);
}


// ----------------------------------------------------------------
// 4B. ADMIN IMPORT / EXPORT (JSON)
// ----------------------------------------------------------------
add_action('admin_post_sml_export_studios', 'sml_export_studios_handler');
add_action('admin_post_sml_import_studios', 'sml_import_studios_handler');

function sml_import_export_page_html() {
    if (!current_user_can('manage_options')) {
        wp_die('Zugriff verweigert', 403);
    }

    $export_url = wp_nonce_url(
        admin_url('admin-post.php?action=sml_export_studios&type=json'),
        'sml_export_studios'
    );

    $imported = isset($_GET['sml_imported']) ? intval($_GET['sml_imported']) : 0;
    $updated  = isset($_GET['sml_updated']) ? intval($_GET['sml_updated']) : 0;
    $failed   = isset($_GET['sml_failed']) ? intval($_GET['sml_failed']) : 0;
    $error    = isset($_GET['sml_error']) ? sanitize_text_field($_GET['sml_error']) : '';

    ?>
    <div class="wrap">
        <h1>Studio Import/Export</h1>

        <?php if ($error): ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <?php if ($imported || $updated || $failed): ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    Import abgeschlossen — Neu erstellt: <strong><?php echo esc_html($imported); ?></strong>,
                    Aktualisiert: <strong><?php echo esc_html($updated); ?></strong>,
                    Fehlgeschlagen: <strong><?php echo esc_html($failed); ?></strong>.
                </p>
            </div>
        <?php endif; ?>

        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:10px;padding:18px;max-width:820px;">
            <h2 style="margin-top:0;">Export</h2>
            <p>Exportiere alle Studio-Einträge (inklusive Meta-Daten und Kategorien) als JSON-Datei.</p>
            <p><a class="button button-primary" href="<?php echo esc_url($export_url); ?>">Studios exportieren (JSON)</a></p>

            <hr style="margin:18px 0;">

            <h2>Import</h2>
            <p>Importiere Studios aus einer zuvor exportierten JSON-Datei. Bestehende Studios werden primär über die ID aktualisiert; falls keine ID passt, versucht der Importer per exakt übereinstimmendem Titel zuzuordnen.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('sml_import_studios'); ?>
                <input type="hidden" name="action" value="sml_import_studios">
                <input type="file" name="sml_import_file" accept=".json,application/json" required>
                <p style="margin-top:12px;"><button type="submit" class="button button-primary">JSON importieren</button></p>
            </form>
        </div>
    </div>
    <?php
}

function sml_export_studios_handler() {
    if (!current_user_can('manage_options')) {
        wp_die('Zugriff verweigert', 403);
    }
    check_admin_referer('sml_export_studios');

    $q = new WP_Query([
        'post_type'      => 'studio',
        'posts_per_page' => -1,
        'post_status'    => ['publish','pending','draft','private'],
        'fields'         => 'ids',
    ]);

    $items = [];
    foreach ($q->posts as $id) {
        $terms = wp_get_post_terms($id, 'studio_category', ['fields' => 'slugs']);

        $items[] = [
            'ID'          => (int) $id,
            'post_title'  => get_the_title($id),
            'post_content'=> get_post_field('post_content', $id),
            'post_status' => get_post_status($id),
            'terms'       => [
                'studio_category' => is_array($terms) ? array_values($terms) : [],
            ],
            'meta'        => [
                '_sml_address'        => get_post_meta($id, '_sml_address', true),
                '_sml_phone'          => get_post_meta($id, '_sml_phone', true),
                '_sml_email'          => get_post_meta($id, '_sml_email', true),
                '_sml_website'        => get_post_meta($id, '_sml_website', true),
                '_sml_lat'            => get_post_meta($id, '_sml_lat', true),
                '_sml_lng'            => get_post_meta($id, '_sml_lng', true),
                '_sml_tech_array'     => get_post_meta($id, '_sml_tech_array', true),
                '_sml_services_array' => get_post_meta($id, '_sml_services_array', true),
            ],
            'featured_image' => [
                'attachment_id' => (int) get_post_thumbnail_id($id),
                'url'           => get_the_post_thumbnail_url($id, 'full'),
            ],
        ];
    }

    $payload = [
        'exported_at' => gmdate('c'),
        'plugin'      => 'Studio Map Locator & Directory',
        'version'     => '11.2.2',
        'count'       => count($items),
        'studios'     => $items,
    ];

    $filename = 'studio-export-' . gmdate('Y-m-d') . '.json';
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function sml_import_studios_handler() {
    if (!current_user_can('manage_options')) {
        wp_die('Zugriff verweigert', 403);
    }
    check_admin_referer('sml_import_studios');

    if (empty($_FILES['sml_import_file']['tmp_name'])) {
        wp_safe_redirect(add_query_arg('sml_error', rawurlencode('Keine Datei hochgeladen.'), admin_url('edit.php?post_type=studio&page=sml-import-export')));
        exit;
    }

    $raw = file_get_contents($_FILES['sml_import_file']['tmp_name']);
    $data = json_decode($raw, true);

    if (!is_array($data) || empty($data['studios']) || !is_array($data['studios'])) {
        wp_safe_redirect(add_query_arg('sml_error', rawurlencode('Ungültige JSON-Struktur.'), admin_url('edit.php?post_type=studio&page=sml-import-export')));
        exit;
    }

    $created = 0;
    $updated = 0;
    $failed  = 0;

    foreach ($data['studios'] as $row) {
        try {
            $incoming_id = isset($row['ID']) ? intval($row['ID']) : 0;
            $title       = isset($row['post_title']) ? sanitize_text_field($row['post_title']) : '';
            $content     = isset($row['post_content']) ? wp_kses_post($row['post_content']) : '';
            $status      = isset($row['post_status']) ? sanitize_key($row['post_status']) : 'pending';
            $status      = in_array($status, ['publish','pending','draft','private'], true) ? $status : 'pending';

            if (!$title) {
                $failed++;
                continue;
            }

            $target_id = 0;

            // 1) Update by ID if it exists and matches the CPT.
            if ($incoming_id && get_post_type($incoming_id) === 'studio') {
                $target_id = $incoming_id;
            }

            // 2) Otherwise, try exact title match.
            if (!$target_id) {
                $found = sml_find_studio_by_exact_title($title, 0);
                if ($found) $target_id = $found;
            }

            $postarr = [
                'post_type'    => 'studio',
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => $status,
            ];

            if ($target_id) {
                $postarr['ID'] = $target_id;
                wp_update_post($postarr);
                $updated++;
            } else {
                $target_id = wp_insert_post($postarr);
                if (!$target_id || is_wp_error($target_id)) {
                    $failed++;
                    continue;
                }
                $created++;
            }

            // Meta
            if (!empty($row['meta']) && is_array($row['meta'])) {
                $meta = $row['meta'];

                if (isset($meta['_sml_address'])) update_post_meta($target_id, '_sml_address', sanitize_text_field($meta['_sml_address']));
                if (isset($meta['_sml_phone'])) update_post_meta($target_id, '_sml_phone', sanitize_text_field($meta['_sml_phone']));
                if (isset($meta['_sml_email'])) update_post_meta($target_id, '_sml_email', sanitize_email($meta['_sml_email']));
                if (isset($meta['_sml_website'])) update_post_meta($target_id, '_sml_website', esc_url_raw($meta['_sml_website']));
                if (isset($meta['_sml_lat'])) update_post_meta($target_id, '_sml_lat', sanitize_text_field($meta['_sml_lat']));
                if (isset($meta['_sml_lng'])) update_post_meta($target_id, '_sml_lng', sanitize_text_field($meta['_sml_lng']));

                if (isset($meta['_sml_tech_array'])) {
                    $tech = $meta['_sml_tech_array'];
                    if (!is_array($tech)) $tech = [];
                    update_post_meta($target_id, '_sml_tech_array', array_values(array_map('sanitize_text_field', $tech)));
                }

                if (isset($meta['_sml_services_array'])) {
                    $srv = $meta['_sml_services_array'];
                    if (!is_array($srv)) $srv = [];
                    update_post_meta($target_id, '_sml_services_array', array_values(array_map('sanitize_text_field', $srv)));
                }
            }

            // Terms
            if (!empty($row['terms']['studio_category']) && is_array($row['terms']['studio_category'])) {
                $term_slugs = array_map('sanitize_title', $row['terms']['studio_category']);
                $term_ids = [];
                foreach ($term_slugs as $slug) {
                    $t = get_term_by('slug', $slug, 'studio_category');
                    if ($t && !is_wp_error($t)) $term_ids[] = (int) $t->term_id;
                }
                if (!empty($term_ids)) {
                    wp_set_object_terms($target_id, $term_ids, 'studio_category', false);
                }
            }

        } catch (Throwable $e) {
            $failed++;
        }
    }

    $url = add_query_arg([
        'sml_imported' => $created,
        'sml_updated'  => $updated,
        'sml_failed'   => $failed,
    ], admin_url('edit.php?post_type=studio&page=sml-import-export'));

    wp_safe_redirect($url);
    exit;
}


// ----------------------------------------------------------------
// 5. FRONTEND SUBMISSION
// ----------------------------------------------------------------
add_action('wp_ajax_sml_submit_studio', 'sml_handle_frontend_submission');
add_action('wp_ajax_nopriv_sml_submit_studio', 'sml_handle_frontend_submission');

function sml_handle_frontend_submission() {
    check_ajax_referer('sml_submission_nonce', 'security');
    $title = sanitize_text_field($_POST['name']); $addr = sanitize_text_field($_POST['address']);
    if(empty($title) || empty($addr)) wp_send_json_error(['message' => 'Bitte Name und Adresse ausfüllen.']);
    
    if ( ! function_exists( 'post_exists' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/post.php' );
    }
    
    if ( post_exists( $title, '', '', 'studio' ) ) wp_send_json_error(['message' => 'Ein Studio mit diesem Namen existiert bereits.']);
    $address_check = new WP_Query(['post_type' => 'studio', 'post_status' => ['publish', 'pending', 'draft'], 'meta_query' => [['key' => '_sml_address', 'value' => $addr, 'compare' => '=']], 'fields' => 'ids', 'posts_per_page' => 1]);
    if ( $address_check->have_posts() ) wp_send_json_error(['message' => 'Ein Studio an dieser Adresse ist bereits registriert.']);

    if (!empty($_FILES['studio_image']['name'])) {
        $file_size = $_FILES['studio_image']['size']; $file_type = $_FILES['studio_image']['type']; $allowed_types = ['image/jpeg', 'image/webp']; 
        if ($file_size > 300 * 1024) wp_send_json_error(['message' => 'Das Bild ist zu groß (Max. 300 KB).']);
        if (!in_array($file_type, $allowed_types)) wp_send_json_error(['message' => 'Nur JPG und WebP Dateien erlaubt.']);
    }

    $post_id = wp_insert_post(array('post_title'=>$title, 'post_type'=>'studio', 'post_status'=>'pending'));
    if($post_id) {
        update_post_meta($post_id, '_sml_address', $addr);
        update_post_meta($post_id, '_sml_phone', sanitize_text_field($_POST['phone']));
        update_post_meta($post_id, '_sml_email', sanitize_email($_POST['email']));
        update_post_meta($post_id, '_sml_website', esc_url_raw($_POST['website']));
        
        if(isset($_POST['tech']) && is_array($_POST['tech'])) {
            $tech_clean = array_map('sanitize_text_field', $_POST['tech']);
            update_post_meta($post_id, '_sml_tech_array', $tech_clean);
            update_post_meta($post_id, '_sml_tech', implode(', ', $tech_clean));
        }
        if(isset($_POST['services']) && is_array($_POST['services'])) {
            $serv_clean = array_map('sanitize_text_field', $_POST['services']);
            update_post_meta($post_id, '_sml_services_array', $serv_clean);
        }

        if(isset($_POST['cats']) && is_array($_POST['cats'])) { wp_set_object_terms($post_id, array_map('intval', $_POST['cats']), 'studio_category'); }
        if (!empty($_FILES['studio_image']['name'])) {
            require_once(ABSPATH . 'wp-admin/includes/image.php'); require_once(ABSPATH . 'wp-admin/includes/file.php'); require_once(ABSPATH . 'wp-admin/includes/media.php');
            $_FILES['studio_image']['name'] = sanitize_file_name($_FILES['studio_image']['name']);
            $attach_id = media_handle_upload('studio_image', $post_id);
            if (!is_wp_error($attach_id)) { set_post_thumbnail($post_id, $attach_id); }
        }
        
        $admin_email = get_option('admin_email');
        $subject = 'Neuer Studio-Eintrag: ' . $title;
        $message = "Ein neues Studio wurde eingetragen und wartet auf Überprüfung.\n\nName: $title\nAdresse: $addr\n\nHier prüfen: " . admin_url('post.php?post='.$post_id.'&action=edit');
        wp_mail($admin_email, $subject, $message);

        wp_send_json_success(['message' => 'Vielen Dank! Dein Studio wird nach Prüfung freigeschaltet.']);
    } else { wp_send_json_error(['message' => 'Fehler beim Speichern.']); }
}

// ----------------------------------------------------------------
// 6. FRONTEND DISPLAY
// ----------------------------------------------------------------
add_action('wp_enqueue_scripts', function() {
    wp_register_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
    wp_register_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), null, true);
    wp_enqueue_style('leaflet-css'); wp_enqueue_script('leaflet-js');
    // FIX FOR INCOGNITO: Explicitly load Dashicons
    wp_enqueue_style('dashicons');
});

function sml_shortcode_output() {
    $query = new WP_Query(array('post_type' => 'studio', 'posts_per_page' => -1, 'post_status' => 'publish'));
    $studios = array(); $all_tech_tags = array(); $all_service_tags = array();

    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $addr = get_post_meta(get_the_ID(), '_sml_address', true);
            $full_city = ''; $pure_city = '';
            if (preg_match('/(\d{5})\s+(.*)$/', $addr, $matches)) { $pure_city = trim(explode(',', $matches[2])[0]); $full_city = $matches[1] . ' ' . $pure_city; } 
            else { $parts = explode(',', $addr); $pure_city = trim(end($parts)); $full_city = $pure_city; }
            $cat_names = wp_get_post_terms(get_the_ID(), 'studio_category', array('fields' => 'names'));
            
            $tech_arr = get_post_meta(get_the_ID(), '_sml_tech_array', true);
            if(empty($tech_arr)) { $tech_raw = get_post_meta(get_the_ID(), '_sml_tech', true); $tech_arr = $tech_raw ? array_map('trim', explode(',', $tech_raw)) : []; }
            if(is_array($tech_arr)) { $all_tech_tags = array_merge($all_tech_tags, $tech_arr); }
            
            $service_arr = get_post_meta(get_the_ID(), '_sml_services_array', true);
            if(!is_array($service_arr)) $service_arr = [];
            if(is_array($service_arr)) { $all_service_tags = array_merge($all_service_tags, $service_arr); }

            $website = get_post_meta(get_the_ID(), '_sml_website', true);

            $studios[] = array(
                'id' => get_the_ID(), 'title' => get_the_title(), 'url' => $website,
                'lat' => floatval(get_post_meta(get_the_ID(), '_sml_lat', true)), 'lng' => floatval(get_post_meta(get_the_ID(), '_sml_lng', true)),
                'address' => $addr, 'city' => $pure_city, 'full_city' => $full_city,
                'phone' => get_post_meta(get_the_ID(), '_sml_phone', true), 'email' => get_post_meta(get_the_ID(), '_sml_email', true),
                'image' => get_the_post_thumbnail_url(get_the_ID(), 'medium'), 'cats' => $cat_names, 
                'tech' => $tech_arr, 'services' => $service_arr
            );
        }
        wp_reset_postdata();
    }
    
    $all_tech_tags = array_unique($all_tech_tags); sort($all_tech_tags);
    $all_service_tags = array_unique($all_service_tags); sort($all_service_tags);
    
    $tech_options_form = sml_get_standard_tech_options(); 
    $service_options_form = sml_get_service_options();
    $all_cats = get_terms(array('taxonomy' => 'studio_category', 'hide_empty' => false));
    $admin_email = get_option('admin_email');
    
    $json_studios = json_encode($studios, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

    ob_start();
    ?>
    <style>
        :root { --brand-blue: #1a93ee; --brand-dark: #333; --bg-gray: #f4f6f8; --btn-dark: #0f141a; --error-red: #dc3232; }
        #sml-container { max-width: 1200px; margin: 0 auto;  position: relative; box-sizing: border-box; }
        
        /* HEADER */
        #sml-beta-header{
            background: transparent;
            border: none;
            border-radius: 0;
            padding: 8px 0 6px 0;
            margin: 0 0 16px 0;
            display: flex;
            justify-content: flex-start;
            align-items: flex-start;
            gap: 18px;
            color: #0f141a;
            box-shadow: none;
        }
        .sml-bh-left{ min-width: 0; }
        .sml-bh-title-row{ display:flex; align-items:center; gap:12px; flex-wrap:wrap; margin:0 0 12px 0; }
        .sml-bh-left h2{
            margin: 0;
            font-size: 34px;
            line-height: 1.12;
            letter-spacing: -0.02em;
            font-weight: 600;
        }
        .sml-bh-badge{
            justify-content:center;
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 850;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            background: rgba(26,147,238,0.10);
            border: 1px solid rgba(26,147,238,0.28);
            color: #0b67b1;
        
            justify-content: center;
            line-height: 1;}
        .sml-bh-lead{
            margin: 0 0 10px 0;
            font-size: 15px;
            line-height: 1.6;
            color: #27313b;
            max-width: 820px;
        }
        .sml-bh-sub{
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
            color: #5b6670;
            max-width: 860px;
        }
        .sml-bh-actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            justify-content:flex-end;
            align-items:center;
            padding-top: 2px;
        }
        .sml-bh-primary{
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--brand-blue);
            border: none;
            border-radius: 999px;
            padding: 12px 18px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            line-height: 18.2px;
            color: rgb(255, 255, 255);
            text-decoration: none;
            white-space: nowrap;
        }
        .sml-bh-primary{
            background: var(--brand-blue);
            color: #ffffff;
            border: 1px solid rgba(26,147,238,0.20);
            box-shadow: none;
                        font-size: 14px;
            font-weight: 500;
            line-height: 18.2px;
            color: rgb(255, 255, 255);
        
            display: inline-flex;
            align-items: center;
            justify-content: center;}
        .sml-bh-primary:hover{ background:#137ecf; }
        .sml-bh-secondary{
            background: #ffffff;
            color: #0f141a;
            border: 1px solid rgba(15,20,26,0.14);
        }
        .sml-bh-secondary:hover{ transform: translateY(-1px); box-shadow: 0 12px 26px rgba(0,0,0,0.08); }
        @media (max-width: 860px){
            #sml-beta-header{ flex-direction: column; align-items: stretch; }
            .sml-bh-actions{ justify-content: flex-start; }
        }

        /* LEGAL FOOTER */
        #sml-footer{
            margin: 26px auto 0;
            padding: 16px 18px;
            border-radius: 14px;
            border: 1px solid rgba(15,20,26,0.10);
            background: #fff;
            max-width: 1200px;
            width: calc(100% - 0px);
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 12px;
            box-shadow: 0 10px 28px rgba(0,0,0,0.06);
        }
        #sml-footer .sml-footer-links{
            display:flex;
            gap: 12px;
            flex-wrap:wrap;
            align-items:center;
            justify-self: start;
        }
        #sml-footer .sml-footer-links a,
        #sml-footer .sml-footer-links button{
            background: transparent;
            border: none;
            padding: 0;
            margin: 0;
            font-size: 12px;
            color: #27313b;
            font-weight: 700;
            cursor:pointer;
            text-decoration: none;
        }
        #sml-footer .sml-footer-links a:hover,
        #sml-footer .sml-footer-links button:hover{
            text-decoration: underline;
        }
        #sml-footer .sml-footer-meta{
            font-size: 12px;
            color: #5b6670;
            text-align: center;
            justify-self: center;
        }
        #sml-footer .sml-footer-credit{
            font-size: 12px;
            color: #5b6670;
            text-align: right;
            justify-self: end;
            white-space: nowrap;
        }
        @media (max-width: 860px){
            #sml-footer{
                grid-template-columns: 1fr;
                text-align: center;
            }
            #sml-footer .sml-footer-links{ justify-content:center; justify-self:center; }
            #sml-footer .sml-footer-meta{ justify-self:center; }
            #sml-footer .sml-footer-credit{ justify-self:center; text-align:center; white-space: normal; }
        }

        
        .sml-footer-spacer{ text-align:center; }
        #sml-footer .sml-footer-links{ justify-self: start; }
        #sml-footer .sml-footer-credit{ justify-self: end; text-align: right; white-space: nowrap; }
        
        .sml-bh-notice{
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(26,147,238,0.18);
            background: rgba(26,147,238,0.08);
            color: #0f141a;
            font-size: 13px;
            line-height: 1.5;
        }
        .sml-bh-feedback{
            margin-top: 12px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            height: 44px;
            padding: 0 18px;
            border-radius: 999px;
            text-decoration: none;
        }
@media(max-width: 720px){
            #sml-footer{ grid-template-columns: 1fr; }
            #sml-footer .sml-footer-links{ justify-self: start; }
            #sml-footer .sml-footer-credit{ justify-self: start; text-align:left; white-space: normal; }
        }

        
        /* BUTTON TEXT CENTERING (GLOBAL) */
        button, .sml-submit-btn, #sml-search-btn, #sml-reset-btn{
            line-height: 1.1;
        }

        /* TABLE MAP ICON */
        .sml-btn-map .dashicons{
            font-size: 18px;
            width: 18px;
            height: 18px;
            line-height: 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .sml-table tbody{
            position: relative;
            z-index: 1;
        }

/* FEEDBACK MODAL */
        #sml-feedback-modal{
            display:none;
            position:fixed;
            inset:0;
            z-index:2147483647;
            background-color: rgba(0,0,0,0.60);
            width:100vw;
            height:100vh;
            align-items:center;
            justify-content:center;

            opacity: 0;
            pointer-events: none;
            transition: opacity 180ms ease;
        }
        #sml-feedback-modal.is-open{
            opacity: 1;
            pointer-events: auto;
        }
        .sml-feedback-modal{
            max-width: 640px;
            transform: translateY(10px) scale(0.98);
            opacity: 0;
            transition: transform 180ms ease, opacity 180ms ease;
        }
        #sml-feedback-modal.is-open .sml-feedback-modal{
            transform: translateY(0) scale(1);
            opacity: 1;
        }
        .sml-feedback-toggle{
            display:flex;
            gap:10px;
            margin: 14px 0 22px 0;
            padding: 6px;
            border-radius: 14px;
            background: #f4f6f8;
            border: 1px solid rgba(15,20,26,0.08);
        }

        .sml-feedback-form{ margin-top: 6px; }

        .sml-toggle-btn{
            display:flex;
            align-items:center;
            justify-content:center;
            flex:1;
            appearance:none;
            border: none;
            background: transparent;
            border-radius: 12px;
            padding: 10px 12px;
            font-weight: 700;
            font-size: 13px;
            cursor:pointer;
            color:#27313b;
        }
        .sml-toggle-btn.is-active{
            background: var(--brand-blue);
            color: #fff;
            border: none;
            box-shadow: 0 10px 22px rgba(26,147,238,0.18);
        }
        .sml-feedback-form label{
            display:block;
            margin: 0 0 6px 0;
            font-size: 12px;
            font-weight: 800;
            color:#27313b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .sml-feedback-grid{
            display:grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .sml-feedback-input,
        .sml-feedback-textarea{
            width:100%;
            box-sizing:border-box;
            border-radius: 12px;
            border: 1px solid rgba(15,20,26,0.14);
            background:#fff;
            padding: 12px 12px;
            font-size: 14px;
            outline:none;
        }
        .sml-feedback-input:focus,
        .sml-feedback-textarea:focus{
            border-color: rgba(26,147,238,0.65);
            box-shadow: 0 0 0 3px rgba(26,147,238,0.16);
        }
        .sml-feedback-actions{
            display:flex;
            gap: 12px;
            align-items:center;
            margin-top: 12px;
        }
        .sml-feedback-submit{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            appearance:none;
            border:none;
            border-radius: 12px;
            padding: 12px 16px;
            background: var(--brand-blue);
            color:#fff;
            font-weight: 800;
            cursor:pointer;
        }
        .sml-feedback-submit:hover{ filter: brightness(0.98); }
        .sml-feedback-status{ font-size: 13px; color:#5b6670; }
        @media(max-width: 720px){
            .sml-feedback-grid{ grid-template-columns: 1fr; }
        }

/* ADDITIONAL MODALS (Impressum / Privacy) */
        #sml-impressum-modal,
        #sml-privacy-modal{
            display: none;
            position: fixed;
            inset: 0;
            z-index: 2147483647;
            background-color: rgba(0,0,0,0.6);
            width: 100vw;
            height: 100vh;
            align-items: center;
            justify-content: center;
        }
        .sml-legal-modal .sml-modal-close{
            position:absolute;
            right: 16px;
            top: 14px;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid rgba(15,20,26,0.12);
            background: #fff;
            cursor:pointer;
            font-size: 18px;
            font-weight: 900;
            line-height: 1;
        }
        .sml-legal-modal h3{ margin: 0 0 10px 0; font-size: 18px; }
        .sml-legal-modal p{ margin: 0 0 10px 0; font-size: 13px; line-height: 1.55; color:#2f3b45; }
        .sml-legal-modal .sml-legal-grid{
            display:grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }
        .sml-legal-modal .sml-legal-box{
            background: #f6f8fa;
            border: 1px solid rgba(15,20,26,0.08);
            border-radius: 12px;
            padding: 12px 12px;
        }

        @media(max-width: 720px){
            #sml-beta-header{ padding: 20px 18px; }
            .sml-bh-left h2{ font-size: 22px; }
            .sml-bh-actions{ justify-content:flex-start; }}


        .sml-top-actions { display: flex; justify-content: flex-end; margin-bottom: 20px; }
        .sml-submit-btn { background: var(--btn-dark); color: #fff; border: none; padding: 0 24px; height: 46px; border-radius: 50px; font-weight: 600; cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 14px; transition: background 0.2s; line-height: 1; }
        .sml-submit-btn:hover { background: #333; }
        
        #sml-controls { background: #fff; padding: 25px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid #eee; display: grid; grid-template-columns: repeat(4, 1fr) auto; gap: 20px; align-items: end; margin-bottom: 30px; }
        .sml-group { display: flex; flex-direction: column; }
        .sml-group label { font-weight: 700; margin-bottom: 8px; color: var(--brand-dark); font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .sml-input, .sml-slider-box, .sml-multi-select, input#user-plz { width: 100%; height: 50px !important; box-sizing: border-box; border: 2px solid #eee !important; border-radius: 8px !important; font-size: 15px; background: #fff; appearance: none; -webkit-appearance: none; padding: 0 40px 0 15px; line-height: normal; }
        .sml-input:focus { border-color: var(--brand-blue); outline: none; }
        .sml-input-wrapper { position: relative; width: 100%; }
        
        /* Loading Animation CSS for Geo Button */
        #sml-geo-btn { position: absolute; right: 5px; top: 0; bottom: 0; background: none; border: none; cursor: pointer; padding: 0 10px; color: #aaa; display: flex; align-items: center; transition: color 0.3s; }
        #sml-geo-btn:hover { color: var(--brand-blue); }
        #sml-geo-btn svg { width: 22px; height: 22px; fill: currentColor; }
        #sml-geo-btn.loading svg { animation: spin 1s linear infinite; }
        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* ONBOARDING BUBBLE (BELOW) */
        #sml-geo-hint {
            position: absolute; top: calc(100% + 15px); left: 0; bottom: auto;
            background: #333; color: #fff; padding: 8px 14px;
            border-radius: 6px; font-size: 12px; font-weight: 600;
            opacity: 0; transform: translateY(-10px);
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            pointer-events: none; z-index: 100;
            white-space: nowrap; box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        #sml-geo-hint::after {
            content: ""; position: absolute; bottom: 100%; top: auto; left: 20px;
            margin-left: -5px; border-width: 5px; border-style: solid;
            border-color: transparent transparent #333 transparent;
        }
        #sml-geo-hint.visible { opacity: 1; transform: translateY(0); }

        .sml-slider-box { display: flex; align-items: center; padding: 0 15px; }
        input[type=range]#search-radius { -webkit-appearance: none; width: 100%; background: transparent; margin: 0; }
        input[type=range]#search-radius:focus { outline: none; }
        input[type=range]#search-radius::-webkit-slider-runnable-track { width: 100%; height: 5px; background: #e0e0e0; border-radius: 5px; }
        input[type=range]#search-radius::-webkit-slider-thumb { height: 20px; width: 20px; border-radius: 50%; background: var(--brand-blue); -webkit-appearance: none; margin-top: -8px; box-shadow: 0 2px 6px rgba(0,0,0,0.2); border: 2px solid #fff; }
        
        .sml-multi-select { position: relative; cursor: pointer; display: flex; align-items: center; padding: 0 15px; user-select: none; }
        .sml-ms-placeholder { color: #555; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex-grow: 1; }
        .sml-ms-arrow { font-size: 18px; color: #333; font-weight: bold; margin-left: 10px; }
        .sml-ms-dropdown { display: none; position: absolute; top: 105%; left: 0; right: 0; background: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); z-index: 1000; max-height: 250px; overflow-y: auto; padding: 10px; }
        .sml-ms-dropdown label { display: block; padding: 5px; margin: 0; font-weight: normal; font-size: 13px; color: #333; cursor: pointer; text-transform: none; letter-spacing: 0; }
        .sml-ms-dropdown label:hover { background: #f5f5f5; border-radius: 4px; }
        .sml-ms-dropdown.show { display: block; }
        
        .sml-ms-dropdown::-webkit-scrollbar, 
        .sml-table-scroll-wrapper::-webkit-scrollbar,
        #sml-list::-webkit-scrollbar { width: 6px; height: 6px; }
        .sml-ms-dropdown::-webkit-scrollbar-track,
        .sml-table-scroll-wrapper::-webkit-scrollbar-track,
        #sml-list::-webkit-scrollbar-track { background: transparent; }
        .sml-ms-dropdown::-webkit-scrollbar-thumb,
        .sml-table-scroll-wrapper::-webkit-scrollbar-thumb,
        #sml-list::-webkit-scrollbar-thumb { background-color: #ccc; border-radius: 6px; }
        
        #sml-search-btn { background: var(--brand-blue); color: #fff; border: none; padding: 0 30px; font-size: 16px; font-weight: 600; border-radius: 50px; cursor: pointer; height: 50px; box-shadow: 0 4px 10px rgba(26, 147, 238, 0.2); white-space: nowrap; display: flex; align-items: center; justify-content: center; line-height: 1; }
        #sml-search-btn:hover { background: #137ecf; }
        #sml-wrapper { display: flex; gap: 25px; height: auto; min-height: 600px; margin-bottom: 50px; }
        #sml-map { flex: 2; border-radius: 12px; border: 1px solid #ddd; z-index: 1; box-shadow: 0 4px 15px rgba(0,0,0,0.05); min-height: 100%; position: relative; }
        #sml-list { flex: 1; overflow-y: auto; max-height: 600px; background: #fff; border: 1px solid #eee; border-radius: 12px; display: flex; flex-direction: column; scroll-behavior: smooth; }
        
        .sml-list-head { padding: 0 20px; height: 50px; min-height: 50px; max-height: 50px; background: #fff; border-bottom: 1px solid #eee; font-weight: 700; font-size: 16px; color: var(--brand-dark); position: sticky; top: 0; z-index: 10; display: flex; align-items: center; }
        #list-content { padding: 0 15px 15px !important; background: var(--bg-gray); flex-grow: 1; margin-top: 0 !important; }
        
        .studio-item { margin-top: 10px; position: relative; background: #fff; padding: 15px; border-radius: 10px; margin-bottom: 12px; cursor: pointer; border: 1px solid #eee; box-shadow: 0 2px 5px rgba(0,0,0,0.02); display: flex; gap: 15px; align-items: flex-start; text-align: left; transition: all 0.3s ease; }
        .studio-item:first-child { margin-top: 20px !important; }
        .studio-item:hover { border-color: var(--brand-blue); background: #fff; box-shadow: 0 8px 20px rgba(0,0,0,0.08); }
        .studio-item.active-card { border-left: 5px solid var(--brand-blue); background: #f0f7ff; box-shadow: 0 8px 20px rgba(26, 147, 238, 0.15); }
        .studio-item::after { content: attr(data-cat); position: absolute; top: -10px; right: 10px; background: #222; color: #fff; font-size: 10px; padding: 4px 8px; border-radius: 4px; opacity: 0; transition: all 0.2s; pointer-events: none; font-weight: 600; text-transform: uppercase; transform: translateY(5px); }
        .studio-item[data-cat=""]::after { display: none; }
        .studio-item:hover::after { opacity: 1; transform: translateY(0); }
        .studio-thumb { width: 70px; height: 70px; flex-shrink: 0; background-color: #f0f0f0; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; color: #ccc; }
        .studio-thumb img { width: 100%; height: 100%; object-fit: contain; padding: 4px; background-color: #fff; display: block; }
        .studio-info { flex-grow: 1; display: flex; flex-direction: column; justify-content: center; }
        .studio-item h4 { margin: 0 0 6px 0; color: var(--brand-dark); font-size: 16px; font-weight: 700; line-height: 1.2; }
        .studio-item .addr { font-size: 13px; color: #666; margin-bottom: 8px; }
        .studio-item .dist { display: inline-block; background: #eef7ff; color: var(--brand-blue); font-weight: 700; font-size: 11px; padding: 3px 8px; border-radius: 4px; align-self: center; }
        
        .leaflet-popup-content-wrapper { border-radius: 8px; padding: 0; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.15); border: none; background: #fff; }
        .leaflet-popup-content { margin: 0; width: 280px !important; line-height: 1.5; }
        .leaflet-container a.leaflet-popup-close-button { top: 10px; right: 10px; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,0.3); z-index: 30; }
        .sml-popup-header-img { height: 80px; background-size: contain; background-position: center; background-repeat: no-repeat; background-color: #ffffff; border-bottom: 1px solid #eee; margin-bottom: 0; position: relative; }
        .sml-popup-no-img { height: 50px; background: var(--brand-blue); display:flex; align-items:center; justify-content:center; }
        .sml-popup-body { padding: 15px; }
        .sml-popup-title { font-size: 16px; font-weight: 700; color: var(--brand-dark); margin-bottom: 2px; }
        .sml-popup-cats-line { font-size: 11px; color: #888; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .sml-popup-addr { font-size: 12px; color: #666; margin-bottom: 12px; border-bottom: 1px solid #eee; padding-bottom: 12px; }
        .sml-popup-footer { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .sml-popup-route { width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; background: #f0f0f0; border-radius: 50%; color: #555 !important; text-decoration: none; flex-shrink: 0; }
        .sml-popup-route svg { width: 18px; height: 18px; fill: currentColor; }
        .sml-popup-main-btn { flex-grow: 1; text-align: center; background: var(--brand-blue); color: #fff !important; text-decoration: none; font-size: 12px; font-weight: 600; padding: 7px 0; border-radius: 50px; transition: background 0.2s; cursor: pointer; }
        .sml-popup-main-btn:hover { background: #137ecf; }
        .sml-popup-web-link { font-size: 12px; font-weight: 600; color: var(--brand-blue) !important; text-decoration: none; padding-left: 10px; border-left: 1px solid #ddd; white-space: nowrap; }
        
        /* GHOST ACTIONS (UNIFIED) */
        .sml-row-actions { margin-top: 4px; display: inline-block; margin-left: 0; }
        .sml-ghost-btn {
            color: #d0d0d0; opacity: 0; transition: all 0.2s; font-size: 12px; text-decoration: none; cursor: pointer; margin-right: 5px; display: inline-flex; align-items: center;
        }
        .sml-ghost-btn span.dashicons { font-size: 13px; width: 13px; height: 13px; line-height: 1; }
        .sml-btn-map span.dashicons{ font-size: 15px; width: 15px; height: 15px; }

        .studio-table-row:hover .sml-ghost-btn { opacity: 1; }
        .sml-btn-flag:hover { color: #dc3232 !important; }
        
        /* Always visible map icon */
        .sml-btn-map { opacity: 1 !important; color: #ccc; }
        .sml-btn-map:hover { color: var(--brand-blue) !important; }

        
        .sml-btn-map .dashicons{ font-size: 18px; width: 18px; height: 18px; }
/* DIRECT CONTACT DISPLAY (CLEANED, NO LIFT, 15.2px) */
        .sml-secure-contact {
            color: #555;
            text-decoration: none !important; /* NO UNDERLINE */
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 15.2px !important; /* FIXED SIZE MATCHING TABLE */
            transition: color 0.2s ease;
            margin-bottom: 2px;
            /* Prevent liftup effect */
            transform: translate(0, 0) !important;
            vertical-align: middle;
            box-shadow: none !important;
        }
        .sml-secure-contact:hover { 
            color: var(--brand-blue); 
            text-decoration: none !important;
            transform: none !important;
            box-shadow: none !important;
        }
        .sml-secure-contact .dashicons { color: #999; font-size: 15px; width: 15px; height: 15px; display: flex; align-items: center; }
        .sml-secure-contact:hover .dashicons { color: var(--brand-blue); }

        /* Mobile override */
        @media(max-width: 900px) { .sml-ghost-btn { opacity: 1 !important; color: #eee !important; } }

        /* USER POPUP & FADE */
        .sml-user-popup-head { background: #f8f8f8; color: #333; padding: 12px 15px; font-weight: 700; border-bottom: 1px solid #eee; font-size: 14px; }
        .sml-user-popup-body { padding: 15px; text-align: center; color: #666; font-size: 13px; }
        .leaflet-popup-content-wrapper:has(.sml-user-popup-head) .leaflet-popup-close-button { color: #888; text-shadow: none; }
        
        .leaflet-popup.sml-fade-out { transition: opacity 0.5s ease-out; opacity: 0; }

        img.sml-marker-out { opacity: 0.5 !important; filter: none !important; }
        img.sml-marker-in { opacity: 1 !important; filter: none !important; }
        
        #sml-table-container { margin-top: 40px; }
        .sml-table-search-wrap { position: relative; margin-bottom: 30px; width: 100%; }
        .sml-table-search-wrap svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); width: 18px; height: 18px; fill: #999; }
        #sml-table-filter { width: 100%; padding: 12px 15px 12px 40px; border: 1px solid #ddd; border-radius: 8px; font-size: 16px; box-sizing: border-box; }
        .sml-table-scroll-wrapper { width: 100%; overflow-y: auto; overflow-x: auto; -webkit-overflow-scrolling: touch; max-height: 800px; border: 1px solid #eee; border-radius: 8px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); }
        .sml-table { width: 100%; min-width: 1000px; border-collapse: separate; border-spacing: 0; background: #fff; font-size: 14.5px !important; table-layout: auto; }
        
        /* INCREASED PADDING TO 15px */
        .sml-table th { font-size: 14.5px !important; background: #f4f6f8; text-align: left; padding: 15px; color: #555; font-weight: 700; border-bottom: 1px solid #eee; cursor: pointer; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 0 rgba(0,0,0,0.05); background-clip: padding-box; }
        .sml-table tbody { position: relative; z-index: 1; }
        .sml-table th.sml-sortable:hover { background: #eef2f5; color: var(--brand-blue); }
        .sml-table th.sml-sortable::after { content: " \21C5"; opacity: 0.4; font-size: 1.1em; margin-left: 5px; font-weight: normal; vertical-align: middle; }
        
        /* INCREASED PADDING TO 15px */
        .sml-table td { font-size: 14.5px !important; padding: 15px; border-bottom: 1px solid #eee; color: #444; vertical-align: middle; word-wrap: break-word; }
        .sml-table tr:hover td { background: #f9fbff; }
        .sml-table a { font-size: 14.5px !important; color: var(--brand-blue); text-decoration: none; font-weight: 500; }
        
        /* VISIT LINK SIZE (Matched Global) */
        .sml-table td:last-child a { font-size: 15.2px !important; }
        
        .sml-table-tech { font-size: 11px; color: #777; line-height: 1.4; }
        
        .sml-badge-container { position: relative; display: inline-block; }
        .sml-badge { background: #f0f7ff; color: var(--brand-blue); font-weight: 600; font-size: 11px; padding: 4px 10px; border-radius: 20px; cursor: pointer; border: 1px solid #dceeff; white-space: nowrap; }
        .sml-badge:hover { background: var(--brand-blue); color: #fff; border-color: var(--brand-blue); }
        .sml-badge-container .sml-badge-popup { display: none !important; }

        #sml-tooltip-fixed {
            position: fixed; z-index: 2147483647; background: #fff;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15); border-radius: 8px; 
            padding: 15px; border: 1px solid #eee; font-size: 12px; color: #333; 
            line-height: 1.5; width: 280px; pointer-events: none; display: none;
            white-space: normal; text-align: left;
        }
        #sml-tooltip-fixed::after { content: ""; position: absolute; top: 100%; left: 50%; margin-left: -6px; border-width: 6px; border-style: solid; border-color: #fff transparent transparent transparent; }

        /* MAP TOAST NOTIFICATION */
        #sml-map-toast {
            position: absolute; top: 20px; left: 50%; transform: translateX(-50%);
            background: rgba(255, 255, 255, 0.95); padding: 8px 16px; border-radius: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15); z-index: 1000;
            font-size: 13px; font-weight: 700; color: var(--brand-blue);
            opacity: 0; pointer-events: none; transition: opacity 0.3s;
            display: flex; align-items: center; gap: 6px;
        }

        .sml-table th:nth-child(1), .sml-table td:nth-child(1) { width: 1%; white-space: nowrap; }
        .sml-table th:nth-child(2), .sml-table td:nth-child(2) { width: 15%; white-space: normal; }
        .sml-table th:nth-child(3), .sml-table td:nth-child(3),
        .sml-table th:nth-child(4), .sml-table td:nth-child(4) { width: 1%; white-space: nowrap; }
        .sml-table th:nth-child(5), .sml-table td:nth-child(5) { width: auto; white-space: normal; word-break: break-word;}
        .sml-table th:last-child, .sml-table td:last-child { width: 1%; white-space: nowrap; text-align: center; padding-left: 25px; padding-right: 15px; }
        
        #sml-reset-btn { background: var(--brand-blue); color: #fff; border: none; padding: 0 30px; font-size: 14px; font-weight: 600; border-radius: 50px; cursor: pointer; height: 50px; box-shadow: 0 4px 10px rgba(26, 147, 238, 0.2); white-space: nowrap; display: flex; align-items: center; justify-content: center; line-height: 1; }
        #sml-reset-btn:hover { background: #137ecf; }

        #sml-modal { display: none; position: fixed; inset: 0; z-index: 2147483647; background-color: rgba(0,0,0,0.6); align-items: center; justify-content: center; backdrop-filter: none; }
        .sml-modal-content { background-color: #fff; padding: 40px; border: none; width: 90%; max-width: 600px; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.5); position: relative; animation: smlFadeIn 0.3s; max-height: 90vh; overflow-y: auto; box-sizing: border-box; margin: auto; }
        body.sml-modal-open { overflow: hidden; height: 100vh; }
        .sml-modal-content::-webkit-scrollbar { width: 6px; }
        .sml-modal-content::-webkit-scrollbar-track { background: transparent; margin: 15px 0; }
        .sml-modal-content::-webkit-scrollbar-thumb { background-color: #ccc; border-radius: 10px; }
        @keyframes smlFadeIn { from {opacity: 0; transform: scale(0.95);} to {opacity: 1; transform: scale(1);} }
        .sml-close-modal { color: #888; position: absolute; top: 20px; right: 20px; font-size: 28px; cursor: pointer; line-height: 1; }
        .sml-upload-zone { border: 2px dashed var(--brand-blue); border-radius: 8px; padding: 15px; text-align: center; background: #f0f7ff; color: var(--brand-blue); cursor: pointer; position: relative; transition: all 0.2s; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .sml-upload-zone:hover { background: #e0f0ff; }
        .sml-upload-zone input { position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 2; }
        .sml-upload-content { pointer-events: none; display: flex; flex-direction: column; align-items: center; }
        .sml-upload-text { font-size: 13px; font-weight: 600; margin-top: 5px; }
        .sml-upload-hint { font-size: 11px; color: #666; margin-top: 6px; text-align: center; display: block; font-style: italic; }
        .sml-form-group { margin-bottom: 15px; }
        .sml-form-group label { display: block; margin-bottom: 6px; font-weight: 600; color: #333; font-size: 14px; }
        .sml-form-input { width: 100%; padding: 12px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; font-size: 15px; }
        .sml-form-tech-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; max-height: 180px; overflow-y: auto; border: 1px solid #eee; padding: 12px; border-radius: 6px; background: #fcfcfc; }
        .sml-form-tech-grid label { font-weight: normal; font-size: 13px; display: flex; align-items: center; gap: 6px; margin: 0; cursor: pointer; }
        .sml-gdpr-check { margin: 25px 0 25px; font-size: 14px !important; display: flex; gap: 8px; align-items: flex-start; }
        .sml-gdpr-check input { margin-top: 3px; }
        #sml-submit-form-btn { background: var(--brand-blue); color: white; border: none; padding: 0 30px; height: 50px; border-radius: 50px; font-size: 16px; font-weight: bold; cursor: pointer; width: 100%; margin-top: 10px; transition: background 0.2s; display: flex; align-items: center; justify-content: center; line-height: 1; }
        #sml-submit-form-btn:hover { background: #137ecf; }
        #sml-submit-form-btn:disabled { background: #ccc; cursor: not-allowed; }
        
        .sml-input-error { border-color: var(--error-red) !important; background-color: #fff8f8 !important; }
        .sml-error-text { color: var(--error-red); font-size: 12px; margin-top: 4px; display: block; font-weight: 600; }

        @media(max-width: 900px) { 
            #sml-controls { grid-template-columns: 1fr; } 
            #sml-wrapper { flex-direction: column !important; height: auto !important; } 
            #sml-map { height: 400px !important; min-height: 400px !important; width: 100% !important; } 
            #sml-list { max-height: 600px; width: 100% !important; } 
            .sml-table-scroll-wrapper { max-height: 600px; }
            .sml-table th, .sml-table td { font-size: 13px; padding: 10px; }
        }
    </style>

    <div id="sml-container">
        
        <div id="sml-beta-header" role="banner">
            <div class="sml-bh-left">
                <div class="sml-bh-title-row">
                    <h2>Studio-Finder</h2>
                    <span class="sml-bh-badge">BETA</span>
                </div>
                <p class="sml-bh-lead">
                    Finde Synchronstudios, Tonstudios &amp; Agenturen in Deiner Nähe, filtere nach Leistungen und Ausstattung und plane Deine Route zum Studio in Sekunden.
                </p>

                <div class="sml-bh-notice" role="note" aria-label="Beta Hinweis">
                    <strong>Hinweis:</strong> Studio Finder ist in der Beta - danke fürs Mithelfen beim Verbessern.
                </div>

                <button type="button" class="sml-bh-primary sml-bh-feedback" onclick="smlOpenFeedbackModal()">Idee/Bug melden</button>
            </div>
        </div>

<div class="sml-top-actions">
            <button class="sml-submit-btn" onclick="openModal()"><span class="dashicons dashicons-plus"></span> Studio eintragen</button>
        </div>

        <div id="sml-controls">
            <div class="sml-group">
                <label>Dein Standort</label>
                <div class="sml-input-wrapper">
                    <input type="text" id="user-plz" class="sml-input loc-input" placeholder="PLZ, Stadt oder GPS" />
                    <button id="sml-geo-btn" title="Standort verwenden" type="button"><svg viewBox="0 0 24 24"><path d="M12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm8.94 3c-.46-4.17-3.77-7.48-7.94-7.94V1h-2v2.06C6.83 3.52 3.52 6.83 3.06 11H1v2h2.06c.46 4.17 3.77 7.48 7.94 7.94V23h2v-2.06c4.17-.46 7.48-3.77 7.94-7.94H23v-2h-2.06zM12 19c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z"/></svg></button>
                    <div id="sml-geo-hint">📍 Tipp: Für exakte Ergebnisse hier orten</div>
                </div>
            </div>
            <div class="sml-group"> 
                <label>Umkreis: <span id="radius-val" style="color:var(--brand-blue)">50 km</span></label>
                <div class="sml-slider-box">
                    <input type="range" id="search-radius" min="10" max="600" value="50"> 
                </div>
            </div>
            
            <div class="sml-group">
                <label>Leistung</label>
                <div class="sml-multi-select" id="sml-service-filter">
                    <span class="sml-ms-placeholder" id="sml-service-ph">Leistung wählen...</span>
                    <span class="sml-ms-arrow">▾</span>
                    <div class="sml-ms-dropdown" id="sml-service-dropdown">
                        <?php foreach($all_service_tags as $s_tag): ?>
                            <label><input type="checkbox" value="<?php echo esc_attr($s_tag); ?>" onchange="updateServiceFilter()"> <?php echo esc_html($s_tag); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="sml-group">
                <label>Ausstattung</label>
                <div class="sml-multi-select" id="sml-multi-filter">
                    <span class="sml-ms-placeholder" id="sml-tech-ph">Ausstattung wählen...</span>
                    <span class="sml-ms-arrow">▾</span>
                    <div class="sml-ms-dropdown" id="sml-tech-dropdown">
                        <?php foreach($all_tech_tags as $tech_tag): ?>
                            <label><input type="checkbox" value="<?php echo esc_attr($tech_tag); ?>" onchange="updateMultiFilter()"> <?php echo esc_html($tech_tag); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="sml-group">
                <button id="sml-reset-btn">Zurücksetzen</button>
            </div>
        </div>

        <div id="sml-wrapper">
            <div id="sml-map">
                 <div id="sml-map-toast"><span class="dashicons dashicons-location-alt"></span> <span id="sml-toast-text">0 Studios</span></div>
            </div>
            <div id="sml-list">
                <div class="sml-list-head" id="list-header">Ergebnisse</div>
                <div id="list-content" style="color:#777; text-align:center; padding-top:40px;">
                    <p>Gib deinen Standort ein,<br>um Studios zu finden.</p>
                </div>
            </div>
        </div>

        <div id="sml-table-container">
            <h3 style="margin-bottom: 20px; color: var(--brand-dark);">Verzeichnis-Übersicht</h3>
            <div class="sml-table-search-wrap">
                <svg viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                <input type="text" id="sml-table-filter" placeholder="Filtere die Tabelle nach Name, Stadt..." onkeyup="filterTableOnly()">
            </div>
            
            <div class="sml-table-scroll-wrapper">
                <table class="sml-table" id="studio-table">
                    <thead>
                        <tr>
                            <th class="sml-sortable" onclick="sortHTMLTable(0)">Name</th>
                            <th class="sml-sortable" onclick="sortHTMLTable(1)">Stadt</th>
                            <th>Leistungen</th>
                            <th>Ausstattung</th>
                            <th>Kontakt</th>
                            <th>Webseite</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($studios as $s): ?>
                        <tr id="studio-row-<?php echo $s['id']; ?>" class="studio-table-row" data-tech="<?php echo esc_attr(implode(',', $s['tech'])); ?>" data-services="<?php echo esc_attr(implode(',', $s['services'])); ?>">
                            <td data-label="Name"><strong><?php echo esc_html($s['title']); ?></strong>
                            <div class="sml-row-actions">
                                <a href="mailto:<?php echo esc_attr($admin_email); ?>?subject=Änderungswunsch Studio: <?php echo rawurlencode($s['title']); ?>&body=Hallo, ich möchte eine Änderung für das Studio '<?php echo esc_attr($s['title']); ?>' (ID: <?php echo $s['id']; ?>) melden:%0D%0A%0D%0A" class="sml-ghost-btn sml-btn-flag" title="Eintrag melden/ändern"><span class="dashicons dashicons-flag"></span></a>
                            </div>
                            </td>
                            <td data-label="Stadt"><?php echo htmlspecialchars($s['city']); ?>
                                <div class="sml-row-actions">
                                    <a href="#" onclick="jumpToMap(<?php echo $s['id']; ?>); return false;" class="sml-ghost-btn sml-btn-map" title="Auf Karte zeigen"><span class="dashicons dashicons-location"></span></a>
                                </div>
                            </td>
                            
                            <td data-label="Leistungen" class="sml-table-tech">
                                <?php 
                                    $count = count($s['services']);
                                    if($count > 0) {
                                        echo '<div class="sml-badge-container">';
                                        echo '<span class="sml-badge">' . $count . ' Leistungen</span>';
                                        echo '<div class="sml-badge-popup"><strong>Leistungen:</strong><br>' . esc_html(implode(', ', $s['services'])) . '</div>';
                                        echo '</div>';
                                    } else {
                                        echo '<span style="color:#ccc; font-size:11px;">-</span>';
                                    }
                                ?>
                            </td>
                            
                            <td data-label="Ausstattung" class="sml-table-tech">
                                <?php 
                                    $count = count($s['tech']);
                                    if($count > 0) {
                                        echo '<div class="sml-badge-container">';
                                        echo '<span class="sml-badge">' . $count . ' Ausstattung</span>';
                                        echo '<div class="sml-badge-popup"><strong>Ausstattung:</strong><br>' . esc_html(implode(', ', $s['tech'])) . '</div>';
                                        echo '</div>';
                                    } else {
                                        echo '<span style="color:#ccc; font-size:11px;">-</span>';
                                    }
                                ?>
                            </td>
                            
                            <td data-label="Kontakt">
                                <?php $b64_phone = $s['phone'] ? base64_encode($s['phone']) : ''; ?>
                                <?php $b64_email = $s['email'] ? base64_encode($s['email']) : ''; ?>
                                
                                <?php if($b64_phone): ?>
                                    <a href="#" class="sml-secure-contact" data-val="<?php echo $b64_phone; ?>" data-type="tel">
                                        <span class="dashicons dashicons-phone"></span>...
                                    </a><br>
                                <?php endif; ?>

                                <?php if($b64_email): ?>
                                    <a href="#" class="sml-secure-contact" data-val="<?php echo $b64_email; ?>" data-type="mail">
                                        <span class="dashicons dashicons-email-alt"></span>...
                                    </a>
                                <?php endif; ?>
                            </td>

                            <td data-label="Webseite"><?php if($s['url']): ?><a href="<?php echo esc_url($s['url']); ?>" target="_blank">Besuchen</a><?php else: ?><span style="color:#ccc; font-size:12px;">n/a</span><?php endif; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="sml-modal">
            <div class="sml-modal-content">
                <span class="sml-close-modal" onclick="closeModal()">×</span>
                <h2 style="margin-top:0; margin-bottom:20px; color:var(--brand-dark);">Neues Studio eintragen</h2>
                
                <form id="sml-submission-form" enctype="multipart/form-data">
                    <?php wp_nonce_field('sml_submission_nonce', 'security'); ?>
                    
                    <div class="sml-form-group">
                        <label>Name des Studios *</label>
                        <input type="text" name="name" class="sml-form-input sml-live-check" data-type="title" required placeholder="Name eingeben...">
                    </div>

                    <div class="sml-form-group">
                        <label>Adresse *</label>
                        <input type="text" name="address" class="sml-form-input sml-live-check" data-type="address" required placeholder="Straße, PLZ Stadt">
                        <small style="color:#888; display:block; margin-top:4px;">Wird für die Kartenposition verwendet.</small>
                    </div>

                    <div class="sml-row">
                        <div class="sml-col">
                            <div class="sml-form-group">
                                <label>Telefon</label>
                                <input type="text" name="phone" class="sml-form-input">
                            </div>
                        </div>
                        <div class="sml-col">
                            <div class="sml-form-group">
                                <label>E-Mail</label>
                                <input type="email" name="email" class="sml-form-input">
                            </div>
                        </div>
                    </div>

                    <div class="sml-form-group">
                        <label>Webseite</label>
                        <input type="url" name="website" class="sml-form-input" placeholder="https://...">
                    </div>

                    <div class="sml-form-group">
                        <label>Kategorie</label>
                        <div class="sml-form-tech-grid">
                            <?php foreach($all_cats as $cat): ?>
                                <label><input type="checkbox" name="cats[]" value="<?php echo $cat->term_id; ?>"> <?php echo esc_html($cat->name); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="sml-form-group">
                        <label>Leistungen</label>
                        <div class="sml-form-tech-grid">
                            <?php foreach($service_options_form as $opt): ?>
                                <label><input type="checkbox" name="services[]" value="<?php echo esc_attr($opt); ?>"> <?php echo esc_html($opt); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="sml-form-group">
                        <label>Technik & Ausstattung</label>
                        <div class="sml-form-tech-grid">
                            <?php foreach($tech_options_form as $opt): ?>
                                <label><input type="checkbox" name="tech[]" value="<?php echo esc_attr($opt); ?>"> <?php echo esc_html($opt); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="sml-form-group">
                        <label>Bild hochladen (Max 300KB)</label>
                        <div class="sml-upload-zone">
                            <input type="file" name="studio_image" accept="image/jpeg,image/webp" onchange="updateFileName(this)">
                            <div class="sml-upload-content">
                                <span class="dashicons dashicons-upload" style="font-size:30px; height:30px; width:30px; margin-bottom:5px;"></span>
                                <span class="sml-upload-text">Bild auswählen (JPG/WebP)</span>
                                <span class="sml-upload-hint">Klicken oder hierher ziehen</span>
                            </div>
                        </div>
                    </div>

                    <div class="sml-gdpr-check">
                        <input type="checkbox" required>
                        <span>Ich stimme zu, dass diese Daten gespeichert und veröffentlicht werden.</span>
                    </div>

                    <p id="sml-form-msg" style="font-weight:bold; text-align:center; margin-bottom:10px;"></p>
                    <button type="submit" id="sml-submit-form-btn">Jetzt kostenlos eintragen</button>
                </form>
            </div>
        </div>
    </div>
    
    <div id="sml-tooltip-fixed"></div>

    <div id="sml-footer">
        <div class="sml-footer-links">
            <button type="button" onclick="smlOpenImpressumModal()">Impressum</button>
            <button type="button" onclick="smlOpenPrivacyModal()">Datenschutz</button>
            <button type="button" onclick="smlOpenFeedbackModal()">Feedback</button>
        </div>
        <div class="sml-footer-spacer" aria-hidden="true"></div>
        <div class="sml-footer-credit">
            Bereitgestellt von Sprecher Pascal Krell &copy; 2026
        </div>
    </div>

    <!-- Impressum Modal -->
    <div id="sml-impressum-modal" class="sml-modal" aria-hidden="true">
        <div class="sml-modal-content sml-legal-modal" role="dialog" aria-modal="true" aria-label="Impressum">
            <button type="button" class="sml-modal-close" onclick="smlCloseImpressumModal()" aria-label="Schließen">×</button>
            <h3>Impressum</h3>
            <div class="sml-legal-grid">
                <div class="sml-legal-box">
                    <p><strong>Angaben gemäß §5 DDG</strong></p>
                    <p>Pascal Krell<br>Hohe Str. 34<br>21073 Hamburg<br>Deutschland</p>
                </div>
                <div class="sml-legal-box">
                    <p><strong>Kontakt</strong></p>
                    <p>Telefon: 0160 32 48 536<br>E-Mail: kontakt@sprecher-pascal.de</p>
                </div>
                <div class="sml-legal-box">
                    <p><strong>Umsatzsteuer-ID</strong></p>
                    <p>USt-IdNr. gemäß § 27 a UStG: DE363294652</p>
                </div>
                <div class="sml-legal-box">
                    <p><strong>Redaktionell verantwortlich</strong></p>
                    <p>Pascal Krell, Hohe Str. 34, 21073 Hamburg</p>
                </div>
            </div>
            </div>
    </div>

    <!-- Privacy Modal -->
    <div id="sml-privacy-modal" class="sml-modal" aria-hidden="true">
        <div class="sml-modal-content sml-legal-modal" role="dialog" aria-modal="true" aria-label="Datenschutz">
            <button type="button" class="sml-modal-close" onclick="smlClosePrivacyModal()" aria-label="Schließen">×</button>
            <h3>Datenschutz (DSGVO)</h3>

            <div class="sml-legal-grid">
                <div class="sml-legal-box">
                    <p><strong>Welche Daten verarbeitet werden</strong></p>
                    <p>
                        Der Studio Finder verarbeitet deine <strong>Standorteingabe</strong> (Adresse/Stadt) und optional deine <strong>Browser-Geolocation</strong>
                        (nur wenn du ausdrücklich zustimmst), um Distanzen zu berechnen und Studios auf der Karte anzuzeigen.
                        Geolocation-Koordinaten werden nur für die aktuelle Nutzung verwendet und durch dieses System nicht dauerhaft gespeichert.
                    </p>
                </div>

                <div class="sml-legal-box">
                    <p><strong>Lokale Speicherung</strong></p>
                    <p>
                        Zur besseren Nutzung können deine letzten Sucheinstellungen lokal im Browser gespeichert werden.
                        Du kannst diese Daten jederzeit über die Browser-Einstellungen löschen.
                    </p>
                </div>

                <div class="sml-legal-box">
                    <p><strong>Studio eintragen</strong></p>
                    <p>
                        Wenn du ein neues Studio über das Formular einreichst, werden die angegebenen Daten (z. B. Name, Adresse, Kontakt, ausgewählte Leistungen/Ausstattung
                        sowie ein optionales Bild) in der Website-Datenbank gespeichert, vom Betreiber geprüft und ggf. nach Freigabe veröffentlicht.
                    </p>
                
<div class="sml-legal-box">
    <p><strong>Idee/Fehler melden</strong></p>
    <p>
        Wenn du über das Feedback-Formular eine Idee einreichst oder einen Fehler meldest, werden die von dir angegebenen Inhalte
        (und – falls angegeben – Kontaktdaten) zur Bearbeitung gespeichert und per E-Mail an den Betreiber übermittelt.
    </p>
</div>

</div>

                <div class="sml-legal-box">
                    <p><strong>Drittanbieter</strong></p>
                    <p>
                        Für die Umwandlung von Adresse zu Koordinaten kann dieses System einen Geocoding-Dienst (z. B. Nominatim/OpenStreetMap) anfragen.
                        Die Routenplanung öffnet deine Route in einem externen Kartendienst (z. B. Google Maps). Dort gilt die Datenschutzerklärung des jeweiligen Anbieters.
                    </p>
                </div>
            </div>

            <p style="margin-top:12px;">
                
            </p>
        </div>
    </div>


    <!-- Feedback Modal -->
    <div id="sml-feedback-modal" class="sml-modal" aria-hidden="true">
        <div class="sml-modal-content sml-legal-modal sml-feedback-modal" role="dialog" aria-modal="true" aria-label="Idee oder Fehler melden">
            <button type="button" class="sml-modal-close" onclick="smlCloseFeedbackModal()" aria-label="Schließen">×</button>
            <h3>Idee/Fehler melden</h3>

            <div class="sml-feedback-toggle" role="tablist" aria-label="Auswahl">
                <button type="button" class="sml-toggle-btn is-active" data-kind="idea" onclick="smlSetFeedbackKind('idea')" role="tab" aria-selected="true">Idee einreichen</button>
                <button type="button" class="sml-toggle-btn" data-kind="bug" onclick="smlSetFeedbackKind('bug')" role="tab" aria-selected="false">Fehler melden</button>
            </div>

            <form id="sml-feedback-form" class="sml-feedback-form">
                <input type="hidden" name="action" value="sml_submit_feedback">
                <input type="hidden" name="security" value="<?php echo esc_attr(wp_create_nonce('sml_feedback_nonce')); ?>">
                <input type="hidden" name="kind" id="sml-feedback-kind" value="idea">
                <input type="hidden" name="page_url" id="sml-feedback-url" value="">
                <input type="text" name="company" id="sml-feedback-company" value="" autocomplete="off" tabindex="-1" aria-hidden="true" style="position:absolute; left:-9999px; width:1px; height:1px; opacity:0;">

                <div class="sml-feedback-grid">
                    <div class="sml-feedback-field">
                        <label for="sml-feedback-name">Name (optional)</label>
                        <input id="sml-feedback-name" name="name" type="text" class="sml-feedback-input" autocomplete="name">
                    </div>
                    <div class="sml-feedback-field">
                        <label for="sml-feedback-email">E-Mail (für Rückfragen)</label>
                        <input id="sml-feedback-email" name="email" type="email" class="sml-feedback-input" autocomplete="email" required>
                    </div>
                </div>

                <div class="sml-feedback-field">
                    <label for="sml-feedback-message">Beschreibung</label>
                    <textarea id="sml-feedback-message" name="message" class="sml-feedback-textarea" rows="5" required placeholder="Beschreibe deine Idee oder den Fehler so konkret wie möglich."></textarea>
                </div>

                <div class="sml-feedback-actions">
                    <button type="submit" class="sml-feedback-submit">Absenden</button>
                    <span class="sml-feedback-status" id="sml-feedback-status" aria-live="polite"></span>
                </div>
            </form>
        </div>
    </div>

    <script>
    var globalStudioData = <?php echo $json_studios; ?>; 
    var map; var markers = []; var currentLat, currentLng;
    var selectedTechs = [];
    var selectedServices = []; 
    var userCircle;
    var preSelectionCenter;
    var preSelectionZoom;
    var isInternalNavigation = false;
    var toastTimer;

    // --- HELPER FUNCTIONS ---
    function jumpToMap(id) {
        const s = globalStudioData.find(st => st.id == id);
        if(s && s.markerObj) {
            isInternalNavigation = true;
            document.getElementById('sml-wrapper').scrollIntoView({behavior: 'smooth'});
            setTimeout(() => {
                map.flyTo([s.lat, s.lng], 16, { duration: 1.5 });
                s.markerObj.openPopup();
                highlightCard(id); 
            }, 300);
        }
    }

    function openModal() { document.getElementById('sml-modal').style.display = 'flex'; document.body.classList.add('sml-modal-open'); }
    function closeModal() { document.getElementById('sml-modal').style.display = 'none'; document.body.classList.remove('sml-modal-open'); }
    document.addEventListener('keydown', function(e) { if(e.key === "Escape") closeModal(); });
    window.onclick = function(e) { if(e.target === document.getElementById('sml-modal')) closeModal(); }
    
    function updateFileName(input) {
        if(input.files && input.files.length > 0) {
            const textSpan = input.nextElementSibling.querySelector('.sml-upload-text');
            if(textSpan) textSpan.innerText = "Ausgewählt: " + input.files[0].name;
            input.parentElement.style.backgroundColor = '#e0f0ff';
        }
    }

    // --- SECURE CONTACT AUTO-REVEAL (INSTANT ON LOAD) ---
    document.addEventListener('DOMContentLoaded', function() {
        const secureLinks = document.querySelectorAll('.sml-secure-contact');
        secureLinks.forEach(link => {
            const encoded = link.getAttribute('data-val');
            const type = link.getAttribute('data-type');
            if(encoded) {
                try {
                    const decoded = atob(encoded); // Base64 Decode
                    // Set Text content (keeping icon)
                    const iconHtml = type === 'tel' ? '<span class="dashicons dashicons-phone"></span>' : '<span class="dashicons dashicons-email-alt"></span>';
                    link.innerHTML = iconHtml + ' ' + decoded;
                    // Set Href
                    if(type === 'tel') link.href = 'tel:' + decoded;
                    else if(type === 'mail') link.href = 'mailto:' + decoded;
                } catch(e) { console.error('Decode error', e); }
            }
        });
        
        // --- ONBOARDING BUBBLE LOGIC ---
        if (!localStorage.getItem('sml_geo_hint_seen')) {
            const hint = document.getElementById('sml-geo-hint');
            if(hint) {
                setTimeout(() => {
                    hint.classList.add('visible');
                    // Hide automatically after 4 seconds
                    setTimeout(() => {
                        hint.classList.remove('visible');
                        localStorage.setItem('sml_geo_hint_seen', 'true');
                    }, 4000);
                }, 1000); // 1s delay after page load
            }
        }
    });

    // --- DROPDOWN LOGIC ---
    document.addEventListener('click', function(e) {
        const ms = document.getElementById('sml-multi-filter');
        const dd = document.getElementById('sml-tech-dropdown');
        if(ms && dd) {
            const isInside = ms.contains(e.target);
            const isDropdownClick = dd.contains(e.target);
            if (isInside && !isDropdownClick) { dd.classList.toggle('show'); } else if (!isInside) { dd.classList.remove('show'); }
        }
        const ms2 = document.getElementById('sml-service-filter');
        const dd2 = document.getElementById('sml-service-dropdown');
        if(ms2 && dd2) {
            const isInside = ms2.contains(e.target);
            const isDropdownClick = dd2.contains(e.target);
            if (isInside && !isDropdownClick) { dd2.classList.toggle('show'); } else if (!isInside) { dd2.classList.remove('show'); }
        }
    });

    function updateMultiFilter() {
        const checkboxes = document.querySelectorAll('#sml-tech-dropdown input[type="checkbox"]:checked');
        selectedTechs = Array.from(checkboxes).map(cb => cb.value);
        const ph = document.getElementById('sml-tech-ph');
        if(ph) {
            if(selectedTechs.length === 0) ph.innerText = "Ausstattung wählen...";
            else if(selectedTechs.length === 1) ph.innerText = selectedTechs[0];
            else ph.innerText = selectedTechs.length + " gewählt";
        }
        applyGlobalFilter();
    }
    
    function updateServiceFilter() {
        const checkboxes = document.querySelectorAll('#sml-service-dropdown input[type="checkbox"]:checked');
        selectedServices = Array.from(checkboxes).map(cb => cb.value);
        const ph = document.getElementById('sml-service-ph');
        if(ph) {
            if(selectedServices.length === 0) ph.innerText = "Leistung wählen...";
            else if(selectedServices.length === 1) ph.innerText = selectedServices[0];
            else ph.innerText = selectedServices.length + " gewählt";
        }
        applyGlobalFilter();
    }
    
    function applyGlobalFilter() {
       if(currentLat && currentLng) performSearch(currentLat, currentLng, document.getElementById('user-plz').value, false, true);
       filterTableRows();
    }

    // --- INIT ---
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('sml-modal');
        if(modal) document.body.appendChild(modal);

        if(document.getElementById('sml-map')) { 
            map = L.map('sml-map').setView([51.1657, 10.4515], 6);
            L.tileLayer('https://{s}.tile.openstreetmap.de/tiles/osmde/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
            
            map.on('popupclose', function() {
                if(isInternalNavigation) { isInternalNavigation = false; return; }
                document.querySelectorAll('.studio-item').forEach(el => el.classList.remove('active-card'));
                if(preSelectionCenter && preSelectionZoom) {
                    map.flyTo(preSelectionCenter, preSelectionZoom, { duration: 1.2 });
                    preSelectionCenter = null;
                } else if(userCircle) {
                    map.fitBounds(userCircle.getBounds(), { padding: [50, 50], animate: true, duration: 1.2 });
                } else {
                    map.flyTo([51.1657, 10.4515], 6, { duration: 1.2 });
                }
            });

            renderMapAndList(globalStudioData);
        }
        
        // Tooltip logic
        const badgeContainers = document.querySelectorAll('.sml-badge-container');
        const tooltip = document.getElementById('sml-tooltip-fixed');
        badgeContainers.forEach(el => {
            el.addEventListener('mouseenter', () => {
                const content = el.querySelector('.sml-badge-popup').innerHTML;
                tooltip.innerHTML = content;
                tooltip.style.display = 'block';
                const rect = el.getBoundingClientRect();
                tooltip.style.left = (rect.left + (rect.width / 2) - 140) + 'px'; 
                tooltip.style.top = (rect.top - tooltip.offsetHeight - 8) + 'px';
            });
            el.addEventListener('mouseleave', () => {
                tooltip.style.display = 'none';
            });
        });

        // Live check for form
        document.querySelectorAll('.sml-live-check').forEach(input => {
            let timer;
            input.addEventListener('input', function() {
                const el = this;
                const type = el.getAttribute('data-type');
                const val = el.value;
                const btn = document.getElementById('sml-submit-form-btn');
                el.classList.remove('sml-input-error');
                const err = el.parentElement.querySelector('.sml-error-text');
                if(err) err.remove();
                if(btn) btn.disabled = false;
                clearTimeout(timer);
                if(val.length < 3) return;
                timer = setTimeout(() => {
                    const fd = new FormData();
                    fd.append('action', 'sml_live_check_duplicate');
                    fd.append('type', type);
                    fd.append('value', val);
                    fd.append('security', '<?php echo wp_create_nonce('sml_live_check'); ?>');
                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method:'POST', body:fd }).then(r => r.json()).then(res => {
                        if(res.success && res.data.exists) {
                            el.classList.add('sml-input-error');
                            const msgDiv = document.createElement('span');
                            msgDiv.className = 'sml-error-text';
                            msgDiv.innerText = res.data.message;
                            el.parentElement.appendChild(msgDiv);
                            if(btn) btn.disabled = true;
                        }
                    });
                }, 500);
            });
        });

        // --- NEW GEOLOCATION & RESET LOGIC ---

        const saved = localStorage.getItem('sml_last_search');
        if(saved && document.getElementById('user-plz')) {
            const p = JSON.parse(saved); 
            document.getElementById('user-plz').value = p.plz||''; 
            if(document.getElementById('search-radius')) {
                document.getElementById('search-radius').value = p.radius||50; 
                document.getElementById('radius-val').innerText = p.radius + ' km';
            }
            if(p.lat && p.lng) performSearch(p.lat, p.lng, p.plz, false);
        }

        const geoBtn = document.getElementById('sml-geo-btn');
        if(geoBtn) {
            geoBtn.addEventListener('click', function() {
                if(!navigator.geolocation) {
                    return alert("Geolokalisierung wird von diesem Browser nicht unterstützt.");
                }

                geoBtn.classList.add('loading');
                geoBtn.style.color = '#1a93ee';
                document.getElementById('user-plz').placeholder = "Ortung läuft...";
                document.getElementById('user-plz').value = "";

                // DOUBLE-TAP STRATEGY (SILENT SKIP VERSION)
                const runPreciseScan = () => {
                    navigator.geolocation.getCurrentPosition(
                        (finalPos) => {
                            // THIS is the result we use (from 2nd scan)
                            geoBtn.classList.remove('loading');
                            const lat = finalPos.coords.latitude;
                            const lng = finalPos.coords.longitude;
                            
                            performSearch(lat, lng, "Dein Standort", true);

                            // Resolve Address
                            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`)
                            .then(r => r.json())
                            .then(d => {
                                 let locName = "GPS Standort";
                                 if(d.address) {
                                    const city = d.address.city || d.address.town || d.address.village;
                                    const road = d.address.road;
                                    if(road && city) locName = `${road}, ${city}`;
                                    else if(city) locName = city;
                                 }
                                 const accText = finalPos.coords.accuracy ? ` (+/- ${Math.round(finalPos.coords.accuracy)}m)` : '';
                                 document.getElementById('user-plz').value = locName + accText;
                                 
                                 localStorage.setItem('sml_last_search', JSON.stringify({
                                     lat: lat, lng: lng, radius: parseInt(document.getElementById('search-radius').value), plz: locName + accText
                                 }));
                                 
                                 if(userMarker) {
                                     userMarker.setPopupContent(`<div class="sml-user-popup-head">Dein Standort</div><div class="sml-user-popup-body"><div style="font-weight:600;font-size:14px;margin-bottom:5px;">${locName}</div><small style="color:#999">Suchradius-Mittelpunkt</small></div>`);
                                 }
                            })
                            .catch(() => { document.getElementById('user-plz').value = "GPS Position"; });
                        },
                        (err) => {
                            geoBtn.classList.remove('loading');
                            geoBtn.style.color = '#aaa';
                            alert("Standort konnte nicht ermittelt werden (Timeout).");
                        },
                        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 } // FORCE FRESH
                    );
                };

                // 1. DUMMY CALL (To wake up GPS/WiFi)
                navigator.geolocation.getCurrentPosition(
                    (dummyPos) => {
                        // IGNORE result, immediately trigger precise scan
                        setTimeout(runPreciseScan, 500); // 500ms DELAY FOR CACHE CLEARING
                    },
                    (err) => {
                        // Even if 1st fails, try 2nd anyway
                        setTimeout(runPreciseScan, 500);
                    },
                    { enableHighAccuracy: true, timeout: 4000, maximumAge: 0 }
                );
            });
        }

        const searchBtn = document.getElementById('sml-search-btn');
        if(searchBtn) {
            searchBtn.addEventListener('click', function() {
                const val = document.getElementById('user-plz').value; 
                if(!val) return alert('Bitte Ort oder PLZ eingeben.');
                
                const originalText = searchBtn.innerText;
                searchBtn.innerText = "...";
                
                fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=de&q='+encodeURIComponent(val))
                .then(r => { if (!r.ok) throw new Error('Netzwerk-Fehler'); return r.json(); })
                .then(d => { 
                    searchBtn.innerText = originalText;
                    if(d.length > 0 && d[0].lat && d[0].lon) {
                        performSearch(parseFloat(d[0].lat), parseFloat(d[0].lon), d[0].display_name || val, true); 
                    } else { 
                        alert('Ort nicht gefunden. Bitte versuchen Sie es genauer (z.B. mit PLZ).'); 
                    } 
                })
                .catch(e => {
                    searchBtn.innerText = originalText;
                    console.error("Search Error:", e);
                    alert("Fehler bei der Suche. Bitte prüfen Sie Ihre Internetverbindung.");
                });
            });
        }

        const resetBtn = document.getElementById('sml-reset-btn');
        if(resetBtn) {
            resetBtn.addEventListener('click', function() {
                if(document.getElementById('user-plz')) document.getElementById('user-plz').value = '';
                if(document.getElementById('sml-table-filter')) document.getElementById('sml-table-filter').value = '';
                
                if(document.getElementById('search-radius')) {
                    document.getElementById('search-radius').value = 50;
                    document.getElementById('radius-val').innerText = '50 km';
                }
                
                document.querySelectorAll('.sml-ms-dropdown input[type="checkbox"]').forEach(cb => cb.checked = false);
                selectedTechs = []; selectedServices = [];
                if(document.getElementById('sml-tech-ph')) document.getElementById('sml-tech-ph').innerText = "Ausstattung wählen...";
                if(document.getElementById('sml-service-ph')) document.getElementById('sml-service-ph').innerText = "Leistung wählen...";
                
                if(userMarker) map.removeLayer(userMarker);
                if(userCircle) map.removeLayer(userCircle);
                userMarker = null; userCircle = null; currentLat = null; currentLng = null;
                preSelectionCenter = null; preSelectionZoom = null;
                
                localStorage.removeItem('sml_last_search');
                
                map.flyTo([51.1657, 10.4515], 6, { duration: 1.5 });
                
                renderMapAndList(globalStudioData);
                filterTableRows();
            });
        }

        const userPlz = document.getElementById('user-plz');
        if(userPlz) { userPlz.addEventListener("keypress", e=>{if(e.key==="Enter")document.getElementById('sml-search-btn').click();}); }

        const slider = document.getElementById('search-radius');
        if(slider) {
            slider.addEventListener('input', function() { 
                const r = parseInt(this.value); 
                document.getElementById('radius-val').innerText = r + ' km';
                
                if(userCircle) {
                    userCircle.setRadius(r * 1000);
                } else if(currentLat && currentLng) {
                    userCircle = L.circle([currentLat, currentLng], { color: '#1a93ee', fillColor: '#1a93ee', fillOpacity: 0.15, weight: 1, radius: r*1000 }).addTo(map);
                }
            });
            slider.addEventListener('change', function() {
                if(currentLat) performSearch(currentLat,currentLng,document.getElementById('user-plz').value,true);
            });
        }

        const form = document.getElementById('sml-submission-form');
        if(form) {
            form.addEventListener('submit', function(e){
                e.preventDefault(); const btn = document.getElementById('sml-submit-form-btn'); const msg = document.getElementById('sml-form-msg');
                btn.disabled = true; btn.innerText = 'Sende...';
                const formData = new FormData(this);
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: formData }).then(response => response.json()).then(data => {
                    btn.disabled = false; btn.innerText = 'Jetzt kostenlos eintragen'; msg.innerText = data.data.message;
                    if(data.success) { msg.style.color = 'green'; document.getElementById('sml-submission-form').reset(); setTimeout(() => { closeModal(); msg.innerText=''; }, 2000); } else { msg.style.color = 'red'; }
                });
            });
        }
    });

    // --- SHARED FUNCTIONS ---
    function escapeHtml(str){
        return String(str).replace(/[&<>"']/g, function(s){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[s];
        });
    }
    function escapeAttr(str){ return escapeHtml(str); }


    // --- LEGAL MODALS (Impressum / Privacy) ---
    function smlOpenImpressumModal(){
        const m = document.getElementById('sml-impressum-modal');
        if(!m) return;
        m.style.display = 'flex';
        m.setAttribute('aria-hidden', 'false');
    }
    function smlCloseImpressumModal(){
        const m = document.getElementById('sml-impressum-modal');
        if(!m) return;
        m.style.display = 'none';
        m.setAttribute('aria-hidden', 'true');
    }
    function smlOpenPrivacyModal(){
        const m = document.getElementById('sml-privacy-modal');
        if(!m) return;
        m.style.display = 'flex';
        m.setAttribute('aria-hidden', 'false');
    }
    function smlClosePrivacyModal(){
        const m = document.getElementById('sml-privacy-modal');
        if(!m) return;
        m.style.display = 'none';
        m.setAttribute('aria-hidden', 'true');
    }
    
    // --- FEEDBACK MODAL ---
    function smlOpenFeedbackModal(){
        const m = document.getElementById('sml-feedback-modal');
        if(!m) return;

        // Sicherstellen, dass das Modal direkt unter <body> hängt (damit der Backdrop garantiert den Viewport abdeckt)
        if(m.parentElement !== document.body){ document.body.appendChild(m); }

        const urlEl = document.getElementById('sml-feedback-url');
        if(urlEl) urlEl.value = window.location.href;

        const status = document.getElementById('sml-feedback-status');
        if(status) status.textContent = '';

        m.style.display = 'flex';
        m.setAttribute('aria-hidden','false');

        // Animation starten
        requestAnimationFrame(() => { m.classList.add('is-open'); });
    }

    /**
     * Schließt das Feedback-Modal.
     * @param {boolean} animated
     */
    function smlCloseFeedbackModal(animated){
        const m = document.getElementById('sml-feedback-modal');
        if(!m) return;

        const doHide = () => {
            m.style.display = 'none';
            m.setAttribute('aria-hidden','true');
            m.classList.remove('is-open');
        };

        if(animated === false){
            doHide();
            return;
        }

        m.classList.remove('is-open');
        setTimeout(doHide, 190);
    }

    // Backdrop-Klick: nur schließen, wenn wirklich der Overlay-Hintergrund geklickt wurde
    document.addEventListener('click', function(ev){
        const overlay = document.getElementById('sml-feedback-modal');
        if(!overlay) return;
        if(ev.target === overlay){ smlCloseFeedbackModal(true); }
    });
    function smlSetFeedbackKind(kind){
        const hidden = document.getElementById('sml-feedback-kind');
        if(hidden) hidden.value = kind;
        document.querySelectorAll('#sml-feedback-modal .sml-toggle-btn').forEach(btn=>{
            const isActive = btn.getAttribute('data-kind') === kind;
            btn.classList.toggle('is-active', isActive);
            btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
    }

    // Ensure modals are attached to <body> so fixed overlays reliably cover the full viewport
    (function smlEnsureModalsInBody(){
        const ids = ['sml-impressum-modal','sml-privacy-modal','sml-feedback-modal'];
        ids.forEach(id => {
            const el = document.getElementById(id);
            if(!el) return;
            if(el.parentElement !== document.body) {
                document.body.appendChild(el);
            }
        });
    })();

    // Submit feedback (AJAX -> Email)
    document.addEventListener('submit', function(e){
        const form = e.target;
        if(!form || form.id !== 'sml-feedback-form') return;
        e.preventDefault();

        const status = document.getElementById('sml-feedback-status');
        const submitBtn = form.querySelector('.sml-feedback-submit');
        if(status) status.textContent = 'Sende...';
        if(submitBtn) submitBtn.disabled = true;

        const fd = new FormData(form);
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if(res && res.success){
                    if(status) status.textContent = 'Danke! Deine Nachricht wurde gesendet.';
                    // Modal nach erfolgreichem Versand automatisch schließen (mit Animation)
                    setTimeout(function(){ smlCloseFeedbackModal(true); }, 900);
                    form.reset();
                    smlSetFeedbackKind('idea');
                    // Keep nonce + hidden fields after reset
                    form.querySelector('input[name="action"]').value = 'sml_submit_feedback';
                    form.querySelector('input[name="security"]').value = '<?php echo esc_attr(wp_create_nonce('sml_feedback_nonce')); ?>';
                    form.querySelector('#sml-feedback-kind').value = 'idea';
                    form.querySelector('#sml-feedback-url').value = window.location.href;
                } else {
                    const msg = (res && res.data && res.data.message) ? res.data.message : 'Senden fehlgeschlagen. Bitte später erneut versuchen.';
                    if(status) status.textContent = msg;
                }
            })
            .catch(() => {
                if(status) status.textContent = 'Senden fehlgeschlagen. Bitte später erneut versuchen.';
            })
            .finally(() => {
                if(submitBtn) submitBtn.disabled = false;
            });
    });

    // Close modals when clicking on the backdrop
    document.addEventListener('click', function(e){
        const imp = document.getElementById('sml-impressum-modal');
        const pri = document.getElementById('sml-privacy-modal');
        const fb  = document.getElementById('sml-feedback-modal');
        if(imp && e.target === imp) smlCloseImpressumModal();
        if(pri && e.target === pri) smlClosePrivacyModal();
        if(fb && e.target === fb)  smlCloseFeedbackModal();
    });
    // Close on ESC
    document.addEventListener('keydown', function(e){
        if(e.key !== 'Escape') return;
        smlCloseImpressumModal();
        smlClosePrivacyModal();
        smlCloseFeedbackModal();
    });

    function planRoute(lat, lng) {
        if(!currentLat || !currentLng) {
            alert("Bitte geben Sie zuerst einen Standort an, um die Route zu berechnen.");
            return;
        }
        window.open(`https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent(currentLat+','+currentLng)}&destination=${encodeURIComponent(lat+','+lng)}`);
    }

    function renderMapAndList(data) {
        markers.forEach(m => map.removeLayer(m)); markers = [];
        const list = document.getElementById('list-content'); 
        if(!list) return;
        list.innerHTML = '';
        data.forEach(s => {
            if(s.lat && s.lng) {
                const m = L.marker([s.lat, s.lng]).addTo(map); 
                
                let initialOpacity = 1;
                if(userCircle && currentLat) {
                     const d = getDistance(currentLat, currentLng, s.lat, s.lng);
                     const rad = parseInt(document.getElementById('search-radius').value);
                     initialOpacity = (d <= rad) ? 1 : 0.5;
                }
                m.setOpacity(initialOpacity);
                L.DomUtil.addClass(m._icon, 'sml-marker-blue');
                
                m.on('click', function() {
                    isInternalNavigation = true;
                    highlightCard(s.id); 
                    if (preSelectionCenter === null) {
                         preSelectionCenter = map.getCenter();
                         preSelectionZoom = map.getZoom();
                    }
                    map.flyTo([s.lat, s.lng], 16, { duration: 1.5 });
                });
                
                let catsText = escapeHtml(s.cats.join(' • ')); let catsHtml = catsText ? `<div class="sml-popup-cats-line">${catsText}</div>` : '';
                let imgHeader = s.image ? `<div class="sml-popup-header-img" style="background-image:url('${escapeAttr(s.image)}')"></div>` : `<div class="sml-popup-no-img"><span class="dashicons dashicons-microphone" style="color:#fff; font-size:24px;"></span></div>`;
                
                let webLinkHtml = s.url ? `<a href="${escapeAttr(s.url)}" class="sml-popup-web-link" target="_blank" rel="noopener">Webseite</a>` : '';
                
                // POPUP CONTENT (NAV REMOVED)
                const popupHtml = `${imgHeader}<div class="sml-popup-body"><div class="sml-popup-title">${escapeHtml(s.title)}</div>${catsHtml}<div class="sml-popup-addr">${escapeHtml(s.full_city)}</div><div class="sml-popup-footer"><a href="#" onclick="planRoute(${s.lat},${s.lng}); return false;" class="sml-popup-route" title="Route planen"><svg viewBox="0 0 24 24"><path d="M21.71 11.29l-9-9c-.39-.39-1.02-.39-1.41 0l-9 9c-.39.39-.39 1.02 0 1.41l9 9c.39.39 1.02.39 1.41 0l9-9c.39-.38.39-1.01 0-1.41zM14 14.5V12h-4v3H8v-4c0-.55.45-1 1-1h5V7.5l3.5 3.5-3.5 3.5z"/></svg></a><button onclick="scrollToTable('${s.id}')" class="sml-popup-main-btn">Weitere Infos</button>${webLinkHtml}</div></div>`;
                
                m.bindPopup(popupHtml); s.markerObj = m; markers.push(m);
                const div = document.createElement('div'); div.className = 'studio-item'; div.id = 'studio-card-' + s.id;
                div.setAttribute('data-cat', s.cats.length ? s.cats[0] : ''); 
                div.onclick = () => { 
                    isInternalNavigation = true;
                    if (preSelectionCenter === null) {
                         preSelectionCenter = map.getCenter();
                         preSelectionZoom = map.getZoom();
                    }
                    map.flyTo([s.lat, s.lng], 16, { duration: 1.5 }); 
                    m.openPopup(); 
                    highlightCard(s.id, false); 
                };
                let thumbHtml = s.image ? `<div class="studio-thumb"><img src="${escapeAttr(s.image)}" loading="lazy" alt="${escapeAttr(s.title)}"></div>` : `<div class="studio-thumb"><span class="dashicons dashicons-microphone" style="font-size:30px; height:30px; width:30px;"></span></div>`;
                div.innerHTML = `${thumbHtml}<div class="studio-info"><h4>${escapeHtml(s.title)}</h4><div class="addr">${escapeHtml(s.address)}</div><div class="dist" style="display:none;"></div></div>`;
                list.appendChild(div); s.listItem = div; 
            }
        });
        if(document.getElementById('list-header')) document.getElementById('list-header').innerText = markers.length + ' Treffer';
    }

    var userMarker, userCircle;
    function performSearch(lat, lng, plz, save, preventZoom = false) {
        currentLat=lat; currentLng=lng; const rad = parseInt(document.getElementById('search-radius').value);
        if(userMarker) map.removeLayer(userMarker); if(userCircle) map.removeLayer(userCircle);
        const redIcon = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34], shadowSize: [41, 41] });
        
        let cleanPlz = (plz || "").replace(/\s*\(\+\/- \d+m\)/, '');
        if(cleanPlz.length > 40) cleanPlz = cleanPlz.substring(0, 37) + '...';

        userMarker = L.marker([lat, lng], {icon: redIcon}).addTo(map).bindPopup(`<div class="sml-user-popup-head">Dein Standort</div><div class="sml-user-popup-body"><div style="font-weight:600;font-size:14px;margin-bottom:5px;">${cleanPlz}</div><small style="color:#999">Suchradius-Mittelpunkt</small></div>`).openPopup();
        
        // AUTO CLOSE POPUP LOGIC
        setTimeout(() => { 
            if(userMarker && userMarker.getPopup().isOpen()) {
                const el = userMarker.getPopup().getElement();
                if(el) el.classList.add('sml-fade-out');
                setTimeout(() => userMarker.closePopup(), 500); // Close after fade
            }
        }, 2500);

        userCircle = L.circle([lat, lng], { color: '#1a93ee', fillColor: '#1a93ee', fillOpacity: 0.15, weight: 1, radius: rad*1000 }).addTo(map);
        if(!preventZoom) {
            map.fitBounds(userCircle.getBounds());
        }
        let found = 0;
        markers.forEach(m => {
            let s = globalStudioData.find(st => st.markerObj === m); if(!s) return; 
            const d = getDistance(lat, lng, s.lat, s.lng); const listEl = s.listItem; 
            
            if(d <= rad) { 
                m.setOpacity(1); 
                m.setZIndexOffset(1000); 
                if(listEl) { listEl.style.display = 'flex'; listEl.querySelector('.dist').innerHTML = d.toFixed(1) + ' km entfernt'; listEl.querySelector('.dist').style.display = 'inline-block'; } 
                found++; 
            } else { 
                m.setOpacity(0.5); 
                m.setZIndexOffset(0);
                if(listEl) listEl.style.display = 'none'; 
            }
        });
        if(document.getElementById('list-header')) document.getElementById('list-header').innerText = found + ' Treffer';
        
        // SHOW TOAST
        const toast = document.getElementById('sml-map-toast');
        const toastText = document.getElementById('sml-toast-text');
        if(toast && toastText) {
            toastText.innerText = found + " Studios im Radius";
            toast.style.opacity = '1';
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => { toast.style.opacity = '0'; }, 3000);
        }

        if(save) localStorage.setItem('sml_last_search', JSON.stringify({lat:lat, lng:lng, radius:rad, plz:plz}));
    }

    function scrollToTable(id) { 
        const searchInput = document.getElementById('sml-table-filter');
        if(searchInput) searchInput.value = '';
        selectedTechs = []; selectedServices = [];
        document.querySelectorAll('.sml-ms-dropdown input[type="checkbox"]').forEach(cb => cb.checked = false);
        const techPh = document.getElementById('sml-tech-ph');
        if(techPh) techPh.innerText = "Ausstattung wählen...";
        const servPh = document.getElementById('sml-service-ph');
        if(servPh) servPh.innerText = "Leistung wählen...";
        filterTableRows();
        const row = document.getElementById('studio-row-' + id); 
        if(row) { 
            row.scrollIntoView({ behavior: 'smooth', block: 'center' }); 
            row.style.backgroundColor = '#1a93ee1a'; 
            setTimeout(() => { row.style.backgroundColor = ''; }, 1500); 
        } 
    }
    
    function highlightCard(id, shouldScroll = true) { 
        document.querySelectorAll('.studio-item').forEach(el => el.classList.remove('active-card')); 
        const target = document.getElementById('studio-card-' + id); 
        const container = document.getElementById('sml-list'); 
        if(target && container) { 
            target.classList.add('active-card'); 
            if(shouldScroll) {
                const topPos = target.offsetTop - container.offsetTop - 20; 
                container.scrollTo({ top: topPos, behavior: 'smooth' }); 
            }
        } 
    }
    
    function getDistance(lat1,lon1,lat2,lon2) { const R=6371; const dLat=(lat2-lat1)*(Math.PI/180); const dLon=(lon2-lon1)*(Math.PI/180); const a=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(lat1*(Math.PI/180))*Math.cos(lat2*(Math.PI/180))*Math.sin(dLon/2)*Math.sin(dLon/2); return R*(2*Math.atan2(Math.sqrt(a), Math.sqrt(1-a))); }
    
    function filterTableRows() {
        const textFilter = document.getElementById("sml-table-filter") ? document.getElementById("sml-table-filter").value.toUpperCase() : '';
        const trs = document.getElementById("studio-table") ? document.getElementById("studio-table").getElementsByTagName("tr") : [];
        for (let i=1; i<trs.length; i++) {
            const row = trs[i];
            const rowText = row.textContent || row.innerText;
            const techData = row.getAttribute('data-tech');
            const servData = row.getAttribute('data-services');
            
            let techMatch = true;
            if(selectedTechs.length > 0) { techMatch = selectedTechs.every(v => techData && techData.includes(v)); }
            
            let servMatch = true;
            if(selectedServices.length > 0) { servMatch = selectedServices.every(v => servData && servData.includes(v)); }

            const textMatch = rowText.toUpperCase().indexOf(textFilter) > -1;
            
            if (textMatch && techMatch && servMatch) { row.style.display = ""; } else { row.style.display = "none"; }
        }
    }
    
    function filterTableOnly() { filterTableRows(); }

    function sortHTMLTable(n) { var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0; table = document.getElementById("studio-table"); switching = true; dir = "asc"; while (switching) { switching = false; rows = table.rows; for (i = 1; i < (rows.length - 1); i++) { shouldSwitch = false; x = rows[i].getElementsByTagName("TD")[n]; y = rows[i + 1].getElementsByTagName("TD")[n]; if (dir == "asc") { if (x.innerHTML.toLowerCase() > y.innerHTML.toLowerCase()) { shouldSwitch = true; break; } } else if (dir == "desc") { if (x.innerHTML.toLowerCase() < y.innerHTML.toLowerCase()) { shouldSwitch = true; break; } } } if (shouldSwitch) { rows[i].parentNode.insertBefore(rows[i + 1], rows[i]); switching = true; switchcount ++; } else { if (switchcount == 0 && dir == "asc") { dir = "desc"; switching = true; } } } }
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('studio_map', 'sml_shortcode_output');

// ----------------------------------------------------------------
// FEEDBACK (Frontend Modal -> Email)
// ----------------------------------------------------------------
add_action('wp_ajax_sml_submit_feedback', 'sml_submit_feedback_handler');
add_action('wp_ajax_nopriv_sml_submit_feedback', 'sml_submit_feedback_handler');


/**
 * Feedback-Backend: Admin-Spalten und Anzeige verbessern.
 */
function sml_feedback_admin_columns($columns) {
    // Keep checkbox and date, replace the rest
    $new = array();
    if (isset($columns['cb'])) $new['cb'] = $columns['cb'];
    $new['title'] = 'Betreff';
    $new['sml_fb_kind'] = 'Typ';
    $new['sml_fb_email'] = 'E-Mail';
    $new['sml_fb_page'] = 'Seite';
    $new['date'] = 'Datum';
    return $new;
}
add_filter('manage_edit-sml_feedback_columns', 'sml_feedback_admin_columns');

function sml_feedback_admin_column_content($column, $post_id) {
    if ($column === 'sml_fb_kind') {
        $kind = get_post_meta($post_id, '_sml_fb_kind', true);
        echo esc_html($kind === 'bug' ? 'Fehler' : 'Idee');
        return;
    }
    if ($column === 'sml_fb_email') {
        $email = get_post_meta($post_id, '_sml_fb_email', true);
        if ($email) {
            echo '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
        }
        return;
    }
    if ($column === 'sml_fb_page') {
        $page = get_post_meta($post_id, '_sml_fb_page', true);
        if ($page) {
            echo '<a href="' . esc_url($page) . '" target="_blank" rel="noopener">Öffnen</a>';
        } else {
            echo '–';
        }
        return;
    }
}
add_action('manage_sml_feedback_posts_custom_column', 'sml_feedback_admin_column_content', 10, 2);

function sml_submit_feedback_handler() {
    if ( ! isset($_POST['security']) || ! wp_verify_nonce($_POST['security'], 'sml_feedback_nonce') ) {
        wp_send_json_error(['message' => 'Ungültige Anfrage. Bitte lade die Seite neu und versuche es erneut.'], 403);
    }

    // Basic honeypot to reduce spam
    if ( ! empty($_POST['company']) ) {
        wp_send_json_success(['ok' => true]);
    }

    $kind    = isset($_POST['kind']) ? sanitize_text_field(wp_unslash($_POST['kind'])) : 'idea';
    $name    = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
    $email   = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';
    $page    = isset($_POST['page_url']) ? esc_url_raw(wp_unslash($_POST['page_url'])) : '';

    if ( empty($email) || ! is_email($email) ) {
        wp_send_json_error(['message' => 'Bitte eine gültige E-Mail-Adresse angeben.'], 400);
    }
    if ( empty(trim(wp_strip_all_tags($message))) ) {
        wp_send_json_error(['message' => 'Bitte eine Beschreibung eingeben.'], 400);
    }

    // Lightweight rate limit (per IP)
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
    if ($ip) {
        $key = 'sml_fb_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= 10) {
            wp_send_json_error(['message' => 'Zu viele Anfragen. Bitte versuche es später erneut.'], 429);
        }
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
    }
$kind_label = ($kind === 'bug') ? 'Fehler' : 'Idee';

// Feedback zusätzlich im Backend protokollieren
$ua  = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
$ref = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';

$fb_title = sprintf('%s: %s', $kind_label, $name ? $name : $email);

$fb_post_id = wp_insert_post(array(
    'post_type'    => 'sml_feedback',
    'post_status'  => 'publish',
    'post_title'   => $fb_title,
    'post_content' => $message,
), true);

if ( ! is_wp_error($fb_post_id) && $fb_post_id ) {
    update_post_meta($fb_post_id, '_sml_fb_kind', $kind);
    update_post_meta($fb_post_id, '_sml_fb_name', $name);
    update_post_meta($fb_post_id, '_sml_fb_email', $email);
    update_post_meta($fb_post_id, '_sml_fb_page', $page);
    update_post_meta($fb_post_id, '_sml_fb_ip', $ip);
    update_post_meta($fb_post_id, '_sml_fb_ua', $ua);
    update_post_meta($fb_post_id, '_sml_fb_ref', $ref);
}
    $subject = sprintf('[Studio Finder] %s', $kind_label);

    $body  = "Typ: {$kind_label}\n";
    if ($name)  $body .= "Name: {$name}\n";
    $body .= "E-Mail: {$email}\n";
    if ($page)  $body .= "Seite: {$page}\n";
    $body .= "\n--- Nachricht ---\n";
    $body .= wp_strip_all_tags($message);

    $headers = [];
    $headers[] = 'Reply-To: ' . $email;

    // Send to your address (fixed)
    $to = 'kontakt@sprecher-pascal.de';

    $sent = wp_mail($to, $subject, $body, $headers);

    if ( ! $sent ) {
        wp_send_json_error(['message' => 'E-Mail konnte nicht gesendet werden. Bitte später erneut versuchen.'], 500);
    }

    wp_send_json_success(['ok' => true]);
}

;

<?php
/**
 * Plugin Name: AWB Barcode Scanner (Offline Bundle)
 * Description: Lite, fast barcode scanner with rear camera, beep, Publish/Draft, editable barcode, image size control, Breakdance meta, and offline decoder (local zxing.min.js). Admin sidebar + Admin Bar + Offline Decoder page (fetch/upload).
 * Version: 1.6.4
 * Author: Wan Mohd Aiman Binawebpro.com
 * License: GPL2+
 * Text Domain: awb-barcode-scanner
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class AWB_Barcode_Scanner_Offline {
    const VERSION = '1.6.4';
    const NONCE_ACTION = 'awb_scanner_nonce_action';
    const NONCE_NAME = 'awb_scanner_nonce';
    const OPT_KEY = 'awb_scanner_settings';

    public function __construct() {
        add_action('init', [$this, 'register_shortcode']);
        add_action('init', [$this, 'register_public_meta']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_bar_menu', [$this, 'admin_bar_item'], 80);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('admin_enqueue_scripts', [$this, 'register_assets']);
        add_action('wp_ajax_awb_create_post', [$this, 'handle_create_post']);
        add_action('wp_ajax_nopriv_awb_create_post', [$this, 'handle_create_post']);
        add_action('wp_ajax_awb_check_duplicate', [$this, 'handle_check_duplicate']);
        add_action('wp_ajax_nopriv_awb_check_duplicate', [$this, 'handle_check_duplicate']);
        add_action('admin_post_awb_fetch_zxing', [$this, 'handle_fetch_zxing']);
        add_action('admin_post_awb_upload_zxing', [$this, 'handle_upload_zxing']);
        $this->maybe_set_defaults();
        $this->create_barcode_index();
    }

    private function maybe_set_defaults() {
        $defaults = [
            'button_scan' => 'SCAN NOW',
            'button_save' => 'Save Now',
            'default_status' => 'publish',
            'rear_only' => 1,
            'beep' => 1,
            'beep_volume' => 0.7,
            'aspect_ratio' => '16:9',
            'max_width' => 200,
            'max_height' => 200,
        ];
        $opt = get_option(self::OPT_KEY);
        if (!$opt) update_option(self::OPT_KEY, $defaults);
        else update_option(self::OPT_KEY, wp_parse_args($opt, $defaults));
    }

    private function create_barcode_index() {
        global $wpdb;
        
        // Check if index already exists
        $index_exists = $wpdb->get_var("
            SHOW INDEX FROM {$wpdb->postmeta} 
            WHERE Key_name = 'awb_barcode_idx'
        ");
        
        if (!$index_exists) {
            // Create index for faster barcode searches
            $wpdb->query("
                ALTER TABLE {$wpdb->postmeta} 
                ADD INDEX awb_barcode_idx (meta_key, meta_value(20))
            ");
            
            // Log the index creation
            error_log('AWB Scanner: Created database index for barcode searches');
        }
    }

    public function get_setting($key, $fallback = null) {
        $opt = get_option(self::OPT_KEY, []);
        return isset($opt[$key]) ? $opt[$key] : $fallback;
    }

    public function register_public_meta() {
        register_post_meta('post', 'awb_barcode', ['type'=>'string','single'=>true,'show_in_rest'=>true,'auth_callback'=>'__return_true']);
        register_post_meta('post', 'awb_photos', ['type'=>'string','single'=>true,'show_in_rest'=>true,'auth_callback'=>'__return_true']);
    }

    public function register_assets() {
        $handle_js = 'awb-scanner-js';
        $handle_css = 'awb-scanner-css';

        wp_register_style($handle_css, plugins_url('assets/css/awb-scanner.css', __FILE__), [], self::VERSION);
        wp_enqueue_style($handle_css);

        wp_register_script($handle_js, plugins_url('assets/js/awb-scanner.js', __FILE__), [], self::VERSION, true);
        $data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'nonce_name' => self::NONCE_NAME,
            'is_ssl' => is_ssl(),
            'plugin_url' => plugins_url('', __FILE__),
            'settings' => [
                'button_scan' => $this->get_setting('button_scan', 'SCAN NOW'),
                'button_save' => $this->get_setting('button_save', 'Save Now'),
                'rear_only'   => (int)$this->get_setting('rear_only', 1),
                'beep'        => (int)$this->get_setting('beep', 1),
                'beep_volume' => floatval($this->get_setting('beep_volume', 0.7)),
                'aspect_ratio'=> $this->get_setting('aspect_ratio', '16:9'),
                'default_status' => $this->get_setting('default_status', 'publish'),
            ]
        ];
        wp_localize_script($handle_js, 'AWB_SCANNER_VARS', $data);
        wp_enqueue_script($handle_js);
    }

    public function admin_menu() {
        add_menu_page('AWB Scanner', 'AWB Scanner', 'manage_options', 'awb-scanner', [$this, 'render_settings_page'], 'dashicons-camera', 56);
        add_submenu_page('awb-scanner', 'Settings', 'Settings', 'manage_options', 'awb-scanner', [$this, 'render_settings_page']);
        add_submenu_page('awb-scanner', 'Offline Decoder', 'Offline Decoder', 'manage_options', 'awb-scanner-offline', [$this, 'render_offline_page']);
    }

    public function admin_bar_item($wp_admin_bar) {
        if (!current_user_can('manage_options')) return;
        $wp_admin_bar->add_node([
            'id' => 'awb_scanner_top',
            'title' => 'AWB Scanner',
            'href' => admin_url('admin.php?page=awb-scanner'),
        ]);
    }

    public function render_settings_page() {
        if (isset($_POST['awb_save_settings']) && check_admin_referer('awb_save_settings_nonce')) {
            $opt = [
                'button_scan' => sanitize_text_field($_POST['button_scan'] ?? 'SCAN NOW'),
                'button_save' => sanitize_text_field($_POST['button_save'] ?? 'Save Now'),
                'default_status' => in_array($_POST['default_status'] ?? 'publish', ['publish','draft']) ? $_POST['default_status'] : 'publish',
                'rear_only' => isset($_POST['rear_only']) ? 1 : 0,
                'beep' => isset($_POST['beep']) ? 1 : 0,
                'beep_volume' => min(max(floatval($_POST['beep_volume'] ?? 0.7),0),1),
                'aspect_ratio' => in_array($_POST['aspect_ratio'] ?? '16:9', ['16:9','4:3','1:1']) ? $_POST['aspect_ratio'] : '16:9',
                'max_width' => max(50, intval($_POST['max_width'] ?? 200)),
                'max_height'=> max(50, intval($_POST['max_height'] ?? 200)),
            ];
            update_option(self::OPT_KEY, $opt);
            echo '<div class="updated"><p>Saved.</p></div>';
        }
        $opt = get_option(self::OPT_KEY, []);
        
// --- AWB: helpers for barcode duplicate + perf ---
if ( ! function_exists('awb_barcode_key') ) {
function awb_barcode_key(){ return '_awb_barcode'; }
}

if ( ! function_exists('awb_find_post_by_barcode') ) {
function awb_find_post_by_barcode( $barcode, $post_type = 'post' ){
    $q = new WP_Query([
        'post_type'      => $post_type,
        'post_status'    => ['publish','draft','pending','private'],
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'fields'         => 'ids',
        'meta_query'     => [[
            'key'   => awb_barcode_key(),
            'value' => (string) $barcode,
        ]],
    ]);
    return $q->have_posts() ? (int) $q->posts[0] : 0;
}
}

if ( ! function_exists('awb_acquire_barcode_lock') ) {
function awb_acquire_barcode_lock( $barcode ){
    $key = 'awb_lock_' . md5( (string) $barcode );
    if ( get_transient($key) ) return false;
    set_transient($key, 1, 20); // 20s lock to avoid race duplicates
    return $key;
}
}

if ( ! function_exists('awb_release_barcode_lock') ) {
function awb_release_barcode_lock( $lock_key ){
    if ( $lock_key ) delete_transient( $lock_key );
}
}
?>
        <div class="wrap">
            <h1>AWB Scanner Settings</h1>
            <form method="post">
                <?php wp_nonce_field('awb_save_settings_nonce'); ?>
                <table class="form-table">
                    <tr><th>Scan Button</th><td><input name="button_scan" value="<?php echo esc_attr($opt['button_scan'] ?? 'SCAN NOW'); ?>" class="regular-text"></td></tr>
                    <tr><th>Save Button</th><td><input name="button_save" value="<?php echo esc_attr($opt['button_save'] ?? 'Save Now'); ?>" class="regular-text"></td></tr>
                    <tr><th>Default Status</th><td>
                        <select name="default_status">
                            <option value="publish" <?php selected(($opt['default_status'] ?? 'publish')==='publish'); ?>>Publish</option>
                            <option value="draft" <?php selected(($opt['default_status'] ?? 'publish')==='draft'); ?>>Draft</option>
                        </select>
                    </td></tr>
                    <tr><th>Rear Camera Only</th><td><label><input type="checkbox" name="rear_only" <?php checked(!empty($opt['rear_only'])); ?>> Force back camera</label></td></tr>
                    <tr><th>Beep</th><td><label><input type="checkbox" name="beep" <?php checked(!empty($opt['beep'])); ?>> Enable &nbsp; Volume <input type="number" step="0.05" min="0" max="1" name="beep_volume" value="<?php echo esc_attr($opt['beep_volume'] ?? 0.7); ?>" style="width:80px;"></label></td></tr>
                    <tr><th>Aspect Ratio</th><td>
                        <select name="aspect_ratio">
                            <option>16:9</option>
                            <option <?php selected(($opt['aspect_ratio'] ?? '16:9')==='4:3'); ?>>4:3</option>
                            <option <?php selected(($opt['aspect_ratio'] ?? '16:9')==='1:1'); ?>>1:1</option>
                        </select>
                    </td></tr>
                    <tr><th>Image Save Resolution</th><td>
                        <input name="max_width" type="number" value="<?php echo esc_attr($opt['max_width'] ?? 200); ?>" style="width:90px;"> ×
                        <input name="max_height" type="number" value="<?php echo esc_attr($opt['max_height'] ?? 200); ?>" style="width:90px;"> px
                        <br><small>Smaller images = faster uploads. Recommended: 200x200px</small>
                    </td></tr>
                </table>
                <p><button class="button button-primary" name="awb_save_settings" value="1">Save Settings</button></p>
            </form>
        </div>
        <?php
    }

    public function render_offline_page() {
        $file = plugins_url('assets/vendor/zxing.min.js', __FILE__);
        $exists = file_exists(plugin_dir_path(__FILE__) . 'assets/vendor/zxing.min.js');
        ?>
        <div class="wrap">
            <h1>Offline Decoder</h1>
            <p>This plugin prefers the native <code>BarcodeDetector</code>. If your iPhone doesn't expose it, a local decoder (<code>zxing.min.js</code>) will be used to avoid CSP/CDN issues.</p>
            <h2>Status: <?php echo $exists ? '<span style="color:green">Installed</span>' : '<span style="color:red">Not Found</span>'; ?></h2>
            <?php if ($exists): ?>
                <p>Local path: <code><?php echo esc_html($file); ?></code></p>
            <?php endif; ?>
            <h3>Upload a new decoder</h3>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="awb_upload_zxing">
                <?php wp_nonce_field('awb_upload_zxing'); ?>
                <input type="file" name="zxing" accept=".js" required>
                <button class="button button-primary">Upload</button>
            </form>
            <h3>Fetch from source (server-side)</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="awb_fetch_zxing">
                <?php wp_nonce_field('awb_fetch_zxing'); ?>
                <button class="button">Fetch ZXing From Source</button>
            </form>
            <p class="description">The file is saved to <code>/assets/vendor/zxing.min.js</code>.</p>
        </div>
        <?php
    }

    public function handle_upload_zxing() {
        if (!current_user_can('manage_options') || !check_admin_referer('awb_upload_zxing')) wp_die('Not allowed');
        if (empty($_FILES['zxing']['tmp_name'])) wp_die('No file.');
        $dir = plugin_dir_path(__FILE__) . 'assets/vendor/';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        $dest = $dir . 'zxing.min.js';
        if (!move_uploaded_file($_FILES['zxing']['tmp_name'], $dest)) wp_die('Upload failed.');
        wp_safe_redirect(admin_url('admin.php?page=awb-scanner-offline&uploaded=1'));
        exit;
    }

    public function handle_fetch_zxing() {
        if (!current_user_can('manage_options') || !check_admin_referer('awb_fetch_zxing')) wp_die('Not allowed');
        $urls = [
            'https://unpkg.com/@zxing/browser@0.1.5/umd/index.js',
            'https://cdn.jsdelivr.net/npm/@zxing/browser@0.1.5/umd/index.js'
        ];
        $ok = false; $body = '';
        foreach ($urls as $u) {
            $resp = wp_remote_get($u, ['timeout' => 30]);
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                $body = wp_remote_retrieve_body($resp);
                if ($body) { $ok = true; break; }
            }
        }
        if (!$ok) wp_die('Fetch failed: server cannot reach sources.');
        $dir = plugin_dir_path(__FILE__) . 'assets/vendor/';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        file_put_contents($dir.'zxing.min.js', $body);
        wp_safe_redirect(admin_url('admin.php?page=awb-scanner-offline&fetched=1'));
        exit;
    }

    public function register_shortcode() { add_shortcode('awb_scanner', [$this, 'render_scanner']); }

    public function render_scanner($atts = []) {
        $opt = get_option(self::OPT_KEY, []);
        $scan = esc_html($opt['button_scan'] ?? 'SCAN NOW');
        $save = esc_html($opt['button_save'] ?? 'Save Now');
        $default_status = esc_html($opt['default_status'] ?? 'publish');
        ob_start(); ?>
        <div id="awb-scanner-root" class="awb-scanner">
            <div class="awb-card">
                <div class="awb-video-wrap">
                    <video id="awb-video" playsinline muted></video>
                    <div id="awb-overlay" class="awb-overlay"><div class="awb-reticle"></div></div>
                </div>
                <div class="awb-controls">
                    <button id="awb-start" class="awb-btn awb-btn-primary"><?php echo $scan; ?></button>
                    <button id="awb-stop" class="awb-btn awb-btn-outline">Stop</button>
                    <button id="awb-flash" class="awb-btn awb-btn-outline" aria-pressed="false">Flash</button>
                </div>
                <div class="awb-result">
                    <label>Detected Barcode</label>
                    <input id="awb-barcode" type="text" placeholder="— waiting —" />
                </div>
                <div class="awb-photo">
                    <label>Attach Photo(s)</label>
                    <input id="awb-photos" type="file" accept="image/*" capture="environment" multiple />
                    <div id="awb-previews" class="awb-previews"></div>
                </div>
                <div class="awb-post-fields">
                    <div class="awb-row">
                        <label>Visibility</label>
                        <select id="awb-status-select" class="awb-select">
                            <option value="publish" <?php selected($default_status==='publish'); ?>>Publish</option>
                            <option value="draft" <?php selected($default_status==='draft'); ?>>Draft</option>
                        </select>
                    </div>
                    <label>Post Content (optional)</label>
                    <textarea id="awb-content-input" rows="3" placeholder="Add any notes here..."></textarea>
                </div>
                <div class="awb-actions">
                    <button id="awb-submit" class="awb-btn awb-btn-primary"><?php echo $save; ?></button>
                    <div id="awb-status" class="awb-status"></div>
                </div>
            </div>
        </div>
        <?php return ob_get_clean();
    }

    private function resize_image_in_place($filepath, $max_w, $max_h) {
        if (!file_exists($filepath)) return;
        $editor = wp_get_image_editor($filepath);
        if (is_wp_error($editor)) return;
        $size = $editor->get_size();
        if (empty($size['width']) || empty($size['height'])) return;
        if ($size['width'] <= $max_w && $size['height'] <= $max_h) return;
        
        // Set JPEG quality to 80% for faster processing and smaller files
        if (method_exists($editor, 'set_quality')) {
            $editor->set_quality(80);
        }
        
        $editor->resize($max_w, $max_h, false);
        $editor->save($filepath);
    }

    private function insert_attachments_from_files($post_id, $files) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $max_w = intval($this->get_setting('max_width', 200));
        $max_h = intval($this->get_setting('max_height', 200));

        $attachment_ids = [];
        if (isset($files['name']) && is_array($files['name'])) {
            foreach ($files['name'] as $i => $name) {
                if (empty($files['name'][$i])) continue;
                $file_array = [
                    'name' => sanitize_file_name($files['name'][$i]),
                    'type' => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error' => $files['error'][$i] ?? 0,
                    'size' => $files['size'][$i] ?? 0,
                ];
                $overrides = ['test_form' => false];
                $movefile = wp_handle_sideload($file_array, $overrides);
                if ($movefile && !isset($movefile['error'])) {
                    $this->resize_image_in_place($movefile['file'], $max_w, $max_h);
                    $filetype = wp_check_filetype($movefile['file']);
                    $attachment = [
                        'post_mime_type' => $filetype['type'],
                        'post_title' => sanitize_file_name(pathinfo($movefile['file'], PATHINFO_FILENAME)),
                        'post_content' => '',
                        'post_status' => 'inherit',
                    ];
                    $attach_id = wp_insert_attachment($attachment, $movefile['file'], $post_id);
                    $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
                    wp_update_attachment_metadata($attach_id, $attach_data);
                    $attachment_ids[] = $attach_id;
                }
            }
        }
        if (!empty($attachment_ids)) {
            set_post_thumbnail($post_id, $attachment_ids[0]);
            update_post_meta($post_id, '_awb_photo_ids', $attachment_ids);
            update_post_meta($post_id, 'awb_photos', implode(',', $attachment_ids));
        }
        return $attachment_ids;
    }

    public function handle_check_duplicate() {
        $nonce = isset($_POST[self::NONCE_NAME]) ? sanitize_text_field($_POST[self::NONCE_NAME]) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_send_json_error(['message'=>'Invalid security token.'],403);

        $barcode_raw = isset($_POST['barcode']) ? wp_unslash($_POST['barcode']) : '';
        $barcode_raw = is_string($barcode_raw) ? trim($barcode_raw) : '';
        $barcode = sanitize_text_field($barcode_raw);
        if (empty($barcode)) wp_send_json_error(['message'=>'No barcode provided.'],400);

        $barcode_norm = function_exists('awb_normalize_barcode') ? awb_normalize_barcode($barcode_raw) : $barcode;

        // Use shared lookup helpers so all meta variations are checked
        $existing_post_id = 0;
        if (function_exists('awb_find_post_by_barcode_any')) {
            $existing_post_id = awb_find_post_by_barcode_any($barcode_raw);
        } elseif (function_exists('awb_find_post_by_barcode_robust')) {
            $existing_post_id = awb_find_post_by_barcode_robust($barcode_norm, 'post');
        } elseif (function_exists('awb_find_post_by_barcode')) {
            $existing_post_id = awb_find_post_by_barcode($barcode_norm, 'post');
        }

        if ($existing_post_id) {
            wp_send_json_success([
                'is_duplicate' => true,
                'existing_post_id' => $existing_post_id,
                'existing_link' => get_permalink($existing_post_id)
            ]);
        } else {
            wp_send_json_success(['is_duplicate' => false]);
        }
    }

    public function handle_create_post(){
    /* AWB: relaxed auth for scanner roles */
    $nonce = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_POST['_ajax_nonce']) ? $_POST['_ajax_nonce'] : '');
    if ($nonce && function_exists('wp_verify_nonce') && !wp_verify_nonce($nonce, 'awb_scan')) {
        wp_send_json_error(['message' => 'Invalid nonce'], 403);
    }
    if ( ! is_user_logged_in() || ! current_user_can('read') ) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }

    $barcode    = isset($_POST['barcode']) ? trim(wp_unslash($_POST['barcode'])) : '';
    $barcode_raw = isset($_POST['barcode_raw']) ? trim(wp_unslash($_POST['barcode_raw'])) : $barcode;
    $barcode_norm = function_exists('awb_normalize_barcode') ? awb_normalize_barcode($barcode) : $barcode;
    $title      = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : ('Parcel ' . $barcode);
    $content    = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
    $post_type  = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : 'post';

    if ( $barcode === '' ) {
        wp_send_json_error(['message' => 'Barcode missing'], 400);
    }

    // Server-side duplicate prevention
    $existing_id = function_exists('awb_find_post_by_barcode_any') ? awb_find_post_by_barcode_any($barcode) : (function_exists('awb_find_post_by_barcode_robust') ? awb_find_post_by_barcode_robust($barcode, $post_type) : (function_exists('awb_find_post_by_barcode') ? awb_find_post_by_barcode($barcode, $post_type) : 0));
    if ( $existing_id ) {
        wp_send_json_success([
            'message' => 'Duplicate prevented: already exists.',
            'post_id' => $existing_id,
            'edit'    => get_edit_post_link($existing_id, 'raw'),
            'view'    => get_permalink($existing_id),
            'duplicate' => true,
        ]);
    }

    // Short lock to prevent race duplicates
    $lock_key = awb_acquire_barcode_lock( $barcode );
    if ( ! $lock_key ) {
        wp_send_json_success([
            'message' => 'Duplicate prevented (in progress by another operator).',
            'duplicate' => true,
        ]);
    }

    // Create post
    $postarr = [
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => 'publish',
        'post_type'    => $post_type,
    ];

    $post_id = wp_insert_post( $postarr, true );
    if ( is_wp_error($post_id) ) {
        awb_release_barcode_lock($lock_key);
        wp_send_json_error(['message' => $post_id->get_error_message()], 500);
    }

    // Save the barcode meta
    add_post_meta( $post_id, awb_barcode_key(), (string) $barcode_norm, true );
    update_post_meta( $post_id, 'awb_barcode', (string) $barcode_norm );
    update_post_meta( $post_id, 'awb_barcode_norm', (string) $barcode_norm );
    update_post_meta( $post_id, 'awb_barcode_raw', (string) $barcode_raw );

    // Faster media handling (disable sizes + lower quality + pre-scale)
    if ( ! empty($_FILES['photos']) ) {
        awb_insert_attachments_from_files( $post_id, $_FILES['photos'] );
    }

    awb_release_barcode_lock($lock_key);

    wp_send_json_success([
        'message' => 'Saved',
        'post_id' => $post_id,
        'edit'    => get_edit_post_link($post_id, 'raw'),
        'view'    => get_permalink($post_id),
        'duplicate' => false,
    ]);
}
}
new AWB_Barcode_Scanner_Offline();

function awb_insert_attachments_from_files( $post_id, $files_field ){
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // Disable generating intermediate sizes ONLY during these uploads
    $sizes_cb = function( $sizes ){ return []; };
    $quality_cb = function( $q ){ return 70; }; // good balance

    add_filter('intermediate_image_sizes_advanced', $sizes_cb, 99);
    add_filter('wp_editor_set_quality', $quality_cb, 99);

    try {
        $file_count = is_array($files_field['name']) ? count($files_field['name']) : 0;
        for ( $i = 0; $i < $file_count; $i++ ) {
            if ( ! isset($files_field['name'][$i]) || $files_field['error'][$i] !== UPLOAD_ERR_OK ) continue;

            $single_file = [
                'name'     => $files_field['name'][$i],
                'type'     => $files_field['type'][$i],
                'tmp_name' => $files_field['tmp_name'][$i],
                'error'    => $files_field['error'][$i],
                'size'     => $files_field['size'][$i],
            ];

            // Pre-scale huge camera images
            $tmp = $single_file['tmp_name'];
            $editor = wp_get_image_editor( $tmp );
            if ( ! is_wp_error($editor) ) {
                $editor->set_quality(70);
                $size = $editor->get_size();
                $max_w = 1600; $max_h = 1600;
                if ( $size && ($size['width'] > $max_w || $size['height'] > $max_h) ) {
                    $editor->resize( $max_w, $max_h, false );
                }
                $editor->save( $tmp ); // strips most EXIF, respects quality
            }

            // Sideload
            $attachment_id = media_handle_sideload( $single_file, $post_id );
            if ( is_wp_error($attachment_id) ) continue;

            if ( ! has_post_thumbnail($post_id) ) {
                set_post_thumbnail( $post_id, $attachment_id );
            }
        }
    } finally {
        remove_filter('intermediate_image_sizes_advanced', $sizes_cb, 99);
        remove_filter('wp_editor_set_quality', $quality_cb, 99);
    }
}


if ( ! function_exists('awb_find_post_by_barcode_robust') ) {
function awb_find_post_by_barcode_robust( $barcode, $post_type = 'post' ){
    $keys = array('_awb_barcode','awb_barcode','barcode','tracking_no','tracking_number');
    foreach ($keys as $k){
        $q = new WP_Query([
            'post_type'      => $post_type,
            'post_status'    => ['publish','draft','pending','private'],
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'meta_query'     => [[ 'key' => $k, 'value' => (string)$barcode ]],
        ]);
        if ( $q->have_posts() ) return (int) $q->posts[0];
    }
    return 0;
}
}



// AJAX: check if barcode exists (for pre-submit warning/beep)
add_action('wp_ajax_awb_check_barcode','awb_ajax_check_barcode');
add_action('wp_ajax_nopriv_awb_check_barcode','awb_ajax_check_barcode');
if ( ! function_exists('awb_ajax_check_barcode') ) {
function awb_ajax_check_barcode(){
    $barcode    = isset($_POST['barcode']) ? trim(wp_unslash($_POST['barcode'])) : '';
    $barcode_raw = isset($_POST['barcode_raw']) ? trim(wp_unslash($_POST['barcode_raw'])) : $barcode;
    $barcode_norm = function_exists('awb_normalize_barcode') ? awb_normalize_barcode($barcode) : $barcode;
    $post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : 'post';
    if ($barcode === '') {
        wp_send_json_error(['message'=>'Missing barcode'], 400);
    }
    $existing_id = function_exists('awb_find_post_by_barcode_any') ? awb_find_post_by_barcode_any($barcode) : (function_exists('awb_find_post_by_barcode_robust') ? awb_find_post_by_barcode_robust($barcode, $post_type) : (function_exists('awb_find_post_by_barcode') ? awb_find_post_by_barcode($barcode, $post_type) : 0));
    if ($existing_id) {
        wp_send_json_success(['exists'=>true,'post_id'=>$existing_id,'view'=>get_permalink($existing_id)]);
    } else {
        wp_send_json_success(['exists'=>false]);
    }
}
}



// Normalize barcode: trim, uppercase, strip non-alphanumerics
if ( ! function_exists('awb_normalize_barcode') ) {
function awb_normalize_barcode( $raw ){
    $s = strtoupper( trim( (string) $raw ) );
    $s = preg_replace('/[^A-Z0-9]/', '', $s);
    return $s;
}
}



if ( ! function_exists('awb_find_post_by_barcode_any') ) {
function awb_find_post_by_barcode_any( $barcode_raw ){
    $norm = awb_normalize_barcode($barcode_raw);
    // 1) normalized meta key first
    $q = new WP_Query([
        'post_type'      => 'any',
        'post_status'    => ['publish','draft','pending','private'],
        'posts_per_page' => 1,
        'no_found_rows'  => true,
        'fields'         => 'ids',
        'meta_query'     => [[ 'key' => 'awb_barcode_norm', 'value' => $norm ]],
    ]);
    if ( $q->have_posts() ) return (int) $q->posts[0];

    // 2) legacy/meta keys exact (raw or normalized) across common keys
    $keys = array('_awb_barcode','awb_barcode','barcode','tracking_no','tracking_number');
    foreach ($keys as $k){
        $q2 = new WP_Query([
            'post_type'      => 'any',
            'post_status'    => ['publish','draft','pending','private'],
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'   => $k,
                'value' => array( (string)$barcode_raw, $norm ),
                'compare' => 'IN',
            ]],
        ]);
        if ( $q2->have_posts() ) return (int) $q2->posts[0];
    }
    return 0;
}
}


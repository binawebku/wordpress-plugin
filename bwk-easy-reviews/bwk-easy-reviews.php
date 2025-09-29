<?php
/*
Plugin Name: BWK Easy Reviews for WooCommerce
Plugin URI: https://binawebpro.com
Description: Verified-buyer review forms on product & order pages, masonry shortcodes, and admin settings. Breakdance-friendly.
Version: 1.6.4
Author: Wan Mohd Aiman Binawebpro.com
Text Domain: bwk-easy-reviews
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BWK_Easy_Reviews {
    const V = '1.6.4';
    const NS = 'bwk-er';
    const OPT = 'bwk_er_settings';
    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        // Require WooCommerce
        add_action('plugins_loaded', function() {
            if ( ! class_exists('WooCommerce') ) {
                add_action('admin_notices', function(){
                    echo '<div class="notice notice-error"><p><strong>BWK Easy Reviews</strong> requires WooCommerce.</p></div>';
                });
            }
        });

        // Assets
        add_action('wp_enqueue_scripts', [$this, 'assets']);

        // Product page prompt
        add_action('woocommerce_single_product_summary', [$this, 'maybe_render_product_prompt'], 35);

        // Add action in My Orders
        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'order_actions'], 10, 2);
        add_action('woocommerce_view_order', [$this, 'render_order_review_block'], 25);

        // AJAX
        add_action('wp_ajax_bwk_er_submit_review', [$this, 'ajax_submit_review']);

        // Shortcodes
        add_shortcode('bwk_review_product', [$this, 'sc_review_product']);
        add_shortcode('bwk_review_list', [$this, 'sc_review_list']);
        add_shortcode('bwk_reviews_masonry', [$this, 'sc_reviews_masonry']);
        add_shortcode('bwk_all_reviews_masonry', [$this, 'sc_all_reviews_masonry']);
        add_shortcode('bwk_my_reviews', [$this, 'sc_my_reviews']);

        // Ensure ratings enabled
        add_action('admin_init', function(){
            if ( 'yes' !== get_option('woocommerce_enable_review_rating') ) {
                update_option('woocommerce_enable_review_rating', 'yes');
            }
        });

        // Admin settings page
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);

        // Admin: Comments → Product Reviews (list table filtered to type=review)
        add_action('admin_menu', [$this, 'register_reviews_menu']);
    }

    // ========== Settings helpers
    private function defaults() {
        return [
            'enabled_product_prompt' => 1,
            'enabled_order_block'    => 1,
            'auto_approve'           => 0,
            'button_color'           => '#00880C',
            'box_bg_color'           => '#f4f1ea',
            'text_thank'             => __('Thanks! Your review was submitted.', 'bwk-easy-reviews'),
            'text_error'             => __('Could not submit review. Please try again.', 'bwk-easy-reviews'),
            'font_size'              => '14px',
            'font_weight'            => '400',
            'font_color'             => '#111827',
        ];
    }
    private function s() {
        $s = wp_parse_args(get_option(self::OPT, []), $this->defaults());
        foreach (['button_color','box_bg_color','font_color'] as $key) {
            if (!preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $s[$key])) {
                $s[$key] = $this->defaults()[$key];
            }
        }
        return $s;
    }

    public function register_settings(){
        register_setting(self::OPT, self::OPT, [$this, 'sanitize_settings']);
    }
    public function sanitize_settings($in){
        $d = $this->defaults();
        $out = [];
        $out['enabled_product_prompt'] = empty($in['enabled_product_prompt']) ? 0 : 1;
        $out['enabled_order_block']    = empty($in['enabled_order_block']) ? 0 : 1;
        $out['auto_approve']           = empty($in['auto_approve']) ? 0 : 1;
        $out['button_color']           = (isset($in['button_color']) && preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $in['button_color'])) ? $in['button_color'] : $d['button_color'];
        $out['box_bg_color']           = (isset($in['box_bg_color']) && preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $in['box_bg_color'])) ? $in['box_bg_color'] : $d['box_bg_color'];
        $out['text_thank']             = isset($in['text_thank']) ? wp_kses_post($in['text_thank']) : $d['text_thank'];
        $out['text_error']             = isset($in['text_error']) ? wp_kses_post($in['text_error']) : $d['text_error'];
        $out['font_size']              = isset($in['font_size']) ? sanitize_text_field($in['font_size']) : $d['font_size'];
        $out['font_weight']            = isset($in['font_weight']) ? sanitize_text_field($in['font_weight']) : $d['font_weight'];
        $out['font_color']             = (isset($in['font_color']) && preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $in['font_color'])) ? $in['font_color'] : $d['font_color'];
        return $out;
    }

    public function admin_menu(){
        add_submenu_page(
            'woocommerce',
            __('BWK Easy Reviews', 'bwk-easy-reviews'),
            __('BWK Easy Reviews', 'bwk-easy-reviews'),
            'manage_options',
            'bwk-easy-reviews',
            [$this, 'settings_page']
        );
    }
    public function admin_assets($hook){
        if (isset($_GET['page']) && $_GET['page']==='bwk-easy-reviews') {
            wp_enqueue_style(self::NS.'-admin', plugins_url('assets/bwk-er-admin.css', __FILE__), [], self::V);
        }
    }
    public function register_reviews_menu(){
        add_submenu_page(
            'edit-comments.php',
            __('Product Reviews','bwk-easy-reviews'),
            __('Product Reviews','bwk-easy-reviews'),
            'moderate_comments',
            'bwk-product-reviews',
            [$this, 'reviews_screen']
        );
    }
    public function reviews_screen(){
        if ( ! current_user_can('moderate_comments') ) { return; }
        require_once ABSPATH . 'wp-admin/includes/class-wp-comments-list-table.php';
        echo '<div class="wrap"><h1>'.esc_html__('Product Reviews','bwk-easy-reviews').'</h1>';
        $_GET['type'] = 'review';
        $_REQUEST['type'] = 'review';
        $table = new WP_Comments_List_Table();
        $table->prepare_items();
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="bwk-product-reviews" />';
        echo '<input type="hidden" name="type" value="review" />';
        $table->search_box( esc_html__('Search Reviews','bwk-easy-reviews'), 'bwk-er' );
        $table->display();
        echo '</form></div>';
    }
    public function settings_page(){
        if ( ! current_user_can('manage_options') ) { return; }
        $opt = $this->s();
        ?>
        <div class="wrap bwk-er-admin">
            <h1>BWK Easy Reviews — Settings</h1>
            <p style="margin:8px 0 16px;">
                <a class="button button-primary" href="<?php echo esc_url( admin_url('edit-comments.php?page=bwk-product-reviews') ); ?>">Open Product Reviews</a>
                <a class="button" href="<?php echo esc_url( admin_url('edit.php?post_type=product') ); ?>">Open Products</a>
            </p>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPT); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Enable on Product Page</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[enabled_product_prompt]" value="1" <?php checked($opt['enabled_product_prompt'],1); ?>> Show inline review card for verified buyers</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Enable on Order Page</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[enabled_order_block]" value="1" <?php checked($opt['enabled_order_block'],1); ?>> Show review cards in My Account → Order view</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-approve Verified Buyer Reviews</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPT); ?>[auto_approve]" value="1" <?php checked($opt['auto_approve'],1); ?>> Bypass moderation if user is verified buyer</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Button Color</th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[button_color]" value="<?php echo esc_attr($opt['button_color']); ?>" placeholder="#00880C">
                            <p class="description">Hex color (e.g. #00880C). Used for Submit button + accents.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Box Background Color</th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[box_bg_color]" value="<?php echo esc_attr($opt['box_bg_color']); ?>" placeholder="#f4f1ea">
                            <p class="description">Hex color for review card background.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Success Text</th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[text_thank]" value="<?php echo esc_attr($opt['text_thank']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Error Text</th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[text_error]" value="<?php echo esc_attr($opt['text_error']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Font Size</th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[font_size]" value="<?php echo esc_attr($opt['font_size']); ?>" placeholder="14px"></td>
                    </tr>
                    <tr>
                        <th scope="row">Font Weight</th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[font_weight]" value="<?php echo esc_attr($opt['font_weight']); ?>" placeholder="400"></td>
                    </tr>
                    <tr>
                        <th scope="row">Font Color</th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr(self::OPT); ?>[font_color]" value="<?php echo esc_attr($opt['font_color']); ?>" placeholder="#111827"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2 style="margin-top:24px;">Shortcodes</h2>
            <ul class="ul-disc">
                <li><code>[bwk_review_product id="123"]</code> — Render review card for a product (auto-detect on single product page)</li>
                <li><code>[bwk_review_list]</code> — Render all purchased-but-not-reviewed products for current user</li>
                <li><code>[bwk_reviews_masonry product_id="current|123|all" per_page="20" order="DESC"]</code> — Masonry grid of reviews</li>
                <li><code>[bwk_all_reviews_masonry per_page="50" order="DESC"]</code> — Summary header + all reviews masonry (site-wide)</li>
                <li><code>[bwk_my_reviews per_page="-1" order="DESC"]</code> — Masonry grid of reviews written by the logged-in user</li>
            </ul>
        </div>
        <?php
    }

    // ========== Helpers
    private function bought($user_id, $product_id) {
        if (!$user_id) return false;
        return function_exists('wc_customer_bought_product') ? wc_customer_bought_product(wp_get_current_user()->user_email, $user_id, $product_id) : false;
    }
    private function already_reviewed($user_id, $product_id) {
        if (!$user_id || !$product_id) {
            return false;
        }

        $comments = get_comments([
            'user_id' => $user_id,
            'post_id' => $product_id,
            'type'    => 'review',
            'status'  => 'all',
        ]);

        if (empty($comments)) {
            return false;
        }

        foreach ($comments as $comment) {
            if (in_array($comment->comment_approved, ['spam', 'trash'], true)) {
                continue;
            }

            return true;
        }

        return false;
    }
    private function can_review($user_id, $product_id) {
        return $this->bought($user_id, $product_id) && ! $this->already_reviewed($user_id, $product_id);
    }

    // ========== Assets
    public function assets() {
        $s = $this->s();
        wp_register_style(self::NS, plugins_url('assets/bwk-er.css', __FILE__), [], self::V);
        wp_register_style(self::NS.'-all', plugins_url('assets/bwk-er-all.css', __FILE__), [], self::V);
        wp_register_style(self::NS.'-masonry', plugins_url('assets/bwk-er-masonry.css', __FILE__), [], self::V);

        $inline = ':root{--bwk-er-accent: '.esc_attr($s['button_color']).'; --bwk-er-bg: '.esc_attr($s['box_bg_color']).'; --bwk-er-font-size: '.esc_attr($s['font_size']).'; --bwk-er-font-weight: '.esc_attr($s['font_weight']).'; --bwk-er-font-color: '.esc_attr($s['font_color']).';}';
        wp_add_inline_style(self::NS, $inline);

        wp_register_script(self::NS, plugins_url('assets/bwk-er.js', __FILE__), ['jquery'], self::V, true);
        wp_localize_script(self::NS, 'BWKER', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bwk_er_nonce'),
            'i18n'  => [
                'submit' => __('Submit review', 'bwk-easy-reviews'),
                'thank'  => $s['text_thank'],
                'error'  => $s['text_error'],
                'login'  => __('Please log in to review.', 'bwk-easy-reviews'),
            ]
        ]);
    }

    private function enqueue_now() {
        wp_enqueue_style(self::NS);
        wp_enqueue_script(self::NS);
    }

    // ========== UI Blocks
    public function form_markup($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) return '';
        $user_id = get_current_user_id();
        if (!is_user_logged_in()) {
            $login_url = wp_login_url(get_permalink($product_id));
            return '<div class="bwk-er-card"><p>'.esc_html__('Please log in to review.', 'bwk-easy-reviews').'</p><a class="bwk-er-btn" href="'.esc_url($login_url).'">'.esc_html__('Login', 'bwk-easy-reviews').'</a></div>';
        }
        if ( ! $this->can_review($user_id, $product_id) ) {
            return '<div class="bwk-er-card"><p>'.esc_html__('You have already reviewed or not purchased this product with this account.', 'bwk-easy-reviews').'</p></div>';
        }
        ob_start(); ?>
        <div class="bwk-er-card" id="bwk-er-form-<?php echo esc_attr($product_id); ?>" data-product="<?php echo esc_attr($product_id); ?>">
            <div class="bwk-er-head">
                <div class="bwk-er-prod">
                    <?php echo $product->get_image('thumbnail'); ?>
                    <div>
                        <div class="bwk-er-prod-title"><?php echo esc_html($product->get_name()); ?></div>
                        <div class="bwk-er-prod-sub"><?php echo esc_html__('Verified purchase review', 'bwk-easy-reviews'); ?></div>
                    </div>
                </div>
                <button class="bwk-er-close" type="button" aria-label="<?php esc_attr_e('Close', 'bwk-easy-reviews'); ?>">×</button>
            </div>
            <div class="bwk-er-rating">
                <label><?php esc_html_e('Rating', 'bwk-easy-reviews'); ?></label>
                <div class="bwk-er-stars" role="radiogroup" aria-label="<?php esc_attr_e('Select rating', 'bwk-easy-reviews'); ?>">
                    <?php for($i=1;$i<=5;$i++): ?>
                        <button type="button" class="bwk-er-star" data-value="<?php echo $i; ?>" aria-pressed="false">★</button>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="bwk-er-field">
                <label for="bwk-er-title-<?php echo esc_attr($product_id); ?>"><?php esc_html_e('Title (optional)', 'bwk-easy-reviews'); ?></label>
                <input id="bwk-er-title-<?php echo esc_attr($product_id); ?>" type="text" class="bwk-er-input" maxlength="120" placeholder="<?php esc_attr_e('Great product!', 'bwk-easy-reviews'); ?>">
            </div>
            <div class="bwk-er-field">
                <label for="bwk-er-text-<?php echo esc_attr($product_id); ?>"><?php esc_html_e('Your review', 'bwk-easy-reviews'); ?></label>
                <textarea id="bwk-er-text-<?php echo esc_attr($product_id); ?>" class="bwk-er-textarea" rows="5" placeholder="<?php esc_attr_e('Share your experience...', 'bwk-easy-reviews'); ?>"></textarea>
            </div>
            <div class="bwk-er-actions">
                <button class="bwk-er-btn bwk-er-submit" type="button"><?php esc_html_e('Submit review', 'bwk-easy-reviews'); ?></button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // Product page prompt
    public function maybe_render_product_prompt() {
        if ( ! $this->s()['enabled_product_prompt'] ) return;
        global $product;
        if ( ! $product || ! is_user_logged_in() ) return;
        $product_id = $product->get_id();
        if ( $this->can_review(get_current_user_id(), $product_id) ) {
            $this->enqueue_now();
            echo '<div class="bwk-er-inline">'.$this->form_markup($product_id).'</div>';
        }
    }

    // Add "Review" action to orders list
    public function order_actions($actions, $order) {
        if ( ! $this->s()['enabled_order_block'] ) return $actions;
        if ( ! $order instanceof WC_Order ) return $actions;
        if ( ! is_user_logged_in() || get_current_user_id() !== $order->get_user_id() ) return $actions;
        foreach ( $order->get_items() as $item ) {
            $pid = $item->get_product_id();
            if ( $this->can_review(get_current_user_id(), $pid) ) {
                $actions['bwk_review'] = [
                    'url'  => $order->get_view_order_url() . '#bwk-er-order',
                    'name' => __('Review items', 'bwk-easy-reviews'),
                ];
                break;
            }
        }
        return $actions;
    }

    // Render block in order view page
    public function render_order_review_block($order_id) {
        if ( ! $this->s()['enabled_order_block'] ) return;
        $order = wc_get_order($order_id);
        if ( ! $order || ! is_user_logged_in() || get_current_user_id() !== $order->get_user_id() ) return;
        $this->enqueue_now();
        echo '<div id="bwk-er-order" class="bwk-er-grid">';
        foreach ( $order->get_items() as $item ) {
            $pid = $item->get_product_id();
            if ( $this->can_review(get_current_user_id(), $pid) ) {
                echo $this->form_markup($pid);
            }
        }
        echo '</div>';
    }

    // ========== Shortcodes
    public function sc_review_product($atts) {
        $atts = shortcode_atts(['id' => 0], $atts, 'bwk_review_product');
        $pid = absint($atts['id']);
        if (!$pid) {
            global $product;
            if ($product) $pid = $product->get_id();
        }
        if (!$pid) return '';
        $this->enqueue_now();
        return '<div class="bwk-er-inline">'.$this->form_markup($pid).'</div>';
    }

    public function sc_review_list($atts) {
        if (!is_user_logged_in()) return '';
        $this->enqueue_now();
        $orders = wc_get_orders([
            'customer' => get_current_user_id(),
            'status'   => array_keys(wc_get_is_paid_statuses()),
            'limit'    => -1,
            'return'   => 'objects',
        ]);
        $pids = [];
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $pid = $item->get_product_id();
                if ( $this->can_review(get_current_user_id(), $pid) ) $pids[$pid] = true;
            }
        }
        if (empty($pids)) return '<div class="bwk-er-card"><p>'.esc_html__('No products to review right now.', 'bwk-easy-reviews').'</p></div>';
        $out = '<div class="bwk-er-grid">';
        foreach (array_keys($pids) as $pid) {
            $out .= $this->form_markup($pid);
        }
        $out .= '</div>';
        return $out;
    }

    public function sc_reviews_masonry($atts){
        $atts = shortcode_atts([
            'product_id' => 'current',
            'per_page'   => 20,
            'order'      => 'DESC',
        ], $atts, 'bwk_reviews_masonry');

        $product_ids = [];
        if ($atts['product_id'] === 'all') {
            $product_ids = [];
        } else {
            $pid = 0;
            if ($atts['product_id'] === 'current') {
                global $product;
                if ($product) $pid = $product->get_id();
            } else {
                $pid = absint($atts['product_id']);
            }
            if ($pid) $product_ids = [$pid];
        }

        $args = [
            'status'   => 'approve',
            'type'     => 'review',
            'orderby'  => 'comment_date_gmt',
            'order'    => in_array(strtoupper($atts['order']), ['ASC','DESC']) ? strtoupper($atts['order']) : 'DESC',
            'number'   => intval($atts['per_page']) === -1 ? 0 : intval($atts['per_page']),
        ];
        if (!empty($product_ids)) {
            $args['post__in'] = $product_ids;
        }
        $comments = get_comments($args);
        if (!$comments) {
            return '<div class="bwk-er-card"><p>'.esc_html__('No reviews yet.', 'bwk-easy-reviews').'</p></div>';
        }
        $this->enqueue_now();
        wp_enqueue_style(self::NS.'-masonry');

        ob_start();
        echo '<div class="bwk-er-masonry">';
        foreach ($comments as $c) {
            $rating = intval(get_comment_meta($c->comment_ID, 'rating', true));
            $verified = function_exists('wc_review_is_from_verified_owner') ? wc_review_is_from_verified_owner($c->comment_ID) : false;
            $product = wc_get_product($c->comment_post_ID);
            $prod_name = $product ? $product->get_name() : '';
            $prod_img  = $product ? $product->get_image('thumbnail') : '';
            ?>
            <article class="bwk-er-card bwk-er-review">
                <header class="bwk-er-review-hd">
                    <div class="bwk-er-review-left">
                        <?php echo $prod_img; ?>
                        <div class="bwk-er-meta">
                            <div class="bwk-er-author"><?php echo esc_html($c->comment_author); ?><?php if($verified): ?> <span class="bwk-er-verified" title="<?php esc_attr_e('Verified','bwk-easy-reviews'); ?>">✔</span><?php endif; ?></div>
                            <div class="bwk-er-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($c->comment_date))); ?></div>
                        </div>
                    </div>
                    <div class="bwk-er-stars-row" aria-label="<?php echo esc_attr($rating); ?>">
                        <?php for($i=1;$i<=5;$i++): ?>
                            <span class="bwk-er-star-read<?php echo $i <= $rating ? ' on':''; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                </header>
                <div class="bwk-er-titleline">
                    <span class="bwk-er-prodname"><?php echo esc_html($prod_name); ?></span>
                </div>
                <div class="bwk-er-content"><?php echo wpautop(esc_html($c->comment_content)); ?></div>
            </article>
            <?php
        }
        echo '</div>';
        return ob_get_clean();
    }

    public function sc_all_reviews_masonry($atts){
        $atts = shortcode_atts([
            'per_page' => 50,
            'order'    => 'DESC',
        ], $atts, 'bwk_all_reviews_masonry');

        $args = [
            'status'   => 'approve',
            'type'     => 'review',
            'orderby'  => 'comment_date_gmt',
            'order'    => in_array(strtoupper($atts['order']), ['ASC','DESC']) ? strtoupper($atts['order']) : 'DESC',
            'number'   => intval($atts['per_page']) == -1 ? 0 : intval($atts['per_page']),
        ];
        $comments = get_comments($args);
        $count = count($comments);
        $stars = [1=>0,2=>0,3=>0,4=>0,5=>0];
        $sum = 0;
        foreach($comments as $c){
            $r = intval(get_comment_meta($c->comment_ID, 'rating', true));
            if ($r >=1 && $r <=5){ $stars[$r]++; $sum += $r; }
        }
        $avg = $count ? round($sum / $count, 1) : 0;

        $this->enqueue_now();
        wp_enqueue_style(self::NS.'-masonry');
        wp_enqueue_style(self::NS.'-all');

        ob_start(); ?>
        <section class="bwk-er-summary">
            <div class="bwk-er-summary-left">
                <div class="bwk-er-score"><?php echo esc_html($avg); ?></div>
                <div class="bwk-er-total"><?php echo esc_html($count); ?> <?php esc_html_e('Reviews','bwk-easy-reviews'); ?></div>
            </div>
            <div class="bwk-er-bars">
                <?php for($i=5;$i>=1;$i--):
                    $pct = $count ? ( $stars[$i] / $count * 100 ) : 0; ?>
                <div class="bwk-er-barrow">
                    <div class="bwk-er-barlabel">
                        <?php for($s=1;$s<=5;$s++): ?>
                            <span class="bwk-er-star-read<?php echo $s <= $i ? ' on':''; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                    <div class="bwk-er-bar">
                        <span style="width: <?php echo esc_attr(number_format_i18n($pct,0)); ?>%;"></span>
                    </div>
                    <div class="bwk-er-count">(<?php echo esc_html($stars[$i]); ?>)</div>
                </div>
                <?php endfor; ?>
            </div>
            <div class="bwk-er-actions">
                <a href="#write-review" class="bwk-er-btn bwk-er-jump"><?php esc_html_e('Write a review','bwk-easy-reviews'); ?></a>
            </div>
        </section>
        <?php
        echo $this->sc_reviews_masonry(['product_id'=>'all','per_page'=>$atts['per_page'],'order'=>$atts['order']]);
        return ob_get_clean();
    }

    public function sc_my_reviews($atts){
        if ( ! is_user_logged_in() ){
            return '<div class="bwk-er-card"><p>'.esc_html__('Please log in to see your reviews.', 'bwk-easy-reviews').'</p></div>';
        }
        $atts = shortcode_atts([
            'per_page' => -1,
            'order'    => 'DESC',
        ], $atts, 'bwk_my_reviews');

        $args = [
            'status'   => 'approve',
            'type'     => 'review',
            'orderby'  => 'comment_date_gmt',
            'order'    => in_array(strtoupper($atts['order']), ['ASC','DESC']) ? strtoupper($atts['order']) : 'DESC',
            'user_id'  => get_current_user_id(),
            'number'   => intval($atts['per_page']) == -1 ? 0 : intval($atts['per_page']),
        ];
        $comments = get_comments($args);
        if (!$comments){
            return '<div class="bwk-er-card"><p>'.esc_html__('You have not written any reviews yet.', 'bwk-easy-reviews').'</p></div>';
        }
        $this->enqueue_now();
        wp_enqueue_style(self::NS.'-masonry');

        ob_start();
        echo '<div class="bwk-er-masonry">';
        foreach ($comments as $c) {
            $rating = intval(get_comment_meta($c->comment_ID, 'rating', true));
            $product = wc_get_product($c->comment_post_ID);
            $prod_name = $product ? $product->get_name() : '';
            $prod_img  = $product ? $product->get_image('thumbnail') : '';
            $prod_url  = $product ? get_permalink($product->get_id()) : '#';
            ?>
            <article class="bwk-er-card bwk-er-review">
                <header class="bwk-er-review-hd">
                    <a class="bwk-er-review-left" href="<?php echo esc_url($prod_url); ?>">
                        <?php echo $prod_img; ?>
                        <div class="bwk-er-meta">
                            <div class="bwk-er-author"><?php echo esc_html(get_the_author_meta('display_name', get_current_user_id())); ?></div>
                            <div class="bwk-er-date"><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($c->comment_date))); ?></div>
                        </div>
                    </a>
                    <div class="bwk-er-stars-row" aria-label="<?php echo esc_attr($rating); ?>">
                        <?php for($i=1;$i<=5;$i++): ?>
                            <span class="bwk-er-star-read<?php echo $i <= $rating ? ' on':''; ?>">★</span>
                        <?php endfor; ?>
                    </div>
                </header>
                <div class="bwk-er-titleline">
                    <a class="bwk-er-prodname" href="<?php echo esc_url($prod_url); ?>"><?php echo esc_html($prod_name); ?></a>
                </div>
                <div class="bwk-er-content"><?php echo wpautop(esc_html($c->comment_content)); ?></div>
            </article>
            <?php
        }
        echo '</div>';
        return ob_get_clean();
    }

    // ========== AJAX submit
    public function ajax_submit_review() {
        $s = $this->s();
        if ( ! check_ajax_referer('bwk_er_nonce', 'nonce', false) ) {
            wp_send_json_error(['message' => __('Invalid request.', 'bwk-easy-reviews')], 400);
        }
        if ( ! is_user_logged_in() ) {
            wp_send_json_error(['message' => __('Please log in.', 'bwk-easy-reviews')], 401);
        }

        $user = wp_get_current_user();
        $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $rating     = isset($_POST['rating']) ? absint($_POST['rating']) : 0;
        $title      = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $content    = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';

        if ( ! $product_id || $rating < 1 || $rating > 5 || empty($content) ) {
            wp_send_json_error(['message' => __('Please provide rating and review text.', 'bwk-easy-reviews')], 422);
        }
        if ( ! $this->can_review($user->ID, $product_id) ) {
            wp_send_json_error(['message' => __('You cannot review this product.', 'bwk-easy-reviews')], 403);
        }

        $approved = $s['auto_approve'] ? 1 : 0;
        $commentdata = [
            'comment_post_ID'      => $product_id,
            'comment_author'       => $user->display_name ?: $user->user_login,
            'comment_author_email' => $user->user_email,
            'comment_content'      => $title ? ($title . ' — ' . $content) : $content,
            'comment_type'         => 'review',
            'user_id'              => $user->ID,
            'comment_approved'     => $approved,
        ];
        $cid = wp_insert_comment($commentdata);
        if ($cid && ! is_wp_error($cid)) {
            update_comment_meta($cid, 'rating', $rating);
            do_action('comment_post', $cid, $commentdata['comment_approved'], $commentdata);
            wp_send_json_success(['message' => __('Review submitted.', 'bwk-easy-reviews')]);
        } else {
            wp_send_json_error(['message' => __('Unable to save review.', 'bwk-easy-reviews')], 500);
        }
    }
}

BWK_Easy_Reviews::instance();

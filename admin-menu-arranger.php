<?php
/**
 * Plugin Name: Admin Menu Arranger
 * Description: Allows a specific administrator to hide, rename and reorder WordPress admin menus and toolbar items.
 * Version: 1.1.0
 * Author: Wan Mohd Aiman Binawebpro.com
 * Text Domain: admin-menu-arranger
 */

if (!defined('ABSPATH')) {
    exit;
}

function ama_menu_arranger_activate() {
    $user = wp_get_current_user();
    if ($user && in_array('administrator', (array) $user->roles, true)) {
        update_option('ama_admin_user', $user->ID);
    }
}
register_activation_hook(__FILE__, 'ama_menu_arranger_activate');

class Admin_Menu_Arranger {
    private $option_name = 'ama_menu_config';
    private $admin_option_name = 'ama_admin_user';

    private function get_config() {
        $defaults = array(
            'hidden'         => array(),
            'order'          => array(),
            'labels'         => array(),
            'submenu_order'  => array(),
            'submenu_hidden' => array(),
            'submenu_labels' => array(),
            'bar_hidden'     => array(),
            'bar_order'      => array(),
        );
        return wp_parse_args(get_option($this->option_name, array()), $defaults);
    }

    public function __construct() {
        add_action('admin_menu', array($this, 'setup_admin_page'));
        add_action('admin_menu', array($this, 'reorder_admin_menu'), 999);
        add_action('admin_init', array($this, 'save_settings'));
        add_action('admin_bar_menu', array($this, 'adjust_admin_bar'), 999);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    private function is_authorized() {
        $stored = get_option($this->admin_option_name);
        return current_user_can('manage_options') && get_current_user_id() == $stored;
    }

    public function setup_admin_page() {
        if ($this->is_authorized()) {
            add_options_page(
                __('Menu Arranger', 'admin-menu-arranger'),
                __('Menu Arranger', 'admin-menu-arranger'),
                'manage_options',
                'ama-settings',
                array($this, 'settings_page')
            );
        }
    }

    public function enqueue_assets($hook) {
        if ($hook === 'settings_page_ama-settings') {
            wp_enqueue_script('jquery-ui-sortable');
        }
    }

    public function settings_page() {
        if (!$this->is_authorized()) {
            wp_die(__('You do not have permission to access this page.', 'admin-menu-arranger'));
        }

        $config = $this->get_config();

        echo '<div class="wrap"><h1>' . esc_html__('Menu Arranger', 'admin-menu-arranger') . '</h1><form method="post">';
        wp_nonce_field('ama_save');

        echo '<h2>' . esc_html__('Admin Menu', 'admin-menu-arranger') . '</h2>';
        echo '<ul id="ama-menu-list">';
        global $menu, $submenu;
        foreach ($menu as $item) {
            $slug = isset($item[2]) ? $item[2] : '';
            if (!$slug) {
                continue;
            }
            $label        = strip_tags($item[0]);
            $hidden       = in_array($slug, $config['hidden'], true) ? 'checked' : '';
            $custom_label = isset($config['labels'][$slug]) ? $config['labels'][$slug] : $label;
            echo '<li class="ama-menu-item">';
            echo '<span class="ama-handle dashicons dashicons-move"></span>';
            echo '<input type="hidden" name="order[]" value="' . esc_attr($slug) . '"/>';
            echo '<input type="text" name="labels[' . esc_attr($slug) . ']" value="' . esc_attr($custom_label) . '"/> ';
            echo '<label><input type="checkbox" name="hidden[]" value="' . esc_attr($slug) . '" ' . $hidden . '/> ' . esc_html__('Hide', 'admin-menu-arranger') . '</label>';
            if (isset($submenu[$slug])) {
                echo '<ul class="ama-submenu-list">';
                foreach ($submenu[$slug] as $sub) {
                    $subslug       = $sub[2];
                    $slabel        = strip_tags($sub[0]);
                    $custom_slabel = isset($config['submenu_labels'][$slug][$subslug]) ? $config['submenu_labels'][$slug][$subslug] : $slabel;
                    $sub_hidden    = isset($config['submenu_hidden'][$slug]) && in_array($subslug, $config['submenu_hidden'][$slug], true) ? 'checked' : '';
                    echo '<li>';
                    echo '<span class="ama-handle dashicons dashicons-move"></span>';
                    echo '<input type="hidden" name="submenu_order[' . esc_attr($slug) . '][]" value="' . esc_attr($subslug) . '"/>';
                    echo '<input type="text" name="submenu_labels[' . esc_attr($slug) . '][' . esc_attr($subslug) . ']" value="' . esc_attr($custom_slabel) . '"/> ';
                    echo '<label><input type="checkbox" name="submenu_hidden[' . esc_attr($slug) . '][]" value="' . esc_attr($subslug) . '" ' . $sub_hidden . '/> ' . esc_html__('Hide', 'admin-menu-arranger') . '</label>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo '</li>';
        }
        echo '</ul>';

        echo '<h2>' . esc_html__('Admin Bar', 'admin-menu-arranger') . '</h2><table class="form-table">';
        global $wp_admin_bar;
        if (is_admin_bar_showing() && $wp_admin_bar) {
            foreach ($wp_admin_bar->get_nodes() as $node) {
                $id     = $node->id;
                $title  = strip_tags($node->title);
                $hidden = in_array($id, $config['bar_hidden'], true) ? 'checked' : '';
                $order  = isset($config['bar_order'][$id]) ? intval($config['bar_order'][$id]) : $node->priority;
                echo '<tr><th scope="row">' . esc_html($title) . '</th><td>';
                echo '<label><input type="checkbox" name="bar_hidden[]" value="' . esc_attr($id) . '" ' . $hidden . '/> ' . esc_html__('Hide', 'admin-menu-arranger') . '</label> ';
                echo '<input type="number" name="bar_order[' . esc_attr($id) . ']" value="' . esc_attr($order) . '" style="width:60px"/> ' . esc_html__('Order', 'admin-menu-arranger');
                echo '</td></tr>';
            }
        }
        echo '</table>';

        submit_button();
        echo '</form>';
        echo '<style>.ama-handle{cursor:move;margin-right:4px}.ama-submenu-list{margin-left:20px}</style>';
        echo '<script>jQuery(function($){$("#ama-menu-list, .ama-submenu-list").sortable({handle:".ama-handle"});});</script>';
        echo '</div>';
    }

    public function save_settings() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'ama_save')) {
            return;
        }
        if (!$this->is_authorized()) {
            return;
        }
        $hidden = isset($_POST['hidden']) ? array_map('sanitize_text_field', (array) $_POST['hidden']) : array();
        $order  = isset($_POST['order']) ? array_map('sanitize_text_field', (array) $_POST['order']) : array();
        $labels = isset($_POST['labels']) ? array_map('sanitize_text_field', (array) $_POST['labels']) : array();

        $submenu_order = array();
        if (isset($_POST['submenu_order'])) {
            foreach ((array) $_POST['submenu_order'] as $parent => $items) {
                $submenu_order[sanitize_text_field($parent)] = array_map('sanitize_text_field', (array) $items);
            }
        }

        $submenu_hidden = array();
        if (isset($_POST['submenu_hidden'])) {
            foreach ((array) $_POST['submenu_hidden'] as $parent => $items) {
                $submenu_hidden[sanitize_text_field($parent)] = array_map('sanitize_text_field', (array) $items);
            }
        }

        $submenu_labels = array();
        if (isset($_POST['submenu_labels'])) {
            foreach ((array) $_POST['submenu_labels'] as $parent => $pairs) {
                $p = sanitize_text_field($parent);
                $submenu_labels[$p] = array();
                foreach ((array) $pairs as $subslug => $label) {
                    $submenu_labels[$p][sanitize_text_field($subslug)] = sanitize_text_field($label);
                }
            }
        }

        $bar_hidden = isset($_POST['bar_hidden']) ? array_map('sanitize_text_field', (array) $_POST['bar_hidden']) : array();
        $bar_order  = isset($_POST['bar_order']) ? array_map('intval', (array) $_POST['bar_order']) : array();

        update_option($this->option_name, array(
            'hidden'         => $hidden,
            'order'          => $order,
            'labels'         => $labels,
            'submenu_order'  => $submenu_order,
            'submenu_hidden' => $submenu_hidden,
            'submenu_labels' => $submenu_labels,
            'bar_hidden'     => $bar_hidden,
            'bar_order'      => $bar_order,
        ));
    }

    public function reorder_admin_menu() {
        if (!is_admin()) {
            return;
        }
        $config = $this->get_config();

        global $menu, $submenu;
        foreach ($menu as $index => $item) {
            $slug = $item[2] ?? '';
            if (in_array($slug, $config['hidden'], true)) {
                remove_menu_page($slug);
                unset($menu[$index]);
                continue;
            }
            if (isset($config['labels'][$slug])) {
                $menu[$index][0] = $config['labels'][$slug];
            }
            if (isset($submenu[$slug])) {
                foreach ($submenu[$slug] as $s_index => $s_item) {
                    $subslug = $s_item[2];
                    if (!empty($config['submenu_hidden'][$slug]) && in_array($subslug, $config['submenu_hidden'][$slug], true)) {
                        unset($submenu[$slug][$s_index]);
                        continue;
                    }
                    if (isset($config['submenu_labels'][$slug][$subslug])) {
                        $submenu[$slug][$s_index][0] = $config['submenu_labels'][$slug][$subslug];
                    }
                }
            }
        }

        if (!empty($config['order'])) {
            $ordered = array();
            foreach ($config['order'] as $slug) {
                foreach ($menu as $i => $item) {
                    if (($item[2] ?? '') === $slug) {
                        $ordered[] = $item;
                        unset($menu[$i]);
                    }
                }
            }
            $menu = array_merge($ordered, $menu);
        }

        if (!empty($config['submenu_order'])) {
            foreach ($config['submenu_order'] as $parent => $order_list) {
                if (isset($submenu[$parent])) {
                    $items   = $submenu[$parent];
                    $ordered = array();
                    foreach ($order_list as $subslug) {
                        foreach ($items as $i => $subitem) {
                            if ($subitem[2] === $subslug) {
                                $ordered[] = $subitem;
                                unset($items[$i]);
                            }
                        }
                    }
                    $submenu[$parent] = array_merge($ordered, $items);
                }
            }
        }
    }

    public function adjust_admin_bar($wp_admin_bar) {
        $config = $this->get_config();
        if (!empty($config['bar_hidden'])) {
            foreach ($config['bar_hidden'] as $id) {
                $wp_admin_bar->remove_node($id);
            }
        }
        if (!empty($config['bar_order'])) {
            $nodes = $wp_admin_bar->get_nodes();
            foreach ($config['bar_order'] as $id => $priority) {
                if (isset($nodes[$id])) {
                    $node = $nodes[$id];
                    $node->priority = intval($priority);
                    $wp_admin_bar->add_node($node);
                }
            }
        }
    }
}

new Admin_Menu_Arranger();

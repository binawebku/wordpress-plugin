<?php
/**
 * Plugin Name: Admin Menu Arranger
 * Description: Allows a specific administrator to hide and reorder WordPress admin menus and toolbar items.
 * Version: 1.0.0
 * Author: Wan Mohd Aiman Binawebpro.com
 * Text Domain: admin-menu-arranger
 */

if (!defined('ABSPATH')) {
    exit;
}

class Admin_Menu_Arranger {
    private $option_name = 'ama_menu_config';
    private $admin_option_name = 'ama_admin_user';

    public function __construct() {
        register_activation_hook(__FILE__, array($this, 'on_activation'));
        add_action('admin_menu', array($this, 'setup_admin_page'));
        add_action('admin_menu', array($this, 'reorder_admin_menu'), 999);
        add_action('admin_init', array($this, 'save_settings'));
        add_action('admin_bar_menu', array($this, 'adjust_admin_bar'), 999);
    }

    public function on_activation() {
        $user = wp_get_current_user();
        if ($user && in_array('administrator', (array) $user->roles)) {
            update_option($this->admin_option_name, $user->ID);
        }
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

    public function settings_page() {
        if (!$this->is_authorized()) {
            wp_die(__('You do not have permission to access this page.', 'admin-menu-arranger'));
        }

        $config = get_option($this->option_name, array(
            'hidden'     => array(),
            'order'      => array(),
            'bar_hidden' => array(),
            'bar_order'  => array(),
        ));

        echo '<div class="wrap"><h1>' . esc_html__('Menu Arranger', 'admin-menu-arranger') . '</h1><form method="post">';
        wp_nonce_field('ama_save');

        echo '<h2>' . esc_html__('Admin Menu', 'admin-menu-arranger') . '</h2><table class="form-table">';
        global $menu;
        foreach ($menu as $index => $item) {
            $slug = isset($item[2]) ? $item[2] : '';
            if (empty($slug)) {
                continue;
            }
            $label  = strip_tags($item[0]);
            $hidden = in_array($slug, $config['hidden'], true) ? 'checked' : '';
            $order  = isset($config['order'][$slug]) ? intval($config['order'][$slug]) : $index;
            echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
            echo '<label><input type="checkbox" name="hidden[]" value="' . esc_attr($slug) . '" ' . $hidden . '/> ' . esc_html__('Hide', 'admin-menu-arranger') . '</label> ';
            echo '<input type="number" name="order[' . esc_attr($slug) . ']" value="' . esc_attr($order) . '" style="width:60px"/> ' . esc_html__('Order', 'admin-menu-arranger');
            echo '</td></tr>';
        }
        echo '</table>';

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
        echo '</form></div>';
    }

    public function save_settings() {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'ama_save')) {
            return;
        }
        if (!$this->is_authorized()) {
            return;
        }
        $hidden     = isset($_POST['hidden']) ? array_map('sanitize_text_field', (array) $_POST['hidden']) : array();
        $order      = isset($_POST['order']) ? array_map('intval', (array) $_POST['order']) : array();
        $bar_hidden = isset($_POST['bar_hidden']) ? array_map('sanitize_text_field', (array) $_POST['bar_hidden']) : array();
        $bar_order  = isset($_POST['bar_order']) ? array_map('intval', (array) $_POST['bar_order']) : array();
        update_option($this->option_name, array(
            'hidden'     => $hidden,
            'order'      => $order,
            'bar_hidden' => $bar_hidden,
            'bar_order'  => $bar_order,
        ));
    }

    public function reorder_admin_menu() {
        if (!is_admin()) {
            return;
        }
        $config = get_option($this->option_name, array(
            'hidden' => array(),
            'order'  => array(),
        ));
        global $menu;
        foreach ($menu as $index => $item) {
            $slug = isset($item[2]) ? $item[2] : '';
            if (in_array($slug, $config['hidden'], true)) {
                remove_menu_page($slug);
            }
        }
        if (!empty($config['order'])) {
            $new_menu = array();
            foreach ($menu as $item) {
                $slug = isset($item[2]) ? $item[2] : '';
                if (isset($config['order'][$slug])) {
                    $new_menu[intval($config['order'][$slug])] = $item;
                } else {
                    $new_menu[] = $item;
                }
            }
            ksort($new_menu);
            $menu = $new_menu;
        }
    }

    public function adjust_admin_bar($wp_admin_bar) {
        $config = get_option($this->option_name, array(
            'bar_hidden' => array(),
            'bar_order'  => array(),
        ));
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

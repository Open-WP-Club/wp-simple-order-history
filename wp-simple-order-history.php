<?php

/**
 * Plugin Name: WP Simple Order History
 * Plugin URI: https://github.com/Open-WP-Club/wp-simple-order-history
 * Description: Adds a purchase history tab with review functionality to WooCommerce My Account page
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: wp-simple-order-history
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 9.0
 * License: GPL v2 or later
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class WP_Simple_Order_History
{

    public function __construct()
    {
        // Initialize the plugin
        add_action('init', array($this, 'init'));

        // Add the endpoint
        add_action('init', array($this, 'add_endpoints'));

        // Add query vars
        add_filter('query_vars', array($this, 'add_query_vars'), 0);

        // Add to WooCommerce query vars
        add_filter('woocommerce_get_query_vars', array($this, 'add_wc_query_vars'));

        // Add tab to My Account menu
        add_filter('woocommerce_account_menu_items', array($this, 'add_menu_item'));

        // Add content to the new tab
        add_action('woocommerce_account_purchase-history_endpoint', array($this, 'tab_content'));

        // Register activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));

        // Register deactivation hook
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function init()
    {
        add_filter('woocommerce_endpoint_purchase-history_title', array($this, 'endpoint_title'));
        // Add custom CSS
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    public function add_query_vars($vars)
    {
        $vars[] = 'purchase-history';
        return $vars;
    }

    public function add_wc_query_vars($vars)
    {
        $vars['purchase-history'] = 'purchase-history';
        return $vars;
    }

    public function activate()
    {
        // First add the endpoint
        $this->add_endpoints();

        // Then flush rewrite rules
        flush_rewrite_rules();

        // Ensure the endpoint is added to WooCommerce settings
        update_option('woocommerce_myaccount_purchase_history_endpoint', 'purchase-history');
    }

    public function deactivate()
    {
        // Flush rewrite rules on deactivation
        flush_rewrite_rules();
    }

    public function add_endpoints()
    {
        add_rewrite_endpoint('purchase-history', EP_ROOT | EP_PAGES);
    }

    public function endpoint_title()
    {
        return __('Purchase History', 'wp-simple-order-history');
    }

    public function add_menu_item($items)
    {
        // Insert purchase history tab after dashboard
        $new_items = array();
        foreach ($items as $key => $item) {
            $new_items[$key] = $item;
            if ($key === 'dashboard') {
                $new_items['purchase-history'] = __('Purchase History', 'wp-simple-order-history');
            }
        }
        return $new_items;
    }

    public function enqueue_styles()
    {
        if (is_account_page()) {
            wp_enqueue_style(
                'wp-simple-order-history',
                plugins_url('assets/css/style.css', __FILE__),
                array(),
                '1.0.0'
            );
        }
    }

    private function get_reviewed_products($email)
    {
        $comments = get_comments(array(
            'author_email' => $email,
            'type' => 'review',
            'status' => 'approve',
        ));

        return array_map(function ($comment) {
            return $comment->comment_post_ID;
        }, $comments);
    }

    private function render_purchase_table($orders, $reviewed_products)
    {
?>
        <div class="wp-simple-order-history-wrapper">
            <table class="woocommerce-orders-table wp-simple-order-history-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Order', 'wp-simple-order-history'); ?></th>
                        <th><?php esc_html_e('Product', 'wp-simple-order-history'); ?></th>
                        <th><?php esc_html_e('Purchase Date', 'wp-simple-order-history'); ?></th>
                        <th><?php esc_html_e('Actions', 'wp-simple-order-history'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order):
                        foreach ($order->get_items() as $item):
                            $product = $item->get_product();
                            if (!$product) continue;
                    ?>
                            <tr>
                                <td>
                                    <a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="order-number">
                                        #<?php echo esc_html($order->get_order_number()); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($product->get_image_id()): ?>
                                        <img src="<?php echo wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'); ?>"
                                            class="product-thumbnail"
                                            alt="<?php echo esc_attr($product->get_name()); ?>">
                                    <?php endif; ?>
                                    <a href="<?php echo esc_url($product->get_permalink()); ?>" class="product-link">
                                        <?php echo esc_html($product->get_name()); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?>
                                </td>
                                <td class="actions">
                                    <?php if (!in_array($product->get_id(), $reviewed_products)): ?>
                                        <a href="<?php echo esc_url($product->get_permalink() . '#review_form'); ?>"
                                            class="button review-button">
                                            <?php esc_html_e('Write Review', 'wp-simple-order-history'); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="reviewed-badge">
                                            <?php esc_html_e('Reviewed', 'wp-simple-order-history'); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                    <?php endforeach;
                    endforeach; ?>
                </tbody>
            </table>
        </div>
<?php
    }

    public function tab_content()
    {
        // Get customer orders
        $customer_orders = wc_get_orders(array(
            'customer_id' => get_current_user_id(),
            'status' => array_map('wc_get_order_status_name', wc_get_is_paid_statuses()),
            'limit' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));

        // Get reviewed products
        $customer = new WC_Customer(get_current_user_id());
        $reviewed_products = $this->get_reviewed_products($customer->get_billing_email());

        if (empty($customer_orders)) {
            echo '<div class="woocommerce-message woocommerce-message--info">';
            echo __('No purchase history found.', 'wp-simple-order-history');
            echo '</div>';
            return;
        }

        $this->render_purchase_table($customer_orders, $reviewed_products);
    }
}

// Initialize the plugin
new WP_Simple_Order_History();

// Create necessary files on plugin activation
function wp_simple_order_history_create_files()
{
    // Create assets/css directory and file if they don't exist
    $css_dir = plugin_dir_path(__FILE__) . 'assets/css';
    if (!file_exists($css_dir)) {
        mkdir($css_dir, 0755, true);
    }

    $css_file = $css_dir . '/style.css';
    if (!file_exists($css_file)) {
        $css_content = file_get_contents(__DIR__ . '/assets/css/style.css');
        file_put_contents($css_file, $css_content);
    }
}

register_activation_hook(__FILE__, 'wp_simple_order_history_create_files');

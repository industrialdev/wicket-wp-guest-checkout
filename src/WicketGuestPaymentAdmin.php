<?php

declare(strict_types=1);

/**
 * Guest Subscription Payment Flow for WooCommerce - Admin Interface.
 *
 * Handles the admin interface for creating and managing guest payment tokens.
 */

// No direct access
defined('ABSPATH') || exit;

/**
 * Admin interface for Guest Subscription Payment Flow.
 */
class WicketGuestPaymentAdmin extends WicketGuestPaymentComponent
{
    /**
     * Core functionality class.
     *
     * @var WicketGuestPaymentCore
     */
    private WicketGuestPaymentCore $core;

    /**
     * Email functionality class.
     *
     * @var WicketGuestPaymentEmail
     */
    private $email;

    /**
     * Constructor.
     *
     * @param WicketGuestPaymentCore  $core Core functionality class.
     * @param WicketGuestPaymentEmail $email Email functionality class.
     */
    public function __construct(WicketGuestPaymentCore $core, WicketGuestPaymentEmail $email)
    {
        $this->core = $core;
        $this->email = $email;

        // Hook the method to add the meta box
        add_action('add_meta_boxes', [$this, 'add_guest_payment_meta_box'], 10, 2);

        // Standard form submissions
        add_action('admin_post_wicket_resend_guest_payment', [$this, 'handle_guest_payment_request']);
        add_action('admin_post_wicket_invalidate_guest_payment', [$this, 'handle_guest_payment_request']);
        // AJAX action for generating link only
        add_action('wp_ajax_wicket_generate_manual', [$this, 'handle_generate_guest_link_only_ajax']);
        // AJAX action for generating and sending email
        add_action('wp_ajax_wicket_generate_and_send_email', [$this, 'handle_generate_and_send_ajax']);
        add_action('wp_ajax_wicket_resend_email', [$this, 'handle_resend_email_ajax']);
        add_action('wp_ajax_wicket_invalidate_link', [$this, 'handle_invalidate_link_ajax']);
        // Enqueue JS for AJAX
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Initialize hooks.
     *
     * @return void
     */
    public function init(): void
    {
        // Add order actions
        add_filter('woocommerce_order_actions', [$this, 'add_guest_payment_order_action']);
        add_action('woocommerce_order_action_wicket_create_guest_payment_link', [$this, 'process_guest_payment_order_action']);
    }

    /**
     * Add a meta box for guest payment management to the order edit screen.
     *
     * @param string $post_type The post type of the current screen.
     * @param WP_Post|object $post_or_order_object The post or object being edited.
     * @return void
     */
    public function add_guest_payment_meta_box(string $post_type, $post_or_order_object): void
    {
        // Determine the correct screen IDs based on HPOS status
        $hpos_enabled = $this->is_custom_order_tables_usage_enabled();

        $order_screen_id = $this->get_order_screen_id(); // Already handles HPOS/legacy

        $subscription_screen_id = 'shop_subscription'; // Default legacy post type
        if ($hpos_enabled) {
            // Use wc_get_page_screen_id for consistency if HPOS is on
            $subscription_screen_id = wc_get_page_screen_id('shop-subscription');
        }

        $allowed_screens = [$order_screen_id, $subscription_screen_id];

        $current_screen_id = isset($GLOBALS['current_screen']) ? $GLOBALS['current_screen']->id : 'NOT_SET';

        // Primarily rely on the current_screen global for HPOS compatibility
        if ('NOT_SET' === $current_screen_id || !in_array($current_screen_id, $allowed_screens)) {
            return;
        }

        // Add the meta box, ensuring it targets the correct screens
        add_meta_box(
            'wicket_guest_payment_metabox',
            __('Guest Payment', 'wicket-wgc'),
            [$this, 'render_guest_payment_meta_box'],
            $allowed_screens, // Pass the array of correct screen IDs
            'side',
            'default'
        );
    }

    /**
     * Get the appropriate screen ID for order screens based on HPOS status.
     *
     * @return string Screen ID
     */
    private function get_order_screen_id(): string
    {
        // Check if HPOS is enabled
        if (
            class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')
            && $this->is_custom_order_tables_usage_enabled()
        ) {
            return wc_get_page_screen_id('shop-order');
        }

        return 'shop_order';
    }

    /**
     * Check if Custom Order Tables (HPOS) is enabled.
     *
     * @return bool
     */
    private function is_custom_order_tables_usage_enabled(): bool
    {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }

        return false;
    }

    /**
     * Render the guest payment meta box content.
     *
     * @param WP_Post|WC_Order $post_or_order_object Post or order object.
     * @return void
     */
    public function render_guest_payment_meta_box($post_or_order_object): void
    {
        // Get order object regardless of whether we received a post or order
        $order = ($post_or_order_object instanceof WP_Post)
            ? wc_get_order($post_or_order_object->ID)
            : $post_or_order_object;

        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'wicket-wgc') . '</p>';

            return;
        }

        // Check if the order is a new, unsaved order (status is 'auto-draft').
        if ($order->get_status() === 'auto-draft') {
            echo '<p>' . esc_html__('Please create and save the order first to generate a guest payment link.', 'wicket-wgc') . '</p>';

            return;
        }

        // Check if the order is already paid/completed - guest payment links cannot be generated for these
        $paid_statuses = ['completed', 'processing', 'refunded'];
        if (in_array($order->get_status(), $paid_statuses, true)) {
            echo '<div class="notice notice-info inline" style="margin: 0; padding: 10px;">';
            echo '<p>' . esc_html__('This order has already been paid and completed. Payment links cannot be generated for orders with completed, processing, or refunded status.', 'wicket-wgc') . '</p>';
            echo '</div>';

            return;
        }

        $order_id = $order->get_id();

        // Add nonce for all actions within this meta box
        wp_nonce_field('guest_payment_actions_' . $order_id, '_wpnonce_guest_payment');

        // Get current token data, passing the existing order object
        $token_data = $this->core->get_valid_token_data($order_id, $order);

        // START: Generate/Send Email OR Manage Existing Token
        if (!$token_data) {
            // No valid token exists - show form to generate and send
            ?>
            <div id="wicket-guest-payment-generate-<?php echo esc_attr($order_id); ?>" class="wicket-guest-payment-generate-section">
                <h4><?php esc_html_e('Generate and Send Link', 'wicket-wgc'); ?></h4>
                <p>
                    <label for="wicket_guest_email_send_<?php echo esc_attr($order_id); ?>"><?php esc_html_e('Guest Email:', 'wicket-wgc'); ?></label>
                    <input type="email" id="wicket_guest_email_send_<?php echo esc_attr($order_id); ?>" name="wicket_guest_email" value="" class="regular-text wicket-guest-email-input" style="width: 100%;">
                    <span class="spinner wicket-ajax-spinner"></span>
                </p>
                <?php $ajax_send_nonce = wp_create_nonce('wicket_generate_send_ajax_' . $order_id); ?>
                <button type="button" class="button button-primary wicket-generate-send-button"
                    data-order-id="<?php echo esc_attr($order_id); ?>"
                    data-nonce="<?php echo esc_attr($ajax_send_nonce); ?>">
                    <?php esc_html_e('Generate & Send Email', 'wicket-wgc'); ?>
                </button>
                <span class="spinner wicket-ajax-spinner"></span>
                <div class="wicket-ajax-feedback wicket-ajax-feedback-top notice" style="display: none; margin-top: 10px;"></div>
            </div>
        <?php
        } else {
            // Valid token exists - show management options
            $created_date = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $token_data['created_timestamp']);
            $generation_method = $token_data['generation_method'] ?? 'email'; // Get method, default to 'email'
            ?>
            <h4><?php esc_html_e('Manage Existing Link', 'wicket-wgc'); ?></h4>
            <?php
                if ($generation_method === 'manual') {
                    // Message for manually generated links
                    if (empty($token_data['guest_email'])) {
                        echo '<p>' . esc_html__('A valid payment link (generated manually) exists.', 'wicket-wgc') . '</p>';
                    } else {
                        printf(
                            '<p>' . esc_html__('A valid payment link (generated manually) exists for %s.', 'wicket-wgc') . '</p>',
                            '<strong>' . esc_html($token_data['guest_email']) . '</strong>'
                        );
                    }
                } else {
                    // Message for links sent via email (original message)
                    printf(
                        '<p>' . esc_html__('A valid payment link exists for %s.', 'wicket-wgc') . '</p>',
                        '<strong>' . esc_html($token_data['guest_email']) . '</strong>'
                    );
                }
            ?>
            <p><?php printf(esc_html__('Link created on: %s', 'wicket-wgc'), '<em>' . esc_html($created_date) . '</em>'); ?></p>

            <div class="wicket-manage-link-container" style="display: flex; flex-direction: column; gap: 10px;">
                <div style="display: flex; gap: 10px; align-items: center;">
                    <!-- Resend Token Button -->
                    <div style="position: relative;">
                        <button type="button" class="button button-secondary wicket-resend-email-button"
                            data-order-id="<?php echo esc_attr($order_id); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('wicket_resend_email_' . $order_id)); ?>"
                            data-guest-email="<?php echo esc_attr($token_data['guest_email']); ?>">
                            <?php esc_html_e('Resend Email', 'wicket-wgc'); ?>
                        </button>
                        <span class="spinner wicket-ajax-spinner" style="margin-left: 4px;"></span>
                    </div>

                    <!-- Invalidate Token Button -->
                    <div style="position: relative;">
                        <button type="button" class="button button-link-delete wicket-invalidate-link-button"
                            data-order-id="<?php echo esc_attr($order_id); ?>"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('wicket_invalidate_link_' . $order_id)); ?>"
                            data-confirm="<?php echo esc_attr__('Are you sure you want to invalidate this payment link? The guest will no longer be able to use it.', 'wicket-wgc'); ?>">
                            <?php esc_html_e('Invalidate Link', 'wicket-wgc'); ?>
                        </button>
                        <span class="spinner wicket-ajax-spinner" style="margin-left: 4px;"></span>
                    </div>
                </div>
                <!-- Feedback area for manage buttons -->
                <div class="wicket-ajax-feedback wicket-ajax-feedback-top notice" style="display: none; margin: 5px 0 0 0; padding: 5px;"></div>
            </div>
        <?php
        }
        // END: Generate/Send Email OR Manage Existing Token

        // START: Add 'Generate Link Manually' Button
        ?>
        <hr style="margin: 20px 0;">
        <h4><?php esc_html_e('Manual Link Generation', 'wicket-wgc'); ?></h4>
        <p><?php esc_html_e('Generate a new link (invalidating any previous one) without sending an email. The link will appear below.', 'wicket-wgc'); ?></p>

        <?php
        // Prepare link data, always render container but hide if no link yet
        $has_token = $token_data && !empty($token_data['token']);
        $manual_guest_link = $has_token ? add_query_arg('guest_payment_token', $token_data['token'], wc_get_cart_url()) : '';
        $container_style = $has_token ? '' : ' style="display: none;"';
        ?>
        <div class="wicket-guest-payment-manual-link" <?php echo $container_style; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Style attribute is controlled
        ?>>
            <label for="wicket_manual_guest_link_<?php echo esc_attr($order_id); ?>" style="display: block; margin-bottom: 5px;"><strong><?php esc_html_e('Current Payment Link:', 'wicket-wgc'); ?></strong></label>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: nowrap; margin-bottom: 5px;">
                <input type="text" id="wicket_manual_guest_link_<?php echo esc_attr($order_id); ?>" readonly value="<?php echo esc_attr($manual_guest_link); ?>" style="width: calc(100% - 120px);">
                <button type="button" class="button wicket-copy-link-button" style="flex-shrink: 0;"><?php esc_html_e('Copy Link', 'wicket-wgc'); ?></button>
                <span class="wicket-copy-feedback" style="display: none; color: #008a20; white-space: nowrap;"><?php esc_html_e('Copied!', 'wicket-wgc'); ?></span>
            </div>
            <span class="description"><?php esc_html_e('This is the current active link for the guest.', 'wicket-wgc'); ?></span>
        </div>

        <?php
        // Show 'no link' message only if there isn't one initially
        if (!$has_token) {
            ?>
            <p id="wgp-no-link-message"><em><?php esc_html_e('No valid payment link currently exists for this order.', 'wicket-wgc'); ?></em></p>
        <?php
        }
        ?>

        <!-- Button container - Should always be present -->
        <div id="wicket-guest-payment-manual-generate" style="margin-top: 15px;">
            <?php $ajax_manual_nonce = wp_create_nonce('wicket_generate_manual_ajax_' . $order_id); ?>
            <button type="button" class="button wicket-generate-manual-button"
                data-order-id="<?php echo esc_attr($order_id); ?>"
                data-nonce="<?php echo esc_attr($ajax_manual_nonce); ?>"
                data-confirm="<?php echo esc_attr__('Are you sure? Generating a new link will invalidate any existing link for this order.', 'wicket-wgc'); ?>">
                <?php echo $has_token ? esc_html__('Generate New Link', 'wicket-wgc') : esc_html__('Generate Link', 'wicket-wgc'); ?>
            </button>
            <span class="spinner wicket-ajax-spinner"></span>
            <div class="wicket-ajax-feedback wicket-ajax-feedback-bottom notice" style="display: none; margin: 5px 0 0 0; padding: 5px;"></div>
        </div>

        <hr style="margin: 20px 0;">
        <h4><?php esc_html_e('Pay For Customer', 'wicket-wgc'); ?></h4>
        <p><?php esc_html_e('Open a secure checkout session for this order. You will be returned to this order after payment completes.', 'wicket-wgc'); ?></p>
        <?php
        if (!$this->current_user_is_admin()) {
            echo '<p><em>' . esc_html__('Admin access required.', 'wicket-wgc') . '</em></p>';
        } else {
            $pay_user_id = (int) $order->get_customer_id();
            if (!$pay_user_id) {
                echo '<p><em>' . esc_html__('This order must be assigned to a customer before paying on their behalf.', 'wicket-wgc') . '</em></p>';
            } else {
                $admin_pay_url = wp_nonce_url(
                    admin_url('admin-post.php?action=wicket_admin_pay&order_id=' . $order_id),
                    'wicket_admin_pay_' . $order_id
                );
                ?>
            <a class="button button-primary" href="<?php echo esc_url($admin_pay_url); ?>" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e('Pay for Customer Now', 'wicket-wgc'); ?>
            </a>
                <div id="wgp-admin-pay-overlay" class="wgp-admin-pay-overlay" style="display: none;" aria-hidden="true">
                    <div class="wgp-admin-pay-overlay__panel" role="dialog" aria-live="polite">
                        <p class="wgp-admin-pay-overlay__title"><?php esc_html_e('Admin Pay Session Starting', 'wicket-wgc'); ?></p>
                        <p class="wgp-admin-pay-overlay__text"><?php esc_html_e('A checkout tab has opened for the customer. If you close it or lose access, use the button below to return to your admin session.', 'wicket-wgc'); ?></p>
                        <div class="wgp-admin-pay-overlay__actions">
                            <button
                                type="button"
                                class="button button-primary wgp-admin-pay-overlay__return"
                                data-action="wicket_admin_pay_return"
                                data-nonce="<?php echo esc_attr(wp_create_nonce('wicket_admin_pay_return')); ?>"
                                data-url="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                            >
                                <?php esc_html_e('Cancel and Return to Admin', 'wicket-wgc'); ?>
                            </button>
                        </div>
                </div>
            </div>
            <p class="description" style="margin-top: 8px;"><?php esc_html_e('Opens in a new tab and temporarily switches to the customer for payment.', 'wicket-wgc'); ?></p>
            <style>
                .wgp-admin-pay-overlay { position: fixed; inset: 0; background: rgba(17, 24, 39, 0.7); display: flex; align-items: center; justify-content: center; z-index: 100000; padding: 24px; }
                .wgp-admin-pay-overlay__panel { width: min(640px, 100%); background: #ffffff; border-radius: 12px; padding: 28px; text-align: center; box-shadow: 0 20px 50px rgba(0, 0, 0, 0.35); }
                .wgp-admin-pay-overlay__title { margin: 0 0 8px 0; font-size: 20px; font-weight: 700; color: #1d2327; }
                .wgp-admin-pay-overlay__text { margin: 0 0 20px 0; font-size: 14px; color: #3c434a; }
                .wgp-admin-pay-overlay__actions { display: flex; justify-content: center; }
                .wgp-admin-pay-overlay__actions .button { padding: 10px 18px; }
            </style>
            <script>
                (function() {
                    const payLink = document.querySelector('a.button.button-primary[href*="action=wicket_admin_pay"]');
                    const overlay = document.getElementById('wgp-admin-pay-overlay');
                    const returnButton = overlay ? overlay.querySelector('.wgp-admin-pay-overlay__return') : null;
                    if (!payLink || !overlay) {
                        return;
                    }

                    payLink.addEventListener('click', () => {
                        overlay.style.display = 'flex';
                        overlay.setAttribute('aria-hidden', 'false');
                    });

                    if (returnButton) {
                        returnButton.addEventListener('click', () => {
                            const actionUrl = returnButton.getAttribute('data-url') || '';
                            const actionName = returnButton.getAttribute('data-action') || '';
                            const nonce = returnButton.getAttribute('data-nonce') || '';
                            if (!actionUrl || !actionName || !nonce) {
                                return;
                            }

                            const form = document.createElement('form');
                            form.method = 'post';
                            form.action = actionUrl;

                            const actionInput = document.createElement('input');
                            actionInput.type = 'hidden';
                            actionInput.name = 'action';
                            actionInput.value = actionName;
                            form.appendChild(actionInput);

                            const nonceInput = document.createElement('input');
                            nonceInput.type = 'hidden';
                            nonceInput.name = '_wpnonce';
                            nonceInput.value = nonce;
                            form.appendChild(nonceInput);

                            document.body.appendChild(form);
                            form.submit();
                        });
                    }
                })();
            </script>
        <?php
            }
        }
        ?>

<?php
        // END: Add 'Generate Link Manually' Button
    }

    private function current_user_is_admin(): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    /**
     * Handle the form submission from the meta box.
     *
     * @return void
     */
    public function handle_guest_payment_request(): void
    {
        // Use null coalescing operator for safer access
        $order_id = absint($_POST['order_id'] ?? 0);

        // Determine which action is being performed by checking the submitted nonce
        $current_action = ''; // Will store the validated action
        $nonce_verified = false;

        if ($order_id) { // Proceed only if order ID is present
            // Check for 'resend' nonce
            if (isset($_POST['_wpnonce_resend_guest']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce_resend_guest'])), 'resend_guest_payment_nonce_' . $order_id)) {
                $current_action = 'wicket_resend_guest_payment';
                $nonce_verified = true;
            }
            // Check for 'invalidate' nonce if not already verified
            elseif (isset($_POST['_wpnonce_invalidate_guest']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce_invalidate_guest'])), 'invalidate_guest_payment_nonce_' . $order_id)) {
                $current_action = 'wicket_invalidate_guest_payment';
                $nonce_verified = true;
            }
        }

        if (!$nonce_verified) { // Security check fails if no valid nonce for this order ID was found
            wp_die(esc_html__('Security check failed or invalid request.', 'wicket-wgc'));
        }

        // Check user capability
        if (!current_user_can('edit_shop_order', $order_id)) {
            wp_die(__('You do not have permission to perform this action.', 'wicket-wgc'));
        }

        switch ($current_action) { // Use the action determined by the verified nonce
            case 'wicket_resend_guest_payment':
                // Get order
                $order = wc_get_order($order_id);
                $guest_email = $order ? $order->get_meta('_wgp_guest_payment_email', true) : null;
                $user_id = $order ? (int) $order->get_meta('_wgp_guest_payment_user_id', true) : 0;
                // Pass the fetched order object to avoid redundant fetch
                $token_data = $this->core->get_valid_token_data($order_id, $order);

                if ($guest_email && $user_id && $token_data && isset($token_data['token'])) {
                    $sent = $this->email->send_payment_email($guest_email, $token_data['token'], $order_id, $user_id);
                    if ($sent) {
                        add_action('admin_notices', function () {
                            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Guest payment link resent.', 'wicket-wgc') . '</p></div>';
                        });
                    } else {
                        add_action('admin_notices', function () {
                            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Failed to resend guest payment link.', 'wicket-wgc') . '</p></div>';
                        });
                    }
                } else {
                    add_action('admin_notices', function () {
                        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Could not resend link: Missing email or valid token.', 'wicket-wgc') . '</p></div>';
                    });
                }
                break;

            case 'wicket_invalidate_guest_payment':
                $invalidated = $this->core->invalidate_token_for_order($order_id);
                if ($invalidated) {
                    add_action('admin_notices', function () {
                        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Guest payment link invalidated.', 'wicket-wgc') . '</p></div>';
                    });
                } else {
                    // May fail if already invalid, so perhaps a warning is better?
                    add_action('admin_notices', function () {
                        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Could not invalidate link (it might have been invalid already).', 'wicket-wgc') . '</p></div>';
                    });
                }
                break;
        }

        // Redirect back to the order edit page to show the notice and updated state
        $redirect_url = wp_get_referer();
        if (!$redirect_url) {
            $redirect_url = admin_url('post.php?post=' . $order_id . '&action=edit');
        }
        wp_safe_redirect($redirect_url);
        $this->maybe_exit();
    }

    /**
     * Enqueue admin scripts for AJAX functionality.
     *
     * @param string $hook_suffix The current admin page.
     */
    public function enqueue_admin_scripts(string $hook_suffix): void
    {
        $screen = get_current_screen();

        // Check if it's the classic post editor screen for orders/subscriptions
        $is_classic_editor = $screen && in_array($screen->post_type, ['shop_order', 'shop_subscription']) && in_array($screen->base, ['post']);

        // Check if it's an HPOS order/subscription screen (list or edit)
        // The screen ID for HPOS orders seems to be 'woocommerce_page_wc-orders'
        $is_hpos_order_screen = $screen && str_starts_with($screen->id, 'woocommerce_page_wc-orders');

        // Only load the script on the relevant screens
        if (!$is_classic_editor && !$is_hpos_order_screen) {
            return;
        }

        $script_path = '/assets/js/admin-guest-payment.js';
        $plugin_path = plugin_dir_path(dirname(__FILE__));
        $plugin_url = plugin_dir_url(dirname(__FILE__));
        $script_asset_path = $plugin_path . 'assets/js/admin-guest-payment.asset.php';
        $script_full_path = $plugin_path . 'assets/js/admin-guest-payment.js';
        $script_asset = file_exists($script_asset_path) ? require ($script_asset_path) : ['dependencies' => [], 'version' => file_exists($script_full_path) ? filemtime($script_full_path) : WICKET_GUEST_CHECKOUT_VERSION];

        wp_enqueue_script(
            'wicket-guest-payment-admin',
            $plugin_url . 'assets/js/admin-guest-payment.js',
            $script_asset['dependencies'],
            $script_asset['version'],
            true // Load in footer
        );

        wp_localize_script('wicket-guest-payment-admin', 'wicketGuestPayment', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce_action_prefix' => 'wicket_generate_manual_ajax_',
            'text' => [
                'copied' => esc_html__('Copied!', 'wicket-wgc'),
                'copyLink' => esc_html__('Copy Link', 'wicket-wgc'),
                'error' => esc_html__('An error occurred. Please try again.', 'wicket-wgc'),
                'errorGeneral' => esc_html__('An error occurred. Please try again.', 'wicket-wgc'),
                'errorNetwork' => esc_html__('Network error occurred. Please try again.', 'wicket-wgc'),
                'errorInvalidEmail' => esc_html__('Please enter a valid email address.', 'wicket-wgc'),
                'copyFailed' => esc_html__('Copy failed', 'wicket-wgc'),
                'generateNewLink' => esc_html__('Generate New Link', 'wicket-wgc'),
                'reloading' => esc_html__('Reloading page...', 'wicket-wgc'),
                'resendConfirmation' => esc_html__('Are you sure you want to resend the payment link email to %s?', 'wicket-wgc'),
            ],
        ]);
    }

    /**
     * AJAX handler to generate a guest payment link only (no email).
     */
    public function handle_generate_guest_link_only_ajax(): void
    {
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

        if (!$order_id || !wp_verify_nonce($nonce, 'wicket_generate_manual_ajax_' . $order_id)) {
            wp_send_json_error(['message' => __('Invalid request or missing data.', 'wicket-wgc')]);
        }

        if (!current_user_can('edit_shop_order', $order_id)) {
            wp_send_json_error(['message' => __('You do not have permission to perform this action.', 'wicket-wgc')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found. Please create and save the order before generating a guest payment link.', 'wicket-wgc')]);
        }

        // Check if order has a user assigned
        $user_id = (int) $order->get_meta('_wgp_guest_payment_user_id', true) ?: $order->get_customer_id();
        if (!$user_id) {
            wp_send_json_error(['message' => __('This order must be assigned to a customer before generating a guest payment link. Please assign a customer to this order and try again.', 'wicket-wgc')]);
        }

        // For manual link generation, we explicitly want NO guest email associated
        // so that the user is prompted on the Thank You page.
        $guest_email = '';

        // Generate the token (this also invalidates previous ones)
        $token = $this->core->generate_token_for_order($order_id, $guest_email, 'manual'); // Specify manual generation

        if (!$token) {
            wp_send_json_error(['message' => __('Failed to generate payment token.', 'wicket-wgc')]);
        }

        // Construct the payment URL
        $payment_url = add_query_arg('guest_payment_token', $token, wc_get_cart_url());

        if (!$payment_url) {
            wp_send_json_error(['message' => __('Failed to construct payment URL.', 'wicket-wgc')]);
        }

        // Add order note
        $order->add_order_note(__('New guest payment link generated manually by admin.', 'wicket-wgc'));

        wp_send_json_success([
            'message' => __('New payment link has been generated successfully.', 'wicket-wgc'),
            'link' => $payment_url,
        ]);
    }

    /**
     * Handle AJAX request to resend email for existing token.
     *
     * @return void
     */
    public function handle_resend_email_ajax(): void
    {
        $order_id = absint($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID.', 'wicket-wgc')]);
        }

        // Verify nonce
        if (!check_ajax_referer('wicket_resend_email_' . $order_id, 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'wicket-wgc')]);
        }

        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found.', 'wicket-wgc')]);
        }

        // Get token data, passing the fetched order object
        $token_data = $this->core->get_valid_token_data($order_id, $order);
        if (!$token_data || empty($token_data['guest_email']) || empty($token_data['token'])) {
            wp_send_json_error(['message' => __('No valid token found for this order.', 'wicket-wgc')]);
        }

        // Send the email
        $sent = $this->email->send_payment_email($token_data['guest_email'], $token_data['token'], $order_id, $token_data['user_id']);
        if (!$sent) {
            wp_send_json_error(['message' => __('Failed to send email.', 'wicket-wgc')]);
        }

        wp_send_json_success(['message' => __('Email sent successfully.', 'wicket-wgc')]);
    }

    /**
     * Handle AJAX request to invalidate link.
     *
     * @return void
     */
    public function handle_invalidate_link_ajax(): void
    {
        $order_id = absint($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID.', 'wicket-wgc')]);
        }

        // Verify nonce
        if (!check_ajax_referer('wicket_invalidate_link_' . $order_id, 'nonce', false)) {
            wp_send_json_error(['message' => __('Security check failed.', 'wicket-wgc')]);
        }

        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found.', 'wicket-wgc')]);
        }

        // Use the Core class method to invalidate the token
        $invalidated = $this->core->invalidate_token_for_order($order_id);

        if ($invalidated) {
            // Add order note
            $order->add_order_note(__('Guest payment link invalidated by admin.', 'wicket-wgc'));
            wp_send_json_success(['message' => __('Payment link has been invalidated successfully.', 'wicket-wgc')]);
        } else {
            wp_send_json_error(['message' => __('Failed to invalidate payment link.', 'wicket-wgc')]);
        }
    }

    /**
     * Handle AJAX request to generate and send email.
     */
    public function handle_generate_and_send_ajax(): void
    {
        // Check nonce
        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

        if (!$order_id || !wp_verify_nonce($nonce, 'wicket_generate_send_ajax_' . $order_id)) {
            wp_send_json_error(['message' => esc_html__('Security check failed.', 'wicket-wgc')]);

            return;
        }

        // Check capability
        if (!current_user_can('edit_shop_order', $order_id)) {
            wp_send_json_error(['message' => esc_html__('You do not have permission to perform this action.', 'wicket-wgc')]);

            return;
        }

        // Get and validate email
        $guest_email = isset($_POST['guest_email']) ? sanitize_email($_POST['guest_email']) : null;
        if (!is_email($guest_email)) {
            wp_send_json_error(['message' => esc_html__('Invalid email address provided.', 'wicket-wgc')]);

            return;
        }

        // Get order
        $order = wc_get_order($order_id);

        // Add check to ensure order exists before proceeding
        if (!$order) {
            wp_send_json_error(['message' => esc_html__('Order not found. Please save the order before generating and sending a guest payment link.', 'wicket-wgc')]);

            return; // Exit early
        }

        // Get user ID associated with the order
        $user_id = $order ? (int) $order->get_user_id() : 0;
        if (!$user_id) {
            $user_id = $order ? (int) $order->get_meta('_wgp_guest_payment_user_id', true) : 0;
        }

        if (!$user_id) {
            wp_send_json_error(['message' => esc_html__('This order must be assigned to a customer before generating a guest payment link. Please assign a customer to this order and try again.', 'wicket-wgc')]);

            return;
        }

        // Generate token
        $token = $this->core->generate_token_for_order($order_id, $guest_email, 'email'); // Specify email generation

        if (!$token) {
            wp_send_json_error(['message' => esc_html__('Failed to generate payment token.', 'wicket-wgc')]);

            return;
        }

        // Send email
        $sent = $this->email->send_payment_email($guest_email, $token, $order_id, $user_id);

        if ($sent) {
            // Optionally: Regenerate the metabox content to show the 'Manage' section
            // For simplicity now, just send success
            wp_send_json_success(['message' => sprintf(esc_html__('Payment link generated and sent to %s.', 'wicket-wgc'), $guest_email)]);
        } else {
            wp_send_json_error(['message' => esc_html__('Failed to send guest payment link email.', 'wicket-wgc')]);
        }
    }

    /**
     * Add "Create Guest Payment Link" to order actions dropdown.
     *
     * @param array $actions Order actions.
     * @return array Modified order actions.
     */
    public function add_guest_payment_order_action(array $actions): array
    {
        global $theorder;

        // Only add for orders with no active token
        $token = $theorder->get_meta('_wgp_guest_payment_token', true);
        if (!$token) {
            $actions['wicket_create_guest_payment_link'] = __('Create Guest Payment Link', 'wicket-wgc');
        }

        return $actions;
    }

    /**
     * Process the "Create Guest Payment Link" order action.
     *
     * @param WC_Order $order Order object.
     * @return void
     */
    public function process_guest_payment_order_action(WC_Order $order): void
    {
        // Redirect to the edit screen - we'll show a notice that this requires an email address
        add_action('admin_notices', function () {
            echo '<div class="notice notice-info is-dismissible"><p>'
                . esc_html__('Please use the Guest Payment box to enter an email address and create a payment link.', 'wicket-wgc')
                . '</p></div>';
        });
    }
}

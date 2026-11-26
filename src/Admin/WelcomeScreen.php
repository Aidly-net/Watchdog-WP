<?php

namespace WebsiteWatchdog\Agent\Admin;

use WebsiteWatchdog\Agent\UsageTelemetry;

class WelcomeScreen
{
    public function register(): void
    {
        add_action('admin_init', [$this, 'redirect_after_activation'], 1);
        add_action('admin_menu', [$this, 'add_page']);
        add_action('admin_post_website_watchdog_complete_welcome', [$this, 'complete']);
    }

    public function redirect_after_activation(): void
    {
        if (
            !get_option('website_watchdog_welcome_pending', false)
            || !current_user_can('manage_options')
            || 'admin-post.php' === basename((string) ($_SERVER['PHP_SELF'] ?? ''))
            || 'website-watchdog-welcome' === sanitize_key((string) ($_GET['page'] ?? ''))
        ) {
            return;
        }

        delete_option('website_watchdog_welcome_pending');
        wp_safe_redirect(admin_url('admin.php?page=website-watchdog-welcome'));
        exit;
    }

    public function add_page(): void
    {
        add_submenu_page(
            null,
            __('Website Watchdog is ready', 'website-watchdog'),
            __('Welcome', 'website-watchdog'),
            'manage_options',
            'website-watchdog-welcome',
            [$this, 'render']
        );
    }

    public function complete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'website-watchdog'));
        }
        check_admin_referer('website_watchdog_complete_welcome');

        $consent = isset($_POST['send_usage'])
            && '1' === sanitize_text_field(wp_unslash($_POST['send_usage']));
        UsageTelemetry::set_consent($consent);
        update_option('website_watchdog_welcome_completed', 1, false);
        delete_option('website_watchdog_welcome_pending');

        wp_safe_redirect(admin_url('admin.php?page=website-watchdog-settings'));
        exit;
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'website-watchdog'));
        }
        if (get_option('website_watchdog_welcome_completed', false)) {
            wp_safe_redirect(admin_url('admin.php?page=website-watchdog-settings'));
            exit;
        }
?>
        <div class="wrap watchdog-welcome">
            <h1><?php echo esc_html__('Website Watchdog is ready.', 'website-watchdog'); ?></h1>
            <p><?php echo esc_html__('Your site is being monitored locally.', 'website-watchdog'); ?></p>
            <p><?php echo esc_html__('Incidents and detailed diagnostic data stay on this site.', 'website-watchdog'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="website_watchdog_complete_welcome" />
                <?php wp_nonce_field('website_watchdog_complete_welcome'); ?>
                <p>
                    <label>
                        <input type="checkbox" name="send_usage" value="1" />
                        <?php echo esc_html__('Send anonymous usage information such as WordPress/Plugin/PHP versions.', 'website-watchdog'); ?>
                        <strong><?php echo esc_html__('No incident details, error messages, or custom event data are sent.', 'website-watchdog'); ?></strong>
                    </label>
                </p>
                <p class="description"><?php echo esc_html__('Help us improve Website Watchdog.', 'website-watchdog'); ?></p>
                <p class="description"><?php echo esc_html__('A random installation ID is used to count opt-in installations. Reports do not include your site URL or API key.', 'website-watchdog'); ?></p>

                <h2><?php echo esc_html__('Want remote monitoring?', 'website-watchdog'); ?></h2>
                <p><a class="button button-secondary" href="<?php echo esc_url(WEBSITE_WATCHDOG_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('Connect Website Watchdog Cloud', 'website-watchdog'); ?> &rarr;</a></p>

                <div class="watchdog-welcome-actions">
                    <?php submit_button(__('Done', 'website-watchdog'), 'primary', 'submit', false); ?>
                </div>
            </form>
        </div>
<?php
    }
}

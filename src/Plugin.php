<?php

namespace WebsiteWatchdog\Agent;

use WebsiteWatchdog\Agent\Admin\Settings;
use WebsiteWatchdog\Agent\UsageTelemetry;
use WebsiteWatchdog\Agent\Cron\Heartbeat;
use WebsiteWatchdog\Agent\Rest\HealthEndpoint;
use WebsiteWatchdog\Agent\Rest\JsErrorEndpoint;
use WebsiteWatchdog\Agent\Rest\IncidentEndpoint;
use WebsiteWatchdog\Agent\Trappers\ErrorTrapper;
use WebsiteWatchdog\Agent\Trappers\StateChangeListener;
use WebsiteWatchdog\Agent\Local\Store;

class Plugin
{
    protected $settings;
    protected $error_trapper;
    protected $state_change_listener;
    protected $js_error_endpoint;
    protected $health_endpoint;
    protected $heartbeat;

    public function __construct()
    {
        $this->settings = new Settings();
        $this->error_trapper = new ErrorTrapper();
        $this->state_change_listener = new StateChangeListener();
        $this->js_error_endpoint = new JsErrorEndpoint();
        $this->health_endpoint = new HealthEndpoint();
        $this->heartbeat = new Heartbeat();
    }

    public function register(): void
    {
        (new Store())->install();
        UsageTelemetry::register();
        $this->settings->register();
        $this->error_trapper->register();
        $this->state_change_listener->register();
        $this->js_error_endpoint->register();
        $this->health_endpoint->register();
        (new IncidentEndpoint())->register();
        $this->heartbeat->register();
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_script']);
    }

    public function enqueue_frontend_script(): void
    {
        wp_enqueue_script(
            'website-watchdog-frontend',
            plugins_url('assets/js/website-watchdog-frontend.js', WEBSITE_WATCHDOG_PATH . 'website-watchdog.php'),
            [],
            WEBSITE_WATCHDOG_VERSION,
            true
        );
    }
}

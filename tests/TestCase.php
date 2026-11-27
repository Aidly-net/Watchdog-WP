<?php

namespace WebsiteWatchdog\Agent\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use WPState;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WPState::reset();
        $_POST = [];
    }

    protected function configureVerifiedKey(string $key = 'secret-key'): void
    {
        WPState::$options['website_watchdog_api_key'] = $key;
        WPState::$options['website_watchdog_api_key_verified'] = true;
        WPState::$options['website_watchdog_site_id'] = 'site_1';
        WPState::$options['website_watchdog_notification_method'] = 'cloud';
    }
}

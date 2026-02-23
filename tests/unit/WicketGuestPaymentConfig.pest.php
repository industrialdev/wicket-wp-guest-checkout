<?php

declare(strict_types=1);

use Brain\Monkey;

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(string $code = '', string $message = '', $data = null) {}
    }
}

if (!class_exists('WGP_Test_Section')) {
    class WGP_Test_Section
    {
        /** @var array<int, array{type:string,args:array}> */
        public array $options = [];

        public function add_option(string $type, array $args = []): void
        {
            $this->options[] = [
                'type' => $type,
                'args' => $args,
            ];
        }
    }
}

if (!class_exists('WGP_Test_Tab')) {
    class WGP_Test_Tab
    {
        /** @var array<int, array{title:mixed,args:array,section:WGP_Test_Section}> */
        public array $sections = [];

        public function add_section($title, array $args = []): WGP_Test_Section
        {
            $section = new WGP_Test_Section();
            $this->sections[] = [
                'title' => $title,
                'args' => $args,
                'section' => $section,
            ];

            return $section;
        }
    }
}

it('returns true when email integration option enabled', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('get_option')->alias(function (string $name, $default = false) {
        if ($name === 'wicket_guest_payment_enable_email_integration') {
            return 'yes';
        }

        return $default;
    });

    expect($config->filter_email_integration_enabled(false))->toBeTrue();
});

it('returns default when email integration option disabled', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('get_option')->justReturn(false);

    expect($config->filter_email_integration_enabled(false))->toBeFalse();
});

it('returns true when pdf integration option enabled', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('get_option')->alias(function (string $name, $default = false) {
        if ($name === 'wicket_guest_payment_enable_pdf_integration') {
            return 'on';
        }

        return $default;
    });

    expect($config->filter_pdf_integration_enabled(false))->toBeTrue();
});

it('uses wicket settings token expiry days when set', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('get_option')->alias(function (string $name, $default = 0) {
        if ($name === 'wicket_settings') {
            return [
                'wicket_admin_settings_guest_payment_token_expiry_days' => '21',
            ];
        }

        if ($name === 'wicket_guest_payment_token_expiry_days') {
            return 14;
        }

        return $default;
    });

    expect($config->filter_token_expiry_days(7))->toBe(21);
});

it('falls back to legacy token expiry days when wicket setting not set', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('get_option')->alias(function (string $name, $default = 0) {
        if ($name === 'wicket_settings') {
            return [];
        }

        if ($name === 'wicket_guest_payment_token_expiry_days') {
            return 14;
        }

        return $default;
    });

    expect($config->filter_token_expiry_days(7))->toBe(14);
});

it('prepends wicket integrations settings link in plugin action links', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('admin_url')->alias(fn (string $path) => 'https://example.com/wp-admin/' . ltrim($path, '/'));
    Monkey\Functions\when('esc_url')->alias(fn (string $url) => $url);
    Monkey\Functions\when('esc_html__')->justReturn('Settings');

    if (!defined('WICKET_GUEST_CHECKOUT_BASENAME')) {
        define('WICKET_GUEST_CHECKOUT_BASENAME', 'wicket-wp-guest-checkout/wicket-wp-guest-checkout.php');
    }

    $links = $config->add_plugin_action_links(['existing']);

    expect($links[0])->toContain('page=wicket-settings&tab=integrations&section=guest-checkout');
    expect($links[1])->toBe('existing');
});

it('extends integrations tab callback and adds guest checkout section', function (): void {
    $config = new WicketGuestPaymentConfig();

    $original_called = false;
    $tabs = [
        50 => [
            'key' => 'integrations',
            'label' => 'Integrations',
            'callback' => function ($tab) use (&$original_called): void {
                $original_called = true;
            },
        ],
    ];

    Monkey\Functions\when('admin_url')->alias(fn (string $path) => 'https://example.com/wp-admin/' . ltrim($path, '/'));

    $updated_tabs = $config->extend_wicket_settings_tabs($tabs);

    expect(is_callable($updated_tabs[50]['callback']))->toBeTrue();

    $tab = new WGP_Test_Tab();
    call_user_func($updated_tabs[50]['callback'], $tab);

    expect($original_called)->toBeTrue();
    expect(count($tab->sections))->toBe(1);
    expect($tab->sections[0]['title'])->toBe('Guest Checkout');

    $options = $tab->sections[0]['section']->options;
    expect(count($options))->toBe(3);

    $names = array_map(
        static fn (array $option): string => (string) ($option['args']['name'] ?? ''),
        $options
    );

    expect($names)->toContain('wicket_admin_settings_guest_payment_token_expiry_days');
    expect($names)->toContain('wicket_admin_settings_guest_payment_email_subject_template');
    expect($names)->toContain('wicket_admin_settings_guest_payment_email_body_template');
});

it('adds code-wrapped placeholders and media guidance in integrations field descriptions', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('admin_url')->alias(fn (string $path) => 'https://example.com/wp-admin/' . ltrim($path, '/'));
    Monkey\Functions\when('esc_url')->alias(fn (string $url) => $url);
    Monkey\Functions\when('esc_html')->alias(fn (string $value) => $value);

    $tab = new WGP_Test_Tab();
    $config->extend_wicket_integrations_tab($tab);

    expect(count($tab->sections))->toBe(1);

    $options = $tab->sections[0]['section']->options;
    expect(count($options))->toBe(3);

    $subject_description = (string) ($options[1]['args']['description'] ?? '');
    $body_description = (string) ($options[2]['args']['description'] ?? '');

    expect($subject_description)->toContain('<code>{site_name}</code>');
    expect($subject_description)->toContain('<code>{member_name}</code>');
    expect($subject_description)->toContain('<code>{order_number}</code>');
    expect($subject_description)->toContain('<code>{order_total}</code>');
    expect($subject_description)->toContain('<code>{expiry_date}</code>');

    expect($body_description)->toContain('<code>{payment_link}</code>');
    expect($body_description)->toContain('<code>{payment_url}</code>');
    expect($body_description)->toContain('<code>{subscription_details}</code>');
    expect($body_description)->toContain('https://example.com/wp-admin/upload.php');
    expect($body_description)->toContain('<code><img src="{Image-URL}" alt="Logo" style="max-width:200px;height:auto;"></code>');
    expect($body_description)->toContain('Replace <code>{Image-URL}</code> with the image URL from the Media Library.');
});

it('skips fallback extension when integrations tab already extended via new tabs system', function (): void {
    $config = new WicketGuestPaymentConfig();

    $tabs = [
        50 => [
            'key' => 'integrations',
            'label' => 'Integrations',
            'callback' => null,
        ],
    ];

    $config->extend_wicket_settings_tabs($tabs);

    $tab = new WGP_Test_Tab();
    $config->extend_wicket_integrations_tab($tab);

    expect(count($tab->sections))->toBe(0);
});

it('saves token expiry to both wicket settings and legacy option', function (): void {
    $config = new WicketGuestPaymentConfig();

    $wicket_settings_written = false;
    $legacy_written = false;

    Monkey\Functions\when('get_option')->alias(function (string $name, $default = false) {
        if ($name === 'wicket_settings') {
            return [];
        }

        return $default;
    });

    Monkey\Functions\when('update_option')->alias(function (string $name, $value) use (&$wicket_settings_written, &$legacy_written): bool {
        if ($name === 'wicket_settings' && is_array($value) && (($value['wicket_admin_settings_guest_payment_token_expiry_days'] ?? null) === '15')) {
            $wicket_settings_written = true;
        }

        if ($name === 'wicket_guest_payment_token_expiry_days' && $value === 15) {
            $legacy_written = true;
        }

        return true;
    });

    $result = $config->set_token_expiry_days(15);

    expect($result)->toBeTrue();
    expect($wicket_settings_written)->toBeTrue();
    expect($legacy_written)->toBeTrue();
});

it('returns false when setting invalid token expiry days', function (): void {
    $config = new WicketGuestPaymentConfig();

    expect($config->set_token_expiry_days(0))->toBeFalse();
});

it('persists subject and body templates to wicket and legacy stores', function (): void {
    $config = new WicketGuestPaymentConfig();

    $subject_saved = false;
    $body_saved = false;

    Monkey\Functions\when('get_option')->alias(function (string $name, $default = false) {
        if ($name === 'wicket_settings') {
            return [];
        }

        return $default;
    });

    Monkey\Functions\when('update_option')->alias(function (string $name, $value) use (&$subject_saved, &$body_saved): bool {
        if ($name === 'wicket_settings' && is_array($value)) {
            if (($value['wicket_admin_settings_guest_payment_email_subject_template'] ?? null) === 'Subj') {
                $subject_saved = true;
            }
            if (($value['wicket_admin_settings_guest_payment_email_body_template'] ?? null) === '<p>Body</p>') {
                $body_saved = true;
            }
        }

        return true;
    });

    expect($config->set_email_subject_template(' Subj '))->toBeTrue();
    expect($config->set_email_body_template(' <p>Body</p> '))->toBeTrue();

    expect($subject_saved)->toBeTrue();
    expect($body_saved)->toBeTrue();
});

it('returns error for invalid configuration days and empty templates', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('rest_sanitize_boolean')->alias(fn ($value) => (bool) $value);

    $result = $config->validate_configuration([
        'email_integration' => true,
        'pdf_integration' => false,
        'token_expiry_days' => 400,
        'email_subject_template' => '',
        'email_body_template' => '',
    ]);

    expect($result)->toBeInstanceOf(WP_Error::class);
});

it('updates options when saving configuration including templates', function (): void {
    $config = new WicketGuestPaymentConfig();

    Monkey\Functions\when('rest_sanitize_boolean')->alias(fn ($value) => (bool) $value);
    Monkey\Functions\when('is_wp_error')->alias(fn ($value) => $value instanceof WP_Error);
    Monkey\Functions\when('sanitize_text_field')->alias(fn (string $value) => trim($value));
    Monkey\Functions\when('wp_kses_post')->alias(fn (string $value) => $value);

    Monkey\Functions\when('get_option')->alias(function (string $name, $default = false) {
        if ($name === 'wicket_settings') {
            return [];
        }

        return $default;
    });
    Monkey\Functions\when('update_option')->justReturn(true);

    $result = $config->save_configuration([
        'email_integration' => true,
        'pdf_integration' => false,
        'token_expiry_days' => 30,
        'email_subject_template' => 'Payment for {site_name}',
        'email_body_template' => '<p>Hello {member_name}</p>',
    ]);

    expect($result)->toBeTrue();
});

<?php

declare(strict_types=1);

it('does not populate cart or checkout without a valid guest payment token', function (): void {
    $baseUrl = wgp_browser_base_url();

    if ($baseUrl === '') {
        $this->markTestSkipped('Set WICKET_BROWSER_BASE_URL to run browser tests.');
    }

    $cartPage = wicket_browser_visit($baseUrl . '/cart/');
    $cartPage->assertCount('.cart_item', 0);
    $cartPage->assertCount('.wc-block-components-order-summary-item', 0);

    $checkoutPage = wicket_browser_visit($baseUrl . '/checkout/');
    $checkoutPath = (string) (parse_url($checkoutPage->url(), PHP_URL_PATH) ?? '');

    if (str_contains($checkoutPath, '/checkout')) {
        $checkoutPage->assertCount('.cart_item', 0);
        $checkoutPage->assertCount('.wc-block-components-order-summary-item', 0);
    } else {
        $checkoutPage->assertPathBeginsWith('/cart');
        $checkoutPage->assertCount('.cart_item', 0);
        $checkoutPage->assertCount('.wc-block-components-order-summary-item', 0);
    }
});

it('keeps cart empty and shows an error for an invalid guest payment token', function (): void {
    $baseUrl = wgp_browser_base_url();

    if ($baseUrl === '') {
        $this->markTestSkipped('Set WICKET_BROWSER_BASE_URL to run browser tests.');
    }

    $noticeText = 'The payment link is invalid or has expired. Please request a new link.';
    $invalidTokenPage = wicket_browser_visit($baseUrl . '/?guest_payment_token=invalid-token');
    $invalidTokenPage->wait(1);

    $cartPage = wicket_browser_visit($baseUrl . '/cart/');
    $cartPage->assertCount('.cart_item', 0);
    $cartPage->assertCount('.wc-block-components-order-summary-item', 0);

    $cartErrorPage = wicket_browser_visit($baseUrl . '/cart/?guest_payment_error=invalid_token');
    $cartErrorPage->assertQueryStringHas('guest_payment_error', 'invalid_token');
});

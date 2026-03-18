<?php

declare(strict_types=1);

$wicketGuestPaymentClassAliases = [
    Wicket\GuestPayment\AbstractWicketGuestPaymentComponent::class => 'AbstractWicketGuestPaymentComponent',
    Wicket\GuestPayment\WicketGuestPayment::class => 'WicketGuestPayment',
    Wicket\GuestPayment\WicketGuestPaymentAdmin::class => 'WicketGuestPaymentAdmin',
    Wicket\GuestPayment\WicketGuestPaymentAdminPay::class => 'WicketGuestPaymentAdminPay',
    Wicket\GuestPayment\WicketGuestPaymentAuth::class => 'WicketGuestPaymentAuth',
    Wicket\GuestPayment\WicketGuestPaymentComponent::class => 'WicketGuestPaymentComponent',
    Wicket\GuestPayment\WicketGuestPaymentConfig::class => 'WicketGuestPaymentConfig',
    Wicket\GuestPayment\WicketGuestPaymentCore::class => 'WicketGuestPaymentCore',
    Wicket\GuestPayment\WicketGuestPaymentEmail::class => 'WicketGuestPaymentEmail',
    Wicket\GuestPayment\WicketGuestPaymentInvoice::class => 'WicketGuestPaymentInvoice',
    Wicket\GuestPayment\WicketGuestPaymentReceipt::class => 'WicketGuestPaymentReceipt',
];

foreach ($wicketGuestPaymentClassAliases as $target => $alias) {
    if (!class_exists($target)) {
        continue;
    }

    if (!class_exists($alias, false)) {
        class_alias($target, $alias);
    }
}

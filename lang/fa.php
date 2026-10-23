<?php

return [
    'driver.name' => 'پی پینگ',
    'service.name' => 'پی پینگ',
    'fields.apiKey' => 'توکن دسترسی',
    'fields.apiKey_help' => 'توکن دسترسی پی پینگ (به صورت Bearer ارسال می شود).',
    'messages.PayPing requires a callback URL.' => 'پی پینگ نیازمند آدرس بازگشت است.',
    'messages.PayPing requires the original amount for verification.' => 'پی پینگ برای تایید نیازمند مبلغ اصلی است.',
    'messages.PayPing requires the paymentRefId (from the callback) for verification.' => 'پی پینگ برای تایید نیازمند paymentRefId است.',
    'messages.PayPing requires the paymentRefId for refund.' => 'پی پینگ برای بازپرداخت نیازمند paymentRefId است.',
    'messages.PayPing invalid Callback payload.' => 'پاسخ بازگشتی پی پینگ نامعتبر است.',
    'messages.Amount must be greater than zero.' => 'مبلغ باید بزرگ‌تر از صفر باشد.',
];

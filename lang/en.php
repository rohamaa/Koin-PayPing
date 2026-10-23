<?php

return [
    'driver.name' => 'PayPing',
    'service.name' => 'PayPing',
    'fields.apiKey' => 'Access Token',
    'fields.apiKey_help' => 'Your PayPing access token (sent as a Bearer token).',
    'messages.PayPing requires a callback URL.' => 'PayPing requires a callback URL.',
    'messages.PayPing requires the original amount for verification.' => 'PayPing requires the original amount for verification.',
    'messages.PayPing requires the paymentRefId (from the callback) for verification.' => 'PayPing requires the paymentRefId (from the callback) for verification.',
    'messages.PayPing requires the paymentRefId for refund.' => 'PayPing requires the paymentRefId for refund.',
    'messages.PayPing invalid Callback payload.' => 'PayPing invalid Callback payload.',
    'messages.Amount must be greater than zero.' => 'Amount must be greater than zero.',
];

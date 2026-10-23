<?php

namespace Rohama\Koin\Payping;

use GuzzleHttp\Client;
use Rohama\Koin\Support\PaymentStatus;
use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\PaymentFailedException;
use Rohama\Koin\Exception\VerificationFailedException;
use Rohama\Koin\PaymentGateway;
use Rohama\Koin\RefundableGateway;
use Rohama\Koin\Requests\Callback;
use Rohama\Koin\Responses\PaymentResponse;
use Rohama\Koin\Responses\RefundResponse;
use Rohama\Koin\Responses\VerificationResponse;
use Rohama\Koin\Traits\IsPayment;
use Rohama\Translator\Translator;

/**
 * PayPing gateway — REST API v3.
 */
class PaypingGateway implements PaymentGateway, RefundableGateway
{
    use IsPayment { __construct as protected paymentConstruct; }

    public const name = 'payping';

    private const HTTP_OK = 200;
    private const HTTP_PROCESSING = 202;
    private const HTTP_CONFLICT = 409;
    private const HTTP_BAD_GATEWAY = 502;
    private const ALREADY_VERIFIED_CODE = 110;
    private const CALLBACK_CANCELED_CODE = 127;
    private const UNPAID_META_CODES = [121, 122, 131, 133, 137];

    public function __construct(string $apiKey, ?string $callbackUrl = null, bool $sandbox = false, array $extra = [], bool $refundable = false)
    {
        $this->paymentConstruct($apiKey, $callbackUrl, $sandbox, $extra, $refundable);

        Translator::register('Koin-' . self::name, dirname(__DIR__) . '/lang');
        $this->client = new Client([
            'base_uri' => 'https://api.payping.ir',
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ],
        ]);
    }

    public static function configFields(): array
    {
        $prefix = 'Koin-' . self::name;

        return [
            [
                'key' => 'apiKey',
                'name' => Translator::trans($prefix . ':fields.apiKey'),
                'desc' => Translator::trans($prefix . ':fields.apiKey_help'),
                'type' => 'text',
                'required' => true,
                'validation' => ['required', 'string'],
                'regex' => '/^.+$/',
            ],
        ];
    }

    public function request(
        int $amount,
        string $orderId = '',
        ?string $callbackUrl = null,
        string $description = '',
        ?string $mobile = null,
        ?string $email = null,
        ?string $currency = null,
        array $metadata = [],
        array $extra = [],
    ): PaymentResponse {
        if ($amount <= 0) {
            throw new InvalidRequestException('Amount must be greater than zero.', gateway: self::name);
        }

        if ($callbackUrl === null && $this->callbackUrl === null) {
            throw new InvalidRequestException('PayPing requires a callback URL.', gateway: self::name);
        }

        $isReversible = $extra['isReversible'] ?? $this->refundable;
        $response = $this->client()->post('/v3/pay', [
            'json' => array_filter(array_merge([
                'amount' => $amount,
                'returnUrl' => $callbackUrl ?? $this->callbackUrl,
                'description' => $description,
                'clientRefId' => $orderId,
                'payerIdentity' => $mobile,
                'payerName' => $metadata['name'] ?? null,
                'isReversible' => $isReversible,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() !== self::HTTP_OK || !isset($data['paymentCode'])) {
            throw new PaymentFailedException($this->problemError($data), $response->getStatusCode(), raw: $data, gateway: self::name);
        }

        return new PaymentResponse(
            $data,
            $response,
            PaymentStatus::PENDING,
            (string) $data['paymentCode'],
            isset($data['url']) ? (string) $data['url'] : null,
            $orderId,
            null,
        );
    }

    public function parseCallback(array $query, array $body): Callback
    {
        $raw = array_merge($query, $body);

        $payload = [];
        if (isset($raw['data']) && is_string($raw['data'])) {
            $payload = json_decode($raw['data'], true) ?? [];
        } elseif (is_array($raw['data'] ?? null)) {
            $payload = $raw['data'];
        }

        $paid = isset($raw['status']) && (int) $raw['status'] === 1;
        $errorCode = isset($raw['errorCode']) && $raw['errorCode'] !== '' ? (int) $raw['errorCode'] : null;

        $paymentCode = isset($payload['paymentCode']) ? (string) $payload['paymentCode'] : '';
        $orderId = isset($payload['clientRefId']) ? (string) $payload['clientRefId'] : '';

        if ($paymentCode === '') {
            throw new InvalidRequestException('PayPing invalid Callback payload.', gateway: self::name);
        }

        if (! $paid) {
            $status = $errorCode === self::CALLBACK_CANCELED_CODE ? PaymentStatus::CANCELED : PaymentStatus::FAILED;
            return new Callback($status, $paymentCode, $orderId, $raw);
        }

        $extras = isset($payload['paymentRefId']) ? ['paymentRefId' => $payload['paymentRefId']] : [];
        $amount = isset($payload['amount']) ? (int) $payload['amount'] : null;
        $verified = $this->verify($paymentCode, $orderId, $amount, extra: $extras);

        return new Callback($verified->status, $paymentCode, $orderId, $raw);
    }

    public function verify(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): VerificationResponse {
        if ($amount === null) {
            throw new InvalidRequestException('PayPing requires the original amount for verification.', gateway: self::name);
        }

        if (!isset($extra['paymentRefId'])) {
            throw new InvalidRequestException('PayPing requires the paymentRefId (from the callback) for verification.', gateway: self::name);
        }

        $response = $this->client()->post('/v3/pay/verify', [
            'json' => array_filter(array_merge([
                'paymentRefId' => (int) $extra['paymentRefId'],
                'paymentCode' => $transactionId,
                'amount' => $amount,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        $status = $response->getStatusCode();

        if ($status === self::HTTP_CONFLICT && (int) ($data['metaData']['code'] ?? 0) === self::ALREADY_VERIFIED_CODE) {
            $expected = $amount;
            $actual = isset($data['metaData']['message']['Amount']) ? (int) $data['metaData']['message']['Amount'] : null;

            if ($expected !== null && $actual !== null && $actual !== $expected) {
                throw new VerificationFailedException(
                    sprintf('PayPing verified amount mismatch: expected %d, got %s.', $expected, $actual),
                    0,
                    raw: ['expected' => $expected, 'actual' => $actual],
                    gateway: self::name,
                );
            }

            return new VerificationResponse(
                $data,
                $response,
                PaymentStatus::SUCCESS,
                $transactionId,
                isset($data['metaData']['paymentRefId']) ? (string) $data['metaData']['paymentRefId'] : null,
                null,
                null,
                'Already verified.',
            );
        }

        if ($status === self::HTTP_PROCESSING || $status === self::HTTP_BAD_GATEWAY) {
            return new VerificationResponse(
                $data,
                $response,
                PaymentStatus::PENDING,
                $transactionId,
                null,
                null,
                null,
                'Verification is still processing, please retry.',
            );
        }

        if ($status >= 400 && $status < 500) {
            $metaCode = (int) ($data['metaData']['code'] ?? 0);

            if ($metaCode === self::CALLBACK_CANCELED_CODE) {
                return new VerificationResponse(
                    $data,
                    $response,
                    PaymentStatus::CANCELED,
                    $transactionId,
                    null,
                    null,
                    null,
                    $this->problemError($data),
                );
            }

            if (in_array($metaCode, self::UNPAID_META_CODES, true)) {
                return new VerificationResponse(
                    $data,
                    $response,
                    PaymentStatus::FAILED,
                    $transactionId,
                    null,
                    null,
                    null,
                    $this->problemError($data),
                );
            }
        }

        if ($status !== self::HTTP_OK) {
            throw new VerificationFailedException($this->problemError($data), $status, raw: $data, gateway: self::name);
        }

        $actual = isset($data['amount']) ? (int) $data['amount'] : null;

        if ($actual === null || $actual !== $amount) {
            throw new VerificationFailedException(
                sprintf('PayPing verified amount mismatch: expected %d, got %s.', $amount, $actual === null ? 'null' : (string) $actual),
                0,
                raw: ['expected' => $amount, 'actual' => $actual],
                gateway: self::name,
            );
        }

        return new VerificationResponse(
            $data,
            $response,
            PaymentStatus::SUCCESS,
            $transactionId,
            isset($data['paymentRefId']) ? (string) $data['paymentRefId'] : null,
            $actual,
            isset($data['cardNumber']) ? (string) $data['cardNumber'] : null,
            null,
        );
    }

    public function refund(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): RefundResponse {
        if (!$this->refundable) {
            throw new InvalidRequestException('PayPing refunds are only available for gateways constructed with refundable: true.', gateway: self::name);
        }

        if (!isset($extra['paymentRefId'])) {
            throw new InvalidRequestException('PayPing requires the paymentRefId for refund.', gateway: self::name);
        }

        $response = $this->client()->post('/v3/pay/reverse', [
            'json' => array_filter(array_merge([
                'paymentRefId' => (int) $extra['paymentRefId'],
                'paymentCode' => $transactionId,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() !== self::HTTP_OK) {
            throw new PaymentFailedException($this->problemError($data), $response->getStatusCode(), raw: $data, gateway: self::name);
        }

        return new RefundResponse(
            $data,
            $response,
            PaymentStatus::REFUNDED,
            $transactionId,
            isset($data['paymentRefId']) ? (string) $data['paymentRefId'] : null,
            null,
        );
    }

    /**
     * Builds an error message from a PayPing ProblemDetails payload.
     */
    private function problemError(array $data): string
    {
        $title = isset($data['title']) ? (string) $data['title'] : 'Unknown error';

        $code = null;
        if (isset($data['metaData']['code']) && $data['metaData']['code'] !== '') {
            $code = (int) $data['metaData']['code'];
        }

        return $code !== null
            ? sprintf('PayPing error (code %d): %s', $code, $title)
            : $title;
    }
}

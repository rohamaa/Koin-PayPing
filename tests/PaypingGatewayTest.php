<?php

use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\PaymentFailedException;
use Rohama\Koin\Exception\VerificationFailedException;
use Rohama\Koin\Payping\PaypingGateway;
use Rohama\Translator\Translator;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

final class PaypingGatewayTest extends TestCase
{
    public function testRequestSendsPayerNameFromMetadata(): void
    {
        $container = [];
        $stack = new HandlerStack(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['paymentCode' => 'pc1', 'url' => 'https://payping.ir/p/1'])),
        ]));
        $stack->push(Middleware::history($container));

        $gateway = new PaypingGateway('k', 'https://example.com/cb');
        $gateway->setClient(new Client(['handler' => $stack]));

        $gateway->request(1000, 'o1', metadata: ['name' => 'Ali']);

        $body = json_decode($container[0]['request']->getBody()->getContents(), true);

        $this->assertSame('Ali', $body['payerName']);
        $this->assertSame('o1', $body['clientRefId']);
        $this->assertSame(1000, $body['amount']);
    }

    public function testRequestForwardsIsReversibleForRefundablePayments(): void
    {
        $container = [];
        $stack = new HandlerStack(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['paymentCode' => 'pc1', 'url' => 'https://payping.ir/p/1'])),
        ]));
        $stack->push(Middleware::history($container));

        $gateway = new PaypingGateway('k', 'https://example.com/cb', refundable: true);
        $gateway->setClient(new Client(['handler' => $stack]));

        $gateway->request(1000, 'o1');

        $body = json_decode($container[0]['request']->getBody()->getContents(), true);

        $this->assertTrue($body['isReversible']);
    }

    public function testParseCallbackVerifiesASuccessfulPaymentLikeIdpay(): void
    {
        $gateway = new PaypingGateway('k');
        $gateway->setClient($this->mockHttp([[200, ['amount' => 1000, 'paymentRefId' => 'pr1']]]));
        $result = $gateway->parseCallback([
            'status' => '1',
            'errorCode' => '',
            'data' => json_encode([
                'clientRefId' => 'o1',
                'paymentCode' => 'pc1',
                'paymentRefId' => 'pr1',
                'amount' => 1000,
            ]),
        ], []);

        $this->assertSame('success', $result->status);
        $this->assertSame('pc1', $result->transactionId);
        $this->assertSame('o1', $result->orderId);
    }

    public function testParseCallbackMapsAnUnpaidCallbackToFailedWithoutVerifying(): void
    {
        $gateway = new PaypingGateway('k');
        $result = $gateway->parseCallback([
            'status' => '0',
            'errorCode' => '',
            'data' => json_encode(['clientRefId' => 'o1', 'paymentCode' => 'pc1']),
        ], []);

        $this->assertSame('failed', $result->status);
        $this->assertSame('pc1', $result->transactionId);
    }

    public function testParseCallbackMapsAnError127CallbackToCanceled(): void
    {
        $gateway = new PaypingGateway('k');
        $result = $gateway->parseCallback([
            'status' => '0',
            'errorCode' => '127',
            'data' => json_encode(['clientRefId' => 'o1', 'paymentCode' => 'pc1']),
        ], []);

        $this->assertSame('canceled', $result->status);
    }

    public function testParseCallbackThrowsWithoutAPaymentCode(): void
    {
        $this->expectException(InvalidRequestException::class);
        (new PaypingGateway('k'))->parseCallback([], []);
    }

    public function testVerifyReturnsPendingStatusForStillProcessingResponses(): void
    {
        $gateway = new PaypingGateway('k');
        $gateway->setClient($this->mockHttp([[202, ['title' => 'processing']]]));

        $result = $gateway->verify('pc1', 'o1', 1000, extra: ['paymentRefId' => 'pr1']);

        $this->assertSame('pending', $result->status);
    }

    public function testVerifyMapsAMetaCode122PaymentNotFoundToFailedResult(): void
    {
        $gateway = new PaypingGateway('k');
        $gateway->setClient($this->mockHttp([[404, ['title' => 'NotFoundException', 'metaData' => ['code' => 122]]]]));

        $result = $gateway->verify('pc1', 'o1', 1000, extra: ['paymentRefId' => 'pr1']);

        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('code 122', $result->message);
    }

    public function testVerifyMapsAMetaCode121UnverifiedPaymentToFailedResult(): void
    {
        $gateway = new PaypingGateway('k');
        $gateway->setClient($this->mockHttp([[400, ['title' => 'BusinessException', 'metaData' => ['code' => 121]]]]));

        $result = $gateway->verify('pc1', 'o1', 1000, extra: ['paymentRefId' => 'pr1']);

        $this->assertSame('failed', $result->status);
    }

    public function testVerifyMapsAMetaCode127CanceledPaymentToCanceledResult(): void
    {
        $gateway = new PaypingGateway('k');
        $gateway->setClient($this->mockHttp([[400, ['title' => 'BusinessException', 'metaData' => ['code' => 127]]]]));

        $result = $gateway->verify('pc1', 'o1', 1000, extra: ['paymentRefId' => 'pr1']);

        $this->assertSame('canceled', $result->status);
    }

    public function testVerifyTreatsAnAlreadyVerified409AsSuccess(): void
    {
        $gateway = new PaypingGateway('k');
        $gateway->setClient($this->mockHttp([
            [409, ['metaData' => ['code' => 110, 'message' => ['Amount' => 1000, 'PaymentRefId' => 'pr1']]]],
        ]));

        $result = $gateway->verify('pc1', 'o1', 1000, extra: ['paymentRefId' => 'pr1']);

        $this->assertSame('success', $result->status);
    }

    public function testVerifyThrowsOnAmountMismatch(): void
    {
        $gateway = new PaypingGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['amount' => 999, 'paymentRefId' => 'pr1']],
        ]));

        $this->expectException(VerificationFailedException::class);
        $this->expectExceptionMessage('amount mismatch');
        $gateway->verify('pc1', 'o1', 1000, extra: ['paymentRefId' => 'pr1']);
    }

    public function testRefundReturnsRefundedStatus(): void
    {
        $gateway = new PaypingGateway('k', refundable: true);
        $gateway->setClient($this->mockHttp([[200, ['paymentRefId' => 'pr1']]]));

        $result = $gateway->refund('pc1', 'o1', extra: ['paymentRefId' => 'pr1']);

        $this->assertSame('refunded', $result->status);
        $this->assertSame('pc1', $result->transactionId);
    }

    public function testRefundThrowsOnAFailureResponse(): void
    {
        $gateway = new PaypingGateway('k', refundable: true);
        $gateway->setClient($this->mockHttp([[404, ['title' => 'payment not reversible']]]));

        $this->expectException(PaymentFailedException::class);
        $this->expectExceptionMessage('payment not reversible');
        $gateway->refund('pc1', 'o1', extra: ['paymentRefId' => 'pr1']);
    }

    public function testRefundThrowsWhenTheGatewayIsNotRefundable(): void
    {
        $gateway = new PaypingGateway('k');
        $gateway->setClient($this->mockHttp([[200, []]]));

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('refundable: true');

        $gateway->refund('pc1', extra: ['paymentRefId' => '1']);
    }

    public function testRefundThrowsWithoutAPaymentRefId(): void
    {
        $this->expectException(InvalidRequestException::class);
        (new PaypingGateway('k'))->refund('pc1', 'o1');
    }

    public function testConfigFieldsListsThePaypingInputs(): void
    {
        $keys = array_map(fn ($f) => $f['key'], PaypingGateway::configFields());

        $this->assertSame(['apiKey'], $keys);
    }

    public function testLangFilesCoverThePaypingName(): void
    {
        $this->assertSame('PayPing', Translator::trans('Koin-payping:driver.name'));
    }
    public function testRefundRequiresTheRefundableFlag(): void
    {
        $gateway = new PaypingGateway('k');

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('constructed with refundable: true');

        $gateway->refund('t1');
    }
}

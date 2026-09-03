<?php

namespace App\Tests\Unit\Payment;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\EmailAlias;
use App\Entity\HelloAssoConfig;
use App\Entity\Payment;
use App\Entity\PaymentStatus;
use App\Integration\Cyclos\CyclosClient;
use App\Integration\HelloAsso\HelloAssoClient;
use App\Integration\HelloAsso\HelloAssoFetchedPayment;
use App\Integration\HelloAsso\HelloAssoNotificationPayload;
use App\Notification\NotificationMailer;
use App\Payment\PaymentProcessor;
use App\Repository\EmailAliasRepository;
use App\Repository\HelloAssoConfigRepository;
use App\Repository\PaymentRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Covers the manual-sync auto-credit behaviour: PaymentProcessor::fetchMissingPayments()
 * must only attempt a Cyclos credit per fetched payment when explicitly asked to
 * (the manual "Synchro Hello Asso" button), never for the periodic safety-net command,
 * and must still respect the client's own automatic/amount-limit rules either way.
 */
class PaymentProcessorTest extends TestCase
{
    private function makeClient(bool $automatic, int $maxAmount = 250): Client
    {
        $client = (new Client())->setName('Test')->setSlug('test')->setActive(true);

        $haConfig = (new HelloAssoConfig())
            ->setLabel('Particuliers')
            ->setActive(true)
            ->setApiUrl('https://api.helloasso.example/')
            ->setHelloAssoClientId('id')
            ->setClientSecretEncrypted('enc')
            ->setOrganizationSlug('org')
            ->setFormSlug('form')
            ->setMaxAmount($maxAmount)
            ->setFetchNbDays(5);
        $client->addHelloAssoConfig($haConfig);

        $cyclosConfig = (new CyclosConfig())
            ->setBaseUrl('https://cyclos.example/api/')
            ->setTechnicalUserId('1')
            ->setPasswordEncrypted('enc')
            ->setGroupProInternal('pro')
            ->setGroupsPartInternal('part')
            ->setEmissionProInternal('emission.Pro')
            ->setEmissionPartInternal('emission.Part');
        $client->setCyclosConfig($cyclosConfig);

        $setting = (new ClientSetting())
            ->setPaymentCyclosEnabled(true)
            ->setPaymentAutomaticEnabled($automatic)
            ->setMailRecipient('ops@example.com');
        $client->setSetting($setting);

        return $client;
    }

    private function makeProcessor(
        HelloAssoClient $helloAssoClient,
        CyclosClient $cyclosClient,
        NotificationMailer $mailer,
        ?EmailAliasRepository $emailAliasRepository = null,
        ?PaymentRepository $paymentRepository = null,
        ?EntityManagerInterface $entityManager = null,
        ?HelloAssoConfigRepository $helloAssoConfigRepository = null,
    ): PaymentProcessor {
        $entityManager ??= $this->createStub(EntityManagerInterface::class);
        if ($paymentRepository === null) {
            $paymentRepository = $this->createStub(PaymentRepository::class);
        }
        $paymentRepository->method('findAllHelloAssoIdsForClient')->willReturn([]);

        if ($emailAliasRepository === null) {
            $emailAliasRepository = $this->createStub(EmailAliasRepository::class);
            $emailAliasRepository->method('findOneByClientAndSourceEmail')->willReturn(null);
        }

        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        return new PaymentProcessor(
            $entityManager,
            $paymentRepository,
            $emailAliasRepository,
            $helloAssoConfigRepository ?? $this->createStub(HelloAssoConfigRepository::class),
            $helloAssoClient,
            $cyclosClient,
            $mailer,
            new NullLogger(),
            $messageBus,
        );
    }

    public function testPeriodicFetchNeverAttemptsCreditEvenWithAutomaticEnabled(): void
    {
        $client = $this->makeClient(automatic: true);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('fetchPaymentsHistory')->willReturn([
            new HelloAssoFetchedPayment(111, 2000, '2026-08-19T10:00:00+02:00', 'Jean', 'Dupont', 'jean@example.com'),
        ]);

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::never())->method('findUserByEmail');

        $mailer = $this->createStub(NotificationMailer::class);

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer);

        $added = $processor->fetchMissingPayments($client);

        self::assertSame(1, $added);
    }

    public function testManualSyncSkipsCreditWhenAutomaticDisabled(): void
    {
        $client = $this->makeClient(automatic: false);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('fetchPaymentsHistory')->willReturn([
            new HelloAssoFetchedPayment(222, 2000, '2026-08-19T10:00:00+02:00', 'Jean', 'Dupont', 'jean@example.com'),
        ]);

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::never())->method('findUserByEmail');

        $mailer = $this->createStub(NotificationMailer::class);

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer);

        $added = $processor->fetchMissingPayments($client, attemptAutomaticCredit: true);

        self::assertSame(1, $added);
    }

    public function testManualSyncMarksOverLimitPaymentAsTooHighWithoutAttemptingCredit(): void
    {
        $client = $this->makeClient(automatic: true, maxAmount: 10);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('fetchPaymentsHistory')->willReturn([
            new HelloAssoFetchedPayment(333, 100000, '2026-08-19T10:00:00+02:00', 'Jean', 'Dupont', 'jean@example.com'),
        ]);

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::never())->method('findUserByEmail');

        $mailer = $this->createMock(NotificationMailer::class);
        $mailer->expects(self::once())->method('sendForClient')
            ->with(self::isInstanceOf(Client::class), 'ops@example.com', 'over_limit', self::anything());

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer);

        $added = $processor->fetchMissingPayments($client, attemptAutomaticCredit: true);

        self::assertSame(1, $added);
    }

    /**
     * A HelloAsso payment whose date can't be parsed must not be auto-credited:
     * parseDate() falls back to the Unix epoch so isLate() flags it, and it is
     * left for manual review with a "late payment" alert.
     */
    public function testManualSyncDoesNotAutoCreditPaymentWithUnparseableDate(): void
    {
        $client = $this->makeClient(automatic: true);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('fetchPaymentsHistory')->willReturn([
            new HelloAssoFetchedPayment(999, 2000, 'pas-une-date', 'Jean', 'Dupont', 'jean@example.com'),
        ]);

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::never())->method('findUserByEmail');

        $mailer = $this->createMock(NotificationMailer::class);
        $mailer->expects(self::once())->method('sendForClient')
            ->with(self::isInstanceOf(Client::class), 'ops@example.com', 'too_late', self::anything());

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer);

        $added = $processor->fetchMissingPayments($client, attemptAutomaticCredit: true);

        self::assertSame(1, $added);
    }

    public function testManualSyncAttemptsCreditForEachEligibleFetchedPayment(): void
    {
        $client = $this->makeClient(automatic: true);

        // Recent (not hardcoded) dates: PaymentProcessor::isLate() compares
        // against the real current time, so a fixed past date eventually
        // drifts past the 12h window and starts failing this test for
        // unrelated reasons.
        $recentDate = (new \DateTimeImmutable())->modify('-1 hour')->format(DATE_ATOM);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('fetchPaymentsHistory')->willReturn([
            new HelloAssoFetchedPayment(444, 2000, $recentDate, 'Jean', 'Dupont', 'jean@example.com'),
            new HelloAssoFetchedPayment(555, 3000, $recentDate, 'Marie', 'Curie', 'marie@example.com'),
        ]);
        $helloAssoClient->method('getAlternativeEmail')->willReturn(null);

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::exactly(2))->method('findUserByEmail')
            ->willReturn(null); // simulate "user not found" so the flow stops there without needing to fake the rest of the credit pipeline.

        $mailer = $this->createStub(NotificationMailer::class);

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer);

        $added = $processor->fetchMissingPayments($client, attemptAutomaticCredit: true);

        self::assertSame(2, $added);
    }

    public function testCreditUsesTheAliasedEmailInsteadOfThePayerEmailWhenARuleExists(): void
    {
        $client = $this->makeClient(automatic: true);

        $payment = new Payment(
            client: $client,
            helloAssoConfig: $client->getHelloAssoConfigs()->first(),
            helloAssoPaymentId: 666,
            paymentDate: new \DateTimeImmutable(),
            amount: 20.0,
            payerFirstName: 'Jean',
            payerLastName: 'Dupont',
            email: 'jean.helloasso@example.com',
        );

        $alias = (new EmailAlias())->setClient($client)
            ->setSourceEmail('jean.helloasso@example.com')
            ->setTargetEmail('jean.real-cyclos-account@example.com');

        $emailAliasRepository = $this->createStub(EmailAliasRepository::class);
        $emailAliasRepository->method('findOneByClientAndSourceEmail')->willReturn($alias);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::once())->method('findUserByEmail')
            ->with(self::anything(), 'jean.real-cyclos-account@example.com')
            ->willReturn(null); // stop here — the assertion above is what this test cares about.

        $mailer = $this->createStub(NotificationMailer::class);

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer, $emailAliasRepository);
        $processor->creditManually($payment);
    }

    public function testAliasLookupUsesTheImmutablePayerEmailEvenIfEmailWasAlreadyOverwritten(): void
    {
        $client = $this->makeClient(automatic: true);

        $payment = new Payment(
            client: $client,
            helloAssoConfig: $client->getHelloAssoConfigs()->first(),
            helloAssoPaymentId: 777,
            paymentDate: new \DateTimeImmutable(),
            amount: 20.0,
            payerFirstName: 'Marie',
            payerLastName: 'Curie',
            email: 'marie.helloasso@example.com',
        );
        // Simulate a prior successful credit having already overwritten `email`
        // (e.g. via the HelloAsso alternative-email fallback) — payerEmail must
        // stay untouched and keep being the lookup key for the alias rule.
        $payment->setEmail('marie.alternative@example.com');

        $emailAliasRepository = $this->createMock(EmailAliasRepository::class);
        $emailAliasRepository->expects(self::once())->method('findOneByClientAndSourceEmail')
            ->with($client, 'marie.helloasso@example.com')
            ->willReturn(null);

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('getAlternativeEmail')->willReturn(null);

        $cyclosClient = $this->createStub(CyclosClient::class);
        $cyclosClient->method('findUserByEmail')->willReturn(null);

        $mailer = $this->createStub(NotificationMailer::class);

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer, $emailAliasRepository);
        $processor->creditManually($payment);
    }

    /**
     * The whole point of Payment::$helloAssoConfig (see its docblock): once a
     * client has more than one HelloAsso form, a manual/late credit must use
     * the config the payment actually came from for the alternative-email
     * fallback — not just whichever config the client happens to expose
     * first. Two configs with distinct organizationSlugs make a wrong pick
     * observable via the assertion below.
     */
    public function testManualCreditUsesThePaymentsOwnConfigForTheAlternativeEmailLookupWhenClientHasTwoForms(): void
    {
        $client = $this->makeClient(automatic: true);

        $secondConfig = (new HelloAssoConfig())
            ->setLabel('Professionnels')
            ->setActive(true)
            ->setApiUrl('https://api.helloasso.example/')
            ->setHelloAssoClientId('id-pro')
            ->setClientSecretEncrypted('enc')
            ->setOrganizationSlug('org-pro')
            ->setFormSlug('form-pro')
            ->setMaxAmount(250)
            ->setFetchNbDays(5);
        $client->addHelloAssoConfig($secondConfig);

        $payment = new Payment(
            client: $client,
            helloAssoConfig: $secondConfig,
            helloAssoPaymentId: 888,
            paymentDate: new \DateTimeImmutable(),
            amount: 20.0,
            payerFirstName: 'Paul',
            payerLastName: 'Martin',
            email: 'paul@example.com',
        );

        $cyclosClient = $this->createStub(CyclosClient::class);
        $cyclosClient->method('findUserByEmail')->willReturn(null); // forces the alternative-email fallback below.

        $helloAssoClient = $this->createMock(HelloAssoClient::class);
        $helloAssoClient->expects(self::once())->method('getAlternativeEmail')
            ->with(self::callback(static fn (HelloAssoConfig $config) => $config->getOrganizationSlug() === 'org-pro'), 888)
            ->willReturn(null);

        $mailer = $this->createStub(NotificationMailer::class);

        $processor = $this->makeProcessor($helloAssoClient, $cyclosClient, $mailer);
        $processor->creditManually($payment);
    }

    private function makeTodoPayment(Client $client, \DateTimeImmutable $paymentDate): Payment
    {
        $payment = new Payment(
            client: $client,
            helloAssoConfig: $client->getHelloAssoConfigs()->first(),
            helloAssoPaymentId: 4242,
            paymentDate: $paymentDate,
            amount: 20.0,
            payerFirstName: 'Jean',
            payerLastName: 'Dupont',
            email: 'jean@example.com',
        );
        $payment->setStatus(PaymentStatus::Todo);

        return $payment;
    }

    private function makeProcessorForQueued(Payment $payment, CyclosClient $cyclosClient, NotificationMailer $mailer): PaymentProcessor
    {
        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('find')->willReturn($payment);

        return $this->makeProcessor($this->createStub(HelloAssoClient::class), $cyclosClient, $mailer, null, $paymentRepository);
    }

    public function testQueuedProcessingMarksALatePaymentTooLateWithoutCrediting(): void
    {
        $client = $this->makeClient(automatic: true);
        $payment = $this->makeTodoPayment($client, (new \DateTimeImmutable())->modify('-13 hours'));

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::never())->method('findUserByEmail');

        $mailer = $this->createMock(NotificationMailer::class);
        $mailer->expects(self::once())->method('sendForClient')
            ->with(self::isInstanceOf(Client::class), 'ops@example.com', 'too_late', self::anything());

        $this->makeProcessorForQueued($payment, $cyclosClient, $mailer)->processQueuedPayment(4242, reportedWaiting: false);

        self::assertSame(PaymentStatus::TooLate, $payment->getStatus());
    }

    public function testQueuedProcessingMarksAWaitingPaymentWaitingWithoutCrediting(): void
    {
        $client = $this->makeClient(automatic: true);
        $payment = $this->makeTodoPayment($client, (new \DateTimeImmutable())->modify('-1 hour'));

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::never())->method('findUserByEmail');

        $mailer = $this->createMock(NotificationMailer::class);
        $mailer->expects(self::once())->method('sendForClient')
            ->with(self::isInstanceOf(Client::class), 'ops@example.com', 'waiting', self::anything());

        $this->makeProcessorForQueued($payment, $cyclosClient, $mailer)->processQueuedPayment(4242, reportedWaiting: true);

        self::assertSame(PaymentStatus::Waiting, $payment->getStatus());
    }

    public function testQueuedProcessingIsANoOpWhenPaymentIsNoLongerTodo(): void
    {
        $client = $this->makeClient(automatic: true);
        $payment = $this->makeTodoPayment($client, (new \DateTimeImmutable())->modify('-1 hour'));
        $payment->setStatus(PaymentStatus::SuccessAuto); // already handled (redelivered message)

        $cyclosClient = $this->createMock(CyclosClient::class);
        $cyclosClient->expects(self::never())->method('findUserByEmail');

        $mailer = $this->createMock(NotificationMailer::class);
        $mailer->expects(self::never())->method('sendForClient');

        $this->makeProcessorForQueued($payment, $cyclosClient, $mailer)->processQueuedPayment(4242, reportedWaiting: false);

        self::assertSame(PaymentStatus::SuccessAuto, $payment->getStatus());
    }

    /**
     * Two webhook deliveries of the same payment can both pass the "already known"
     * check and race to insert. The loser hits the unique index; that must be
     * swallowed (return null, no ProcessPaymentMessage) instead of bubbling up as
     * a 500 that makes HelloAsso retry.
     */
    public function testWebhookSwallowsAConcurrentDuplicateInsert(): void
    {
        $client = $this->makeClient(automatic: true);

        $payload = new HelloAssoNotificationPayload(
            helloAssoPaymentId: 555,
            amountCents: 2000,
            rawDate: (new \DateTimeImmutable())->format(DATE_ATOM),
            state: 'Authorized',
            payerFirstName: 'Jean',
            payerLastName: 'Dupont',
            payerEmail: 'jean@example.com',
            formSlug: 'form',
        );

        $helloAssoClient = $this->createStub(HelloAssoClient::class);
        $helloAssoClient->method('parseNotification')->willReturn($payload);

        $haConfigRepo = $this->createStub(HelloAssoConfigRepository::class);
        $haConfigRepo->method('findOneActiveByClientAndFormSlug')->willReturn($client->getHelloAssoConfigs()->first());

        $paymentRepository = $this->createStub(PaymentRepository::class);
        $paymentRepository->method('findOneByClientAndHelloAssoId')->willReturn(null); // not "already known" at check time

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willThrowException($this->createStub(UniqueConstraintViolationException::class));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $processor = new PaymentProcessor(
            $entityManager,
            $paymentRepository,
            $this->createStub(EmailAliasRepository::class),
            $haConfigRepo,
            $helloAssoClient,
            $this->createStub(CyclosClient::class),
            $this->createStub(NotificationMailer::class),
            new NullLogger(),
            $messageBus,
        );

        self::assertNull($processor->handleWebhookNotification($client, ['eventType' => 'Payment']));
    }
}

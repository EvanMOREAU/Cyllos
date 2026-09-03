<?php

namespace App\Tests\Functional;

use App\Notification\NotificationMailer;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * NotificationMailer::sendTranslated() must resolve subject + body from the
 * "emails" translation domain (translations/emails.fr.yaml), fill placeholders,
 * and keep the multi-line bodies (a "\n" in the YAML is a real newline).
 */
class NotificationMailerTest extends KernelTestCase
{
    private function captureEmail(string $subjectId, string $bodyId, array $params): Email
    {
        self::bootKernel();
        /** @var TranslatorInterface $translator */
        $translator = self::getContainer()->get('translator');

        $captured = null;
        $inner = $this->createStub(MailerInterface::class);
        $inner->method('send')->willReturnCallback(function (Email $email) use (&$captured): void {
            $captured = $email;
        });

        (new NotificationMailer($inner, new NullLogger(), $translator, 'cyllos@cylaos.test'))
            ->sendTranslated('ops@example.com', $subjectId, $bodyId, $params);

        self::assertInstanceOf(Email::class, $captured);

        return $captured;
    }

    public function testTooLateRendersSubjectBodyAndKeepsTheNewline(): void
    {
        $email = $this->captureEmail('too_late.subject', 'too_late.body', ['%id%' => 42]);

        self::assertSame('[Cyllos] Paiement en retard', $email->getSubject());
        self::assertStringContainsString('Id : 42', $email->getTextBody());
        self::assertStringContainsString("\n", $email->getTextBody());
    }

    public function testSuccessFillsIdAndAmountPlaceholders(): void
    {
        $email = $this->captureEmail('success.subject', 'success.body', ['%id%' => 7, '%amount%' => '12.50']);

        self::assertSame('[Cyllos] Paiement réussi', $email->getSubject());
        self::assertStringContainsString('Id : 7', $email->getTextBody());
        self::assertStringContainsString('Montant : 12.50 €', $email->getTextBody());
    }
}

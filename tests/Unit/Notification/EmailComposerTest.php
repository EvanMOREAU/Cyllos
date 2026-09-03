<?php

namespace App\Tests\Unit\Notification;

use App\Entity\Client;
use App\Entity\ClientCustomization;
use App\Notification\EmailComposer;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

class EmailComposerTest extends TestCase
{
    private const DEFAULTS = [
        'success.subject' => 'Paiement réussi',
        'success.body' => "Un paiement a été effectué avec succès.\nId : %id%\nMontant : %amount% €",
        'failure.subject' => 'Paiement en échec',
        'failure.body' => "Un paiement n'a pas pu être effectué.\nId : %id%",
    ];

    private function composer(): EmailComposer
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => self::DEFAULTS[$id] ?? $id,
        );

        return new EmailComposer($translator);
    }

    private function client(?ClientCustomization $customization = null): Client
    {
        $client = (new Client())->setName('La Cigogne');
        if ($customization !== null) {
            $client->setCustomization($customization);
        }

        return $client;
    }

    public function testFallsBackToDefaultAndAppliesTheStandardPrefix(): void
    {
        $composed = $this->composer()->compose($this->client(), 'success', ['%id%' => 7, '%amount%' => '12.50']);

        self::assertSame('[Cyllos] Paiement réussi', $composed->subject);
        self::assertStringContainsString('Id : 7', $composed->body);
        self::assertStringContainsString('Montant : 12.50 €', $composed->body);
        self::assertStringContainsString("\n", $composed->body);
    }

    public function testClientTemplateOverrideWins(): void
    {
        $customization = (new ClientCustomization())->setEmailTemplates([
            'success' => [
                'subject' => 'Recharge confirmée',
                'body' => 'Bonjour %payer%, votre recharge de %amount% € (réf. %id%) est créditée.',
            ],
        ]);

        $composed = $this->composer()->compose($this->client($customization), 'success', [
            '%id%' => 7,
            '%amount%' => '12.50',
            '%payer%' => 'Jean Dupont',
        ]);

        self::assertSame('[Cyllos] Recharge confirmée', $composed->subject);
        self::assertSame('Bonjour Jean Dupont, votre recharge de 12.50 € (réf. 7) est créditée.', $composed->body);
    }

    public function testUnfilledPlaceholderIsLeftLiteral(): void
    {
        $composed = $this->composer()->compose($this->client(), 'failure', []);

        self::assertStringContainsString('Id : %id%', $composed->body);
    }

    public function testSubjectPrefixOverrideAndEmptyMeansNoPrefix(): void
    {
        $custom = (new ClientCustomization())->setEmailSubjectPrefix('[Cigogne]');
        self::assertSame('[Cigogne] Paiement réussi', $this->composer()->compose($this->client($custom), 'success', [])->subject);

        $none = (new ClientCustomization())->setEmailSubjectPrefix('   ');
        self::assertSame('Paiement réussi', $this->composer()->compose($this->client($none), 'success', [])->subject);
    }

    public function testPrefixIsNotDuplicatedWhenSubjectAlreadyCarriesIt(): void
    {
        $custom = (new ClientCustomization())->setEmailTemplates([
            'success' => ['subject' => '[Cyllos] Déjà préfixé'],
        ]);

        self::assertSame('[Cyllos] Déjà préfixé', $this->composer()->compose($this->client($custom), 'success', [])->subject);
    }

    public function testFooterIsAppendedAfterABlankLine(): void
    {
        $custom = (new ClientCustomization())->setEmailFooter("La Cigogne\nsupport@cigogne.test");

        $composed = $this->composer()->compose($this->client($custom), 'failure', ['%id%' => 9]);

        self::assertStringEndsWith("Id : 9\n\nLa Cigogne\nsupport@cigogne.test", $composed->body);
    }
}

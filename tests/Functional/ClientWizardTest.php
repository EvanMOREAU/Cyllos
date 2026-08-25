<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Walks the 4-step "new client" wizard exactly as a browser would — crawling
 * each step's real form and submitting it with its own embedded CSRF token,
 * never a fabricated one. This is what caught the "Réglages" step missing
 * its CSRF field entirely (`form_end(form, {render_rest: false})` without a
 * matching `form_rest(form)` call): the classic session CSRF manager rejects
 * an absent token, whereas the previously-configured stateless CSRF manager
 * silently accepted the request on Origin/Referer alone. See
 * /dev/documentation, section "Incidents résolus".
 */
class ClientWizardTest extends WebTestCase
{
    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function loginAsAdmin(): void
    {
        $admin = new User();
        $admin->setEmail('admin-' . uniqid() . '@wizard.example')->setPassword('irrelevant')->setRoles(['ROLE_ADMIN']);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();

        $this->httpClient->loginUser($admin);
    }

    public function testCompletingAllFourStepsCreatesTheClient(): void
    {
        $this->loginAsAdmin();
        $slug = 'wizard-e2e-' . uniqid();

        $crawler = $this->httpClient->request('GET', '/admin/clients/new');
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Continuer')->form([
            'client_info[name]' => 'Wizard E2E',
            'client_info[slug]' => $slug,
            'client_info[contactEmail]' => 'contact@wizard-e2e.example',
        ]);
        $crawler = $this->httpClient->submit($form);
        self::assertResponseRedirects('/admin/clients/new/helloasso');
        $crawler = $this->httpClient->followRedirect();

        $form = $crawler->selectButton('Continuer')->form([
            'hello_asso_config[apiUrl]' => 'https://api.helloasso.com/',
            'hello_asso_config[helloAssoClientId]' => 'client-id',
            'hello_asso_config[clientSecret]' => 'secret',
            'hello_asso_config[organizationSlug]' => 'org-slug',
            'hello_asso_config[formSlug]' => 'form-slug',
            'hello_asso_config[maxAmount]' => '250',
            'hello_asso_config[fetchNbDays]' => '5',
        ]);
        $crawler = $this->httpClient->submit($form);
        self::assertResponseRedirects('/admin/clients/new/cyclos');
        $crawler = $this->httpClient->followRedirect();

        $form = $crawler->selectButton('Continuer')->form([
            'cyclos_config[baseUrl]' => 'https://cyclos.example/api/',
            'cyclos_config[technicalUserId]' => '1',
            'cyclos_config[password]' => 'pwd',
            'cyclos_config[groupProInternal]' => 'pro',
            'cyclos_config[groupsPartInternal]' => 'part',
            'cyclos_config[emissionProInternal]' => 'emission.Pro',
            'cyclos_config[emissionPartInternal]' => 'emission.Part',
        ]);
        $crawler = $this->httpClient->submit($form);
        self::assertResponseRedirects('/admin/clients/new/reglages');
        $crawler = $this->httpClient->followRedirect();

        $form = $crawler->selectButton('Créer le client')->form([
            'client_setting[mailRecipient]' => 'ops@wizard-e2e.example',
        ]);
        $this->httpClient->submit($form);
        self::assertResponseRedirects();

        $client = $this->entityManager->getRepository(Client::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($client, 'The client should have been persisted at the end of the wizard.');
        self::assertNotNull($client->getSetting());
        self::assertSame('ops@wizard-e2e.example', $client->getSetting()->getMailRecipient());
    }
}

<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\HelloAssoConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<HelloAssoConfig>
 */
class HelloAssoConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HelloAssoConfig::class);
    }

    /**
     * Resolves which of a client's (possibly several) HelloAsso forms a
     * webhook or fetch result belongs to. Ignores disabled configs, so a
     * deactivated form's webhook is rejected the same way an unknown one is.
     */
    public function findOneActiveByClientAndFormSlug(Client $client, string $formSlug): ?HelloAssoConfig
    {
        return $this->findOneBy([
            'client' => $client,
            'formSlug' => $formSlug,
            'active' => true,
        ]);
    }
}

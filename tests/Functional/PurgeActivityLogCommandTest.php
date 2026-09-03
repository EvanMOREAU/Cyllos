<?php

namespace App\Tests\Functional;

use App\Entity\ActivityLog;
use App\Repository\ActivityLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:activity-log:purge keeps the table bounded: API call traces expire on the
 * short retention, audit lines only on the long one, recent rows stay.
 */
class PurgeActivityLogCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ActivityLogRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(ActivityLogRepository::class);
        $this->entityManager->createQuery('DELETE FROM App\Entity\ActivityLog')->execute();
    }

    private function persistLogAged(string $action, string $ageModifier): int
    {
        $log = (new ActivityLog())->setAction($action)->setSummary('t');
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE activity_log SET created_at = :d WHERE id = :id',
            ['d' => (new \DateTimeImmutable($ageModifier))->format('Y-m-d H:i:s'), 'id' => $log->getId()],
        );

        return $log->getId();
    }

    public function testPurgesApiTracesFastAndAuditLinesSlowly(): void
    {
        $oldApi = $this->persistLogAged('api.helloasso', '-30 days');      // > 14d  -> deleted
        $recentApi = $this->persistLogAged('api.cyclos', '-2 days');        // < 14d  -> kept
        $midAudit = $this->persistLogAged('client.update', '-30 days');     // > 14d but not api.* and < 365d -> kept
        $oldAudit = $this->persistLogAged('user.login', '-400 days');       // > 365d -> deleted

        $command = (new Application(self::$kernel))->find('app:activity-log:purge');
        $exitCode = (new CommandTester($command))->execute([]);

        self::assertSame(0, $exitCode);

        $remaining = array_map(static fn (ActivityLog $l) => $l->getId(), $this->repository->findRecent(50));
        sort($remaining);
        $expected = [$recentApi, $midAudit];
        sort($expected);

        self::assertSame($expected, $remaining);
    }
}

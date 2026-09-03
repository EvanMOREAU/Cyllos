<?php

namespace App\Tests\Unit\Deployment;

use App\Deployment\DeploymentRunner;
use PHPUnit\Framework\TestCase;

/**
 * A second deploy must not run git pull / composer / migrations on top of one
 * already in progress: DeploymentRunner takes an exclusive non-blocking lock on
 * var/deploy.lock and bails out with locked=true when it can't get it.
 */
class DeploymentRunnerLockTest extends TestCase
{
    private string $projectDir;

    /** @var resource */
    private $heldLock;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/cyllos-deploy-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var', 0777, true);
        $this->heldLock = fopen($this->projectDir . '/var/deploy.lock', 'c');
        flock($this->heldLock, LOCK_EX);
    }

    protected function tearDown(): void
    {
        flock($this->heldLock, LOCK_UN);
        fclose($this->heldLock);
        @unlink($this->projectDir . '/var/deploy.lock');
        @rmdir($this->projectDir . '/var');
        @rmdir($this->projectDir);
    }

    public function testRunBailsOutWhenTheLockIsAlreadyHeld(): void
    {
        $result = (new DeploymentRunner($this->projectDir))->run();

        self::assertTrue($result['locked']);
        self::assertFalse($result['success']);
        self::assertSame([], $result['steps']);
    }
}

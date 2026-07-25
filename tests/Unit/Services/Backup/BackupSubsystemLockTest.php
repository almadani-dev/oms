<?php

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\BackupSubsystemLock;
use App\Services\Backup\LockMode;
use Tests\TestCase;

/**
 * OMS Task 7C.2 — real flock() contention tests against real temp files
 * (never faked/mocked; the whole point of this class is proving genuine OS
 * lock behavior). Each test uses a unique path so tests can never interfere
 * with each other.
 */
class BackupSubsystemLockTest extends TestCase
{
    private function lockPath(): string
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oms-subsystem-lock-test-'.bin2hex(random_bytes(8));

        return $dir.DIRECTORY_SEPARATOR.'subsystem.lock';
    }

    public function test_multiple_shared_holders_can_coexist(): void
    {
        $path = $this->lockPath();

        $handleA = (new BackupSubsystemLock($path))->acquireShared();
        $handleB = (new BackupSubsystemLock($path))->acquireShared();

        try {
            $this->assertNotNull($handleA);
            $this->assertNotNull($handleB);
        } finally {
            $handleA?->release();
            $handleB?->release();
        }
    }

    public function test_exclusive_fails_while_shared_is_held(): void
    {
        $path = $this->lockPath();
        $sharedHandle = (new BackupSubsystemLock($path))->acquireShared();
        $this->assertNotNull($sharedHandle);

        try {
            $this->assertNull((new BackupSubsystemLock($path))->acquireExclusive());
        } finally {
            $sharedHandle->release();
        }
    }

    public function test_shared_fails_while_exclusive_is_held(): void
    {
        $path = $this->lockPath();
        $exclusiveHandle = (new BackupSubsystemLock($path))->acquireExclusive();
        $this->assertNotNull($exclusiveHandle);

        try {
            $this->assertNull((new BackupSubsystemLock($path))->acquireShared());
        } finally {
            $exclusiveHandle->release();
        }
    }

    public function test_exclusive_fails_while_exclusive_is_held(): void
    {
        $path = $this->lockPath();
        $first = (new BackupSubsystemLock($path))->acquireExclusive();
        $this->assertNotNull($first);

        try {
            $this->assertNull((new BackupSubsystemLock($path))->acquireExclusive());
        } finally {
            $first->release();
        }
    }

    public function test_releasing_a_holder_frees_the_lock_for_a_new_acquisition(): void
    {
        $path = $this->lockPath();
        $handle = (new BackupSubsystemLock($path))->acquireExclusive();
        $this->assertNotNull($handle);

        $handle->release();

        $second = (new BackupSubsystemLock($path))->acquireExclusive();
        $this->assertNotNull($second, 'A released lock must be immediately acquirable again.');
        $second->release();
    }

    /**
     * Simulates a killed/crashed holder: closing the file handle out from
     * under the lock (bypassing BackupSubsystemLockHandle::release()
     * entirely, exactly as an abruptly-terminated process would) must
     * still let the OS reclaim the lock — proving the safety property the
     * whole design depends on: a crashed restore process's exclusive lock
     * does not survive it.
     */
    public function test_a_killed_holders_os_lock_is_released_by_the_operating_system(): void
    {
        $path = $this->lockPath();
        $handle = (new BackupSubsystemLock($path))->acquireExclusive();
        $this->assertNotNull($handle);

        $reflection = new \ReflectionProperty($handle, 'resource');
        $reflection->setAccessible(true);
        $resource = $reflection->getValue($handle);
        fclose($resource);

        $second = (new BackupSubsystemLock($path))->acquireExclusive();
        $this->assertNotNull($second, 'Closing the holder\'s file descriptor must release the OS lock, exactly like a crashed process dying.');
        $second->release();
    }

    public function test_released_handle_is_no_longer_live(): void
    {
        $path = $this->lockPath();
        $handle = (new BackupSubsystemLock($path))->acquireExclusive();
        $this->assertNotNull($handle);
        $this->assertTrue($handle->isLive());

        $handle->release();

        $this->assertFalse($handle->isLive());
    }

    public function test_release_is_idempotent(): void
    {
        $path = $this->lockPath();
        $handle = (new BackupSubsystemLock($path))->acquireExclusive();
        $this->assertNotNull($handle);

        $handle->release();
        $handle->release();

        $this->assertFalse($handle->isLive());
    }

    public function test_validate_handle_rejects_wrong_mode(): void
    {
        $path = $this->lockPath();
        $lock = new BackupSubsystemLock($path);
        $handle = $lock->acquireShared();
        $this->assertNotNull($handle);

        try {
            $this->assertFalse($lock->validateHandle($handle, LockMode::Exclusive));
        } finally {
            $handle->release();
        }
    }

    public function test_validate_handle_rejects_dead_handle(): void
    {
        $path = $this->lockPath();
        $lock = new BackupSubsystemLock($path);
        $handle = $lock->acquireExclusive();
        $this->assertNotNull($handle);
        $handle->release();

        $this->assertFalse($lock->validateHandle($handle, LockMode::Exclusive));
    }

    public function test_validate_handle_rejects_a_handle_from_a_different_lock_path(): void
    {
        $lockA = new BackupSubsystemLock($this->lockPath());
        $lockB = new BackupSubsystemLock($this->lockPath());

        $handleFromA = $lockA->acquireExclusive();
        $this->assertNotNull($handleFromA);

        try {
            $this->assertFalse(
                $lockB->validateHandle($handleFromA, LockMode::Exclusive),
                'A handle issued for a different lock file must never validate against this one.',
            );
        } finally {
            $handleFromA->release();
        }
    }

    public function test_validate_handle_accepts_a_live_matching_exclusive_handle(): void
    {
        $path = $this->lockPath();
        $lock = new BackupSubsystemLock($path);
        $handle = $lock->acquireExclusive();
        $this->assertNotNull($handle);

        try {
            $this->assertTrue($lock->validateHandle($handle, LockMode::Exclusive));
        } finally {
            $handle->release();
        }
    }

    public function test_lock_file_is_created_on_first_acquisition(): void
    {
        $path = $this->lockPath();
        $this->assertFileDoesNotExist($path);

        $handle = (new BackupSubsystemLock($path))->acquireExclusive();

        try {
            $this->assertFileExists($path);
        } finally {
            $handle->release();
        }
    }
}

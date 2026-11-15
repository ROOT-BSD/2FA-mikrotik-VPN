<?php

declare(strict_types=1);

namespace VpnGw\Tests\Admin;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use VpnGw\Admin\AdminAuth;
use VpnGw\Admin\Jobs;
use VpnGw\Admin\LogReader;
use VpnGw\Log\AuditLogger;
use VpnGw\Storage\Database;
use VpnGw\Tests\Support\FrozenClock;
use VpnGw\Tests\Support\TempDir;

final class AdminUnitsTest extends TestCase
{
    public function testPasswordPolicy(): void
    {
        self::assertNotNull(AdminAuth::passwordProblem('short', 'admin'));
        self::assertNotNull(AdminAuth::passwordProblem('my-admin-password', 'admin'));
        self::assertNotNull(AdminAuth::passwordProblem('aaaaaaaaaaaaaaaa', 'admin'));
        self::assertNull(AdminAuth::passwordProblem('Kyiv-Pass-2026!x', 'admin'));
        self::assertNull(AdminAuth::passwordProblem(AdminAuth::generatePassword(), 'admin'));
        self::assertSame(20, strlen(AdminAuth::generatePassword()));
    }

    public function testVerifyAndHash(): void
    {
        $auth = new AdminAuth(Database::inMemory(), new FrozenClock());
        $auth->setPassword('admin', 'Kyiv-Pass-2026!x');
        self::assertTrue($auth->verify('admin', 'Kyiv-Pass-2026!x'));
        self::assertFalse($auth->verify('admin', 'Kyiv-Pass-2026!X'));
        self::assertFalse($auth->verify('ghost', 'Kyiv-Pass-2026!x'));
        self::assertFalse($auth->verify('Admin; DROP', 'x'));
        self::assertTrue($auth->exists('admin'));
        self::assertStringStartsWith('$argon2id$', AdminAuth::hash('x'));
        self::assertFalse(AdminAuth::isValidUsername('a'));
        self::assertFalse(AdminAuth::isValidUsername('Admin'));
        self::assertTrue(AdminAuth::isValidUsername('admin.petrenko'));
    }

    public function testAuditCategoriesFilter(): void
    {
        $handler = new TestHandler();
        $logger = new AuditLogger(new Logger('t', [$handler]), [], ['access', 'admin', 'system']);
        $logger->event('login_ok', 'u', '1.2.3.4');
        $logger->event('mt_add', 'u', '1.2.3.4');
        $logger->event('admin_settings_saved', 'admin', '10.0.0.1', ['keys' => 'MT_LIST', 'new_password' => 'x']);
        self::assertCount(2, $handler->getRecords());
        $ctx = $handler->getRecords()[1]->context;
        self::assertSame('admin', $ctx['category']);
        self::assertArrayNotHasKey('new_password', $ctx);
    }

    public function testLogReaderParsesMonologJson(): void
    {
        $line = '{"message":"mt_error","context":{"event":"mt_error","category":"system","app_version":"0.2","router":"core","ok":false},"level":300,"level_name":"WARNING","channel":"vpn-gw","datetime":"2026-11-15T08:00:00.123456+02:00","extra":{}}';
        $e = LogReader::parse($line);
        self::assertNotNull($e);
        self::assertSame('warning', $e['level']);
        self::assertSame('mt_error', $e['event']);
        self::assertSame('router=core ok=false', $e['details']);
        self::assertNull(LogReader::parse('garbage'));
        self::assertSame([], (new LogReader('/nonexistent/audit.log'))->tail());
    }

    public function testJobsListMergesQueueAndStatus(): void
    {
        $dir = TempDir::make();
        try {
            foreach (['spool', 'status', 'backups'] as $d) {
                mkdir("$dir/$d");
            }
            $jobs = new Jobs("$dir/spool", "$dir/status", "$dir/backups");
            $now = new \DateTimeImmutable('2026-11-15 10:00:00');
            $a = Jobs::newId($now);
            $jobs->enqueue($a, 'backup', 'admin', $now);
            self::assertTrue($jobs->busy('backup'));
            self::assertFalse($jobs->busy('update'));
            self::assertSame('queued', $jobs->get($a)['state'] ?? null);

            file_put_contents("$dir/status/$a.json", json_encode(['id' => $a, 'type' => 'backup', 'state' => 'ok', 'by' => 'admin', 'created' => '2026-11-15 10:00:00']));
            unlink("$dir/spool/$a.job");
            self::assertSame('ok', $jobs->get($a)['state'] ?? null);
            self::assertFalse($jobs->busy('backup'));

            file_put_contents("$dir/status/20261115-110000-deadbeef.json", '{"id":"../../x","type":"backup"}');
            self::assertCount(1, $jobs->list());
            self::assertNull($jobs->get('../etc/passwd'));
            self::assertSame('', $jobs->log('../x'));

            $this->expectException(\InvalidArgumentException::class);
            $jobs->enqueue($a, 'shell', 'admin', $now);
        } finally {
            TempDir::remove($dir);
        }
    }
}

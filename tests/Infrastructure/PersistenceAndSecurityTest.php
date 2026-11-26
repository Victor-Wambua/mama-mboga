<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Infrastructure;

use MamaMboga\Infrastructure\Persistence\Database;
use MamaMboga\Infrastructure\Persistence\Migrator;
use MamaMboga\Infrastructure\Security\Session;
use MamaMboga\Infrastructure\Security\SessionStore;
use MamaMboga\Infrastructure\Security\UrlSigner;
use MamaMboga\Infrastructure\View\View;
use MamaMboga\Tests\Support\TestApp;
use PHPUnit\Framework\TestCase;

final class PersistenceAndSecurityTest extends TestCase
{
    public function testMigratorAppliesEachFileOnceAndIsIdempotent(): void
    {
        $db = Database::connect(':memory:');
        $migrator = new Migrator($db, dirname(__DIR__, 2) . '/migrations');

        $first = $migrator->migrate();
        $second = $migrator->migrate();

        self::assertCount(4, $first);
        self::assertSame([], $second);
        self::assertSame(4, $db->scalar('SELECT COUNT(*) FROM schema_migrations'));
    }

    public function testBrokenMigrationRollsBackAndNamesTheFile(): void
    {
        $dir = sys_get_temp_dir() . '/mm-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/001_ok.sql', 'CREATE TABLE a (id INTEGER);');
        file_put_contents($dir . '/002_bad.sql', 'CREATE TABLE b (id INTEGER); THIS IS NOT SQL;');
        $db = Database::connect(':memory:');

        try {
            (new Migrator($db, $dir))->migrate();
            self::fail('should have thrown');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('002_bad', $e->getMessage());
        }
        self::assertSame(0, $db->scalar("SELECT COUNT(*) FROM sqlite_master WHERE name = 'b'"), 'the failed migration must not leave half its tables');
        self::assertSame(1, $db->scalar('SELECT COUNT(*) FROM schema_migrations'));
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }

    public function testLedgerRowsCannotBeUpdatedOrDeletedEvenWithRawSql(): void
    {
        $app = new TestApp();
        $customer = $app->customer('A', '0700 000 040', 1000);
        $app->db->execute("INSERT INTO ledger_entries (customer_id, entry_type, amount_kes, occurred_at) VALUES (?, 'charge', 100, '2026-03-12T07:00:00+03:00')", [$customer->id]);

        foreach (['UPDATE ledger_entries SET amount_kes = 1', 'DELETE FROM ledger_entries'] as $sql) {
            try {
                $app->db->execute($sql);
                self::fail("{$sql} should be refused");
            } catch (\PDOException $e) {
                self::assertStringContainsString('append-only', $e->getMessage());
            }
        }
        self::assertSame(100, $app->db->scalar('SELECT amount_kes FROM ledger_entries'));
    }

    public function testDatabaseRefusesDuplicateReceiptsAndDoubleChargedOrders(): void
    {
        $app = new TestApp();
        $customer = $app->customer('A', '0700 000 040', 1000);
        $insert = "INSERT INTO ledger_entries (customer_id, entry_type, amount_kes, method, receipt_code, occurred_at) VALUES (?, 'payment', 10, 'cash', 'R1', 'x')";
        $app->db->execute($insert, [$customer->id]);

        $this->expectException(\PDOException::class);
        $app->db->execute($insert, [$customer->id]);
    }

    public function testForeignKeysAreEnforced(): void
    {
        $app = new TestApp();

        $this->expectException(\PDOException::class);
        $app->db->execute("INSERT INTO ledger_entries (customer_id, entry_type, amount_kes, occurred_at) VALUES (999, 'charge', 1, 'x')");
    }

    public function testTransactionsRollBackOnException(): void
    {
        $app = new TestApp();
        try {
            $app->db->run(function () use ($app): void {
                $app->db->execute("INSERT INTO settings (key, value) VALUES ('k', 'v')");
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $app->db->scalar("SELECT COUNT(*) FROM settings WHERE key = 'k'"));
    }

    public function testNestedTransactionsJoinTheOuterOne(): void
    {
        $app = new TestApp();
        try {
            $app->db->run(function () use ($app): void {
                $app->db->run(fn () => $app->db->execute("INSERT INTO settings (key, value) VALUES ('inner', '1')"));
                throw new \RuntimeException('outer fails');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $app->db->scalar("SELECT COUNT(*) FROM settings WHERE key = 'inner'"));
    }

    public function testSessionStoreRoundTripAndExpiry(): void
    {
        $app = new TestApp();
        $store = $app->container->get(SessionStore::class);
        $session = new Session(Session::newId());
        $session->put('owner', true);
        $store->save($session, 2000);

        self::assertTrue($store->load($session->id(), 1000)?->get('owner'));
        self::assertNull($store->load($session->id(), 2001), 'expired sessions do not load');
        self::assertNull($store->load(str_repeat('0', 64), 1000));
    }

    public function testSessionRegenerateChangesTheIdAndKeepsData(): void
    {
        $session = new Session('old', ['a' => 1]);
        $new = $session->regenerate();

        self::assertNotSame('old', $new);
        self::assertSame(64, strlen($new));
        self::assertSame(1, $session->get('a'));
        self::assertTrue($session->isDirty());
    }

    public function testFlashMessagesAreShownOnce(): void
    {
        $session = new Session('s');
        $session->flash('ok', 'Saved');

        self::assertSame([['type' => 'ok', 'message' => 'Saved']], $session->pullFlash());
        self::assertSame([], $session->pullFlash());
    }

    public function testSessionGarbageCollection(): void
    {
        $app = new TestApp();
        $store = $app->container->get(SessionStore::class);
        $store->save(new Session(str_repeat('a', 64), ['x' => 1]), 100);
        $store->save(new Session(str_repeat('b', 64), ['x' => 1]), 9999);
        $store->collectGarbage(500);

        self::assertSame(1, $app->db->scalar('SELECT COUNT(*) FROM sessions'));
    }

    public function testUrlSignerVerifiesOnlyTheExactPayload(): void
    {
        $signer = new UrlSigner(str_repeat('k', 32));
        $sig = $signer->sign('statement:1:100');

        self::assertTrue($signer->verify('statement:1:100', $sig));
        self::assertFalse($signer->verify('statement:2:100', $sig));
        self::assertFalse($signer->verify('statement:1:100', $sig . 'x'));
        self::assertFalse($signer->verify('statement:1:100', ''));
        self::assertFalse((new UrlSigner(str_repeat('z', 32)))->verify('statement:1:100', $sig), 'a different key must not verify');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $sig);
    }

    public function testViewEscapesAndRejectsObjects(): void
    {
        self::assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &#039;q&#039;', str_replace('&apos;', '&#039;', View::escape('<script>alert("x")</script> & \'q\'')));
        self::assertSame('', View::escape(null));
        self::assertSame('5', View::escape(5));

        $this->expectException(\RuntimeException::class);
        View::escape(new \stdClass());
    }

    public function testEveryTemplateEchoGoesThroughAnEscapingHelper(): void
    {
        $bad = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                preg_match_all('/<\?=\s*(.{0,12})/', $line, $m);
                foreach ($m[1] as $start) {
                    $ok = false;
                    foreach (View::SAFE_ECHO as $helper) {
                        $ok = $ok || str_starts_with($start, $helper);
                    }
                    if (!$ok) {
                        $bad[] = basename($file->getPathname()) . ':' . ($n + 1) . ' ' . $start;
                    }
                }
            }
        }

        self::assertSame([], $bad, "Unescaped template output:\n" . implode("\n", $bad));
    }

    public function testNoInlineScriptOrStyleInTemplatesSoTheCspCanBeStrict(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/templates'));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)/i', $src, $file->getFilename());
            self::assertDoesNotMatchRegularExpression('/\sstyle\s*=|<style/i', $src, $file->getFilename());
            self::assertDoesNotMatchRegularExpression('/\son(click|submit|change|load)\s*=/i', $src, $file->getFilename());
        }
    }
}

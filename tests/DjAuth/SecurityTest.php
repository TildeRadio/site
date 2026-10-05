<?php

declare(strict_types=1);

namespace TildeRadio\Site\DjAuth\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TildeRadio\Site\DjAuth\Config;
use TildeRadio\Site\DjAuth\Csrf;
use TildeRadio\Site\DjAuth\Failure;
use TildeRadio\Site\DjAuth\RateLimiter;
use TildeRadio\Site\DjAuth\Service;
use TildeRadio\Site\DjAuth\Session;
use TildeRadio\Site\DjAuth\Transport;

final class SecurityTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/dj-unit-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/sessions', 0700);
        file_put_contents($this->directory . '/rate-key', bin2hex(random_bytes(32)));
        file_put_contents($this->directory . '/client.php', '<?php');
        file_put_contents($this->directory . '/client.json', '{}');
        $_SESSION = ['csrf' => Csrf::token()];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/sessions/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory . '/sessions');
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        $_SESSION = [];
    }

    private function config(array $extra = []): Config
    {
        return new Config(array_replace([
            'origin' => 'https://tilderadio.org', 'state_dir' => $this->directory,
            'client_bootstrap' => $this->directory . '/client.php',
            'client_config' => $this->directory . '/client.json',
            'administrators' => ['1:163'], 'accounts' => ['1:163' => 'deepend'],
        ], $extra));
    }

    public function testAdministratorRequiresStationAndVerifiedAccountId(): void
    {
        $config = $this->config();
        self::assertTrue($config->isAdministrator(1, 163));
        self::assertFalse($config->isAdministrator(1, 4));
        self::assertFalse($config->isAdministrator(2, 163));
        self::assertSame('deepend', $config->slug(1, 163));
        self::assertNull($config->slug(1, 4));
    }

    public function testForwardedHeadersCannotBypassHttpsOrChangeIpWithoutTrustedProxy(): void
    {
        $server = ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_REAL_IP' => '192.0.2.20'];
        self::assertFalse($this->config()->secure($server));
        self::assertSame('192.0.2.10', $this->config()->userIp($server));
        $config = $this->config(['trusted_proxy_ips' => ['192.0.2.10']]);
        self::assertTrue($config->secure($server));
        self::assertSame('192.0.2.20', $config->userIp($server));
    }

    public function testOriginMatchesBrowsersDefaultPortAndHostNormalization(): void
    {
        self::assertSame('https://tilderadio.org', $this->config(['origin' => 'https://TildeRadio.org:443/'])->origin());
        self::assertSame('https://tilderadio.org:8443', $this->config(['origin' => 'https://tilderadio.org:8443'])->origin());
    }

    public function testStateCannotBeReadableByOtherUsers(): void
    {
        chmod($this->directory, 0755);
        clearstatcache();
        $this->expectException(\RuntimeException::class);
        $this->config();
    }

    public function testThrottlePersistsAcrossRequestsAndDoesNotStoreRawIp(): void
    {
        $buckets = [['id' => '192.0.2.10', 'limit' => 2, 'seconds' => 300]];
        self::assertTrue((new RateLimiter($this->directory))->allow($buckets, 600));
        self::assertTrue((new RateLimiter($this->directory))->allow($buckets, 601));
        self::assertFalse((new RateLimiter($this->directory))->allow($buckets, 602));
        self::assertTrue((new RateLimiter($this->directory))->allow($buckets, 900));
        self::assertStringNotContainsString('192.0.2.10', file_get_contents($this->directory . '/login.sqlite'));
    }

    public function testCsrfFailureDoesNotReachAuthenticationService(): void
    {
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::never())->method('verify');
        $service = new Service($transport, new RateLimiter($this->directory), new NullLogger());
        $this->expectException(Failure::class);
        $service->login(['csrf' => 'incorrect', 'username' => 'deepend', 'password' => 'test'], '192.0.2.10', 600);
    }

    public function testPasswordIsPassedUnmodifiedAndIdentityComesFromBridge(): void
    {
        $identity = ['station_id' => 1, 'streamer_id' => 4, 'username' => 'cat', 'display_name' => 'Cat', 'verified_at' => 600];
        $transport = $this->createMock(Transport::class);
        $transport->expects(self::once())->method('verify')->with('cat', ' test ', '192.0.2.10')->willReturn($identity);
        $service = new Service($transport, new RateLimiter($this->directory), new NullLogger());
        self::assertSame($identity, $service->login([
            'csrf' => $_SESSION['csrf'], 'username' => 'cat', 'password' => ' test ', 'streamer_id' => 163,
        ], '192.0.2.10', 600));
    }

    public function testSessionExpiresAtIdleAndAbsoluteBoundaries(): void
    {
        self::assertFalse(Session::expired(['started' => 100, 'seen' => 100], 999));
        self::assertTrue(Session::expired(['started' => 100, 'seen' => 100], 1000));
        self::assertTrue(Session::expired(['started' => 100, 'seen' => 3699], 3700));
        self::assertTrue(Session::expired(['started' => 100, 'seen' => 100], 99));
    }

    public function testCsrfTokensAreIndependentAndRejectArrayInput(): void
    {
        $token = Csrf::token();
        self::assertTrue(Csrf::valid($token, $token));
        self::assertFalse(Csrf::valid($token, Csrf::token()));
        self::assertFalse(Csrf::valid($token, [$token]));
    }
}

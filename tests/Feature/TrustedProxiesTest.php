<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    private const PROXY_IP = '192.0.2.10';

    protected function setUp(): void
    {
        parent::setUp();

        // La liste des proxies de confiance est statique côté Symfony : on repart d'une liste vide.
        SymfonyRequest::setTrustedProxies([], 0);
        Route::get('/_test/client', fn () => request()->getScheme().' '.request()->ip());
    }

    private function getThroughProxy(): string
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::PROXY_IP])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.5', 'X-Forwarded-Proto' => 'https'])
            ->get('/_test/client')
            ->getContent();
    }

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        $this->assertSame('http '.self::PROXY_IP, $this->getThroughProxy());
    }

    public function test_forwarded_headers_of_a_configured_proxy_are_trusted(): void
    {
        config(['trustedproxy.proxies' => '10.0.0.1, '.self::PROXY_IP]);

        $this->assertSame('https 203.0.113.5', $this->getThroughProxy());
    }

    public function test_trusted_hosts_come_from_the_config_and_default_to_none(): void
    {
        $this->assertSame([], app(TrustHosts::class)->hosts());

        config(['app.trusted_hosts' => ['finance.example.com']]);

        $this->assertSame(['^finance\.example\.com$'], app(TrustHosts::class)->hosts());
    }
}

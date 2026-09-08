<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\Tenancy\CurrentTenant;
use App\Api\Tenancy\FixedTenant;
use App\Tests\Support\SqlRecorder;
use App\Tests\Tenancy\TenancyTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The HTTP rig for the `/api/v1` slice.
 *
 * It extends the tenancy rig rather than `WebTestCase` on purpose: the truncate,
 * the tenant-registry seed and the leaked-GUC reset are all still required, and
 * a second copy of them would be a second thing to keep in step. The browser is
 * taken from the container instead of `WebTestCase::createClient()`, and reboot
 * is disabled -- a reboot would build a new container and discard the tenant the
 * test just chose.
 */
abstract class ApiTestCase extends TenancyTestCase
{
    protected const BASE = '/api/v1';

    private ?KernelBrowser $client = null;

    protected function setUp(): void
    {
        parent::setUp();

        $client = self::getContainer()->get('test.client');
        \assert($client instanceof KernelBrowser);
        $client->disableReboot();
        $this->client = $client;

        $this->actAs(self::TENANT_A);
    }

    /**
     * T-1 / API-7: the tenant is chosen on the container, never carried by the
     * request. A test that could send a tenant would be exercising an API that
     * violates the rule.
     */
    protected function actAs(string $tenantId): void
    {
        $tenant = self::getContainer()->get(CurrentTenant::class);
        \assert($tenant instanceof FixedTenant);
        $tenant->actAs($tenantId);
    }

    protected function recorder(): SqlRecorder
    {
        $recorder = self::getContainer()->get(SqlRecorder::class);
        \assert($recorder instanceof SqlRecorder);

        return $recorder;
    }

    protected function client(): KernelBrowser
    {
        return $this->client ?? throw new \LogicException('The client is built in setUp().');
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    protected function send(string $method, string $path, ?array $body = null, array $headers = [], string $accept = 'application/json'): void
    {
        $server = ['HTTP_ACCEPT' => $accept];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        if (null !== $body) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $this->client()->request(
            $method,
            // A `Location` header comes back already carrying the base path, and
            // following it is half of what makes API-21 branch 4 checkable.
            str_starts_with($path, self::BASE) ? $path : self::BASE.$path,
            [],
            [],
            $server,
            null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR),
        );
    }

    protected function httpStatus(): int
    {
        return $this->client()->getResponse()->getStatusCode();
    }

    protected function contentType(): string
    {
        return (string) $this->client()->getResponse()->headers->get('Content-Type');
    }

    /** @return array<string, mixed> */
    protected function json(): array
    {
        $content = (string) $this->client()->getResponse()->getContent();

        $decoded = json_decode($content, true);
        self::assertIsArray($decoded, 'The response body is not a JSON object: '.substr($content, 0, 400));

        return $decoded;
    }
}

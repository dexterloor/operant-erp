<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\SalesReturnCollection;
use App\Modules\Sales\ApiResource\SalesReturnResource;
use App\Modules\Sales\Application\SalesReturnListCriteria;
use App\Modules\Sales\Application\SalesReturnReader;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `ListSalesReturns` (sales step 7 R3). `FulfilmentCollectionProvider`'s parsing.
 *
 * **The `credit_blocked_by_payment` filter from a caller without `invoice.view` is a parameter error, not a 403**
 * (design :246-248, "to measure"; measured 2026-10-01). A 403 here would be the first permission refusal of a PARAMETER
 * rather than an operation: E-1's 403 is `PermissionGate`'s, which writes the `authz.permission.denied` row and keys its
 * denial subject on the operation — a provider-thrown 403 would bypass both. The parameter error depends only on the
 * caller's codes, never on any invoice, so it discloses nothing (`RED-5`'s note), and the operation itself is served.
 */
final readonly class SalesReturnCollectionProvider implements ProviderInterface
{
    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    public function __construct(private SalesReturnReader $reader, private RequestIdentity $identities)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SalesReturnCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $page = $this->reader->list($this->criteria($query));

        return new SalesReturnCollection(
            items: array_map(SalesReturnResource::fromView(...), $page->items),
            nextCursor: $page->nextCursor,
            previousCursor: $page->previousCursor,
            tenantHasRows: $page->tenantHasRows,
        );
    }

    /** @param array<string, mixed> $query */
    private function criteria(array $query): SalesReturnListCriteria
    {
        $errors = [];

        $limit = self::DEFAULT_LIMIT;
        if (isset($query['limit'])) {
            $raw = $query['limit'];
            if (!\is_string($raw) || 1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1 || (int) $raw > self::MAXIMUM_LIMIT) {
                $errors[] = self::error('limit', 'out_of_range', \sprintf('limit must be a whole number between 1 and %d.', self::MAXIMUM_LIMIT));
            } else {
                $limit = (int) $raw;
            }
        }

        $ids = [];
        foreach (['fulfilment_id', 'invoice_id'] as $name) {
            $ids[$name] = null;
            if (isset($query[$name])) {
                $raw = $query[$name];
                if (!\is_string($raw) || !Uuid::isValid($raw)) {
                    $errors[] = self::error($name, 'not_a_uuid', $name.' must be a UUID.');
                } else {
                    $ids[$name] = Uuid::fromString($raw)->toRfc4122();
                }
            }
        }

        $flags = [];
        foreach (['creditable', 'deducts', 'credit_blocked_by_payment'] as $name) {
            $flags[$name] = null;
            if (isset($query[$name])) {
                $raw = $query[$name];
                if ('true' !== $raw && 'false' !== $raw) {
                    $errors[] = self::error($name, 'not_a_boolean', $name.' must be true or false.');
                } else {
                    $flags[$name] = 'true' === $raw;
                }
            }
        }
        if (isset($query['credit_blocked_by_payment']) && !$this->identities->authenticated()->holds('invoice.view')) {
            // M4-1: the filter names payment as the cause; it is an invoice.view holder's alone.
            $errors[] = self::error('credit_blocked_by_payment', 'not_permitted',
                'This filter needs the permission to read invoices (invoice.view).');
        }

        $search = null;
        if (isset($query['q'])) {
            $raw = $query['q'];
            if (!\is_string($raw)) {
                $errors[] = self::error('q', 'not_a_string', 'q must be a string.');
            } elseif ('' !== $raw) {
                $search = $raw;
            }
        }

        if (isset($query['after'], $query['before'])) {
            $errors[] = self::error('after', 'mutually_exclusive', 'Send at most one of after and before.');
        }
        $after = self::cursor($query, 'after', $errors);
        $before = self::cursor($query, 'before', $errors);

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return new SalesReturnListCriteria(
            limit: $limit,
            fulfilmentId: $ids['fulfilment_id'],
            invoiceId: $ids['invoice_id'],
            creditable: $flags['creditable'],
            deducts: $flags['deducts'],
            creditBlockedByPayment: $flags['credit_blocked_by_payment'],
            search: $search,
            after: $after,
            before: $before,
        );
    }

    /**
     * @param array<string, mixed>                                           $query
     * @param list<array{pointer: string, code: string, message: string}>    $errors
     */
    private static function cursor(array $query, string $name, array &$errors): ?Cursor
    {
        if (!isset($query[$name]) || '' === $query[$name]) {
            return null;
        }

        try {
            $cursor = Cursor::decode((string) $query[$name]);
        } catch (InvalidCursor) {
            $cursor = null;
        }

        if (null === $cursor || 1 !== preg_match('/^RET-[0-9]{6}$/', $cursor->sortKey)) {
            $errors[] = self::error($name, 'malformed_cursor', \sprintf('%s must be a cursor returned by a previous response for this view.', $name));

            return null;
        }

        return $cursor;
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $parameter, string $code, string $message): array
    {
        return ['pointer' => '?'.$parameter, 'code' => $code, 'message' => $message];
    }
}

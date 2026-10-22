<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\CreditNoteCollection;
use App\Modules\Sales\ApiResource\CreditNoteResource;
use App\Modules\Sales\Application\CreditNoteListCriteria;
use App\Modules\Sales\Application\CreditNoteReader;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;
use App\Platform\Http\Problem\ApiProblem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** `ListCreditNotes` (sales step 7 C2). `SalesReturnCollectionProvider`'s parsing. */
final readonly class CreditNoteCollectionProvider implements ProviderInterface
{
    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    public function __construct(private CreditNoteReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CreditNoteCollection
    {
        $request = $context['request'] ?? null;
        $query = $request instanceof Request ? $request->query->all() : [];

        $page = $this->reader->list(self::criteriaFrom($query));

        return new CreditNoteCollection(
            items: array_map(CreditNoteResource::fromView(...), $page->items),
            nextCursor: $page->nextCursor,
            previousCursor: $page->previousCursor,
            tenantHasRows: $page->tenantHasRows,
        );
    }

    /**
     * **The list's whole pre-read parse, shared with `CreateExport`** (G6-C1, design Response M-5): the provider and {@see CreditNoteExportSource} both call this, so an
     * export's filters are validated, combined and refused exactly as the list's are — by construction, not by a second copy
     * (TB-7). `$limit` is the export's cap, an explicit argument the wire never reaches (`limit` is not an export filter;
     * API-14a): when given it replaces the parsed page size, unbounded by `MAXIMUM_LIMIT`, and the reader's `LIMIT limit + 1`
     * reports whether the view is over it.
     *
     * @param array<string, mixed> $query
     */
    public static function criteriaFrom(array $query, ?int $limit = null): CreditNoteListCriteria
    {
        $errors = [];

        $limit ??= self::DEFAULT_LIMIT;
        if (isset($query['limit'])) {
            $raw = $query['limit'];
            if (!\is_string($raw) || 1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1 || (int) $raw > self::MAXIMUM_LIMIT) {
                $errors[] = self::error('limit', 'out_of_range', \sprintf('limit must be a whole number between 1 and %d.', self::MAXIMUM_LIMIT));
            } else {
                $limit = (int) $raw;
            }
        }

        $ids = [];
        foreach (['invoice_id', 'customer_id', 'sales_return_id'] as $name) {
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

        return new CreditNoteListCriteria(
            limit: $limit,
            invoiceId: $ids['invoice_id'],
            customerId: $ids['customer_id'],
            salesReturnId: $ids['sales_return_id'],
            search: $search,
            after: $after,
            before: $before,
        );
    }

    /**
     * @param array<string, mixed>                                        $query
     * @param list<array{pointer: string, code: string, message: string}> $errors
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

        if (null === $cursor || 1 !== preg_match('/^CN-[0-9]{6}$/', $cursor->sortKey)) {
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

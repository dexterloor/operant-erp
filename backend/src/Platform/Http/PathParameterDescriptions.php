<?php

declare(strict_types=1);

namespace App\Platform\Http;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Operations;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;

/**
 * Every record id in a path says, in the published spec, whose id it is: "The sales order's id.", "The user's id.".
 *
 * ---------------------------------------------------------------------------
 * The rule
 * ---------------------------------------------------------------------------
 * The record a path variable addresses is named by the URL: the literal segment immediately before `/{variable}` is the
 * plural name of that record (`/users/{id}/roles` → a user; `/sales-orders/{id}/pick-list` → a sales order;
 * `/sales-orders/{id}/lines/{lineId}` → a line). This decorator gives each uri variable of each HTTP operation of each
 * resource under `App\Modules\` the description `The <noun>'s id.`, where `<noun>` is that segment, kebab case read as
 * words, its last word made singular -- unless `self::WORD_FOR_SEGMENT` gives the word for the segment.
 *
 * Singular rule (last word only): `ies` → `y`; `ss|x|z|ch|sh` + `es` → drop `es`; else a final `s` → drop it; else no
 * description is set, the vendor's text is served, and `OpenApiContractTest::everyPathParameterSaysWhoseIdItIs` is red.
 * Known residue of the singular rule, none served: `statuses`→statuse, `sizes`→siz, `prizes`→priz, `caches`→cach,
 * `niches`→nich. T-1's tail check catches each. The remedy is a `WORD_FOR_SEGMENT` entry (with T-1's
 * `RECORD_WORDS_BY_UX` twin), not a rule change.
 *
 * ---------------------------------------------------------------------------
 * Why the URL, and not the Link's `fromClass`
 * ---------------------------------------------------------------------------
 * Without this, API Platform falls back to `"<name> identifier"` (`OpenApiFactory.php:290-299`), where `<name>` is the
 * PHP short name of the Link's `fromClass` (`CustomerResource identifier`) or the operation's own resource. On five
 * operations that names the wrong record: `AssignRole`, `RevokeRole`, `CreateRoleGrantProposal` and `ListOfferedRoles`
 * declare `fromClass` as their own resource although their `{id}` is a user's, and `GetPickList`'s `{id}` is the sales
 * order's. Their `fromClass` is right for what API Platform does with it (identifiers, IRIs), so it is not changed here;
 * the URL names the record on every one of them.
 *
 * ---------------------------------------------------------------------------
 * Why nothing the server does can change
 * ---------------------------------------------------------------------------
 * The only reader of a Link's description is `OpenApiFactory.php:290`, which writes it into the spec. No response,
 * refusal, route or IRI reads it. This class sets the description only: schema, constraints and default are untouched,
 * so it commutes with `UuidPathParameters`.
 *
 * ---------------------------------------------------------------------------
 * Exceptions
 * ---------------------------------------------------------------------------
 * `WORD_FOR_SEGMENT` holds UX's word for a URL segment whose plural name is not the word people use; it is
 * pinned by `PathParameterDescriptionsTest` to `OpenApiContractTest::RECORD_WORDS_BY_UX`, so the two cannot drift. A Link
 * that declares its own `description:` is left untouched; that is the whole-sentence exception, and it must be listed,
 * with its reason, in `OpenApiContractTest::PATH_PARAMETER_DESCRIPTIONS_BY_HAND` (the test fails both ways otherwise).
 * A template in which no literal segment precedes the variable is left alone, never refused: a metadata factory that
 * throws takes down every request, so the test is where it fails.
 */
final readonly class PathParameterDescriptions implements ResourceMetadataCollectionFactoryInterface
{
    /**
     * `'url segment' => 'word'`: UX's word for a record whose URL segment says something else. `{productId}` under
     * `/suppliers/{id}/products/` is a supplier catalogue entry, not an item, and the shipped copy calls it that (BK-30).
     * UX — TO CONFIRM; fallback `'products' => 'supplier product'`.
     */
    public const WORD_FOR_SEGMENT = ['products' => 'catalogue entry'];

    private const SCOPE = 'App\\Modules\\';

    public function __construct(private ResourceMetadataCollectionFactoryInterface $inner)
    {
    }

    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $collection = $this->inner->create($resourceClass);
        if (!str_starts_with($resourceClass, self::SCOPE)) {
            return $collection;
        }

        foreach ($collection as $i => $resource) {
            if (null === $resource->getOperations()) {
                continue;
            }
            // Never mutate the inner Operations in place: build a new one, as UuidPathParameters does.
            $operations = new Operations();
            foreach ($resource->getOperations() as $name => $operation) {
                $uriVariables = $operation instanceof HttpOperation ? $operation->getUriVariables() : null;
                if (null !== $uriVariables && [] !== $uriVariables) {
                    $template = $operation->getUriTemplate() ?? '';
                    $described = [];
                    foreach ($uriVariables as $key => $link) {
                        $described[$key] = $link instanceof Link && null === $link->getDescription()
                            ? self::described($link, (string) $key, $template)
                            : $link;
                    }
                    $operation = $operation->withUriVariables($described);
                }
                $operations->add($name, $operation);
            }
            $collection[$i] = $resource->withOperations($operations);
        }

        return $collection;
    }

    /** The noun for a URL segment: UX's word if listed, else the segment as words with its last word singular; or null. */
    public static function noun(string $segment): ?string
    {
        if (\array_key_exists($segment, self::WORD_FOR_SEGMENT)) {
            return self::WORD_FOR_SEGMENT[$segment];
        }
        $words = explode('-', $segment);
        $last = (string) array_pop($words);
        if (str_ends_with($last, 'ies')) {
            $singular = substr($last, 0, -3).'y';
        } elseif (1 === preg_match('/(ss|x|z|ch|sh)es$/', $last)) {
            $singular = substr($last, 0, -2);
        } elseif (str_ends_with($last, 's')) {
            $singular = substr($last, 0, -1);
        } else {
            return null;
        }
        $words[] = $singular;

        return implode(' ', $words);
    }

    public static function sentence(string $noun): string
    {
        return "The {$noun}'s id.";
    }

    private static function described(Link $link, string $key, string $template): Link
    {
        $name = $link->getParameterName() ?? $key;
        if (1 !== preg_match('#/([a-z][a-z0-9-]*)/\{'.preg_quote($name, '#').'\}#', $template, $m)) {
            return $link;
        }
        $noun = self::noun($m[1]);

        return null === $noun ? $link : $link->withDescription(self::sentence($noun));
    }
}

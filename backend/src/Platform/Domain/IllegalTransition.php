<?php

declare(strict_types=1);

namespace App\Platform\Domain;

use Doctrine\ORM\Mapping\Table;

/**
 * API-15's refusal — a document was asked to make a transition it may not make.
 * Almost always because its current status does not allow it (`of()`,
 * `because()`); since step 6a, also because the status allows it and a fact
 * about the document's content does not (`unmetPrecondition()` — `place()` on a
 * purchase order with no live line).
 *
 * ---------------------------------------------------------------------------
 * Why this is a `\DomainException` and not an `ApiProblem`
 * ---------------------------------------------------------------------------
 * `docs/API.md` §8.2 puts the guard on the **entity**, and §8.1 step 4 runs it
 * inside an approval sequence whose other steps are permission checks and row
 * loads. An entity that threw `ApiProblem` would make an entity depend on
 * `App\Api`, and would make a status rule unusable from anything that is not an
 * HTTP request — the reorder agent that raises a purchase order, a console
 * command, a Messenger handler. The refusal is a fact about the document; the
 * mapping onto §5.2's `business-rule-violated` row is a fact about the API, and
 * it lives in `FrameworkProblemTranslator` where every other such mapping lives.
 *
 * ---------------------------------------------------------------------------
 * The `rule` member, derived rather than written down
 * ---------------------------------------------------------------------------
 * §5.2 gives `business-rule-violated` a `rule` extension member, and the two
 * rules already in the tree are `inventory_item.sku_is_unique_per_workspace` and
 * `inventory_item.is_archived` — `<table>.<rule>`. The table half is read off the
 * entity's own `#[ORM\Table]` attribute rather than restated here, so a table
 * rename cannot leave the wire contract naming a table that no longer exists.
 */
final class IllegalTransition extends \DomainException
{
    /**
     * One rule code for every **status** refusal, on every status machine — what
     * `of()` and `because()` produce. The *which* transition is refused is in
     * `detail`/`message`; a rule code per method would put the method name in a
     * client-visible contract for no gain, and §5.2's `rule` is the rule, not the
     * occurrence.
     *
     * It is **not** the only code this class carries. `unmetPrecondition()`
     * refuses from a status the matrix allows and names its own rule
     * (`purchase_order.has_a_live_line`), because the source status is right and
     * the caller's remedy is different. So "the refusal is a status refusal" is
     * `rule()` ending in this constant, not merely `instanceof IllegalTransition`.
     */
    public const RULE = 'status_transition_is_legal';

    /** @param list<string> $legalFrom the source statuses the matrix allows, empty when the refusal is not a source-state one */
    private function __construct(
        private readonly string $entity,
        private readonly string $table,
        private readonly string $method,
        private readonly string $from,
        private readonly array $legalFrom,
        string $message,
        private readonly string $clientMessage,
        private readonly string $ruleName = self::RULE,
    ) {
        parent::__construct($message);
    }

    /**
     * **A refusal from a status the matrix DOES allow**, because a fact about the
     * document's content does not hold — step 6a's `place()` on an order with no
     * live lines (product-owner ruling, 2026-09-11).
     *
     * It carries its own rule code, and that does not contradict `RULE`'s "one
     * code for every status machine": `RULE` is the code for *the source status
     * is wrong*, and here it is right. The caller's remedy differs — change the
     * document, not wait for its status — so the code the client branches on has
     * to differ too. The table half is still derived, never written down.
     *
     * @param class-string $entity
     * @param string       $rule          the rule half only, e.g. `has_a_live_line`
     * @param string       $clientMessage names no class, method or document — it reaches the wire
     */
    public static function unmetPrecondition(string $entity, string $method, string $from, string $rule, string $clientMessage): self
    {
        return new self(
            $entity,
            self::tableOf($entity),
            $method,
            $from,
            [],
            \sprintf('%s::%s() was refused from "%s": %s', self::shortNameOf($entity), $method, $from, $clientMessage),
            $clientMessage,
            $rule,
        );
    }

    /**
     * The source-state refusal: `docs/API.md` §8.3's matrix does not list
     * `$from` as a legal source for `$method`.
     *
     * @param class-string           $entity
     * @param non-empty-list<string> $legalFrom
     */
    public static function of(string $entity, string $method, string $from, array $legalFrom): self
    {
        $table = self::tableOf($entity);

        return new self(
            $entity,
            $table,
            $method,
            $from,
            array_values($legalFrom),
            \sprintf(
                '%s::%s() is legal only from %s, and this %s is %s.',
                self::shortNameOf($entity),
                $method,
                self::quoted($legalFrom),
                $table,
                self::quoted([$from]),
            ),
            \sprintf(
                'This %s is %s, and that change is legal only from %s.',
                $table,
                self::quoted([$from]),
                self::quoted($legalFrom),
            ),
        );
    }

    /**
     * A refusal that is not about the source state — the target value is not one
     * the entity declares, or the row is one no transition may touch. `Tenant`
     * needs both (API-O-16); nothing else does yet.
     *
     * @param class-string $entity
     */
    public static function because(string $entity, string $method, string $from, string $reason): self
    {
        $table = self::tableOf($entity);

        return new self(
            $entity,
            $table,
            $method,
            $from,
            [],
            \sprintf('%s::%s() was refused: %s', self::shortNameOf($entity), $method, $reason),
            \sprintf('This %s cannot make that status change.', $table),
        );
    }

    /**
     * The `detail` and `message` members of §5.2's `business-rule-violated` body.
     *
     * **Separate from `getMessage()`, and `RED` is the reason.** `getMessage()`
     * names the PHP class and method, which is what a log or a stack trace wants
     * and is exactly what a wire contract must not carry: it would make renaming
     * `place()` a breaking API change, and `because()`'s reasons cite internal
     * documents — `Tenant`'s names ADR-006 §2.1 by section. This one names the
     * table and the statuses, which are already in the `rule` code and in the
     * resource the caller addressed, and nothing else.
     */
    public function clientMessage(): string
    {
        return $this->clientMessage;
    }

    /** The `rule` member of §5.2's `business-rule-violated` body. */
    public function rule(): string
    {
        return $this->table.'.'.$this->ruleName;
    }

    /** @return class-string */
    public function entity(): string
    {
        /** @var class-string */
        return $this->entity;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function from(): string
    {
        return $this->from;
    }

    /** @return list<string> */
    public function legalFrom(): array
    {
        return $this->legalFrom;
    }

    /** @param class-string $entity */
    private static function tableOf(string $entity): string
    {
        $attributes = (new \ReflectionClass($entity))->getAttributes(Table::class);

        if ([] === $attributes) {
            throw new \LogicException(\sprintf('%s declares no #[ORM\Table], so no rule code can be derived for it.', $entity));
        }

        $name = $attributes[0]->newInstance()->name;

        return $name ?? throw new \LogicException(\sprintf('%s\'s #[ORM\Table] carries no name.', $entity));
    }

    /** @param class-string $entity */
    private static function shortNameOf(string $entity): string
    {
        return (new \ReflectionClass($entity))->getShortName();
    }

    /** @param list<string> $values */
    private static function quoted(array $values): string
    {
        return implode(' or ', array_map(static fn (string $v): string => '"'.$v.'"', $values));
    }
}

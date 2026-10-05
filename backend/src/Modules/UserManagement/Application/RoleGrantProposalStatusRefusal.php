<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Infrastructure\Entity\RoleGrantProposal;
use App\Platform\Domain\IllegalTransition;
use App\Platform\Http\Problem\ApiProblem;

/**
 * **A proposal that is no longer pending, told in the proposal flow's own words** (`UX` flag F-1, ruled 2026-10-05,
 * `docs/session13/roles-s3-ux-copy.md` §4.3; roles slice 3 C2 for Confirm, C3 for Reject and Withdraw).
 *
 * **Why a handler rewrites a house sentence, and only this one.** The shipped translation of `IllegalTransition`
 * (`FrameworkProblemTranslator`) puts the table and the stored status in `detail` **on purpose**, and that house choice
 * stands (`DomainRefusalTest::theRefusalBodyNamesNoInternalIdentifier`). The ground here is **vocabulary only**: the
 * generic sentence would print `"cancelled"`, and this resource's copy says "withdrawn" — never "cancel", which a reader
 * takes to mean undoing a grant (`UX` §1's word table). It is the first handler-level override of a house-wide choice;
 * no later module may cite it as a sign that table names left the wire.
 *
 * **The mechanism, as ruled:** a handler catches `IllegalTransition` around its ONE entity call and hands it here. Only a
 * refusal whose entity is `RoleGrantProposal` and whose rule ends `.status_transition_is_legal` is rewritten — same rule
 * code, `UX`'s sentence by the status the proposal is in. Anything else, **including a status with no sentence below**,
 * is returned untouched, so the handler rethrows the original and the generic translator answers.
 * `RoleGrantProposalConfirmTest` holds {@see self::SENTENCES}' keys equal to the entity's non-`pending` statuses.
 */
final class RoleGrantProposalStatusRefusal
{
    /** `UX` §4.3, verbatim — the same sentence on Confirm, Reject and Withdraw. */
    public const SENTENCES = [
        RoleGrantProposal::STATUS_CONFIRMED => 'This proposal has already been confirmed.',
        RoleGrantProposal::STATUS_REJECTED => 'This proposal has already been rejected.',
        RoleGrantProposal::STATUS_CANCELLED => 'This proposal has already been withdrawn by the person who proposed it.',
        RoleGrantProposal::STATUS_LAPSED => 'This proposal has already lapsed.',
    ];

    /** The refusal to throw: `UX`'s sentence for a proposal's status refusal, else `$refusal` itself, untouched. */
    public static function rewrite(IllegalTransition $refusal): \Throwable
    {
        if (RoleGrantProposal::class !== $refusal->entity()
            || !str_ends_with($refusal->rule(), '.'.IllegalTransition::RULE)
            || !\array_key_exists($refusal->from(), self::SENTENCES)) {
            return $refusal;
        }

        return ApiProblem::businessRuleViolated($refusal->rule(), self::SENTENCES[$refusal->from()]);
    }

    /** Not instantiable: one pure decision. */
    private function __construct()
    {
    }
}

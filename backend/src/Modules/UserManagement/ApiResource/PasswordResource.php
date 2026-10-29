<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\ChangePasswordProcessor;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `POST /api/v1/me/password` — §4.2's `ChangePassword`.
 *
 * ---------------------------------------------------------------------------
 * 200, and the branch that decides it
 * ---------------------------------------------------------------------------
 * API-21 branch 5: a `POST` that creates no row this contract addresses. §4.2
 * declares no operation at a row-level URI this `POST` creates — a credential is
 * not a row and `/me/password` answers `POST` and nothing else — so there is no
 * `Location` and branch 4 does not apply.
 *
 * ---------------------------------------------------------------------------
 * The `Perm` cell is `—`, and that is a ruling rather than an omission
 * ---------------------------------------------------------------------------
 * `SECURITY_MODEL.md` §3's catalogue is closed at sixty codes and **none of them
 * fits**. The near miss is `app_user.edit`, and taking it would be wrong twice:
 * it is the *administrative* code behind `UpdateUser` at `/users/{id}`, held by
 * `owner` and `admin` alone, so requiring it here would mean **most seats in a
 * tenant could not change their own password** — ST-4b arriving on the one
 * operation every principal needs. And a permission code is a capability over a
 * resource somebody else may also reach; the caller's own credential is not that.
 *
 * So the operation is **self-service by construction**: it can only ever act on
 * the principal in the token, because `ChangePasswordInput` has no field naming a
 * subject and `ChangePasswordProcessor` reads the actor from
 * `RequestIdentity::authenticated()`. There is nothing for a code to gate that
 * the shape of the request does not already gate.
 *
 * **Minting a code was refused, not overlooked.** §3's catalogue is `SEC`'s and
 * PC-1 governs its shape; `BE-LEAD` adding a sixty-first code would be the
 * classification mistake E4-1 exists to stop, one register over.
 *
 * **`—` is not "public", and this path proves it the way §4.2 says it should.**
 * §4.2: *"Whether it also requires a JWT is decided by the row's own `Path` …
 * `/auth/*` is pre-authentication; everything else requires a valid token."* This
 * path is not under `/auth/`, so `AuthenticationGate` requires one, and
 * `PermissionEnforcementContractTest::anOperationWithNoCodeIsNotThereforePublic`
 * is the standing guard over the whole `—` set —
 * `PasswordChangeTest::theOperationIsAuthenticatedThoughItDeclaresNoCode` drives
 * this row of it specifically.
 *
 * ---------------------------------------------------------------------------
 * `Idem: no`, and the reason is stronger here than on `IssueToken`
 * ---------------------------------------------------------------------------
 * A replay of this request **refuses itself**: the second call presents a
 * `current_password` that is no longer current, so it answers `422` and changes
 * nothing. The operation is idempotent in the only sense that matters — repeating
 * it cannot produce a second `audit_log` row — without a key. Marking it `yes`
 * would additionally make it unshippable, because `Idempotency-Key` has no
 * storage (**API-O-1**), and would put a client-supplied handle on a
 * credential-bearing request.
 *
 * ---------------------------------------------------------------------------
 * **A successful change signs the person out everywhere — including the token
 * that made this call**
 * ---------------------------------------------------------------------------
 * Owner ruling P-A, 2026-10-27; closes `RESIDUAL_GAPS.md` G-84. In the transaction
 * that writes the new hash, `ChangePassword` raises `app_user.sessions_valid_after`
 * (monotone) to the change second, and `IdentityReader` refuses every token minted
 * at or before it. The 200 is still answered; the caller's next request is the
 * constant 401 `authentication-failed`, so a client must sign in again with the new
 * password, no sooner than `changed_at` + 1 s (a sign-in in the change's own second
 * is refused once — the whole-second floor G4 and Mail-C2 already carry). Every
 * unused password-reset link minted at or before that second stops working too
 * (owner Q-3, Mail-C2). This is not a session store (API-O-13 stands): no token is
 * listed or revoked; the principal's floor moves. **No member of this response
 * reports it** — on a 200 it always happens, so a `sessions_revoked` field would be
 * a constant.
 *
 * ---------------------------------------------------------------------------
 * The response shape, and why it is one member
 * ---------------------------------------------------------------------------
 * §4.4.3's derivation yields nothing here — there is no `Create / Password` row,
 * so step 1 finds no accepted-field cell; step 2's `id` does not apply because a
 * credential is not an addressable row; step 3 adds nothing because a password
 * has no `SchemaManifest` deletion class. **API-23a therefore requires an
 * additions row, and §4.4.3 now carries one** — the same shape `ReorderSummary`
 * uses, where the additions row *is* the whole response. `changed_at` is the
 * change second: the session floor the change set, within a second of E-23's audit
 * row, so a support question can still be matched against the trail.
 */
#[ApiResource(
    shortName: 'Password',
    operations: [
        new Post(
            uriTemplate: '/me/password',
            name: 'ChangePassword',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'ChangePassword'),
            input: ChangePasswordInput::class,
            denormalizationContext: [
                // API-9 / B-3. The spec-level clause stops a field being
                // *declared*; only a deny-unknown deserializer stops it being
                // *accepted*.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            read: false,
            processor: ChangePasswordProcessor::class,
            // §4.2's `Perm` cell is `—`, declared rather than omitted:
            // `PermissionGate` refuses an operation that carries no declaration
            // at all, so "this operation needs no code" has to be said out loud.
            // See the class docblock for why no code in §3's catalogue fits.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => null],
        ),
    ],
)]
final class PasswordResource
{
    public function __construct(
        /** RFC 3339, whole seconds — the session floor the change set (owner ruling P-A); within a second of E-23's audit_log row. */
        #[SerializedName('changed_at')]
        public string $changedAt,
    ) {
    }
}

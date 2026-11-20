# Users, roles and sign-in

Generated from `contracts/openapi.json` and `contracts/api-surface.json` by `make contracts`. Do not edit by hand.

## Request a password reset

`POST /api/v1/auth/password-resets` · `CreatePasswordReset`

Asks for a link to set a new password to be emailed. The answer never says whether an account was found.

**Who may call it:** No sign-in.

**Request body:** [PasswordReset.CreatePasswordResetInput](schemas.md#passwordresetcreatepasswordresetinput) (`application/json`)

**Responses**

- **202** Accepted. If that email belongs to an active account in that workspace, and the workspace allows signing in, a link to set a new password is sent to it, subject to a limit on how many links one account is sent in 24 hours. The answer is the same in every case, so it does not say whether the account exists or whether a link was sent. The answer has no body.
- **Refusals** [422](README.md#422) · [429](README.md#429)

## Set a new password

`POST /api/v1/auth/password-resets/redemption` · `CreatePasswordResetRedemption`

Sets a new password using the token from a reset link, and signs the account out everywhere.

**Who may call it:** No sign-in.

**Request body:** [PasswordResetRedemption.CreatePasswordResetRedemptionInput](schemas.md#passwordresetredemptioncreatepasswordresetredemptioninput) (`application/json`)

**Responses**

- **200** The new password is saved; `changed_at` is when. Every session of this account has ended, this link and every other unused link for the account no longer work, and any pause on signing in is lifted. Sign in with the new password, no sooner than one second after `changed_at`: a token issued in that same second is not accepted. [PasswordResetRedemption](schemas.md#passwordresetredemption) (`application/json`)
- **Refusals** 401 (This link can't be used. The same answer is given for every reason (for example a link that is wrong, expired or already used, or an account or workspace that can no longer sign in), so it does not say which.) · [409](README.md#409) · [422](README.md#422) · [429](README.md#429)

## Sign in

`POST /api/v1/auth/token` · `IssueToken`

Signs in to one workspace with an email and a password, and returns an access token.

**Who may call it:** No sign-in.

**Request body:** [Token.IssueTokenInput](schemas.md#tokenissuetokeninput) (`application/json`) — The workspace, email and password to sign in with.

**Responses**

- **200** Signed in. Send `access_token` in an `Authorization: Bearer` header on every request outside `/api/v1/auth/`. It stops working at `expires_at`, or sooner, for example if the account is deactivated or its password is changed or reset. [Token](schemas.md#token) (`application/json`)
- **Refusals** 401 (Sign-in refused. The same answer is given whatever the reason (a workspace, email or password that does not match, or an account or workspace that cannot sign in), so it does not say which.) · [409](README.md#409) · [422](README.md#422) · 429 (Too many sign-in attempts. `type` says which limit applied: too many attempts from this network, or sign-in paused for this workspace and email after repeated failures. Wait before trying again; no wait time is given.)

## Give a role in bulk

`POST /api/v1/bulk/role-assignments` · `CreateBulkRoleAssignment`

Gives one role to several people in one request.

**Who may call it:** `role.assign`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [BulkRoleAssignment.CreateBulkRoleAssignmentInput](schemas.md#bulkroleassignmentcreatebulkroleassignmentinput) (`application/json`)

**Responses**

- **200** How many people were named, how many were given the role, and the role's margin warning. Which ones, and why the rest were not, is never said. `application/json`
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Get current user

`GET /api/v1/me` · `GetCurrentUser`

Returns the current user.

**Who may call it:** No permission code: any signed-in person.

**Responses**

- **200** The current user. [CurrentUser](schemas.md#currentuser) (`application/json`)
- **Refusals** [401](README.md#401) · [422](README.md#422)

## Change password

`POST /api/v1/me/password` · `ChangePassword`

Changes the signed-in person's password. Every session of the account ends, this one included.

**Who may call it:** No permission code: any signed-in person.

**Request body:** [Password.ChangePasswordInput](schemas.md#passwordchangepasswordinput) (`application/json`)

**Responses**

- **200** The password was changed at `changed_at`. Every session of this account has ended, this one included, so the token that made this request no longer works. Sign in again with the new password, no sooner than one second after `changed_at`: a token issued in that same second is not accepted. [Password](schemas.md#password) (`application/json`)
- **Refusals** [401](README.md#401) · [409](README.md#409) · [422](README.md#422) · 429 (This account's password was changed too many times recently. Wait before changing it again; no wait time is given.)

## List permissions

`GET /api/v1/permissions` · `ListPermissions`

Lists permissions.

**Who may call it:** `role.view`

**Responses**

- **200** The permission list. It is not paged: `next_cursor` and `previous_cursor` are always null. [PermissionCollection](schemas.md#permissioncollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## List role grant proposals

`GET /api/v1/role-grant-proposals` · `ListRoleGrantProposals`

Lists role grant proposals, one page at a time.

**Who may call it:** `role.assign`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `status` | query | string: `pending`, `confirmed`, `rejected`, `cancelled`, `lapsed` | no | Only the proposals with one `status`. An expired proposal is still `pending`; that it has expired is shown as `is_expired`. |
| `user` | query | string (`uuid`) | no | Only the proposals whose `user` is one person. A person this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `role` | query | string (`uuid`) | no | Only the proposals of one role. A role this workspace cannot see matches nothing: the answer is an empty page, never a 404. |
| `limit` | query | integer | no | How many rows to return: 50 if not sent, at most 200. |
| `after` | query | string | no | The `next_cursor` of the previous page, to fetch the next one. Not with `before`. |
| `before` | query | string | no | The `previous_cursor` of a later page, to fetch the one before it. Not with `after`. |

**Responses**

- **200** One page of the role grant proposal list. `next_cursor` is null on the last page; otherwise send it as `after` for the next page. [RoleGrantProposalCollection](schemas.md#rolegrantproposalcollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Get role grant proposal

`GET /api/v1/role-grant-proposals/{id}` · `GetRoleGrantProposal`

Returns one role grant proposal.

**Who may call it:** `role.assign`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The role grant proposal's id. |

**Responses**

- **200** The role grant proposal. [RoleGrantProposal](schemas.md#rolegrantproposal) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Cancel role grant proposal

`POST /api/v1/role-grant-proposals/{id}/cancellation` · `CancelRoleGrantProposal`

Cancels one role grant proposal.

**Who may call it:** `role.assign`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The role grant proposal's id. |

**Responses**

- **200** The role grant proposal, as saved. [RoleGrantProposal](schemas.md#rolegrantproposal) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Confirm role grant proposal

`POST /api/v1/role-grant-proposals/{id}/confirmation` · `ConfirmRoleGrantProposal`

Confirms one role grant proposal.

**Who may call it:** `role.assign`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The role grant proposal's id. |

**Responses**

- **200** The role grant proposal, as saved. [RoleGrantProposal](schemas.md#rolegrantproposal) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Reject role grant proposal

`POST /api/v1/role-grant-proposals/{id}/rejection` · `RejectRoleGrantProposal`

Rejects one role grant proposal.

**Who may call it:** `role.assign`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The role grant proposal's id. |

**Request body:** [RoleGrantProposal.RejectRoleGrantProposalInput](schemas.md#rolegrantproposalrejectrolegrantproposalinput) (`application/json`)

**Responses**

- **200** The role grant proposal, as saved. [RoleGrantProposal](schemas.md#rolegrantproposal) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## List roles

`GET /api/v1/roles` · `ListRoles`

Lists roles.

**Who may call it:** `role.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `archived` | query | boolean | no | Return the archived roles instead of the live ones. |
| `q` | query | string | no | Search: a prefix of the `name`, ignoring case. Nothing else is searched. |

**Responses**

- **200** The role list. It is not paged: `next_cursor` and `previous_cursor` are always null. [RoleCollection](schemas.md#rolecollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Create role

`POST /api/v1/roles` · `CreateRole`

Creates one role.

**Who may call it:** `role.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [Role.CreateRoleInput](schemas.md#rolecreateroleinput) (`application/json`) — The role to create.

**Responses**

- **201** The role this request created. `Location` gives its address. [Role](schemas.md#role) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Get role

`GET /api/v1/roles/{id}` · `GetRole`

Returns one role.

**Who may call it:** `role.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The role's id. |

**Responses**

- **200** The role. [Role](schemas.md#role) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Update role

`PATCH /api/v1/roles/{id}` · `UpdateRole`

Changes one role.

**Who may call it:** `role.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The role's id. |

**Request body:** [Role.UpdateRoleInput](schemas.md#roleupdateroleinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The role, as saved. [Role](schemas.md#role) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete role

`DELETE /api/v1/roles/{id}` · `DeleteRole`

Archives one role. `RestoreRole` brings it back.

**Who may call it:** `role.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The role's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Restore role

`POST /api/v1/roles/{id}/restoration` · `RestoreRole`

Restores one archived role.

**Who may call it:** `role.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The role's id. |

**Responses**

- **200** The role, as saved. [Role](schemas.md#role) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Get settings

`GET /api/v1/settings` · `GetSettings`

Returns the settings.

**Who may call it:** `tenant_settings.view`

**Responses**

- **200** The settings. [Settings](schemas.md#settings) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Update settings

`PATCH /api/v1/settings` · `UpdateSettings`

Changes the settings.

**Who may call it:** `tenant_settings.edit`

**Request body:** [Settings.UpdateSettingsInput](schemas.md#settingsupdatesettingsinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The settings, as saved. [Settings](schemas.md#settings) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422)

## List users

`GET /api/v1/users` · `ListUsers`

Lists users.

**Who may call it:** `app_user.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `archived` | query | boolean | no | Return the archived users instead of the live ones. |
| `q` | query | string | no | Search: a prefix of the `email`, ignoring case. Nothing else is searched. |

**Responses**

- **200** The user list. It is not paged: `next_cursor` and `previous_cursor` are always null. [UserCollection](schemas.md#usercollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [422](README.md#422)

## Create user

`POST /api/v1/users` · `CreateUser`

Creates one user.

**Who may call it:** `app_user.create`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Request body:** [User.CreateUserInput](schemas.md#usercreateuserinput) (`application/json`) — The user to create.

**Responses**

- **201** The user this request created. `Location` gives its address. [User](schemas.md#user) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Get user

`GET /api/v1/users/{id}` · `GetUser`

Returns one user.

**Who may call it:** `app_user.view`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The user's id. |

**Responses**

- **200** The user. [User](schemas.md#user) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Update user

`PATCH /api/v1/users/{id}` · `UpdateUser`

Changes one person's name, or switches their account off or on. Switching it off takes away every role they hold and ends their sessions; switching it back on gives no role back.

**Who may call it:** `app_user.edit`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The user's id. |

**Request body:** [User.UpdateUserInput](schemas.md#userupdateuserinput) (`application/json`) — Only the fields to change. A field left out is not changed.

**Responses**

- **200** The user, as saved. [User](schemas.md#user) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Delete user

`DELETE /api/v1/users/{id}` · `DeleteUser`

Archives one person: every role they hold is taken away and their sessions end. `RestoreUser` brings the account back, with no roles.

**Who may call it:** `app_user.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The user's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## List offered roles

`GET /api/v1/users/{id}/offered-roles` · `ListOfferedRoles`

Lists offered roles.

**Who may call it:** `role.assign`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The user's id. |
| `archived` | query | boolean | no | Return the archived roles instead of the live ones. Each one carries a `refusal`, because an archived role cannot be given. |
| `q` | query | string | no | Search: a prefix of the `name`, ignoring case. Nothing else is searched. It chooses which roles are returned; `conflicts_with` and `margin_exposure_with` are still worked out against every role. |

**Responses**

- **200** The offered role list. It is not paged: `next_cursor` and `previous_cursor` are always null. [OfferedRoleCollection](schemas.md#offeredrolecollection) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [422](README.md#422)

## Restore user

`POST /api/v1/users/{id}/restoration` · `RestoreUser`

Restores one archived person, with no roles.

**Who may call it:** `app_user.delete`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The user's id. |

**Responses**

- **200** The user, as saved. [User](schemas.md#user) (`application/json`)
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

## Create role grant proposal

`POST /api/v1/users/{id}/role-grant-proposals` · `CreateRoleGrantProposal`

Creates one role grant proposal.

**Who may call it:** `role.assign`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The user's id. |

**Request body:** [RoleGrantProposal.CreateRoleGrantProposalInput](schemas.md#rolegrantproposalcreaterolegrantproposalinput) (`application/json`) — The role grant proposal to create.

**Responses**

- **201** The role grant proposal this request created. `Location` gives its address. [RoleGrantProposal](schemas.md#rolegrantproposal) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Give role

`POST /api/v1/users/{id}/roles` · `AssignRole`

Gives one role to one person.

**Who may call it:** `role.assign`

**Idempotency-Key:** required: send a fresh key with each new request, and the same key to retry it.

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The user's id. |

**Request body:** [Role.AssignRoleInput](schemas.md#roleassignroleinput) (`application/json`)

**Responses**

- **201** The role, now held by this person. `Location` gives the address of the grant; it is the address `DELETE` uses to take the role away. [Role](schemas.md#role) (`application/json`) Header `Location`: The address of what this request created.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422) · [428](README.md#428)

## Take role away

`DELETE /api/v1/users/{id}/roles/{roleId}` · `RevokeRole`

Takes one role away from one person.

**Who may call it:** `role.assign`

**Parameters**

| Name | In | Type | Required | Description |
| --- | --- | --- | --- | --- |
| `id` | path | string (`uuid`) | yes | The user's id. |
| `roleId` | path | string (`uuid`) | yes | The role's id. |

**Responses**

- **204** Done. The answer has no body.
- **Refusals** [401](README.md#401) · [403](README.md#403) · [404](README.md#404) · [409](README.md#409) · [422](README.md#422)

# Enhance plugin

Provisions hosting on an [Enhance](https://enhance.com) control panel through its REST API (`https://<panel>/api`, checked against the official orchd OpenAPI spec 12.26.3, https://apidocs.enhance.com/spec/oas3-api.yaml). Auth is `Authorization: Bearer <access token>`; the org id is only used in URL paths.

## Setup
1. Enable the plugin (Plugins page).
2. Create an API access token in Enhance for your master/reseller organisation.
3. Servers → Add: module **Enhance**, hostname of the panel, port (443 default), put the **access token in the server secret field**, and the **parent organisation ID** (UUID).
4. "Test connection" lists the plans with their numeric IDs.
5. On a product choose module Enhance and enter the **Plan ID**. Optionally tick "Create a website" to create a site for the service label (a domain).

## What happens
| Billing action | Enhance calls |
|---|---|
| create | `POST /orgs/{parent}/customers` → `POST /logins?orgId=` → `POST /orgs/{customer}/members` (Owner) → `POST /orgs/{parent}/customers/{customer}/subscriptions` → optional `POST /orgs/{customer}/websites`. A failure after the org was created deletes it again. |
| suspend / unsuspend | `PATCH /orgs/{customer}/subscriptions/{id}` `{isSuspended}` |
| change package | `PATCH .../subscriptions/{id}` `{planId}` |
| terminate | `DELETE /orgs/{customer}?force=true`; if the token is not allowed to force-delete (403) it falls back to a soft delete (`DELETE /orgs/{customer}`) |
| Log in (client/admin button) | `GET /orgs/{customer}/members/{member}/sso` (one-time link) |

Stored on the service: `org_id`, `login_id`, `member_id`, `subscription_id`, `website_id`, `domain`, `login_email`, `panel_url`; the generated password is stored encrypted.

## Known limitations
- Subscription PATCH uses the **customer** org id in `/orgs/{org_id}/subscriptions/{id}` (the spec's `Subscription.subscriberId` is the org holding the subscription; the PATCH is "by a parent organization admin", i.e. our reseller token).
- Suspension is represented by `isSuspended` (boolean) on the subscription (`UpdateSubscription`); `status` only knows `active|deleted`. Websites have their own `isSuspended`/`status` (`active|disabled|deleted`) but we only suspend the subscription.
- Force-delete (`force=true`) is limited by the spec to MO Owner/SuperAdmin/Support; other tokens get a soft delete, which keeps the data in the panel.
- The spec does not document rate limits, pagination beyond `offset`/`limit` (+ `total` in listings) or any error body other than `HttpError` `{code, message?, detail?}`; errors are surfaced as `HTTP <status>: <code> - <message> - <detail>`.
- The `/api` base path is not part of the OpenAPI document (paths start at `/orgs/...`); `https://<panel>/api/` is assumed.
- Creating a login with an email that already exists in the panel fails; the error is surfaced and the new org is rolled back. Logins are not deleted on terminate (they may belong to other orgs).
- Not verified against a live panel; the request shapes follow the published spec (v12.26).

# Microsoft Teams Notification Integration Guide

This guide explains **exactly how to set up Microsoft Teams notifications** so your technicians get alerted whenever a new onboarding client is assigned in Taxware Onboarding.

It is written for the current implementation in this repository, which sends notifications from:

- `sales.php`
- `v2/sales.php`

---

## 1) How the integration works (quick overview)

When a Sales user creates a client:

1. The app inserts the client into `Onboarding`.
2. The app creates the internal in-app notification (`Notification` table).
3. The app sends an HTTP `POST` request to a **Teams Workflow URL**.
4. The workflow receives JSON payload fields including:
   - `techName`
   - `techEmail`
   - `clientId`
   - and a readable `text` summary

If Teams fails, client creation still succeeds. The app logs an error and does not block onboarding creation.

---

## 2) Prerequisites

Before starting, make sure you have:

- Microsoft 365 account with Teams + Power Automate (Workflows) access.
- Permission to create a workflow in the target Team/Channel.
- Access to your app database (`admin_settings`) **or** server environment variables.
- Access to deploy updated PHP files.

---

## 3) Create the Teams workflow (Power Automate)

> Teams no longer recommends the old Office 365 Incoming Webhook approach for many scenarios. This integration uses a **workflow endpoint URL**.

### Step 3.1: Create a new workflow

1. Open Microsoft Teams.
2. Go to the target Team/Channel where notifications should originate.
3. Open **Workflows** (or Power Automate).
4. Create a new workflow using a trigger like:
   - **When an HTTP request is received** (recommended), or
   - equivalent trigger that provides an HTTP endpoint URL.

### Step 3.2: Define expected JSON schema

Use a schema that supports the payload sent by this app. Example:

```json
{
  "type": "object",
  "properties": {
    "text": { "type": "string" },
    "techEmail": { "type": "string" },
    "techName": { "type": "string" },
    "clientId": { "type": "string" }
  }
}
```

### Step 3.3: Add routing / posting actions

Inside the workflow, add actions to:

1. Optionally resolve user by `techEmail` (if your tenant allows lookup).
2. Post a message in chat/channel or adaptive card.
3. Include the fields from payload:
   - Assigned Tech = `techName`
   - Tech Email = `techEmail`
   - Client ID = `clientId`
   - Summary = `text`

### Step 3.4: Save and copy the workflow URL

After saving, copy the generated HTTPS endpoint URL.

---

## 4) Configure Taxware Onboarding to use the workflow URL

You can configure the URL in either of these ways (DB first, ENV fallback).

### Option A (recommended): `admin_settings` entry

Add/update this row:

- `Setting_Name = 'TeamsWorkflowUrl'`
- `Setting_Value = '<your workflow url>'`

Example SQL:

```sql
INSERT INTO admin_settings (Setting_Name, Setting_Value, UserID)
VALUES ('TeamsWorkflowUrl', 'https://prod-xx.westus.logic.azure.com:443/workflows/...', 1)
ON DUPLICATE KEY UPDATE Setting_Value = VALUES(Setting_Value);
```

> If your table has no unique key on `Setting_Name`, use UPDATE/INSERT logic manually.

### Option B: Environment variable fallback

Set:

```bash
TEAMS_WORKFLOW_URL="https://prod-xx.westus.logic.azure.com:443/workflows/..."
```

- Apache: configure in vhost / envvars.
- Nginx + PHP-FPM: pass via `fastcgi_param` or service environment.
- Docker: set in compose/environment.

---

## 5) Confirm code points in this repo

The following logic exists in both `sales.php` and `v2/sales.php`:

1. Reads URL from:
   - DB setting `TeamsWorkflowUrl`
   - fallback `TEAMS_WORKFLOW_URL`
2. Builds JSON payload with `text`, `techEmail`, `techName`, `clientId`.
3. Sends HTTP POST using `file_get_contents(..., stream_context_create(...))`.
4. Logs error if delivery fails, without blocking client insert.

---

## 6) End-to-end test checklist

Run this checklist after deployment.

### Step 6.1: App sanity

- Confirm Sales form can still add a client.
- Confirm internal notification row is still inserted.

### Step 6.2: Workflow receives payload

- Add a test client assigned to a known tech.
- Open workflow run history.
- Verify HTTP trigger fired.
- Verify fields:
  - `techName`
  - `techEmail`
  - `clientId`
  - `text`

### Step 6.3: Teams output

- Confirm the tech/channel receives a message.
- Confirm message includes expected client details.

### Step 6.4: Failure behavior

- Temporarily break URL (or disable workflow).
- Add a client.
- Confirm client still saves.
- Check PHP error log for workflow send failure entry.

---

## 7) Troubleshooting guide

### Problem: No Teams message, but client is saved

Likely cause: workflow URL missing/invalid.

Check:

1. `admin_settings` has `TeamsWorkflowUrl` with full URL.
2. If not in DB, `TEAMS_WORKFLOW_URL` is present for PHP runtime.
3. URL has not rotated/expired.

### Problem: Workflow triggers but fields are blank

Likely cause: schema mismatch.

Check:

1. Trigger schema includes `techEmail`, `techName`, `clientId`, `text`.
2. You are mapping dynamic content correctly in actions.

### Problem: Tech-specific routing not working

Likely cause: email mismatch or tenant restrictions.

Check:

1. Tech has valid `Users.Email` in app DB.
2. Email format matches Microsoft Entra ID user principal.
3. Workflow permissions allow user lookup/DM.

### Problem: HTTPS call blocked

Likely cause: outbound firewall/proxy policy.

Check:

1. Server can reach Microsoft workflow endpoint over 443.
2. PHP runtime is allowed outbound HTTPS.

---

## 8) Security and operations recommendations

1. **Treat workflow URL like a secret**.
2. Prefer storing URL in secure config/secret manager (then sync to app setting).
3. Rotate URL immediately if leaked.
4. Avoid writing full URL into logs.
5. Add monitoring/alerts for workflow failures.
6. Keep Teams message payload free of sensitive PII unless required.

---

## 9) Suggested message template for workflow output

If you post a Teams message/card, use a format like:

- **Title:** New Onboarding Client Assigned
- **Tech:** `techName` (`techEmail`)
- **Client ID:** `clientId`
- **Summary:** `text`
- **Timestamp:** workflow runtime timestamp

---

## 10) Implementation runbook (quick copy/paste)

1. Build workflow in Teams/Power Automate with HTTP trigger.
2. Accept JSON fields: `text`, `techEmail`, `techName`, `clientId`.
3. Add action to post message (channel or direct target logic by `techEmail`).
4. Copy workflow URL.
5. Save URL into `admin_settings` as `TeamsWorkflowUrl` **or** `TEAMS_WORKFLOW_URL` env var.
6. Deploy app code.
7. Add a test client in Sales.
8. Confirm workflow run + Teams message.
9. Monitor logs and workflow run history for failures.

---

## 11) Future improvements (optional)

- Add retry/backoff queue for transient network failures.
- Save delivery status in a dedicated table (success/failure timestamps).
- Add admin UI in `settings.php` to manage `TeamsWorkflowUrl` without SQL.
- Use Adaptive Cards for richer notification formatting.
- Add signed request validation between app and workflow.


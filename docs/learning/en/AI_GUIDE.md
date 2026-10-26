# Using AI Tools with Widdx

[العربية](../ar/AI_GUIDE.md)

> A practical guide for programmers: preparing an assistant, dividing work, writing prompts, reviewing changes, and verifying results.
> Based on the Widdx v0.3.8 references in this repository. Read the instructions for **your application's version** if it differs.
> These prompts are working templates, not guarantees of correct output from any tool; they have not been tested here across all tools or versions.

This guide explains **using AI to build applications with Widdx**, not adding an AI model to an application.
For the framework introduction, read [INTRO.md](INTRO.md); for the study path, read [CURRICULUM.md](CURRICULUM.md).
The binding instructions are in [AGENTS.md](../../../AGENTS.md) and the references it routes to. This guide teaches you how to apply them; it does not replace them.

## Contents

1. [The programmer's role and the assistant's role](#01-roles)
2. [Choosing a tool and preparing context](#02-tools)
3. [Preparing the project before the first request](#03-setup)
4. [The correct workflow](#04-workflow)
5. [Writing a good prompt](#05-prompt)
6. [Ready-to-use prompts](#06-library)
7. [A practical example from setup to handoff](#07-example)
8. [Common agent mistakes and how to review them](#08-traps)
9. [Security and authorization boundaries](#09-security)
10. [Evaluating results and learning](#10-review)
11. [References and limitations](#11-references)

---

<a id="01-roles"></a>
## 1. The Programmer's Role and the Assistant's Role

Widdx infers much of its behavior from names and rules. The best assistant is therefore not the one that produces the most files,
but the one that identifies what the framework already provides before writing a replacement.

| Decision or Work | Programmer's Responsibility | What to Ask the Assistant For |
|---|---|---|
| Goal | Define the problem and its users | Translate the request into testable acceptance criteria |
| Data | Identify sensitive fields and ownership | Suggest models and relationships following Widdx conventions |
| Permissions | Decide who reads, writes, and deletes | Implement the decision with guards and allow/deny tests |
| Implementation | Define scope and the environment that may be changed | Use generators, then the smallest necessary customization |
| Quality | Review evidence and changes | Check syntax and actual behavior, recording anything untested |
| Deployment | Approve the destination and timing | Prepare the steps; completed code is not implicit permission to deploy |

**Do not delegate a decision you have not made.** “Build an invoice dashboard” does not say whether users can see every invoice
or only their own. That is a data-model and authorization decision, not a detail for an agent to silently choose.

An assistant can play different roles; name the role in your prompt:

- **Teacher:** Explains, asks questions, and gives hints without doing the exercise for you.
- **Researcher:** Reads the project and cites evidence without editing it.
- **Planner:** Proposes phases, files, and tests, then waits for approval.
- **Implementer:** Completes one defined phase rather than the whole application at once.
- **Reviewer:** Looks for defects in a specified change rather than rewriting the project.

---

<a id="02-tools"></a>
## 2. Choosing a Tool and Preparing Context

### Chat Assistant or Project Agent?

| Mode | What It Can Actually Do | How to Work with It |
|---|---|---|
| Chat without file access | Read what you send and suggest code or commands | Attach the needed text, run commands yourself, and return sanitized output |
| Editor assistant with file access | Understand accessible files and propose changes within its permissions | Specify the application root and ask which files it read |
| IDE or CLI agent with command execution | Read, edit, and execute within its authorization | Limit scope and environment; review consequential commands and resulting evidence |

You might use Qoder, Cursor, Claude Code, Gemini CLI, GitHub Copilot, or another tool. The choice does not change Widdx's rules,
but **access capabilities and instruction-loading behavior vary by tool, version, and configuration**.

### Existing Instruction Files

| File | Role in This Repository |
|---|---|
| [AGENTS.md](../../../AGENTS.md) | Laws, placement map, work order, and definition of done |
| [`.agents/`](../../../.agents) | Specialized references; open what the task needs, not every file automatically |
| [CLAUDE.md](../../../CLAUDE.md) | Handbook pointer for tools that read this filename |
| [GEMINI.md](../../../GEMINI.md) | Handbook pointer for tools that read this filename |
| [.cursorrules](../../../.cursorrules) | Existing pointer file; check whether your version supports it |
| [.github/copilot-instructions.md](../../../.github/copilot-instructions.md) | Project-instruction pointer in environments that support it |
| [llms.txt](../../../llms.txt) | Short documentation map, not a replacement for the laws |

This version's project generator copies the agent instruction files and reference directory when they exist in its source.
Still, confirm they exist **inside the application you are working on**, especially for older or partially copied projects.

A file's presence does not prove the tool read it. Begin with an explicit request:

```text
Read AGENTS.md at the application root before proposing any change, then open the task reference selected by its ROUTER table.
List the files you read and the constraints relevant to my request. Do not simply say “I understand the instructions.”
If a file is inaccessible, name it and do not assume its contents.
```

If the tool cannot read files, attach the handbook, the relevant reference, and the affected files manually.
Do not send the entire repository, environment file, or database just to compensate for missing context.

**Keep one source of rules:** Do not copy the entire handbook into every tool's settings; copies drift during updates.
Use the existing pointers. If a tool-specific setting is needed, consult that version's documentation and explicitly approve the change.

---

<a id="03-setup"></a>
## 3. Preparing the Project Before the First Request

### A. Choose the Correct Directory

Distinguish between:

- **The Widdx framework repository:** where the core and generators themselves are developed.
- **An application using Widdx:** where features belong in the application directories, following its laws, without editing `core/`.

To build an application, open **its root directory**, containing its own `widdx` file.
Do not run generators from the original framework copy while intending to modify another application.

Before creating a project, make sure its directory name is unused. These commands start a new local exercise;
they are not commands to rerun on an existing application:

```bash
php widdx new learning-app
cd learning-app
php widdx migrate
php widdx serve
```

New projects are secure by default; adding `--demo` explicitly opts into public sample CRUD and should be local-only. **This is not a comprehensive security mode**; later generators and route edits still require deliberate authorization design.

### B. Check the Environment Before Fixing Code

In a terminal at the application root:

```bash
php -v
php -m
php widdx help
php widdx route:list
```

- Check PHP and the PDO extension for your database engine; SQLite needs `pdo_sqlite`.
- Check the actual runtime: PHP in a container or web server may differ from terminal PHP.
- Identify the target database without displaying passwords or secret connection strings.
- For a Git project, inspect `git status --short` before edits to recognize pre-existing changes.
- A missing database extension is an environment issue, not a reason to rewrite models.

### C. Define Execution Authority

Tell the agent whether the task is read-only or allows changes, and whether the database is disposable test data or contains work
that must be preserved. Do not make migration execution or write-capable tests an implicit decision against an unknown environment.

On an existing project, do not rerun `new` or a generator over existing files as a generic repair.
Inspect the effect first and preserve prior work.

---

<a id="04-workflow"></a>
## 4. The Correct Workflow

### Step 1 — Describe the Outcome

State what a user must be able to do, not just the technology names. For example:

> A logged-in employee can create an invoice for an existing company. The amount is required, and guests cannot read or modify invoices.

Also state what is **not** required: no payments, PDF export, or email if these are outside the task.

### Step 2 — Read the Reference and Existing Code

Ask the agent to read the handbook, the relevant reference, and any existing implementation.
Before implementing, review inference and the feature cheat sheet, and verify API names and signatures instead of guessing from Laravel.

If an example conflicts with source behavior or application instructions, the agent should identify the conflict with evidence and avoid
using the conflicting example. It must not invent an API to reconcile the texts.

### Step 3 — A Small Plan and Acceptance Criteria

Before writing, establish:

1. Files to create or change; a migration can be described by its filename pattern before its timestamp exists.
2. Generator commands and their effects on files and routes.
3. Data structure and permissions.
4. Success and failure tests.
5. Operations that require separate approval.

Do not reread every file for every phase. Reread what changed and what the next phase depends on.

### Step 4 — Generate, Then Customize

Use an existing generator, then customize its output minimally. Do not create controllers and templates for every CRUD resource
when the built-in behavior satisfies the request.

Review generated output **before making the application accessible**. This version's `make:crud` adds `->public()`,
which opens the CRUD group, not just its list page. For private data, remove that declaration and complete the planned guards and policies
before public testing.

### Step 5 — Apply the Database Schema

After reviewing migrations, against the authorized local database:

```bash
php widdx migrate
```

Creating a migration file does not mean the table exists. On failure, read the error and fix its cause;
do not wipe the database to make the problem disappear.

### Step 6 — Lint and Restart

Lint every changed PHP file. For an existing Product model in the relevant task:

```bash
php -l app/Models/Product.php
```

Restart `php widdx serve` after the final edit. Stop **this application's** server from its terminal, then start it again;
do not kill every PHP process on the machine.

The development server does not reliably reload files it has already loaded. If 404 remains after a restart, check the URL,
HTTP method, registration order, and application root. Not every 404 is a stale-worker issue.

### Step 7 — Prove the Behavior

For the local public-products exercise, with the server on its default port:

```bash
curl -i http://localhost:8000/products
```

Request the status and expected body text. **200 alone is insufficient**: it could be a login page or unrelated content.
Inspect redirects before following them so you do not hide an intentional access-control 302.

For an interface with forms, test in a browser:

- Create a valid record and confirm its values were stored.
- Submit invalid values and observe an appropriate error without saving bad data.
- Edit and delete dedicated test records.
- Test guest, allowed-user, and denied-user cases as required.
- Check CSRF and `_method` on applicable forms.
- If the design changed, check mobile/desktop, RTL, keyboard use, and browser messages.

`curl` does not replace trying the interface, and a successful list page does not test write operations.

### Step 8 — Review the Change and Hand Off

Review the Git diff **and new files**; `git diff` does not show untracked file contents.
Confirm that `core/` is unchanged, temporary files are gone, and no secrets or local data are included.

The handbook's practical sequence is:

**Name files → check inference and references → generator → migrations → restart → HTTP/form evidence → handoff.**

State when a step does not apply: a documentation-only change needs no server, and a task with no schema change needs no artificial migration.
Do not silently omit a required step that could not be completed.

---

<a id="05-prompt"></a>
## 5. Writing a Good Prompt

A good prompt is not necessarily long. It removes ambiguous decisions and defines evidence of success.

| Element | What to Specify |
|---|---|
| Context | Existing application or new Widdx project, working root, and environment |
| Task | One behavior or a defined phase |
| Data | Fields, relationships, ownership, and sensitivity |
| Access | Who reads, creates, updates, and deletes? |
| Boundaries | What must not change and which features are out of scope |
| Working mode | Read-only, planning, or authorized implementation |
| Acceptance | Cases and tests with expected results |
| Handoff | Files, commands, actual results, and anything untested |

**Weak request:**

```text
Build a complete professional ERP system using best practices.
```

**Actionable request:**

```text
We are working on a local Widdx application with a test database.
This phase only: companies with a required name, and invoices with a required amount, an existing company, and an optional note.
Only logged-in users may read, create, and update. Deletion is not allowed in this phase.
Do not add inventory, reports, new roles, or external integrations.
Read AGENTS.md and the relevant references, then show the files, generators, access matrix, and test plan.
Do not implement before the plan is approved.
```

Replace text in `[brackets]` before sending the templates below. If an essential security or relationship decision is still unknown,
ask for a focused question rather than authorizing a guess.

---

<a id="06-library"></a>
## 6. Ready-to-Use Prompts

### 6.1 Initialize an Agent at the Start of a Session

```text
We are working on an application using Widdx, not developing the framework core.
Application root: [absolute path]. Environment: [local/test container].

Read this application's AGENTS.md, then select the task reference from its ROUTER table.
My task: [short description]. This step is read-only research and planning.

Confirm that the widdx file and references belong to this application, and inspect existing changes if it uses Git.
Do not display .env secrets or user data, and do not edit instruction files or tool settings.
Do not modify core/ or assume an API or generator exists because Laravel has it.

Briefly report what you read, what the framework already does, the files that may change, and any implementation blockers.
If you cannot read or execute something, say so explicitly.
```

### 6.2 Understand an Existing Project Without Changing It

```text
Understand [the invoice feature, for example] in this application, read-only.
Start with project instructions, then trace the relevant routes, models, migrations, controllers, policies, and templates.

I want:
- The request path from URL to response.
- The data source and relationships.
- What Widdx provides by default and what the application customizes.
- Who currently has access, supported by code rather than the page name.
- What cannot be confirmed without running the application.

Cite files and line numbers. Do not change code or the database, or expand into unrelated features.
```

### 6.3 Plan a New Application Without Implementing It

```text
I want to build [application type] with Widdx.
Users: [groups]. Core functions: [short list].
Sensitive data: [fields]. Ownership and access rules: [rules].
Out of scope: [excluded features].

Read the handbook, inference reference, and other relevant references. Do not create a project yet.
Propose models, fields, and relationships; distinguish inferring a relationship name from actually creating its table.
Divide the work into small phases, each with files, generators, acceptance criteria, and a denial test.
Identify what built-in CRUD covers and what needs customization. Do not add unnecessary layers or libraries.
Ask only about data or authorization decisions that cannot be safely inferred.
```

### 6.4 Implement an Approved Phase

```text
Implement only phase [name/number] of the agreed plan.
Allowed: change the specified application files and run its generators, migrations, and tests in [test environment].
Not allowed: changes to core/ or agent instructions, data wiping, deployment, commits, or pushes.

Before editing, name the files and check inference, the feature cheat sheet, and the APIs to be used.
Use existing generators, then minimal customization. Review generated public routes against the agreed access matrix.
Run migrations when needed, lint PHP, then restart the application server after the final edit.
Test HTTP, forms, and allow/deny cases. On failure, fix the actual cause.

Report changes, commands, actual results, and anything untested. Proposing a test does not count as running it.
```

### 6.5 Create CRUD for Local Learning

```text
This is a local exercise with fake data, not a public service or private records.
I want Product with name:string, price:float, and photo:image?, using built-in CRUD.
I approve public operations on this resource within the local exercise only.

Read the application instructions, then use make:crud instead of hand-writing the model, migration, and CRUD.
Do not create a controller or custom templates unless the need is demonstrated.
Apply the migration to the test database and restart the server.
Verify /products, record creation, editing, image upload, deletion, and an invalid-input case.
Explicitly state that ->public() opens the whole CRUD group and must not be carried over to private data.
Do not deploy or expose the application at a public address.
```

### 6.6 Add Private Data and Relationships

```text
I want Company with a required name and Invoice with a required amount, an optional note, and a link to an existing company.
Access: guests denied; every logged-in user may read, create, and update; nobody may delete in this phase.
These are private records, not public demo CRUD.

Read the inference, relationship, and authentication references, then inspect existing structures to avoid duplicate models.
First show tables, keys, generators, files, and policies.
Quote fk(Model) arguments in shell commands.
Do not leave make:crud-generated ->public() on private resources.
Do not assume an FK alone handles authorization for related records.

Acceptance: the dropdown lists allowed companies, a nonexistent FK is rejected,
guests are denied, logged-in users can perform only allowed actions, and direct deletion requests remain denied.
```

### 6.7 Add a Many-to-Many Relationship

```text
I want a many-to-many relationship between Post and Tag in an existing project.
Who may attach tags to posts: [rules].

Inspect existing models, tables, interfaces, and relationship/API references before writing.
Identify the inferred pivot name and columns, and verify an actual table and migration exist.
Do not assume belongsToMany creates the table or a tag-selection interface automatically.
Do not assume attach, sync, or other methods from another framework exist; check the documented API.

Propose a small plan for storing, removing, and reading links using supported mechanisms,
preventing duplicates and validating access to both posts and tags. Do not change data before approval.
```

### 6.8 Authentication and Authorization

```text
I want to enable authentication and protect [resource] in this application.
Access matrix: [define viewAny, view, create, update, delete for each user group].
Record ownership: [rule, or no individual ownership].

First check whether authentication is already installed. Do not rerun a generator over customized files without reviewing its effects.
If make:auth is needed, use it; do not register authRoutes() again if the generator added it.
Do not seed a shared password or bypass; create the local test account through registration.
Write all five policy abilities and verify their signatures in the relevant reference and code.
Do not allow unauthorized request input to change is_admin, owner_id, or permission fields.

Test guests, allowed users, denied users, direct URL access,
and access to another user's record when ownership applies. Hiding a button is not enough.
```

### 6.9 Customize an Arabic Interface

```text
Customize [resource]'s interface for [usage description] without changing data logic or permissions.
Read the template and inference references, and inspect the variables supplied to the template.
Use the view generator where applicable, and put overrides in views/<plural-table-name>/.
Do not change core/ views or assume view('crud.index') finds a framework template from an application.

Escape displayed values with e(), include csrf_field() in every POST, and preserve _method for update/delete forms.
Handle nullable auth() on guest pages and hidden 0 inputs for manual checkboxes.
For a custom layout, apply language and direction through lang_attrs() according to the reference.

After the final edit, restart the server and test in a browser on mobile and desktop:
RTL, headings and fields, keyboard access, empty states, validation errors, and form operations.
Check browser messages and state any visual verification you could not perform. Do not add a UI library just to change appearance.
```

### 6.10 Diagnose a Failure Without Rewriting

```text
Failure: [description]. URL and HTTP method: [values].
Expected: [result]. Actual: [status and sanitized error].
Last change: [files or description]. Environment: [PHP, database engine, and server startup method].

Start with diagnosis only. Read application instructions and relevant files.
If the failure followed an edit while serve was running, restart this application's server before changing code.
Then inspect route:list, file syntax, and relevant sanitized log entries.

For 419, inspect CSRF and the session; for 403, inspect policy, public status, and identity;
for 404, inspect URL, method, and route order after ruling out a stale worker;
for missing saved values, inspect the field name, fillable, database column, and validation.

Report an evidence-backed cause and the smallest fix. Do not disable protection, wipe the database, or edit core/ to bypass the failure.
After the fix is approved, test the original case and a neighboring case that might regress.
```

### 6.11 A Background Job or Additional Service

```text
I need [specific task] to run in the background after [event].
Data source: [source]. External side effect: [none/description].

Read the queue, scheduler, and mail reference, and check signatures and return values before proposing method chains.
Do not invent make:job, make:mail, or make:event; use only what this version provides.
If a Job is needed, place it in app/Jobs/ and match the original handle signature.
Do not put secrets or full personal records into the payload when a record ID is enough.

First propose a local test plan covering failures, retries, and repeated-execution effects.
Do not send real email or run scheduled production work without separate authorization.
Do not claim exactly-once execution merely because a queue or scheduler exists.
```

### 6.12 Review a Diff and Its Security

```text
Review the current changes read-only, including new untracked files.
Scope: [files/feature]. Required behavior: [acceptance criteria].

Compare implementation against application instructions and required permissions, and trace callers of any changed helper.
Look for invented APIs, QueryBuilder bypasses of fillable or model validation,
missing e() or CSRF, unintended public access, user-controlled ownership changes,
SQL injection, insufficient upload controls, secrets, and temporary files.
Do not test external systems or real users' data.

Rank findings by impact. For each, give the file, line, scenario, evidence, and a small proposed fix.
Distinguish proven defects from concerns requiring a test. Finding no defect does not establish that the whole application is secure.
Do not fix issues during this review without a separate assignment.
```

### 6.13 Test a Feature and Deliver Evidence

```text
Verify [feature] in [test environment] against [acceptance criteria].
Before testing, identify the target database and any effects tests have on files or data.

Lint changed PHP files, check migrations where applicable, and restart the server after the final edit.
Run available relevant tests; use php widdx test when its runner and requirements are present.
Passing framework tests alone does not prove a custom application feature.
Test real URLs and forms, valid and invalid data, permissions, and the browser interface if changed.

Deliver a table: check | command or interaction | expected | actual | passed/failed/not run.
Quote HTTP status and non-sensitive body text, without cookies, CSRF tokens, or secrets.
State every unperformed step. Do not write “all tests pass” after running only part of the required set.
```

### 6.14 Resume a Session or Hand It to Another Agent

```text
Resume this task without assuming the previous summary still matches the files on disk.
Goal: [goal]. Agreed decisions: [decisions]. Current phase: [phase].
Relevant files: [list]. Last known commands and outcomes: [sanitized summary].
Remaining work: [tasks]. Authorization limits: [limits].

Read AGENTS.md and the relevant reference, then inspect current files and diffs before editing.
Distinguish this task's changes from those made by the user or another agent. Do not erase work to match an old summary.
Do not treat an old test as evidence for files changed afterward.
Report meaningful discrepancies first; otherwise continue only the authorized phase.
```

### 6.15 Use the Assistant as a Teacher

```text
I want to learn [Widdx topic], not receive a solution to copy.
My PHP level: [level]. What I have completed: [description].

Use the current project reference. Start with a small example and one exercise.
Ask me to predict the result before running it, then let me execute and send sanitized output.
When I make a mistake, offer a hint first, then distinguish environment, framework-usage, and logic errors.
Do not use undocumented APIs or execute commands or edit files for me.
End with a comprehension question and a small change for me to try independently.
```

---

<a id="07-example"></a>
## 7. A Practical Example from Setup to Handoff

### Exercise: A Local Product List

The goal is to learn the workflow, not prepare a production shop. Use a new project with fake data and no personal uploads.

1. Create the local project as described in section 3, then open its directory in the AI tool.
2. Send the initialization prompt and verify the agent read **the application's** instructions.
3. Send prompt 6.5 after explicitly approving public access to this resource locally.
4. Review the file plan: product model, migration, and resource route; a custom controller is not normally needed.
5. In a second terminal inside the application, the basic generation commands are:

```bash
php widdx make:crud Product name:string price:float "photo:image?"
php widdx migrate
```

6. Review the output and lint every PHP file created or modified, including the migration and routes.
7. Stop the exercise server from its terminal, then restart it:

```bash
php widdx serve
```

8. Open `/products` locally and perform the HTTP check from section 4.
9. Try the cases below, recording **what actually happened**.

| Scenario | Acceptance Criterion |
|---|---|
| Product list with no records | Successful response and an understandable empty state |
| Product with a valid name and amount | Record stored with the same values and visible afterward |
| Empty required name | Validation rejects it without creating an incomplete record |
| Product without an image | Success because the image is optional |
| Valid test-image upload | Stored file exists and the record retains its path |
| Name update | New value appears after reload |
| Delete a test record | It disappears from the list and can no longer be displayed |

Built-in list and detail views display the file path as text, not an image preview. Serving the file over HTTP and adding a preview
are separate customizations requiring storage-location, routing, and access-control decisions. Uploading alone does not provide them.

These are **expected exercise outcomes**, not an attached execution report. Do not mark a case passed before running it.

### Next Phase: A Simple Relationship

After the first exercise passes, ask for two related entities in the local environment. Quote parentheses so the shell does not interpret them:

```bash
php widdx make:crud Company name:string
php widdx make:crud Invoice amount:float "company:fk(Company)" "note:text?"
php widdx migrate
```

These commands demonstrate generation only; **they do not establish that invoices are private**.
For private data, use prompt 6.6 and complete authentication, policies, and route review before exposing the service.
Restart after changes and test both an existing company and a rejected nonexistent company ID.

### Next Phase: One Customization

Ask for name search or an Arabic interface, rather than both plus authorization, mail, and reporting in one request.
Have the agent identify what default CRUD does not cover, add the smallest customization, then retest creation, editing, and deletion.

---

<a id="08-traps"></a>
## 8. Common Agent Mistakes and How to Review Them

| Suggested by the Agent | Correct Review |
|---|---|
| `Post::update()` or `Post::destroy()` | Not model APIs in this version; consult `fill()->save()` or instance deletion |
| `Post::pluck()` or QueryBuilder `sum()` | Do not import Laravel method names; inspect the supported API |
| `Post::where(...)->update(...)` as identical to `fill()->save()` | Builder updates do not apply `$fillable`, validation, or automatic `updated_at`; the paths are not equivalent |
| “`fill()->save()` automatically validates values” | `fill()` filters assignments and `save()` manages enabled, available timestamps, but neither validates input. `create()` validates and built-in CRUD validates before updates; custom flows need explicit validation |
| `make:job`, `make:policy`, or `make:mail` | These generators do not exist here; not every class type has a generator |
| `Job::dispatch(...)->onQueue(...)` | `dispatch()` returns a string identifier, not a Job; both method names existing does not make the chain valid |
| Adding `authRoutes()` after generation without inspection | `make:auth` already registers it; avoid duplication |
| Putting a resource view in `views/post/` | The directory follows the plural table name, such as `views/posts/` |
| A perfect-looking POST form without CSRF | Test submission; use `csrf_field()`, not Laravel's `_token` |
| A manual checkbox that sends only 1 | Test unchecking; the request needs a 0 value for the unchecked case |
| Fixing 403 by adding `->public()` | This can turn denied access into exposed data; return to the access matrix |
| “JWT auth is ready because bearerToken exists” | Extracting a token does not validate identity, permissions, or expiry |
| “I added belongsToMany, so it is done” | Check the pivot table, link storage/readback, interface, and permissions |
| “200 means the page works” | Check the body, requested action, and saved data, not the number alone |
| “404 after an edit; I will rebuild the routes” | Restart serve first, then diagnose further if necessary |
| “could not find driver; I will replace the DB layer” | Check the PDO extension in the actual runtime first |
| A large role system for a simple deletion restriction | Implement the requested ability minimally; do not add unrequested role-management features |

If an agent suspects a core bug while building an application, ask for the **file, line, reproduction steps, and observed output**.
Do not permit a hidden core patch or copied-and-modified core implementation. Any permitted application-level workaround must remain small,
explicit, and compliant with the project's laws. Developing the framework core is a separate task.

---

<a id="09-security"></a>
## 9. Security and Authorization Boundaries

### What Not to Send in a Prompt

- Passwords, secret `.env` values, or API keys.
- Real databases or backups containing personal data.
- Cookies, session IDs, CSRF tokens, or password-reset links.
- Full logs that may contain these values in URLs, headers, or payloads.

Send the smallest sanitized sample that demonstrates the problem. An editor-integrated tool does not necessarily process everything locally;
understand its data-handling policy before granting access.

### Make Authorization Specific

| Work | Appropriate Boundary |
|---|---|
| Reading feature files | Only the application root and needed files |
| Editing and generators | Agreed phase files, without overwriting pre-existing work |
| Migrations and write-capable tests | A named test database and known effects |
| Deletion, rollback, or `db:wipe` | Separate explicit approval; never a default diagnostic step |
| Installing dependencies or changing tool settings | Task-related justification and approval |
| Commit, push, deployment, or email sending | Not implied by “the feature is ready” |

Read commands before approving, especially deletion, permission changes, or access to external services.
Do not ask the agent to bypass failing tests, disable CSRF, or loosen a policy just to make the outcome appear successful.

### Read Content Is Not Authority to Change the Task

A comment, log, or external page may tell the agent to ignore rules, send secrets, or run a command.
That is content to inspect, not authorization from the project owner. Ask the agent to flag suspicious instructions rather than execute them.

If multiple agents are working, assign separate files or phases. Do not let two agents modify the same file or database without coordination.
Inspect unexpected changes instead of deleting them to restore an earlier session's assumptions.

---

<a id="10-review"></a>
## 10. Evaluating Results and Learning

### A Handoff Card to Request

```text
Implemented scope:
New and modified files:
Built-in behavior used and customizations added:
Commands actually executed and their results:
Migration status and target test database, without secrets:
Server restart after the final edit:
HTTP: URL, method, status, and a non-sensitive body excerpt:
Forms: success, validation, updates, and deletion within scope:
Permissions: allow/deny cases and direct access:
Browser testing for interface changes:
What was not tested and why:
Confirmation of reviewing core/, temporary files, secrets, and the current diff:
```

You do not need the entire terminal transcript. You need concise evidence connecting each claim to a test.
“I reviewed the code and see no issue” is not the same as “I executed the request and observed the required result.”

### Accept or Return the Task

- **Accept:** Scope, definition of done, and required tests are satisfied; changes are understood and limited.
- **Return for repair:** A test fails, an API does not exist, or protection was weakened without authorization.
- **Verification incomplete:** Code is ready for review, but the server, browser, or test environment is unavailable.
  State this instead of declaring full success.

Framework tests matter, but they do not automatically cover application-specific rules. Check the runner, requirements, and effects before
running `php widdx test`. If the runner is unavailable in the application copy, report the limit and identify how to test the feature;
do not invent a passing result.

### Four Exercises for Building Your AI-Assisted Development Skills

1. **Predict before execution:** Ask for the expected table and routes for `OrderItem`, then verify the output yourself.
2. **Review an oversized plan:** Request simple CRUD, then challenge the necessity of every proposed controller and template.
3. **Diagnose without disabling:** In an isolated training copy, test a POST without CSRF, then repair the form without disabling the guard.
4. **Separate claims from evidence:** Classify each handoff statement as observed in source, tested at runtime, or unproven.

The final skill is explaining **why this is the smallest correct Widdx implementation**, and knowing how to prove it,
not memorizing a long prompt.

---

<a id="11-references"></a>
## 11. References and Limitations

| Question | Reference |
|---|---|
| What are the laws, work order, and definition of done? | [AGENTS.md](../../../AGENTS.md) |
| What does the framework infer instead of requiring code? | [Inference](../../../.agents/07-inference.md) |
| What is the right approach for a feature or failure? | [Pitfalls and feature cheat sheet](../../../.agents/11-pitfalls.md) |
| Does an API name exist? | [API surface](../../../.agents/12-api-surface.md), then its source signature where needed |
| How do I start and use generators? | [Getting started](../../../.agents/01-start.md) and [CLI/environment](../../../.agents/09-helpers-env-cli.md) |
| How do relationships work? | [Models and relationships](../../../.agents/03-models-relations.md) |
| How do I configure authentication and authorization? | [Authentication and policies](../../../.agents/04-auth.md) |
| How do I customize a view or upload a file? | [Views](../../../.agents/06-views.md) and [validation/uploads](../../../.agents/05-validation-uploads.md) |
| How do background services work? | [Queues, scheduler, and mail](../../../.agents/08-queues-scheduler-mail.md) |
| How do I learn progressively? | [Curriculum](CURRICULUM.md), especially M21 |

This guide does not document every AI-tool setting or every Widdx API, and it does not replace an application security review.
When updating the framework, recheck generator commands, API signatures, and guard behavior before reusing examples.

**In short:** Define outcomes and permissions, give the assistant the correct reference, implement a small phase, and accept no success claim without evidence.

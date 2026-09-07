# Karya MCP implementation report

Implemented against the existing working tree. No commit or push. Existing Passport, endpoint, tunnel, keys, OAuth clients, environment secrets and Claude configuration were preserved. Existing uncommitted work was retained.

## 1. Files created

- `app/Mcp/AdminAccess.php`
- `app/Mcp/TaskFields.php`
- `app/Mcp/Tools/AdminTool.php`
- `app/Mcp/Tools/UpdateTaskTool.php`
- `app/Mcp/Tools/UpdateProjectTool.php`
- `app/Mcp/Tools/DeleteProjectTool.php`
- `app/Mcp/Tools/ListClientsTool.php`
- `app/Mcp/Tools/CreateClientTool.php`
- `app/Mcp/Tools/UpdateClientTool.php`
- `app/Mcp/Tools/DeleteClientTool.php`
- `app/Mcp/Tools/ListDepartmentsTool.php`
- `app/Mcp/Tools/CreateDepartmentTool.php`
- `app/Mcp/Tools/UpdateDepartmentTool.php`
- `app/Mcp/Tools/DeleteDepartmentTool.php`
- `app/Mcp/Tools/DeleteTeamMemberTool.php`
- `tests/Feature/McpToolAssertions.php`
- `MCP_IMPLEMENTATION_REPORT.md`

## 2. Files modified during this task

- `app/Mcp/Servers/KaryaServer.php`
- `app/Mcp/Tools/ListProjectsTool.php`
- `app/Mcp/Tools/ListTasksTool.php`
- `app/Mcp/Tools/ListTeamMembersTool.php`
- `app/Mcp/Tools/AssignTaskTool.php`
- `app/Mcp/Tools/UpdateTaskStatusTool.php`
- `app/Mcp/Tools/UpdateTaskProgressTool.php`
- `app/Mcp/Tools/CreateTaskTool.php`
- `app/Mcp/Tools/DeleteTaskTool.php`
- `app/Mcp/Tools/CreateProjectTool.php`
- `app/Services/DashboardTaskOperations.php`
- `tests/Feature/TaskApiTest.php`
- `tests/Feature/CompoundTaskAssertions.php`

The nine original MCP tools were already present but untracked on arrival. They are modifications, not new implementations. Other pre-existing Git changes are not part of this task.

## 3. All 21 registered MCP tools

| Area | Tools |
| --- | --- |
| Tasks | ListTasksTool, CreateTaskTool, UpdateTaskTool, AssignTaskTool, UpdateTaskStatusTool, UpdateTaskProgressTool, DeleteTaskTool |
| Projects | ListProjectsTool, CreateProjectTool, UpdateProjectTool, DeleteProjectTool |
| Clients | ListClientsTool, CreateClientTool, UpdateClientTool, DeleteClientTool |
| Departments | ListDepartmentsTool, CreateDepartmentTool, UpdateDepartmentTool, DeleteDepartmentTool |
| Team | ListTeamMembersTool, DeleteTeamMemberTool |

Laravel MCP exposes their kebab-case class names, including the `-tool` suffix, for example `update-project-tool`.

## 4. Supported dashboard management

Claude can resolve entity IDs; create, edit and delete tasks; assign/reassign/unassign tasks; manage status, progress, relationships and recurrence; create projects with an initial task; atomically edit projects and add/edit/delete project tasks; manage client profiles and their project links; create client logins with existing after-commit welcome email; manage departments and rename affected task/user departments; and delete team accounts with assignment cleanup.

`ListTeamMembersTool` retains its default team-only response and accepts `include_admins: true` to include eligible admin assignees. `AssignTaskTool` accepts an empty assignee array to unassign everyone. Normal task editing does not accept dedicated status/progress/assignment fields. Existing attachment data is preserved when editing other fields.

New write adapters use StateConcurrency and existing DashboardTaskOperations/TaskService. Old MCP task writes now recheck MCP authorization inside the transaction. Every tool checks the Passport actor from `$request->user('api')`, current database admin role, role_id 1, and KARYA_MCP_ALLOWED_ADMIN_IDS. No account fallback exists. Read tools now enforce the same authorization.

Client and department operations return entity summaries so MCP does not infer generated IDs. New high-level project/client/department/member deletion activities and department create/update activities name the authenticated actor. Existing task histories, notifications, assignment emails and cleanup remain in the domain services.

## 5. Intentionally not implemented

- Team-member create/update/password change: the dashboard uses StateController's full-state replacement, not an isolated account operation. That path rewrites unrelated messages, history, notifications, settings and delegation rules and depends on session authentication. No parallel account writer or fabricated session was introduced. A separately reviewed extraction into a shared account operation is needed first.
- Client password changes: explicitly prohibited by the existing client update operation.
- Full-state settings, delegation rules and chat editing: no safe targeted admin operation for their persistence; no full-state MCP replacement added.
- Client voice briefs: the existing operation explicitly requires the client role, so it is not exposed as an admin tool.
- Calendar OAuth, file upload flows, maintenance/reset operations and attachment editing: not added to the admin management tools in this change.

## 6. Existing backend limitations preserved

- Project saves require at least one submitted task and reset project due_date_ms to null. Project status and project-level due dates have no supported editable fields in this operation. An empty project needs a new task before this operation can edit it.
- The client form's project selector manages the first existing linked project plus the selected project; it is not a general multi-project replacement operation.
- Department deletion refuses departments containing team members and moves tasks to the alphabetically first remaining department, or General. It does not rewrite non-team users' department strings. Rename does update affected users.
- Notification records include existing simulated outbound notifications; MCP does not introduce new delivery infrastructure.
- Existing list tools are unpaginated; large installations may need a separate pagination change.
- Activity records attribute names in text; the existing activity schema does not store a separate actor ID. MCP responses include both ID and name.

## 7. PHP tests

Final full suite: **59 passed, 2 skipped, 692 assertions**. Includes nine new MCP tests covering every tool's authentication/role/allowlist checks, actual MCP schema serialization, an OAuth-guard HTTP tool call, all original tools, authenticated actor attribution, task changes, recurrence, project rollback after earlier writes/deletions, client rollback, client secret exclusion, member/department cleanup, and mail failures after successful commit.

The two skipped tests require the existing guarded dedicated MySQL test database and verify MySQL mutex/JSON behavior. SQLite transaction/rollback coverage passed.

The first plain `php artisan test` attempt failed because this CLI does not load pdo_sqlite by default. The installed PHP 8.4 SQLite DLLs were enabled via a temporary PHP_INI_SCAN_DIR for the test process and its Artisan child process. Final `php artisan test --compact` passed. No global PHP configuration or application environment file was changed. Future local test runs need SQLite enabled in their PHP process, or the existing dedicated MySQL test setup.

## 8. JavaScript tests

Not applicable: no JavaScript changed. Dashboard JavaScript form/store flows were inspected to confirm which backend operations they use.

## 9. Syntax and routes

`php -l` passed for all **29 changed PHP files**. `php artisan route:list` was inspected (58 routes). A final focused verbose listing confirmed the existing `/mcp/karya` POST endpoint retains `auth:api` alongside the MCP middleware.

## 10. Migrations

None required by this implementation. Existing untracked Passport migrations were left unchanged.

## 11. Security concerns and production TODOs

- Existing welcome emails contain the initial plaintext password. This behavior was deliberately retained. MCP client output contains neither passwords nor hashes, and new adapters do not expose raw database/mail exceptions. Consider a separate invitation/reset-link onboarding change. Client creation inputs still pass through connector infrastructure, so credential handling there needs operational care.
- Run the two guarded MySQL tests before production rollout, and verify actual assignment/welcome email delivery and cleanup behavior in the deployment environment.
- HTTP tests use Passport's authenticated testing guard; actual remote bearer-token issuance/refresh and Claude interoperability require manual connector testing.
- Configure the existing allowlist for the intended admins in production. An empty allowlist retains the existing policy of allowing any otherwise eligible admin.

## 12. Manual Claude connector testing

**Ready for manual testing**, with the preceding environment-specific checks outstanding. Refresh the connector's tools, verify all 21 appear, then use disposable entities to exercise create/edit/delete flows. Check actor names with distinct authorized admins; validate that a project request containing a later invalid task leaves no partial changes; confirm welcome/assignment failure arrays and deletion histories. No production deployment or live remote mutation was performed here.

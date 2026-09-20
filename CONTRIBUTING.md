# Team Development Rules

## Before starting

1. Agree on the task owner and affected files.
2. Update local `main` before creating a branch.
3. Create one branch per task, such as `feature/campaign-form` or `fix/reward-total`.

## While working

- Do not commit directly to `main`.
- Keep each change focused and make small commits.
- Never commit production databases, member data, receipts, sessions, passwords, tokens, or credentials.
- Pull the latest `main` before opening a pull request and resolve any conflicts on the task branch.

## Pull requests

1. Describe what changed and how it was tested.
2. Request review from at least one other team member.
3. Address review comments and verify the application again.
4. Merge only after approval and all conflicts are resolved.

## Conflict resolution

When two branches change the same lines, the author of the later pull request resolves the conflict together with the other author. Preserve the intended behavior from both changes where possible, then test the combined result before merging.

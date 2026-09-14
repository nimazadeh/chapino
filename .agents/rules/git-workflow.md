# Rule: Git Workflow

**Rule ID:** `git-workflow`
**Applies to:** every commit, branch, history and remote operation.
**Canonical owner of:** git hygiene, commit discipline, destructive-operation protection.
**See also:** [`code-review`](code-review.md), [`reporting`](reporting.md).

---

## 1. Before major work

1. Run `git status` and read it. Know what is modified, staged, untracked and ignored.
2. Know the current branch and its relationship to the remote (`git branch -vv`, `git log --oneline`).
3. If the working tree contains changes you did not make, **do not touch them**. Work around them or
   ask. Never stash, discard or commit another party's work without explicit instruction.
4. Confirm the change is happening in the right place; environments with a mandated working branch
   must be respected (see section 6).

## 2. Commit discipline

- One commit = one coherent change. Do not mix refactoring, formatting and behaviour change.
- Stage deliberately (named paths) rather than `git add -A` sweeping in unrelated files.
- Never commit: secrets, `.env` files, credentials, personal data, build output, dependency
  directories, editor/OS noise, large binaries. Check the staged diff before committing.
- Review the staged diff (`git diff --cached`) and confirm it matches the reviewed change.
- Commit messages: imperative subject, under ~72 characters, a body that explains **why** and the
  scope, when the subject alone is insufficient.
  - Format: `type(scope): subject` - e.g. `feat(auth): reject expired session tokens`.
  - Types: `feat`, `fix`, `refactor`, `perf`, `test`, `docs`, `chore`, `build`, `ci`, `security`.
  - Never: "fix", "update", "changes", "wip", "asdf".

## 3. Protecting existing work

- Never discard uncommitted changes you did not create.
- Never rewrite published history (`rebase` on shared branches, `commit --amend` on pushed commits).
- Never resolve a conflict by wholesale choosing one side without understanding both.
- When a conflict or an unexpected state appears, stop and report it rather than forcing a result.

## 4. Destructive commands - explicit authorization required

The following require an explicit instruction from the owner for that specific operation, and must
never be run as a convenience:

- `git reset --hard`, `git checkout -- .`, `git restore .` (discards work)
- `git clean -fd` / `-fdx` (deletes untracked files)
- `git push --force` / `--force-with-lease`
- `git rebase` on shared or pushed history, `git filter-branch`, `git reflog`-based recovery rewrites
- deleting or renaming branches or tags that others may have
- `git branch -D`, `git push origin --delete`

If history surgery is genuinely required (for example, a secret was committed), explain the
consequence, obtain authorization, and prefer a forward fix (rotating the credential) over rewriting
shared history.

## 5. Branching and pull requests

- Work on the branch designated for the task; do not create alternate branches unless instructed.
- Open a pull request only when asked. Do not merge, do not auto-merge, do not push to a protected
  branch directly.
- Before opening a PR: verify the branch is up to date with its base, the diff contains only the
  intended change, and the description states what was verified and what was not.

## 6. Environment note

This repository is being worked on under a mandated working branch. In such an environment:

- Commit and push only to that branch;
- Do not create, switch to, or push to another branch;
- Report the branch, commit hash and message in the task report.

## 7. Checklist

- [ ] `git status` read before and after the work.
- [ ] Working branch correct; no unrelated branch touched.
- [ ] No pre-existing uncommitted work disturbed.
- [ ] Staged diff matches the reviewed change and contains no secrets or generated noise.
- [ ] Commit(s) are focused with meaningful messages.
- [ ] No destructive command run without explicit authorization.
- [ ] Final report states branch, commit hash(es) and message(s).

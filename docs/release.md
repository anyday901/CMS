# Releasing

Releases follow [Semantic Versioning](https://semver.org/) and are tagged `vX.Y.Z` on `main`. [CHANGELOG.md](../CHANGELOG.md) collects changes under **Unreleased** as they are merged.

## v1.0.0 checklist

v1.0.0 is the first public release. Per the [spec](spec.md), it follows the switchover from WHMCS, so it ships with the billing core proven on real data.

Before tagging:

- [ ] Decide the license: AGPL-3.0-or-later (current) or MIT. See the open question in the spec.
- [ ] Import Corey's WHMCS data with `php artisan whmcs:import --dry-run`, then for real, with every total matching or explained.
- [ ] Run a side-by-side billing cycle with `php artisan whmcs:compare` and resolve every line that differs.
- [ ] Test PayPal, Venmo and Cash App with real sandbox accounts, then one small live payment each.
- [ ] Switch Corey's business over and run it for at least one full billing cycle.
- [ ] Decide which of the remaining milestone 2 to 4 items in [TODO.md](../TODO.md) are needed for 1.0 (client 2FA, client sign-up and ordering, Harbor colors on PDFs, Corey's custom provisioning module) and which move to 1.1.
- [ ] Remove the "Status: early development" note from the README.
- [ ] Install from scratch with [docs/install.md](install.md) and with Docker Compose, following the steps exactly.
- [ ] CI and SonarCloud green on `main`.

## Cutting a release

1. In `CHANGELOG.md`, rename **Unreleased** to `[1.0.0] - YYYY-MM-DD`, add a new empty **Unreleased** section above it, and update the comparison links at the bottom if there are any.
2. Commit that on a branch, open a pull request and merge it once CI is green.
3. Tag the merge commit and push the tag:

   ```sh
   git checkout main && git pull
   git tag -a v1.0.0 -m "v1.0.0"
   git push origin v1.0.0
   ```

4. Create a GitHub release from the tag, with that version's changelog section as the notes, and mark it as the latest release.
5. Tell people: the README, the docs and any announcement link to the release and to [docs/install.md](install.md).

Steps 3 to 5 are public and can't be taken back, so they wait for Corey's go-ahead.

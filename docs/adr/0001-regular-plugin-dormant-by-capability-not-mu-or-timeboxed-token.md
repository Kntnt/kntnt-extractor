# A regular plugin, made safe to forget through capability gating rather than an MU-plugin or a time-boxed token

The operator wants a plugin that is installed once and forgotten, with extremely high security requirements — but not at the cost of losing WordPress's own update mechanism. An MU-plugin cannot be deactivated or updated through the Plugins screen at all, so every version bump would require a manual file replacement forever; that trade is worse than the "forget it" property it buys. A regular, self-updating plugin can be forgotten just as safely if its runtime surface is dormant by construction rather than by hiding it from WordPress's own plugin management.

A separate design — a narrow, always-on credential that could only "arm" a short-lived token for the real endpoints — was considered and rejected. Given the whole flow must run unattended (no human clicks a button on the site before each run), an automated client can go arm → token → execute in one round trip, so a leaked arming credential has the same blast radius as a leaked full credential. The token layer would have shortened the exposure window between sessions but done nothing for a credential compromise, which is the threat that actually matters here. See [0002](./0002-authn-app-password-authz-operate-plus-manage-options.md) for what replaced it.

## Consequences

- Kntnt Extractor ships as a normal plugin with a self-hosted update checker ([0005](./0005-github-releases-self-hosted-update-checker.md)), not an MU-plugin.
- There is no "arm" step and no session-scoped token anywhere in the design.

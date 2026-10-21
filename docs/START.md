# Starting Creative Search with Claude Code

The same shape as Creative Stockroom: a repo, the spec in `docs/`, one milestone per pull request, each reviewed against the agency's matrix before the next starts.

## 1. The repo

```
mkdir -p ~/web-projects/creative-search/docs
cd ~/web-projects/creative-search
git init
cp "~/tmp/Claude outputs/creative-search-docs/SPEC.md" docs/SPEC.md
git add docs/SPEC.md && git commit -m "Creative Search: research and build spec"
```

Connect the folder to this chat (Add folder in the Claude app) so milestone reviews can read the code the way the Stockroom reviews did.

## 2. Answer the five questions first

They are at the end of `docs/SPEC.md` under "Open questions, for Zach, before milestone 1": repo name, licence and sale, posts and pages in 1.0 or 1.1, recent searches on or off by default, and whether to buy one FiboSearch Pro licence for the benchmark day. Put the answers in `docs/DECISIONS.md` as the first entries so Claude Code starts from them.

## 3. The first prompt for Claude Code

Paste this into Claude Code in the repo:

> Read docs/SPEC.md in full before writing anything. It is the build spec for Creative Search, a WooCommerce search plugin. Build it milestone by milestone as the "Milestones for Claude Code" section lays out, starting with milestone 1 (Foundation), and stop at the end of each milestone for review: every milestone ends with every CI gate in the "Quality bar" section green, the agency's seven-dimension review written into the pull request, a CHANGELOG.md entry and a tag. Conventions: PSR-4 under src/, one class per concern, repositories own tables and services own rules, nothing in the plugin's root file but the bootstrap, docs/HOOKS.md and docs/SETTINGS.md kept current as hooks and settings appear, docs/DECISIONS.md for every decision you make yourself with its reason. Every WordPress standard applies in full (the spec names them). Do not start milestone 2 until I say so.

Then, after each review: "Milestone N is approved; build milestone N+1."

## 4. Review cadence

After each milestone, send me the IDE's summary (as you did for Stockroom) and I review the code against the spec and the matrix: blockers, should-fix, minor, with file and line. Fixes go in before the next milestone starts. Milestone 8 ends with the manual accessibility pass (the script in docs/A11Y.md, run by someone who did not write the code) and the FiboSearch benchmark day.

## 5. What the spec leaves to the owner

Synonyms to seed, boosts, hidden-product search, no-results fallbacks and retention are the owner's; they are needed by milestone 7 and are listed under "Open questions, for the owner" in the spec.

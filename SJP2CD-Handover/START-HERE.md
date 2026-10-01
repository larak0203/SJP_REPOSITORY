# Start here

Copies of everything a new chat needs to understand the SJP2CD repository project,
gathered in one place so they are easy to attach.

**The originals live in `C:\xampp\htdocs\sjp2cd-repository`.** These are copies made on
1 October 2026. If work continues in the project, copy them across again — a stale
handover is worse than none, because it reads as current.

---

## If the new chat is Claude Code

Do not attach anything. Open it on the folder itself:

```
C:\xampp\htdocs\sjp2cd-repository
```

`CLAUDE.md` loads by itself, and it can read every other file as it needs them. Then say:

> Read HANDOFF.md and SPEC.md, then tell me what's still open.

## If the new chat is the Claude website

It cannot see the computer, so attach from this folder:

| Always | `HANDOFF.md`, `CLAUDE.md`, `SPEC.md` |
|---|---|
| Working on the paper | add `PAPER-CHANGES.md`, `GAP-ANALYSIS.md`, and the paper itself from `Documents\Capstone Research` |
| Working on Google Sign-In | add `GOOGLE-SIGNIN.md` |

For anything code-related you must also paste the file being changed.

**It can advise, but it cannot run anything** — no editing files, no querying the
database, no testing. Most of the defects found in this project were found by running
things, not by reading code. For real changes, use Claude Code on the folder.

---

## What each file is

| File | What it holds |
|---|---|
| `HANDOFF.md` | Where the project stands, who can do what, everything still open |
| `CLAUDE.md` | How to work in the project: paths, commands, house style, known traps |
| `SPEC.md` | Every decision and the reasoning behind it. The important one |
| `PAPER-CHANGES.md` | Edits the research paper still needs |
| `GAP-ANALYSIS.md` | Where the paper and the system disagreed, and what was done |
| `GOOGLE-SIGNIN.md` | Google Sign-In setup, and what to do when it fails |
| `ASSISTANT-LLM.md` | The optional language model behind the chatbot, and why it is off |

---

## The two things most likely to be forgotten

1. **Faculty signing in with Google cannot request faculty access.** The request
   checkbox only exists on the password form, which faculty no longer use. Until this is
   built, the librarian has to notice them and grant the role by hand.
2. **The weekly maintenance task is not scheduled.** Until it is, backups and integrity
   checks only happen when someone runs them.

Both are explained in `HANDOFF.md`.

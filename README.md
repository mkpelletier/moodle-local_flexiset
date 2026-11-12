# FlexiSet (`local_flexiset`)

FlexiSet grades a quiz from a required core plus one or more optional sections. In an optional section the student answers at least N of M questions. The grade uses the answers that count, over the maximum of those answers. Questions outside every FlexiSet group stay in the total in full.

This plugin owns the groups, the grade rewrite, and the Question groups page. The companion access rule, [`quizaccess_flexiset`](../moodle-quizaccessrule_flexiset/README.md), owns the on/off switch on the quiz form and the text students see. Each plugin is useless without the other. `quizaccess_flexiset` declares a dependency on this one.

Release 1.0.0 (beta). Version `2026101600`. Requires Moodle 5.1.0 (`2025100600`) and supports 5.1 through 5.3. GPL v3 or later. Copyright 2026 South African Theological Seminary.

## Install

Moodle expects this code at `local/flexiset`. On the development site that path is a symlink into this repository, and `mod/quiz/accessrule/flexiset` is a symlink to the access rule. Moodle 5.1+ keeps `config.php` one directory above the public web root, and a symlinked plugin sits outside the Moodle tree, so `groups.php` searches for `config.php` from the document root before it uses `__DIR__`.

There is no site administration screen. Every setting is per quiz.

Capability `local/flexiset:manage` is a write capability on the quiz module. Editing teacher and manager have it. It gates the Question groups page and the teacher-only warnings.

## User story

A quiz has 15 required true/false questions, then Section C with five essays. The teacher wants students to answer at least two essays, and wants the best two to count. The quiz grade is out of 100. One student gets 12 true/false questions right and essays marked 7 and 8. Counted marks are 39 out of an applicable maximum of 50, so the gradebook shows 78. The arithmetic is in [The grade](#the-grade). The sentences on screen come from the access rule.

### What the teacher sees

The teacher builds the two sections, then edits the quiz. Under **Extra restrictions**, **Use FlexiSet** is Yes and **When a student answers fewer than the minimum** is "Score missing answers as zero".

**Question groups** is in the quiz activity menu. A student does not see it. The page shows "Use FlexiSet: Yes. When a group is short: Score missing answers as zero." and **Edit in quiz settings**. The teacher adds a group on Section C named Essays, mode Best answers, minimum 2, answers that count 2. The table shows Essays, Best answers, 2, 2, 5 questions, status OK. Saving the group recalculates finished attempts. Deleting it asks for confirmation and does the same.

On the quiz page the info box shows "Essays: answer at least 2 of 5. The best 2 count."

The gradebook and the quiz reports show 78.00 out of 100.00 once the essays are marked. The attempt review shows Moodle's Grade row at 78.00/100.00 and an extra row, "FlexiSet grade", reading "39.00 / 50.00 (78.00%)". Each question still shows its own mark. The review does not say which essays were selected. A weaker third essay still shows the mark it was given.

An essay that still needs a mark leaves the attempt with no grade. The FlexiSet row says "Not yet graded". Marking it from the manual grading screen recalculates the total.

A teacher preview keeps the ordinary question total. The FlexiSet row can still appear on that preview's review page, because the row is calculated for display. FlexiSet stores no result row for a preview, and the preview does not enter the gradebook.

If the teacher adds a quiz grade item, or a group no longer matches its section, the info box shows a warning in place of the rule sentence. The status column on Question groups names the reason: the section was deleted, N or K no longer fits, the mode is unrecognised, or two groups share a question. Finished attempts return to the ordinary question total. Students see no FlexiSet sentence and no FlexiSet row.

### What the student sees

Before **Attempt quiz**, the info box includes "Essays: answer at least 2 of 5. The best 2 count." The sentence uses the group name. During the attempt, Section C still shows the quiz's own section heading.

The question pages are an ordinary quiz. The student may answer two essays, more than two, or none. Submit is never blocked for being short.

The summary page lists each question and its status. Above that list, an info alert titled "Optional questions" reads "Essays: you have answered 2 of at least 2 (5 questions)." The student can still submit when the count is short of the minimum.

When review is allowed, the student sees Grade 78.00 out of 100.00 and "FlexiSet grade 39.00 / 50.00 (78.00%)". The gradebook shows 78.00. A single essay marked 8 produces Grade 64.00 out of 100.00 and "32.00 / 50.00 (64.00%)": the missing essay scores zero and still fills one place in the maximum. On a quiz set to keep the normal total, that short attempt has no FlexiSet row and Grade stays at the ordinary question total, 40.00 out of 100.00.

## What a teacher sets up

1. Build the quiz in sections. One section is the pool for one FlexiSet group. Questions in sections with no group are required.
2. Turn FlexiSet on under **Extra restrictions** on the quiz settings form, and choose what happens when a student answers fewer than N. The access rule writes those two fields through `local_flexiset\api`.
3. Open **Question groups** in the quiz activity menu. That page lists the switch as read-only and links back to the quiz form. Add a group per optional section.

A group has:

| Field | Meaning |
| --- | --- |
| Section | The quiz section whose real questions form the pool. One group per section. The section is fixed after the group is saved. |
| Name | Student-facing label. Blank uses the section heading. A blank heading is shown as "Default section". |
| Which answers count | Best answers, First answers, or All answers. |
| Minimum answers (N) | How many questions in the section the student must answer. From 1 to M, where M is the number of real questions in the section. |
| Answers that count (K) | How many of those answers enter the grade. Blank, or from N to M. |

Description questions are not real questions and are left out of M. Saving or deleting a group rechecks every group on the quiz and queues a regrade of finished attempts. A group can be saved while the switch is still off. That inserts a disabled settings row so the group has somewhere to live.

If N equals M, the form says every question in that section is required.

## The grade

FlexiSet rewrites `quiz_attempts.sumgrades` for a finished, non-preview attempt. It stores the counted marks as `raw` and the counted maximum as `appmax` in `local_flexiset_result`. Then:

```text
sumgrades = raw / appmax * quiz.sumgrades
```

Moodle's normal rescale turns that into the gradebook grade:

```text
grade = sumgrades / quiz.sumgrades * quiz.grade
      = raw / appmax * quiz.grade
```

So the percentage in the gradebook is `raw / appmax`. The review line shows `raw / appmax` and that percentage. It shows "Not yet graded" while a question still needs a mark.

`appmax` of zero writes `sumgrades` of zero.

### Which slots count

An answer counts when its state is anything other than not started, invalid, unprocessed, or gave up. `needsgrading` counts as answered. `mangaveup` is a gave-up state and does not count.

Slots in no group always count: their mark (zero when unanswered) and their full max mark.

For each group the plugin picks up to K answered slots:

- **Best answers.** Sort by fraction, highest first. An equal fraction keeps the earlier slot. Blank K means K = N.
- **First answers.** Walk the section in slot order, skip unanswered slots, and keep the first K. Blank K means K = N.
- **All answers, K blank.** Every answered slot counts. Unanswered slots stay out of both the marks and the maximum, unless the student is short of N.
- **All answers, K set.** The best K, with the same ordering as Best answers.

The marks of the picked slots are `raw` for that group. Their max marks are the group's part of `appmax`.

### Short of N

`missing = N - (answers picked)`. When `missing` is zero, unanswered slots in the group add nothing.

When `missing` is greater than zero, the quiz's shortfall policy decides:

- **Score missing answers as zero** (`zero`, the default). Each missing answer adds zero to `raw` and adds one filler to `appmax`. The filler is the largest max mark among unanswered slots in the group. If every unanswered slot has max mark zero, the filler is the largest max mark of a slot that was not picked. Only the missing count is filled. The other unanswered questions in a larger pool stay out.
- **Keep the normal quiz total** (`core`). The attempt keeps the question-usage total. `raw` and `appmax` are stored as null. The whole attempt uses this fallback as soon as any group is short.

### Worked example

The spec quiz in `tests/rescaler_test.php` is graded out of 100. It has 15 true/false questions worth 2 each, then five essays worth 10 each in Section C. `quiz.sumgrades` is 80. The Section C group is Best answers, N = 2, K = 2, shortfall zero.

A student gets 12 true/false questions right (24 marks) and essays marked 7 and 8. The other three essays are blank.

| Part | Marks | Maximum |
| --- | --- | --- |
| 15 true/false questions | 24 | 30 |
| Best two essays | 15 | 20 |
| Total | 39 | 50 |

`sumgrades` is `39 / 50 * 80 = 62.4`. The gradebook grade is 78.

The same paper with one essay marked 8 is short by one. The filler is 10, an unanswered essay's max mark. Marks are 32, maximum is still 50. `sumgrades` is `32 / 50 * 80 = 51.2`, and the grade is 64. The question-usage total of that attempt is 32, which would have been 40% of the quiz. Shortfall `core` on the same attempt leaves `sumgrades` at 32 and the grade at 40.

## When FlexiSet writes the question total back

A finished attempt keeps the question-usage total, and a later change that removes the reason writes the FlexiSet total again, when any of these is true:

- The switch is off.
- The quiz has a FlexiSet row and no groups.
- Any group is invalid.
- The quiz has extra grade items (`quiz_grade_items`). Those item totals are built from raw question marks, so FlexiSet leaves the attempt on the normal total. This does not mark the group invalid.
- A group slot is absent from this attempt.
- Shortfall is `core` and the student is short of N in any group.

A quiz with no FlexiSet row is left untouched. No result row is written.

Preview attempts are left untouched, including by the nightly repair. An attempt that still has a question in `needsgrading` with a max mark above zero keeps `sumgrades` null. A result row is stored with null figures, and the review line says the attempt is not yet graded.

## Invalid groups

`groups::resolve` checks the live quiz and does not trust the stored flag. `groups::revalidate` writes the flag back onto the group and onto `local_flexiset_quiz`. The Question groups table shows the reason.

| Reason | Cause |
| --- | --- |
| `missingsection` | The section id no longer exists. |
| `bounds` | N or K no longer fits the questions left in the section. |
| `mode` | The stored mode is not best, first, or all. |
| `overlap` | Two groups contain the same slot. Both groups are flagged. |

While any group is invalid, students see no FlexiSet sentences and attempts use the question total. A teacher who can manage FlexiSet sees "FlexiSet is on, but a question group does not match the quiz" on the quiz page. Extra grade items produce their own teacher warning and the same grade fallback, with the invalid flag left at zero.

## Gradebook and late penalties

`apply_attempt` writes `sumgrades` only when the new value differs. A changed value clears an overridden grade for that user on the quiz's main grade item (`overridden` back to 0, `finalgrade` null) and pushes the gradebook again. A second pass that finds the same `sumgrades` leaves an override in place.

That order matters for `quizaccess_duedate`. FlexiSet listens at observer priority 10000. The due-date rule listens at 9999, so it reads the FlexiSet total and can lock a late penalty on top of it. The next FlexiSet pass sees an unchanged `sumgrades` and does not unlock that penalty.

Bulk regrade and max-mark edits overwrite `sumgrades` in SQL after the per-attempt events. FlexiSet therefore applies once on the event and queues the quiz again. The queue flushes at the end of the request, and an adhoc task (`local_flexiset\task\reapply_quiz`) repeats the write if the request dies first. The scheduled task `local_flexiset\task\repair` runs each night at a random minute during 03:00 and reapplies every quiz that has a FlexiSet row.

The gradebook push sets a guard so the events fired by that push do not re-enter `apply_attempt`.

## Data

| Table | Holds |
| --- | --- |
| `local_flexiset_quiz` | One row per quiz: `enabled`, `shortfall` (`zero` or `core`), the invalid flag. `quizid` is unique and is not a foreign key, so deleting the quiz cannot be blocked by this row. |
| `local_flexiset_group` | Section id, optional name, mode, N, K (`maxcount` null when K is blank), invalid flag, sort order. Unique on `(quizid, sectionid)`. The section id is not a foreign key. |
| `local_flexiset_result` | Latest figures for one finished attempt: `raw`, `appmax`, `coretotal` (the question-usage total before the rewrite), `sumgrades`, `timemodified`. Unique on `attemptid`. |

Deleting the quiz activity deletes all three. Deleting an attempt deletes its result row. Turning the switch off keeps the groups.

## Backup and restore

A quiz backup includes the settings row and the groups. Result rows stay behind. They are attempt grades and are rebuilt on the next regrade.

Restore holds the rows until the quiz exists, then remaps each `sectionid` through the `quiz_section` mapping. A section that did not come back is stored as the negative of the old section id, so two lost sections cannot collide on the unique index, and revalidate flags them. Restore then queues a regrade.

## Privacy

The settings and the groups are not personal data. `local_flexiset_result` is the grade calculation for one attempt. Export and delete follow the user, in the quiz module context. The access rule declares that it stores nothing.

## Tests

`tests/rescaler_test.php` covers the formula above, both shortfall policies, the three modes, ties, unequal max marks, invalid sections, extra grade items, a SQL regrade, previews, and an unmarked essay. `tests/backup_test.php` covers section remapping and the omission of result rows. The access rule's `tests/rule_test.php` covers the switch and the teacher warning.

Run them from the Moodle 5.3 root with the public web root on the include path, for example:

```bash
php vendor/bin/phpunit --testsuite local_flexiset_testsuite
php vendor/bin/phpunit --testsuite quizaccess_flexiset_testsuite
```

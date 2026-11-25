# Changelog

All notable changes to FlexiSet (`local_flexiset`) are documented in this file.

## 1.0.1

- Support Moodle 5.0: the minimum version is now 5.0.0 (`2025041400`) and the supported range is 5.0 through 5.3.
- Add a GPL v3 `LICENSE` file to the plugin root.
- Add the missing language string for the `local/flexiset:manage` capability.
- Load `formslib.php` in the group form class so the form works wherever it is first used.

## 1.0.0 — initial release

First release. Question groups, the grade rewrite, and the Question groups page. The companion access rule `quizaccess_flexiset` owns the quiz-form switch and the text on the quiz, summary, and review pages.

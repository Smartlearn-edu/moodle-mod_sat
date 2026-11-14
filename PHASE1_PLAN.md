# `mod_sat` Improvement Plan — Phase 1: Core Bug Fixes, Settings Wiring, & Exam Integrity (`v1.2.0`)

**Target Repository:** `/home/mohammad/Dev/plugins/mod/sat/`  
**Scope:** Backend correctness, Moodle 5.2+ API compliance, site/activity settings enforcement, race-condition safety, image rendering, and scoring/analytics accuracy.  
**Precondition:** Do **not** modify `/var/www/` directly; develop and validate strictly inside the plugin repository and deploy afterward.

---

## 1. Fix `routing_mode = 'fixed'` Transition Trap

### Current Defect
* In [`mod_form.php`](mod_form.php#L75-L80), teachers can select **Routing Mode = Fixed (`fixed`)**.
* In [`classes/local/attempt_manager.php`](classes/local/attempt_manager.php#L332-L378), submitting Module 1 in fixed mode sets `$route = 'fixed'` and `$nextstage = 'm2_fixed'`, then queries `$DB->get_record('sat_modules', ['stage' => 'm2_fixed'])` directly, bypassing [`mst_router::get_target_module()`](classes/local/mst_router.php#L71-L103).
* However, [`sat_provision_default_modules()`](lib.php#L177-L258) only provisions `m1`, `m2_easy`, and `m2_hard`.
* **Result:** `$nextmodule` returns `false`, Module 2 never starts, and the student's attempt stalls as `inprogress` forever.

### Planned Fix
1. **Use `mst_router::get_target_module()` inside `attempt_manager::submit_module()`** instead of raw `$DB->get_record('sat_modules', ...)` so `m2_fixed` gracefully falls back to the first available `m2%` module if no dedicated `m2_fixed` module exists.
2. **Provisioning & Builder Awareness (`lib.php` + `edit.php`)**:
   * When an activity is created or updated with `routing_mode === 'fixed'`, provision `(section, 'm2_fixed')` modules if missing (or mark active routing modules cleanly in `edit.php`).
   * In [`edit.php`](edit.php#L129-L140), visually highlight which Module 2 tabs are active under the instance's `routing_mode` (e.g., show `Module 2 (Standard)` for `fixed`, or `Module 2 (Easy)` + `Module 2 (Hard)` for `adaptive`), preventing teachers from populating the wrong tab.
3. **Zero-Module / Finalization Guard**: If `get_target_module()` still returns `null` when transitioning, log/throw a clear validation exception before partial state mutation rather than leaving an orphaned `inprogress` attempt.

---

## 2. Wire All Site Administration Settings (`settings.php`)

### Current Defect
* [`settings.php`](settings.php#L27-L87) registers 6 admin configuration settings:
  * `mod_sat/default_scope`
  * `mod_sat/default_pacing`
  * `mod_sat/default_threshold_rw`
  * `mod_sat/default_threshold_math`
  * `mod_sat/enable_graphing_calc`
  * `mod_sat/enable_scientific_calc`
* None of these keys are ever read via `get_config('mod_sat', ...)` anywhere in the plugin.

### Planned Fix
1. **Activity Form Defaults (`mod_form.php`)**:
   * Call `$config = get_config('mod_sat');` inside `mod_sat_mod_form::definition()`.
   * Use `$config->default_scope ?? 'full'`, `$config->default_pacing ?? 'continuous'`, `$config->default_threshold_rw ?? 60`, and `$config->default_threshold_math ?? 60` in `$mform->setDefault(...)`.
2. **Threshold Validation (`mod_form.php::validation()`)**:
   * Enforce `0 <= threshold_rw <= 100` and `0 <= threshold_math <= 100`.
   * Validate `timeclose > timeopen` when both are enabled, and `grade >= 0`.
3. **Calculator Policy Flags (`attempt.php` + `amd/src/calculator.js`)**:
   * Pass `enableGraphingCalc` and `enableScientificCalc` from `get_config('mod_sat')` inside `$initparams` in `attempt.php`.
   * In `calculator.js::init()`, hide `#sat-btn-calculator` if both modes are disabled, hide `#sat-calc-mode-toggle` if only Scientific mode is enabled, and boot straight into Graphing mode if only Graphing mode is enabled.

---

## 3. Sync Default Max Grade & Dynamic Re-Provisioning on Activity Edit

### Current Defect
* In [`mod_form.php`](mod_form.php#L129-L132), `$mform->setDefault('grade', 1600)` submits `1600` even when `exam_scope` is `math_only`, `rw_only`, or `single_module` (max official section score `800`).
* In [`lib.php`](lib.php#L105-L117), `sat_update_instance()` never calls `sat_provision_default_modules()`, so changing an existing activity from `math_only` to `full` leaves Reading & Writing modules completely missing.

### Planned Fix
1. **Scope-Aware Grade Defaults**:
   * In `mod_form.php` / `sat_add_instance()`, track whether the user customized `grade` or kept the stock default; for single-section scopes (`math_only`, `rw_only`, `single_module`), default grade to `800` instead of `1600`.
   * Add a quick form hint/validation warning if `grade != 1600` on `full` or `grade != 800` on single-section exams.
2. **Idempotent Module Provisioning (`lib.php`)**:
   * Refactor `sat_provision_default_modules($sat)` so it checks existing `(satid, section, stage)` rows before inserting missing modules.
   * Invoke `sat_provision_default_modules($data)` inside `sat_update_instance($data)`.
3. **Fix Cohort Score Buckets for Single-Section Exams (`classes/local/diagnostic_manager.php`)**:
   * Update [`get_cohort_score_distribution()`](classes/local/diagnostic_manager.php#L278-L321) to accept `$sat->exam_scope` (or query `{sat}.exam_scope`): use `400–1600` buckets (`1400-1600`, `1200-1390`, `1000-1190`, `800-990`, `400-790`) for `full`, and `200–800` buckets (`700-800`, `600-690`, `500-590`, `400-490`, `200-390`) for single-section scopes.

---

## 4. Enforce Availability Windows & Accommodation Overrides

### Current Defect
* [`overrides.php`](overrides.php#L41-L65) saves per-user `attempts` and `timemultiplier`, and schema [`sat_overrides`](db/install.xml#L148-L167) also includes `groupid`, `timeopen`, and `timeclose`.
* [`view.php`](view.php#L173-L174) ignores `sat_overrides` completely when checking `$reachedlimit` and `$isopen`.
* [`attempt.php`](attempt.php#L48-L73) performs **zero** checks on `timeopen`, `timeclose`, or `attempts` before creating/resuming attempts, so direct navigation to `/mod/sat/attempt.php?id=...` bypasses all limits.

### Planned Fix
1. **Central Resolver (`attempt_manager::get_effective_settings(stdClass $sat, int $userid): stdClass`)**:
   * Check user override in `sat_overrides`, falling back to user's group overrides (`groups_get_user_groups($sat->course, $userid)`) and finally `$sat` defaults.
   * Return effective `timeopen`, `timeclose`, `attempts`, and `timemultiplier` (taking highest multiplier / most permissive open-close window when multiple group overrides apply, consistent with Moodle `mod_quiz` semantics).
2. **Gate Both `view.php` and `attempt.php`**:
   * Replace inline checks in `view.php` with `attempt_manager::can_start_or_resume_attempt($sat, $USER->id, $context)`.
   * Enforce the same guard at the top of `attempt.php` (throwing a clean localized notice / redirect for students outside window or out of attempts, unless previewing with `mod/sat:preview` / `mod/sat:manage`).
3. **Complete Override UI (`overrides.php`)**:
   * Add optional `timeopen`, `timeclose`, and group override support to `overrides.php`, displaying effective values in the table.

---

## 5. Concurrency & Deadline Integrity (`submit_module` & `save_slot_state`)

### Current Defect
* [`attempt_manager::submit_module()`](classes/local/attempt_manager.php#L286-L427) is neither transactional nor idempotent: if the client auto-submits at `00:00` simultaneously with [`close_overdue_modules`](classes/task/close_overdue_modules.php) or a double-click, Module 2 can be created twice in `{sat_module_attempts}`.
* [`attempt_api::save_slot_state()`](classes/external/attempt_api.php#L224-L280) accepts writes even after an attempt is `finished` or after `timedeadline + graceperiod` has elapsed.
* [`attempt_manager::start_new_attempt()`](classes/local/attempt_manager.php#L156) initializes `$modattempt->maxscore = (float)count($slots)` instead of summing `is_scored` slot `maxmark` values, and `$firstslotnumber` defaults to `1` in `submit_module()` if `nextmodule` has 0 slots.

### Planned Fix
1. **Idempotent Transactional `submit_module()`**:
   * Open `$transaction = $DB->start_delegated_transaction();`.
   * Re-fetch `$modattempt` inside the transaction; if `$modattempt->state === 'finished'`, commit/return current `$attempt` and derived next action cleanly without duplicating rows.
   * Fix `$firstslotnumber` calculation for subsequent modules (`max((int)$modattempt->lastslot + 1, ...)` if no questions added).
   * Fix initial `maxscore` in `start_new_attempt()` to sum `$s->maxmark` only where `!empty($s->is_scored)`.
2. **Server-Side Write Guard in `save_slot_state()`**:
   * Verify `$attempt->state === attempt_manager::STATE_INPROGRESS`.
   * Load the active `inprogress` `sat_module_attempts` record for `$attempt->id`, verify `$slot` lies within `[$modattempt->firstslot, $modattempt->lastslot]`, and reject saves when `time() > ($modattempt->timedeadline + $sat->graceperiod)` (unless teacher preview).
3. **Graceful Expiry Sync in `get_attempt_state()`**:
   * When `$now > $modattempt->timedeadline`, trigger or expose `expired = true` so a student reloading after browser closure sees their module cleanly auto-submitted rather than a `00:00` loop.

---

## 6. Fix Question Media (`@@PLUGINFILE@@`), `review.php` Headers, Pretest Exclusions, & Review Options

### Current Defect
* [`attempt_api.php`](classes/external/attempt_api.php#L107-L118) and [`review.php`](review.php#L180-L202) call `format_text()` on raw `{question}.questiontext`, `{question}.generalfeedback`, and `{question_answers}.answer` without rewriting `@@PLUGINFILE@@` URLs, breaking all embedded question diagrams/graphs.
* [`review.php`](review.php#L101-L104) reads `$modinfo->moduleorder` and `$modinfo->difficulty`, which do not exist on `{sat_modules}` (`stage` and `sortorder` exist instead), causing headers to display **`Module 0`**.
* [`diagnostic_manager::analyze_attempt_domains()`](classes/local/diagnostic_manager.php#L89-L140) counts `is_scored = 0` unscored pretest slots in domain mastery percentages.
* `sat.reviewoptions` column in [`install.xml`](db/install.xml#L22) is unused.

### Planned Fix
1. **Pluginfile URL Rewriting Helper (`structure_manager` / `attempt_api` / `review.php`)**:
   * Load `$quba = question_engine::load_questions_usage_by_activity($attempt->uniqueid)` (or `question_rewrite_question_preview_urls()` / `$quba->get_question_attempt($usageslot)->rewrite_pluginfile_urls(...)`) so `questiontext`, `answer`, and `generalfeedback` render images properly via `mod_sat_question_pluginfile()`.
2. **Fix `review.php` Module Labels & Pretest Badging**:
   * Replace `$modinfo->moduleorder` / `$modinfo->difficulty` with a helper mapping `$modinfo->stage` (`m1` → `Module 1 (Routing)`, `m2_easy` → `Module 2 (Easy)`, `m2_hard` → `Module 2 (Hard)`, `m2_fixed` → `Module 2 (Standard)`).
   * Display an `Unscored Pretest` badge when `empty($slot->is_scored)`.
3. **Exclude Pretest Questions from Scored Mastery (`diagnostic_manager.php`)**:
   * Skip slots with `empty($slot->is_scored)` in `analyze_attempt_domains()` (or track pretest counts separately without skewing scored domain mastery).
4. **Implement `reviewoptions` Controls (`mod_form.php`, `review.php`, `diagnostic.php`)**:
   * Expose review settings in `mod_form.php` (e.g., *Immediately after attempt* vs *Only after close date* vs *Score only / Hide right answers & explanations*).
   * Enforce selected `reviewoptions` in `review.php` and `diagnostic.php` when viewed by students (teachers with `mod/sat:viewreports` retain full access).

---

## 7. Teacher Preview Isolation & Safe Expression Evaluation

### Current Defect
* Teacher preview attempts (`preview = 1`) write grades to `{sat_grades}` and the Moodle Gradebook via [`finalize_attempt_scoring()`](classes/local/attempt_manager.php#L502) and are included in [`diagnostic_manager::get_cohort_analytics()`](classes/local/diagnostic_manager.php#L212-L215), `get_cohort_score_distribution()`, and `get_cohort_domain_breakdown()`.
* Teachers have no one-click **Delete/Reset Preview** button in `view.php`, leaving stale previews in progress across edits.
* [`calculator.js`](amd/src/calculator.js#L107) calls raw `eval(expr)` without strict input whitelisting, and `jsFn.replace(/x/g, ...)` corrupts any identifier containing `x` (such as future `exp` / `max`).

### Planned Fix
1. **Isolate Previews**:
   * In `attempt_manager::finalize_attempt_scoring()`, skip `scorer::update_final_grade()` when `!empty($attempt->preview)`.
   * Add `'preview' => 0` filter to all cohort queries in `diagnostic_manager.php` (`get_cohort_analytics`, `get_cohort_score_distribution`, `get_cohort_domain_breakdown`) and visually badge preview attempts in `report.php`.
   * Add a **Reset / Delete Preview** action button in `view.php` for users with `mod/sat:preview` or `mod/sat:manage`.
2. **Harden Calculator Parser (`calculator.js`)**:
   * Validate normalized expression characters against a strict allowlist (`digits`, operators, parentheses, `Math.(sin|cos|tan|sqrt|log10|log|PI|E|abs|pow)`, `x`) before evaluating, and replace variable `\bx\b` via word boundaries so identifier names cannot break.

---

## Phase 1 Verification Checklist
- [ ] PHPUnit / PHP assertions covering: `fixed` routing transition, idempotent double `submit_module()` call, `save_slot_state()` rejection after deadline, `effective_settings` overrides, `preview = 1` Gradebook exclusion, and `is_scored = 0` pretest exclusion from domain diagnostics.
- [ ] Verify `questiontext`, MCQ option images, and `generalfeedback` images load cleanly in runner and review via `mod_sat_question_pluginfile`.
- [ ] Verify `validator.py` (`validate`, `phplint`, `phpcs`, `mustache` = PASS) and rebuild `amd/build/*.min.js`.

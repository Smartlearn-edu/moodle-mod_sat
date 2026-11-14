# `mod_sat` Improvement Plan — Phase 2: Authentic SAT-Style Exam Experience & Legal/IP Hygiene (`v1.2.0`)

**Target Repository:** `/home/mohammad/Dev/plugins/mod/sat/`  
**Core Mandate:** Deliver 100% of the functional ergonomics and pacing mechanics of modern adaptive Computer-Based Testing (CBT) for SAT preparation while **strictly avoiding trademark infringement or trade-dress cloning**.

---

## Intellectual Property & Trademark Safety Rules

1. **Zero Commercial Software Names**: Never reference `"Bluebook"` anywhere in UI, PHP, JS, CSS, language strings, or documentation.
2. **No Implied Official Endorsement**: Replace `"Official"` UI claims on score screens and modals with neutral descriptive phrasing (e.g., `"SAT®-Style Practice"`, `"Diagnostic Score Report"`, `"Standard 400–1600 Score Scale"`).
3. **Original UI & Original Instructional Text**:
   * Maintain an original **Moodle 5.2 / Bootstrap 5 / SmartLearn** aesthetic (colors, layout, iconography, typography).
   * Author all section directions, Student-Produced Response (SPR) formatting instructions, and SVG geometry diagrams from scratch—never copy third-party text or proprietary vector assets verbatim.
4. **Visible Non-Affiliation Notice**: Render `$string['disclaimer']` (*"SAT® is a registered trademark of the College Board, which is not affiliated with and does not endorse this plugin."*) cleanly in the footer of [`view.php`](view.php), [`diagnostic.php`](diagnostic.php), and [`review.php`](review.php).

### Immediate Wording Cleanup List
* [`lang/en/sat.php`](lang/en/sat.php#L98): Change `official_sat_scale` from `'Official SAT score scale (400–1600)'` → `'Standard SAT-style score scale (400–1600)'`.
* [`diagnostic.php`](diagnostic.php#L67): Change badge `'Official-Style SAT Diagnostic Report'` → `'SAT-Style Diagnostic Score Report'` and output `get_string('disclaimer', 'mod_sat')`.
* [`attempt.php`](attempt.php#L356): Change modal title `'Standard SAT Math Reference Formulas'` → `'Math Reference Formulas'`.

---

## 1. Scheduled Inter-Section Break Screen (`breakduration`)

### Current Gap
* [`mod_form.php`](mod_form.php#L102-L104) lets teachers configure **Break Duration** (`breakduration`, default `600` seconds = 10 minutes), stored in `{sat}.breakduration`, yet the runner never reads it. Submitting Reading & Writing Module 2 immediately starts the Math Module 1 timer.

### Planned Implementation
1. **Backend State & Break Deadline (`attempt_manager.php` + `attempt_api.php`)**:
   * When transitioning from **RW Module 2 → Math Module 1** in `exam_scope === 'full'` and `pacing_mode === 'continuous'` with `$sat->breakduration > 0`:
     * Insert Math Module 1 in `{sat_module_attempts}` with `state = 'break'`, `timestart = $now`, and `timedeadline = $now + $sat->breakduration` (preserving full Math `timelimit * multiplier` untouched until break ends).
     * Return `nextaction = 'break'` from `submit_module()`.
   * Add `mod_sat_end_break` external function in `attempt_api.php` (plus automatic server-side resolution in `get_or_resume_active_module()` if `$now >= $breakdeadline`) to switch Math Module 1 from `break` to `inprogress`, setting `timestart = $start` and `timedeadline = $start + $mathtimelimit`.
2. **Break Screen UI (`attempt.php` + `amd/src/runner.js`)**:
   * Render an original full-workspace `#sat-break-screen` card displaying:
     * Clear heading (*"Scheduled Section Break"*),
     * Live countdown timer (`MM:SS`) synced to server `breakremaining`,
     * Short break instructions (testing tools locked during break; questions remain hidden until Math begins),
     * **"Resume to Math Section Now"** button triggering `mod_sat_end_break`.
   * Automatically transition into Math Module 1 when the break countdown reaches `00:00`.

---

## 2. Section-Aware Exam Toolbar (`rw` vs `math`)

### Current Gap
* The Calculator and Math Reference Formulas buttons remain clickable during Reading & Writing modules even though SAT rules restrict calculators and math formulas strictly to the Math section.

### Planned Implementation
1. **Dynamic Section Gating (`amd/src/runner.js` + `attempt.php`)**:
   * In `runner.js::loadState()`, inspect `data.section`:
     * When `data.section === 'rw'`: hide `#sat-btn-calculator`, `#sat-calculator-panel`, `#sat-btn-formulas`, and close `#sat-formulas-modal` if open.
     * When `data.section === 'math'`: show `#sat-btn-formulas` (and `#sat-btn-calculator` unless disabled by Phase 1 admin settings).

---

## 3. Complete 12-Formula Math Reference Sheet (Original SVGs)

### Current Gap
* [`#sat-formulas-modal`](attempt.php#L361-L405) only contains 4 geometry items (Circle, Rectangle, Triangle, Pythagorean Theorem). Students lack special right triangles and all 3D volume formulas needed for SAT Math questions.

### Planned Implementation
1. **Expand Modal Content (`attempt.php`)** with clean original SVGs and standard mathematical formulas:
   * **Row 1 (2D Geometry)**: Circle ($A = \pi r^2$, $C = 2\pi r$), Rectangle ($A = lw$), Triangle ($A = \frac{1}{2}bh$), Right Triangle ($a^2 + b^2 = c^2$).
   * **Row 2 (Special Right Triangles & 3D Prisms)**: $30^\circ\text{–}60^\circ\text{–}90^\circ$ ($x, x\sqrt{3}, 2x$), $45^\circ\text{–}45^\circ\text{–}90^\circ$ ($s, s, s\sqrt{2}$), Rectangular Prism ($V = lwh$), Right Circular Cylinder ($V = \pi r^2 h$).
   * **Row 3 (Curved & Tapered Solids)**: Sphere ($V = \frac{4}{3}\pi r^3$), Right Circular Cone ($V = \frac{1}{3}\pi r^2 h$), Rectangular Pyramid ($V = \frac{1}{3}lwh$).
   * **Original Reference Facts Footer**: Arc degrees ($360^\circ$), circle radians ($2\pi$), and interior triangle angle sum ($180^\circ$).

---

## 4. Student-Produced Response (SPR) Formatting Guide & Live Input Validator

### Current Gap
* On Math SPR (free-response) questions without a left-pane passage, `#sat-stimulus-pane` collapses completely and `#sat-spr-input` lets students type letters, commas, spaces, or `%` signs that fail validation silently on grading.

### Planned Implementation
1. **Original SPR Formatting Guide in Left Pane (`attempt.php` + `runner.js`)**:
   * When rendering a slot with `current.is_spr === true`:
     * If `current.stimulus` is empty, automatically populate `#sat-stimulus-pane` (col-6) with an original **Student-Produced Response Directions** card covering:
       * Accepted characters (`0–9`, decimal point `.`, fraction slash `/`, leading negative `-`),
       * Max length rules (5 characters for positive answers, 6 including `-` for negative answers),
       * Rounding/truncation of non-terminating decimals (e.g., `2/3`, `.6666`, `.6667`),
       * Disallowed formats (no mixed numbers like `3 1/2` → write `7/2` or `3.5`; no `%`, `$`, `,`, or spaces),
       * Compact 3-row original examples table.
     * If a question includes a custom figure/graph in `current.stimulus`, display that figure above a collapsible compact SPR rules toggle so nothing is overwritten.
2. **Real-Time Client Validation (`runner.js`)**:
   * Validate `#sat-spr-input` on `input` using matching logic to [`spr_helper::validate_format()`](classes/local/spr_helper.php#L45-L90):
     * Display instant inline feedback badge below input (e.g., `"Invalid character: enter digits, ., /, or leading -"`, `"Mixed numbers not allowed—use improper fraction or decimal"`, `"Cannot divide by zero"`), plus decimal equivalent preview (e.g., `2/3 ≈ 0.6667`).

---

## 5. In-App "Check Your Work" Pre-Submit Screen & Non-Blocking Transitions

### Current Gap
* [`runner.js::showModuleReview()`](amd/src/runner.js#L483-L530) relies on browser-native `confirm()` and `alert()` dialogs, breaking fullscreen focus and offering no clickable list of unanswered or flagged questions before final submission.

### Planned Implementation
1. **In-App Module Review Screen (`attempt.php` + `runner.js` + `styles.css`)**:
   * Clicking **Next** on the last question, top **Submit Module**, bottom **Review & Submit**, or matrix **Review & Submit Module** opens a full workspace `#sat-review-overlay` (replacing native `confirm()`):
     * Shows summary counts: **Answered**, **Unanswered**, and **Marked for Review**.
     * Displays a full clickable question grid (`1..N`) with clear icons for Unanswered and Marked-for-Review items—clicking any tile jumps straight back to that question.
     * Includes **"Back to Module Questions"** and **"Confirm & Submit Module"** buttons (plus a warning badge if unanswered questions remain).
2. **Non-Blocking Submission Transition**:
   * Replace `alert()` popups after `mod_sat_submit_module` resolves with an inline loading/transition state (`"Module Submitted — Preparing next stage..."`) before reloading into Break / Module 2 or redirecting to `diagnostic.php` / `view.php`.

---

## 6. Collapsible Section "Directions" Panel

### Current Gap
* Students cannot check section rules, question counts, or permitted tools without leaving the exam.

### Planned Implementation
* Add a **"Directions"** button in the sub-header/top bar toggling `#sat-directions-panel`:
  * Shows original concise instructions tailored dynamically to **Reading & Writing** (passages/questions format, 4 choices, highlighter/eliminator usage) or **Math** (MCQ + Free-Response rules, calculator/formula sheet availability, domain expectations).

---

## 7. Calculator Enhancements (`DEG`/`RAD`, Dual Graphs, Zoom & Safe Evaluation)

### Current Gap
* [`amd/src/calculator.js`](amd/src/calculator.js) forces radians-only trigonometry, plots only one equation (`y = ...`) on a static `20px/unit` scale, lacks zoom controls, and provides no cursor coordinate readout.

### Planned Implementation
1. **DEG / RAD Toggle**:
   * Add a `#sat-calc-angle-mode` toggle (`DEG` / `RAD`) in the calculator header/keypad; wrap `sin/cos/tan` arguments cleanly with `(Math.PI/180)*` in `DEG` mode.
2. **Dual-Function Graphing ($y_1, y_2$) & Zoom Controls**:
   * Support two simultaneous input equations (`y1` in blue `#2563eb`, `y2` in emerald `#059669`) so students can visually inspect systems-of-equations intersections.
   * Add Zoom In (`+`), Zoom Out (`-`), Reset (`1:1`), and hover `(x, y)` coordinate readout on `#sat-graph-canvas`.
3. **Keyboard Input Support**:
   * Allow typing numbers/operators directly into the calculator display in Scientific mode and pressing `Enter` to evaluate.

---

## 8. Polish & Teacher Authoring Productivity (Optional / Stretch within Phase 2)

1. **Module Blueprint Check Bar in `edit.php`**:
   * Summarize current module question mix: `Total (Scored + Pretest)`, `SPR Count / Percentage`, and tagged SAT Domains present vs missing (`Craft & Structure`, `Algebra`, etc.) so teachers spot imbalanced modules before publishing.
2. **Exam Runner Keyboard Shortcuts & Per-Question Time Spent**:
   * Optional keyboard shortcuts (`Alt+Right` / `Alt+Left` navigate, `Alt+F` flag) shown in `#sat-options-panel`.
   * Track `timespent` seconds per question slot (optional future schema column) to enrich `diagnostic.php` pacing feedback.

---

## Phase 2 Verification Checklist
- [ ] Full end-to-end walkthrough in Moodle dev: R&W M1 → R&W M2 → **10-min Break Screen** → Math M1 (with Calculator + 12-Formula Sheet + SPR Left-Pane Guide + Annotate Highlighter) → In-App **Check Your Work** grid → Math M2 → Score Report & Review.
- [ ] Verify zero references to `"Bluebook"`, no false `"Official"` score branding, and visible College Board trademark disclaimer on student report pages.
- [ ] Verify `validator.py` (`validate`, `phplint`, `phpcs`, `mustache` = PASS), `terser` minified AMD builds (`runner.min.js`, `calculator.min.js`), and Node behavioral unit tests.

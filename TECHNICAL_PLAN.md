# Technical Specification & Architecture Plan: `mod_sat`
## Digital SAT Exam Simulator for Moodle 5.2+

| Property | Value |
| :--- | :--- |
| **Plugin Component** | `mod_sat` |
| **Repository** | `https://github.com/Smartlearn-edu/moodle-mod_sat` |
| **Local Workspace** | `/home/mohammad/Dev/plugins/mod/sat/` |
| **Target Moodle Version** | **Moodle 5.2+ only** (No 4.x legacy code) |
| **Server Baseline (Moodle 5.2)** | PHP 8.3+ (64-bit), PostgreSQL 16+ / MySQL 8.4+ / MariaDB 10.11+ |
| **License** | GNU General Public License v3 or later (100% open-source, self-contained) |
| **Author & Copyright** | `2026 Mohammad Nabil <mohammad@smartlearn.education>`, SmartLearn |
| **Plan Version** | v3.0 (Official SAT Exam Spec · Zero Proprietary UI/Third-Party Dependencies) |

> [!IMPORTANT]
> ### Intellectual Property & Non-Affiliation Mandate
> 1. **Zero Proprietary Cloning**: This plugin does **not** copy, imitate, or reference the visual trade dress, logos, color schemes, or proprietary branding of any commercial testing software. It uses an original, clean **Moodle 5.2 / Bootstrap 5 / SmartLearn** interface designed around universal Computer-Based Testing (CBT) ergonomics.
> 2. **100% Self-Hosted Open-Source Tools**: All exam tools (including the built-in Graphing & Scientific Calculator and Math Reference Sheet) are rendered locally using open-source GPL/MIT-compatible libraries bundled with the plugin. No commercial third-party calculator APIs or external network calls are used.
> 3. **Standard Trademark Disclaimer**: *"SAT® is a trademark registered by the College Board, which is not affiliated with, and does not endorse, this plugin."*

---

## 1. Official Digital SAT Exam Specification

The simulator is engineered around the official structure, domains, skills, timing, and adaptive mechanics of the Digital SAT assessment:

### 1.1 Exam Structure & Timing

| Section | Module 1 (Routing) | Module 2 (Adaptive) | Total Questions | Total Time |Question Formats |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **1. Reading and Writing (RW)** | **27 Questions**<br>*(25 scored + 2 pretest)*<br>**32 minutes** | **27 Questions**<br>*(25 scored + 2 pretest)*<br>**32 minutes** | **54 Questions** | **64 minutes** | 100% 4-Option Multiple Choice ($A, B, C, D$) with short stimulus passages (25–150 words, tables, or bulleted notes). |
| **Scheduled Break** | — | — | — | **10 minutes** | Timed transition screen between sections (in Full Exam mode). |
| **2. Math** | **22 Questions**<br>*(20 scored + 2 pretest)*<br>**35 minutes** | **22 Questions**<br>*(20 scored + 2 pretest)*<br>**35 minutes** | **44 Questions** | **70 minutes** | ~75% 4-Option Multiple Choice ($A, B, C, D$)<br>~25% Student-Produced Response (SPR / Free-Response numeric input). |
| **Full Exam Total** | **49 Questions (67m)** | **49 Questions (67m)** | **98 Questions** | **134 minutes**<br>*(+10m break)* | Multi-Stage Adaptive Testing (MST) per section. |

### 1.2 Official Content Domains & Skill Taxonomy

Questions in Moodle's Question Bank are tagged using these official domains and skills to drive **Blueprint Validation** (Phase 1) and **Diagnostic Analytics** (Phase 2):

#### A. Reading and Writing Domains (Ordered sequentially within each module)
1. **Craft and Structure (~28% / 13–15 questions)**
   * *Words in Context* (`rw-words-in-context`)
   * *Text Structure and Purpose* (`rw-text-structure-purpose`)
   * *Cross-Text Connections* (`rw-cross-text-connections`)
2. **Information and Ideas (~26% / 12–14 questions)**
   * *Central Ideas and Details* (`rw-central-ideas`)
   * *Command of Evidence: Textual* (`rw-evidence-textual`)
   * *Command of Evidence: Quantitative* (`rw-evidence-quantitative`)
   * *Inferences* (`rw-inferences`)
3. **Standard English Conventions (~26% / 11–13 questions)**
   * *Boundaries* (`rw-conventions-boundaries`)
   * *Form, Structure, and Sense* (`rw-conventions-form-structure`)
4. **Expression of Ideas (~20% / 8–12 questions)**
   * *Transitions* (`rw-transitions`)
   * *Rhetorical Synthesis* (`rw-rhetorical-synthesis`)

#### B. Math Domains (Ordered by increasing difficulty within each module)
1. **Algebra (~35% / 13–15 questions)**
   * *Linear equations in one variable* (`math-linear-eq-1v`)
   * *Linear equations in two variables* (`math-linear-eq-2v`)
   * *Linear functions* (`math-linear-functions`)
   * *Systems of two linear equations in two variables* (`math-linear-systems`)
   * *Linear inequalities in one or two variables* (`math-linear-inequalities`)
2. **Advanced Math (~35% / 13–15 questions)**
   * *Equivalent expressions* (`math-equivalent-expressions`)
   * *Nonlinear equations in one variable and systems in two variables* (`math-nonlinear-equations`)
   * *Nonlinear functions* (`math-nonlinear-functions`)
3. **Problem-Solving and Data Analysis (~15% / 5–7 questions)**
   * *Ratios, rates, proportional relationships, and units* (`math-ratios-rates`)
   * *Percentages* (`math-percentages`)
   * *One-variable data: distributions and measures of center and spread* (`math-data-1v`)
   * *Two-variable data: models and scatterplots* (`math-data-2v`)
   * *Probability and conditional probability* (`math-probability`)
   * *Inference from sample statistics and margin of error* (`math-statistical-inference`)
   * *Evaluating statistical claims: observational studies and experiments* (`math-statistical-claims`)
4. **Geometry and Trigonometry (~15% / 5–7 questions)**
   * *Area and volume* (`math-area-volume`)
   * *Lines, angles, and triangles* (`math-lines-angles-triangles`)
   * *Right triangles and trigonometry* (`math-right-triangles-trig`)
   * *Circles* (`math-circles`)

### 1.3 Student-Produced Response (SPR) Rules for Math
* **Character Limit**: Maximum **5 characters** for positive values; maximum **6 characters** for negative values (including the leading `-` sign).
* **Allowed Characters**: Digits (`0–9`), decimal point (`.`), fraction slash (`/`), and leading negative sign (`-`). No spaces, commas, mixed fractions (`1 1/2`), percent signs, or variables.
* **Equivalence & Precision Rules**:
  * Equivalent fractions and decimals are accepted (e.g., `1/2`, `2/4`, `.5`, `0.5`).
  * Repeating or long decimals must be **truncated or rounded to fill the maximum character width** (e.g., for $\frac{2}{3}$, accepted forms are `2/3`, `.6666`, `.6667`, `0.666`, `0.667`; short approximations like `.66` or `0.67` are rejected).

---

## 2. Flexible School & Preparatory Operating Modes

While the official exam is taken in a single 2-hour-14-minute sitting, classroom and prep environments require modular flexibility for Math and English teachers:

```mermaid
flowchart TD
    Scope{"Teacher Selects Exam Scope"}
    Scope -->|Full Exam| Full["Reading & Writing (M1 ➔ M2) ➔ Break ➔ Math (M1 ➔ M2)"]
    Scope -->|Math Only| MathOnly["Math Section Only: Module 1 ➔ Adaptive Module 2"]
    Scope -->|English Only| RWOnly["Reading & Writing Only: Module 1 ➔ Adaptive Module 2"]
    Scope -->|Single Module| Drill["Single Module Practice / Class Quiz"]

    Full & MathOnly & RWOnly --> Pacing{"Session Pacing Mode"}
    Pacing -->|Continuous Sitting| Cont["Modules run back-to-back in one session (Official Exam Simulation)"]
    Pacing -->|Multi-Session / Paced| Paced["Student can take Module 1 today, stop at module boundary,<br/>and sit for the adaptively routed Module 2 at another time"]
```

### Key Pacing Rules:
1. **Within a Module**: Once a module is started, its server clock runs continuously until submitted or expired (`timedeadline = timestart + timelimit × multiplier`).
2. **Between Modules (Multi-Session / Paced Mode)**:
   * As soon as the student submits Module 1, the backend evaluates Module 1's scored questions, locks in the adaptive route (`easy` or `hard` Module 2), and appends the routed Module 2 questions to the attempt.
   * The student sees a transition summary screen: *"Module 1 Complete. You may start Module 2 now or return later to continue."*
   * Answer review/explanations remain locked until all modules in the attempt are completed so no information leaks between sessions.

---

## 3. Core Architecture Decisions for Moodle 5.2+

| ID | Architectural Decision | Rationale |
| :--- | :--- | :--- |
| **A1** | **Target Moodle 5.2+ Exclusively** | Eliminates all Moodle 4.x legacy question-bank shims. Uses Moodle 5.2's native React/TypeScript/ESM frontend pipeline and `mod_qbank` (`question_references`) architecture. |
| **A2** | **Single Self-Contained Plugin (`mod_sat`)** | Uses Moodle's native **Question Engine** (`question_usage_by_activity`) with standard `qtype_multichoice`, `qtype_shortanswer`, and `qtype_numerical`. Includes a built-in **SPR Answer Expander** inside `mod_sat` that automatically generates valid SAT decimal/fraction answers on `qtype_shortanswer` questions—no extra `qtype` plugin installation required. |
| **A3** | **Native Question References (`question_references` & `question_set_references`)** | Supports both **Fixed Curated Forms** (exact questions in official domain/difficulty order) and **Random/Filtered Slots** (pulling by category + domain/difficulty tag). |
| **A4** | **Built-in Open-Source Graphing & Scientific Calculator** | Self-hosted calculator built with open-source GPL/MIT math plotting libraries (`JSXGraph` / `mathjs` / HTML5 Canvas) bundled directly in the plugin. Works 100% offline with zero commercial API keys or privacy leaks. |
| **A5** | **Server-Authoritative Timing & Scheduled Task** | Deadlines are enforced strictly on the server. A Moodle scheduled task (`\mod_sat\task\close_overdue_modules`) automatically submits expired modules and computes routing even if the student closes their browser. |
| **A6** | **Estimated Adaptive Scaled Scoring (200–800 / 400–1600)** | Lookup curve in `mdl_sat_score_tables` maps `(section, route, raw_percentage)` to scaled scores (200–800 per section, 400–1600 composite) and syncs with the Moodle Gradebook in Phase 1. |

---

## 4. Moodle 5.2 Subsystem Integration

```mermaid
flowchart TD
    subgraph Client["Browser Client (Moodle 5.2 React + TypeScript/ESM)"]
        Runner["Exam Simulator UI (attempt.php)"]
        Calc["Built-in Open-Source Graphing & Scientific Calculator"]
        RefSheet["Built-in Math Formula Reference Modal"]
    end

    subgraph ModSat["mod_sat Backend (PHP 8.3+)"]
        ExtAPI["External Web Services (classes/external/*)"]
        Engine["Attempt & MST Routing Manager (classes/local/*)"]
        SPRExpander["SPR Equivalent Answer Generator"]
        Cron["Scheduled Task: close_overdue_modules"]
    end

    subgraph MoodleCore["Moodle 5.2 Core"]
        QEngine["Question Engine (question_usage_by_activity)"]
        QBank["mod_qbank (question_references / question_set_references)"]
        Subsystems["Gradebook · Availability · Completion · Calendar · Privacy · Backup"]
    end

    Runner <--> ExtAPI
    Runner --> Calc & RefSheet
    ExtAPI --> Engine
    Cron --> Engine
    Engine --> QEngine
    QEngine --> QBank
    SPRExpander --> QBank
    Engine --> Subsystems
```

### 4.1 Question Authoring & Stimulus/Question Split
* **Standard Moodle Question Types**:
  * **Multiple Choice**: `qtype_multichoice` (4 choices, single answer).
  * **Math SPR (Grid-In)**: `qtype_shortanswer` or `qtype_numerical`. When a teacher configures a slot as SPR in `mod_sat` and enters a canonical value (e.g., `2/3` or `-1.5`), `mod_sat`'s `spr_helper` automatically generates the full set of SAT-accepted answer rows (`2/3`, `.6666`, `.6667`, `0.666`, `0.667`) in the question's answer table.
* **Dual-Column Stimulus Split**:
  * For questions with a reading passage, poem, table, or multi-paragraph stimulus, placing a standard horizontal rule (`<hr>`) in `questiontext` places everything before `<hr>` into the **Left Stimulus Pane** (with text highlighting and notes enabled) and everything after `<hr>` into the **Right Question Pane**.
  * If no `<hr>` is present in Reading & Writing, the passage renders in the left pane and the options render in the right pane; in Math, single-pane questions render in a centered layout alongside theSPR directions when applicable.
* **Media & LaTeX Support**:
  * Implements `mod_sat_question_pluginfile()` in `lib.php` and formats all question text and choices through `format_text()` so images, SVG graphs, and MathJax/KaTeX equations render seamlessly.

### 4.2 Standard Moodle Quiz-Equivalent Features
* **Timing**: `timeopen`, `timeclose`, customizable per-module durations, server-side grace period.
* **User & Group Overrides (`mdl_sat_overrides`)**: Supports accommodations such as **1.5× extended time** (48m RW / 52.5m Math), **2.0× extended time** (64m RW / 70m Math), custom open/close dates, and extra attempts.
* **Attempts & Grading Method**: Configurable max attempts ($1, 2, \dots, \text{Unlimited}$) and grading method (*Highest score*, *Average score*, *First attempt*, *Last attempt*).
* **Review Options**: Configurable bitmask controlling when students can see their scores, submitted responses, correct answers, and explanations (*Immediately after module*, *After full attempt completion*, *After exam close date*).
* **Restrict Access & Completion**: Full support for Moodle's conditional availability (`core_availability`) and custom completion rules (*View*, *Complete all modules*, *Receive grade*, *Receive passing score*).
* **Backup/Restore & Privacy API**: Full `backup/moodle2` implementation and GDPR `core_privacy` provider (zero external third-party data processors).

---

## 5. Database Schema (`db/install.xml`)

1. **`mdl_sat`** *(Activity settings)*
   * `id`, `course`, `name`, `intro`, `introformat`
   * `exam_scope`: `varchar(20)` (`full`, `rw_only`, `math_only`, `single_module`)
   * `pacing_mode`: `varchar(20)` (`continuous`, `paced`)
   * `routing_mode`: `varchar(20)` (`adaptive`, `fixed`)
   * `timeopen`, `timeclose`, `graceperiod`, `breakduration`: `bigint(10)`
   * `attempts`: `int(4)`, `grademethod`: `smallint(4)`
   * `threshold_rw`, `threshold_math`: `int(4)` *(Percentage of scored marks in Module 1 required for Harder Module 2, default `60`)*
   * `reviewoptions`: `bigint(10)`
   * `grade`: `bigint(10)` *(1600 for full exam, 800 for single section)*
   * `timecreated`, `timemodified`: `bigint(10)`

2. **`mdl_sat_modules`** *(Module definitions within an exam instance)*
   * `id`, `satid`, `section` (`rw`, `math`), `stage` (`m1`, `m2_easy`, `m2_hard`, `m2_fixed`), `timelimit`, `questioncount`, `sortorder`

3. **`mdl_sat_slots`** *(Question slots within a module)*
   * `id`, `satid`, `moduleid`, `slot` (order inside module), `maxmark`, `is_scored` (`1` = operational, `0` = unscored pretest), `is_spr` (`1` = math free-response grid-in, `0` = MCQ), `slottype` (`fixed`, `random`)
   * *Linked to Moodle 5.2 `mdl_question_references` (for fixed questions) or `mdl_question_set_references` (for random filtered questions) using `component = 'mod_sat'`, `questionarea = 'slot'`, `itemid = mdl_sat_slots.id`.*

4. **`mdl_sat_attempts`** *(Overall student exam attempts)*
   * `id`, `satid`, `userid`, `attempt`, `uniqueid` *(FK to `mdl_question_usages.id`)*
   * `state`: `varchar(20)` (`inprogress`, `paused`, `finished`, `abandoned`)
   * `currentstage`: `varchar(20)` (`rw_m1`, `rw_m2`, `break`, `math_m1`, `math_m2`, `completed`)
   * `route_rw`, `route_math`: `varchar(10)` (`easy`, `hard`, `fixed`, `none`)
   * `preview`: `smallint(1)`
   * `raw_rw`, `raw_math`, `scaled_rw`, `scaled_math`, `scaled_total`: `int(6)`
   * `timestart`, `timefinish`, `timemodified`: `bigint(10)`

5. **`mdl_sat_module_attempts`** *(Per-module attempt timing & state)*
   * `id`, `attemptid`, `moduleid`, `state` (`notstarted`, `inprogress`, `finished`)
   * `firstslot`, `lastslot`: `int(6)` *(Slot range in the question usage)*
   * `timestart`, `timedeadline`, `timefinish`: `bigint(10)`
   * `rawscore`, `maxscore`: `numeric(10,5)`

6. **`mdl_sat_slot_state`** *(Student exam workspace tools state per question)*
   * `id`, `attemptid`, `slot`
   * `eliminated`: `text` *(JSON array of crossed-out choice indices)*
   * `annotations`: `text` *(JSON array of stimulus text highlight ranges and notes)*
   * `timemodified`: `bigint(10)`

7. **`mdl_sat_overrides`** *(User and group accommodations)*
   * `id`, `satid`, `groupid`, `userid`, `timeopen`, `timeclose`, `attempts`, `timemultiplier` (`numeric(4,2)`, e.g. `1.50`, `2.00`)

8. **`mdl_sat_grades`** *(Final aggregated grade per user)*
   * `id`, `satid`, `userid`, `grade`, `scaled_rw`, `scaled_math`, `timemodified`

9. **`mdl_sat_score_tables`** *(Configurable Raw % $\rightarrow$ 200–800 Scaled Score lookup curves)*
   * `id`, `satid` (`0` = default curve), `section` (`rw`, `math`), `route` (`easy`, `hard`), `rawpercent`, `scaledscore`

---

## 6. Simulator User Interface (Original Design, Zero Trademarked Style)

The exam runner (`attempt.php`) uses Moodle's minimal/embedded layout and mounts a clean, responsive **Moodle 5.2 React + TypeScript** application styled with scoped Bootstrap 5 variables (`.path-mod-sat`):

1. **Header Bar**:
   * Current Section & Module title (e.g., *Reading and Writing — Module 1 of 2*).
   * **Countdown Timer**: Centered `MM:SS` display with a *Hide/Show* toggle; automatically stays visible with an alert badge during the final 5 minutes.
   * **Exam Toolbar**:
     * **Graphing & Scientific Calculator** (Math section): Opens a draggable, resizable self-hosted calculator panel supporting multi-function graphing ($f(x)$), intersection/root tracing, and scientific calculations.
     * **Math Reference Sheet** (Math section): Opens a modal displaying universal geometry & trigonometry formulas (area, circumference, volume, Pythagorean theorem, special right triangles, radians) rendered via original SVG diagrams and LaTeX.
     * **Passage Highlighter & Notes** (RW section): Select text in the stimulus to highlight or attach a note.
     * **Display Options**: Font size zoom and high-contrast toggle.
2. **Question Workspace**:
   * **Split-Pane View**: Left column for reading passages/tables; right column for question stem and options.
   * **Multiple Choice**: Radio options $A, B, C, D$ (respecting Moodle's `_order` shuffle mapping) with an **Eliminate Option** (strikethrough) toggle button beside each choice.
   * **Student-Produced Response (SPR)**: Live character-validated input box (max 5 chars positive / 6 chars negative) with live mathematical fraction preview and standard entry instructions in the left pane.
   * **Flag for Review**: Bookmark toggle mapped directly to the Moodle Question Engine flag state.
3. **Footer Bar & Navigation Matrix**:
   * Question counter (*Question X of Y*) that opens a popover grid showing all questions in the current module (*Current*, *Answered*, *Unanswered*, *Flagged for Review*).
   * *Previous* and *Next* buttons.
4. **End-of-Module Review & Transition**:
   * Summary table of all questions in the module before final submission.
   * In **Continuous Mode**: Transitions directly to Module 2 (or the 10-minute Break screen between RW and Math).
   * In **Paced / Multi-Session Mode**: Displays a completion card allowing the student to either proceed immediately to the next module or exit safely and resume at a later time.

---

## 7. Incremental Phased Implementation & Testing Plan

Development is divided into **6 self-contained phases**. Every phase ends with a mandatory **Test Gate** (automated `validator.py` + PHPUnit tests + manual browser verification in Moodle 5.2) before proceeding to the next phase.

```mermaid
flowchart LR
    P1["Phase 1<br/>Foundation & Schema"] --> T1{"Test Gate 1"}
    T1 --> P2["Phase 2<br/>QBank & SPR Builder"] --> T2{"Test Gate 2"}
    T2 --> P3["Phase 3<br/>MST Engine & Scoring"] --> T3{"Test Gate 3"}
    T3 --> P4["Phase 4<br/>Simulator UI & Tools"] --> T4{"Test Gate 4"}
    T4 --> P5["Phase 5<br/>Overrides, Reports & Backup"] --> T5{"Test Gate 5"}
    T5 --> P6["Phase 6<br/>Diagnostic Analytics"] --> T6{"Test Gate 6"}
```

---

### Phase 1: Plugin Foundation, Database Schema & Activity Settings
* **Deliverables**:
  * `version.php` (Moodle 5.2+, PHP 8.3+), `db/install.xml` (all 9 tables), `db/install.php` (seeding default 200–800 score lookup curves), `db/access.php`.
  * `lib.php` (activity lifecycle callbacks, grade item setup, completion callbacks).
  * `mod_form.php` (Exam Scope: Full / Math Only / RW Only / Single Module; Pacing: Continuous vs. Paced; Routing: Adaptive vs. Fixed; Timing; Attempts; Grading Method; Review Options; Restrict Access; Activity Completion).
  * `view.php` (initial activity landing page), `index.php`, `settings.php`, `lang/en/sat.php`, `pix/monologo.svg`.
* **Test Gate 1**:
  * **Automated**: Run PHP 8.3 syntax lint and `validator.py` on `/home/mohammad/Dev/plugins/mod/sat/`.
  * **Browser Test in Moodle 5.2**: Install `mod_sat`, add a new *SAT Exam Simulator* activity to a course, test saving and editing all modes in `mod_form.php`, and verify `view.php` and Gradebook item creation.

---

### Phase 2: Module & Question Bank Builder (`mod_qbank` Integration & SPR Expander)
* **Deliverables**:
  * `classes/local/structure_manager.php`: Auto-provisions modules (`mdl_sat_modules`) based on exam scope and manages question slots (`mdl_sat_slots`) linked via Moodle 5.2 `question_references` and `question_set_references`.
  * `edit.php`: Teacher UI to add fixed questions or random tag/category slots from `mod_qbank`, apply 1-click official SAT presets (27 RW / 22 Math), mark pretest (unscored) questions, and preview stimulus/question `<hr>` splits.
  * `classes/local/spr_helper.php`: Rule-based SAT Student-Produced Response (SPR) validator and answer expander (`2/3` $\rightarrow$ `2/3`, `.6666`, `.6667`, `0.666`, `0.667`).
  * `tests/fixtures/sample_sat_questions.xml`: Original sample Math (MCQ + SPR) and Reading & Writing questions tagged across all 8 official SAT domains.
* **Test Gate 2**:
  * **Automated**: PHPUnit test suite (`tests/spr_helper_test.php` and `tests/structure_manager_test.php`) verifying fraction/decimal equivalence expansion, negative width rules, and slot reference creation.
  * **Browser Test in Moodle 5.2**: Import `sample_sat_questions.xml` into the Question Bank, open `edit.php`, populate Module 1, Module 2 (Easy), and Module 2 (Hard) with questions, and test the 1-click SPR answer expander.

---

### Phase 3: Attempt Engine, Multi-Stage Adaptive Routing (MST), Paced Sessions & Scoring
* **Deliverables**:
  * `classes/local/attempt_manager.php`: Manages `question_usage_by_activity`, enforces server-side deadlines (`timedeadline`), saves responses/flags/eliminations/annotations, handles module submissions, and pauses/resumes at module boundaries in Paced Mode.
  * `classes/local/mst_router.php` & `classes/local/scorer.php`: Evaluates Module 1 scored percentage against threshold, routes to Easy or Hard Module 2 (dynamically appending M2 questions to the running `question_usage_by_activity`), computes 200–800 section and 400–1600 total scores, and updates the Moodle Gradebook (`grade_update`).
  * `classes/external/*` & `db/services.php`: AJAX Web Services (`get_attempt_state`, `save_response`, `save_slot_state`, `submit_module`, `start_next_module`).
  * `classes/task/close_overdue_modules.php` & `db/tasks.php`: Scheduled task to auto-submit expired modules.
* **Test Gate 3**:
  * **Automated**: PHPUnit test suite (`tests/attempt_mst_test.php`) testing:
    1. High Module 1 score ($\ge 60\%$) $\rightarrow$ routes to **Hard Module 2** and computes Hard curve scaled score.
    2. Low Module 1 score ($< 60\%$) $\rightarrow$ routes to **Easy Module 2** and computes Easy curve scaled score.
    3. Paced Mode lifecycle: Start M1 $\rightarrow$ Submit M1 $\rightarrow$ Pause $\rightarrow$ Resume M2 later $\rightarrow$ Finish & push grade to Gradebook.
    4. Overdue module auto-submission via scheduled task.

---

### Phase 4: Exam Simulator Frontend UI (`attempt.php`) & Built-in Open-Source Tools
* **Deliverables**:
  * `attempt.php` (distraction-free embedded layout) + interactive frontend exam runner (`amd/src/` & `templates/`).
  * **Header Bar**: Section/module indicator, server-synchronized countdown timer (Hide/Show toggle, locked visible $<5$ min), and tool tray.
  * **Built-in Open-Source Graphing & Scientific Calculator**: Self-hosted draggable/resizable calculator panel supporting multi-function plotting ($f(x)$), trace/intersections, and scientific mode.
  * **Built-in Math Reference Sheet Modal**: Original SVG geometric diagrams and LaTeX formulas.
  * **Workspace & Tools**: Split-pane stimulus/question layout (`<hr>` split), Passage Highlighter & Sticky Notes (RW), Option Eliminator ($A/B/C/D$ strikethrough), live-validated SPR input with fraction preview (Math), and Flag for Review.
  * **Navigation & Transitions**: Bottom question matrix drawer (*Current*, *Answered*, *Unanswered*, *Flagged*), Module Review screen, 10-minute Break screen, and Paced Mode boundary screen (*Continue to Module 2 now* vs. *Save & Continue Later*).
* **Test Gate 4**:
  * **Automated**: JS/AMD build check, CSS scoping check (`.path-mod-sat`), and `validator.py`.
  * **Browser Test in Moodle 5.2**: Complete a full end-to-end student test sitting in Continuous Mode and Paced Mode; verify the Graphing Calculator, Formula Reference Sheet, Highlighter, Option Eliminator, SPR input validation, and question drawer.

---

### Phase 5: Review Page, Accommodations (`overrides.php`), Teacher Reports & Moodle Compliance
* **Deliverables**:
  * `review.php`: Student post-attempt review interface governed by configured `reviewoptions`.
  * `overrides.php`: Teacher management for User and Group accommodations (**1.5× time**, **2.0× time**, custom dates, extra attempts).
  * `report.php`: Teacher attempts table (filter by group/state, inspect M1/M2 raw & scaled scores and Easy/Hard routes, regrade, delete attempts, CSV/Excel export).
  * `backup/moodle2/*` (Activity backup, restore, and course duplication) & `classes/privacy/provider.php` (GDPR Privacy API).
* **Test Gate 5**:
  * **Automated**: PHPUnit tests for Overrides (1.5× / 2.0× timer multiplication), Backup/Restore round-trip, and Privacy API provider, plus full `validator.py` audit.
  * **Browser Test in Moodle 5.2**: Add a 1.5× time override for a student and verify their Math module timer starts at 52:30; review a completed attempt on `review.php`; export grades from `report.php`; duplicate the activity inside the course.

---

### Phase 6: Advanced Diagnostic Reporting & Cohort Analytics Dashboard
* **Deliverables**:
  * **Student Diagnostic Report (`diagnostic.php`)**: Visual score gauge (400–1600 / 200–800), mastery bars across all **8 Official SAT Content Domains** and **19 Sub-Skills**, time-spent-per-question breakdown, and printable/PDF report view.
  * **Teacher Cohort Analytics**: Class score distribution histograms, Easy vs. Hard routing percentages, and Domain/Skill weakness heatmaps to guide classroom instruction.
* **Test Gate 6**:
  * **Automated**: PHPUnit tests for domain/skill tag aggregation and accuracy calculation.
  * **Browser Test in Moodle 5.2**: Inspect student diagnostic report and teacher class analytics dashboard after multiple simulated attempts.

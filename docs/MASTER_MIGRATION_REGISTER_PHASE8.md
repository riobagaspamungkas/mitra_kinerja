# MASTER MIGRATION REGISTER & EXECUTION PLAN (PHASE 8)
## Windows 11 to Ubuntu Desktop Migration — Mitra Kinerja v2.5.2
**Document Version:** 1.0.0 (Adjudicated Final)  
**Host Environment:** Ubuntu 26.04.1 LTS (x86_64, Linux 7.0.0-38-generic) over SSH  
**Adjudication Authority:** Independent Judge Subagent (Phase 6 & 7)  
**Target Repository:** `/home/afra/Agentic Folder/mitra-kinerja`

---

## 1. EXECUTIVE SUMMARY & ADJUDICATION PROTOCOL

This document delivers the definitive adjudication of all claims, disputes, and findings raised during the 20-Agent Debate for migrating the **Mitra Kinerja** application from Windows 11 (NTFS/XAMPP) to Ubuntu Desktop (POSIX/ext4/SSH).

Every finding was adjudicated strictly against empirical evidence gathered directly from the codebase, the userland MySQL 8.4 engine, PHP 8.5 CLI/runtime, and the Linux operating system. No claim was accepted based solely on agent consensus.

### Core Architectural Constraint: Windows-First Dual-OS Parity
Mitra Kinerja's primary production and development environment has historically been Windows 11. The objective of this migration is **dual-platform parity**:
- **Zero Windows Regression:** Windows `.bat` launchers (`start_server_wlan.bat`, `make_backup.bat`) and Windows-specific fallbacks must remain fully operational.
- **Native Linux Compatibility:** Provide native POSIX shell scripts (`start_server.sh`, `make_backup.sh`), standard socket/TCP handling, and headless execution via SSH and `systemd --user`.
- **Cross-Platform Abstractions:** Employ runtime OS detection (`PHP_OS_FAMILY === 'Windows'`, `sys.platform == 'win32'`) and portable directory separators rather than destructive replacements.

---

## 2. 20-AGENT CLAIMS ADJUDICATION MATRIX

| # | Agent Scope & Claim Description | Empirical Evidence & Ground Reality | Judge Verdict | Category |
|---|---|---|---|---|
| **1** | **DB_HOST 'localhost' UNIX Socket Failure** (Agents 6-10, 12) | In Linux PHP PDO, `localhost` attempts connection to default system socket `/var/run/mysqld/mysqld.sock`. Userland MySQL runs at `/home/afra/.local/var/run/mysqld/mysqld.sock` or `127.0.0.1:3306`. Out of the box, `getDB()` fails immediately with `SQLSTATE[HY000] [2002] No such file or directory`. | **RIGHT — UPHELD** | **M0** |
| **2** | **PHP Runtime `session.save_path` Missing/Unwritable** (Agents 6-10) | `ini_get('session.save_path')` defaults to `/var/lib/php/sessions`, which is unwritable/non-existent for user `afra`. Calling `session_start()` yields fatal warnings and fails to persist session cookies, breaking all logins. | **RIGHT — UPHELD** | **M0** |
| **3** | **AI Header Corruption `Authorization: ***`** (Agents 6-10, 13) | Sanitization over-scrubbed `Authorization: Bearer ` to `Authorization: *** ` in `includes/ai_service.php` (lines 232, 309) and `ai_debug_raw.php` (lines 104, 157). Any fallback call to Groq/OpenRouter fails with HTTP 401 Unauthorized. | **RIGHT — UPHELD** | **M1** |
| **4** | **Missing Python Dependencies (`openpyxl`, `pymysql`) & No `requirements.txt`** (Agents 6-10, 11) | Project root has no `requirements.txt`. System python lacks `openpyxl` and `pymysql`. 8 auxiliary scripts (`audit_scorecard_parity.py`, `verify_baseline_full_parity.py`, etc.) immediately throw `ModuleNotFoundError`. | **RIGHT — UPHELD** | **M1** |
| **5** | **Hardcoded Windows Paths (`C:\...`) in Python Scripts** (Agents 1-5, 10) | 7 scripts (`deep_audit_operations.py`, `integrate_final_packet.py`, `reconcile_audited_data.py`, etc.) hardcode `r"C:\xampp\mysql\bin\mysql.exe"` and Windows desktop paths without dynamic path fallback. | **RIGHT — UPHELD** | **M1** |
| **6** | **Missing Linux Shell Launchers (`start_server.sh`, `make_backup.sh`)** (Agents 1-5, 15) | The repository only contains `.bat` files (`start_server_wlan.bat`, `make_backup.bat`). Linux/SSH users have no standard executable bash scripts to launch the server or perform backups. | **RIGHT — UPHELD** | **M1** |
| **7** | **Missing `DB_PORT`/`DB_SOCKET` & PHP 8.5 Multi-Statement Deprecation** (Agents 6-10, 12) | `config/database.php` DSN omits `port=` and `unix_socket=`. Uses deprecated constant `PDO::MYSQL_ATTR_MULTI_STATEMENTS` instead of modern `Pdo\Mysql::ATTR_MULTI_STATEMENTS`. | **RIGHT — UPHELD** | **M1** |
| **8** | **Red Team 20.2: BDN Early-Stage Validation Trap** (Agent 20) | In `mitra_validasi.php` (line 195), approval (`DISETUJUI`) is strictly blocked if `empty($summary['all_evaluable'])`. Early-stage partnerships (like P04 Bapperida Bintan, PKS 89 days old with I4/I5 BDN) are trapped and can never be approved by the validator. | **RIGHT — UPHELD** | **M1** |
| **9** | **Red Team 20.3: Monev Milestone Over-Advancement** (Agent 20) | In `mitra_edit.php` (lines 212-224), saving on subsequent dates when `tanggal_realisasi_evaluasi != date('Y-m-d')` causes fallback `$stmtNextSM` to consume and mark future quarterly milestones as `Selesai` prematurely. | **RIGHT — UPHELD** | **M1** |
| **10** | **PHP Default Upload Limits vs App 25MB Expectation** (Agents 6-10, 17) | Default PHP `upload_max_filesize = 2M` and `post_max_size = 8M` block valid uploads in `baseline.php`, `tindak_lanjut.php`, and `gate0.php`, which explicitly support up to 25MB PDF/Excel attachments. | **RIGHT — UPHELD** | **M1** |
| **11** | **Non-Interactive SSH PATH Missing `~/.local/bin`** (Agents 16-20) | Non-login, non-interactive SSH commands (`ssh user@host "cmd"`) do not evaluate interactive `.bashrc` blocks, losing access to userland binaries (`mysql`, `mysqld`, `php`). | **RIGHT — UPHELD** | **M2** |
| **12** | **CRLF Line Endings & Missing `.gitattributes`** (Agents 1-5) | Files across the repository have `\r\n` line endings, causing large diff churn on Linux edits and risking interpreter failures if shell scripts are authored with CRLF. | **RIGHT — UPHELD** | **M2** |
| **13** | **Script CWD Sensitivity in `scripts/`** (Agents 11-15) | Several Python/PHP scripts in `scripts/` use relative paths to `docs/` and `public/` assuming CWD is repository root. Invoking from inside `scripts/` causes `FileNotFoundError`. | **RIGHT — UPHELD** | **M2** |
| **14** | **cURL Network Resilience & Missing Connection Timeout** (Agents 11-15, 13) | cURL requests in `includes/ai_service.php` define total timeout (15s) but omit `CURLOPT_CONNECTTIMEOUT`. Unresponsive remote DNS/hosts can cause synchronous 45s stalls across triple fallback. | **RIGHT — UPHELD** | **M2** |
| **15** | **Directory Creation Permissions `0777` vs `0775`** (Agents 16-20) | `baseline.php` and `tindak_lanjut.php` invoke `mkdir(..., 0777, true)`. In POSIX multi-user environments, directories should follow principle of least privilege (`0775` or `0755`). | **RIGHT — UPHELD** | **M2** |
| **16** | **Preserve Windows-First Dual-OS Parity** (Agents 1-5, 19) | Existing Windows batch scripts (`.bat`) and workflows must be preserved intact without breaking cross-platform developer workflows. | **RIGHT — UPHELD** | **M2** |
| **17** | **Backup Archive File Permissions (`0660` in zip)** (Agents 11-15) | POSIX file permissions created under umask 007 or restrictive ACLs result in `0660` inside zip archives. Extracting on third-party web servers requires explicit mode normalization. | **RIGHT — UPHELD** | **M2** |
| **18** | **systemd --user Recommended Over Docker** (Agents 11-15) | Host is Ubuntu desktop controlled over unprivileged SSH without root/Docker. `systemd --user` is active, running, and natively manages userland services (`mysqld`, PHP server). | **RIGHT — UPHELD** | **M2** |
| **19** | **Missing Composer (`composer.json`)** (Agents 6-10) | Mitra Kinerja is built as a zero-dependency vanilla procedural PHP application utilizing native PDO. Composer is not required for application runtime, though a manifest is useful for version metadata. | **PARTIALLY RIGHT — NARROWED** | **M3** |
| **20** | **Missing Linux Font "Dancing Script"** (Agents 6-10, 9) | The motto in `style.css` declares `'Dancing Script', 'Segoe Script', cursive`. On Ubuntu, neither is installed, gracefully falling back to generic cursive. Visual difference only; zero functional breakage. | **PARTIALLY RIGHT — NARROWED** | **M3** |
| **21** | **Red Team 20.1: "Overly Restrictive Login Rate-Limiter"** (Agent 20) | Rate limit is 5 attempts / 5 mins with 60s cooldown. 60s is self-healing, not permanent lockout. However, pure IP matching on `127.0.0.1` or shared NAT risks collateral lockout. | **PARTIALLY RIGHT — NARROWED** | **M3** |
| **22** | **Filesystem Case-Sensitivity Compliance** (Agents 6-10, 16) | Comprehensive audit manifest (`CASE_SENSITIVITY_AUDIT_MANIFEST.json`) verified 267 references across PHP includes, asset links, templates, and routes with **zero** case mismatches. | **RIGHT — UPHELD** | **NO CHANGE** |

---

## 3. PRIORITIZED MASTER MIGRATION REGISTER

### Tier M0: Critical Blockers (Prevents Execution, Security, or Data Loss)
*Must be resolved before the application can start or authenticate on Ubuntu.*

1. **[M0-01] Database Connection & UNIX Socket Resolution**
   - **Target:** `config/database.php`, `config/database.local.php`
   - **Issue:** `localhost` triggers connection to missing `/var/run/mysqld/mysqld.sock`. Userland MySQL is on `127.0.0.1:3306` or socket `/home/afra/.local/var/run/mysqld/mysqld.sock`.
   - **Remediation:** Support `DB_PORT` and `DB_SOCKET` in DSN construction. Default `DB_HOST` to `127.0.0.1` on Linux when socket `/var/run/mysqld/mysqld.sock` is absent.

2. **[M0-02] PHP Session Save Path Configuration**
   - **Target:** `config/env.php`, runtime launcher scripts (`start_server.sh`)
   - **Issue:** `/var/lib/php/sessions` is non-existent/unwritable by non-root user `afra`.
   - **Remediation:** In `config/env.php` or custom `php.ini`, ensure `session.save_path` points to a writable local directory (e.g. `cache/sessions/` or `~/.local/var/php/sessions/`) with mode `0700` and automated creation.

---

### Tier M1: Required Migration Fixes (Essential for Correct Operation)
*Must be resolved to ensure complete operational integrity and eliminate runtime bugs.*

1. **[M1-01] AI Service Authorization Header Restoration**
   - **Target:** `includes/ai_service.php` (lines 232, 309), `ai_debug_raw.php` (lines 104, 157)
   - **Issue:** Corrupted `Authorization: *** <KEY>` header blocks all fallback AI requests.
   - **Remediation:** Restore canonical RFC-compliant header: `'Authorization: Bearer ' . <KEY>`.

2. **[M1-02] Python Tooling Dependencies & `requirements.txt`**
   - **Target:** Project root `requirements.txt`, system/venv python
   - **Issue:** Missing `openpyxl` and `pymysql` causes parity and test suite scripts to crash.
   - **Remediation:** Author `requirements.txt` (`requests>=2.31.0`, `openpyxl>=3.1.2`, `pymysql>=1.1.0`), and install in the active user environment.

3. **[M1-03] Portable MySQL Binary Resolution in Python Scripts**
   - **Target:** `scripts/deep_audit_operations.py`, `scripts/integrate_final_packet.py`, `scripts/integrate_27_sep_final_packet.py`, `scripts/reconcile_audited_data.py`, `scripts/sync_baseline_fidelity.py`, `scripts/reconcile_operations_parity.py`
   - **Issue:** Hardcoded `C:\xampp\mysql\bin\mysql.exe` and `mysqldump.exe`.
   - **Remediation:** Implement portable CLI resolution (`shutil.which('mysql')` / `shutil.which('mysqldump')`) with fallback to `/home/afra/.local/bin/` and Windows XAMPP paths.

4. **[M1-04] Linux Bash Launchers (`start_server.sh`, `make_backup.sh`)**
   - **Target:** `start_server.sh`, `make_backup.sh`
   - **Issue:** Missing executable POSIX shell launchers for Ubuntu environment.
   - **Remediation:** Author robust bash launchers with dynamic port failover (8080 -> 8085..8100), IP detection, session path initialization, and `chmod +x` permissions.

5. **[M1-05] PHP 8.5 Deprecated Constants & DSN Compatibility**
   - **Target:** `config/database.php`
   - **Issue:** `PDO::MYSQL_ATTR_MULTI_STATEMENTS` throws deprecation notices on PHP 8.5+.
   - **Remediation:** Use version-safe guard: `(defined('\Pdo\Mysql::ATTR_MULTI_STATEMENTS') ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS : PDO::MYSQL_ATTR_MULTI_STATEMENTS)`.

6. **[M1-06] Red Team 20.2 Resolution: BDN Early-Stage Validation Logic**
   - **Target:** `mitra_validasi.php` (line 195)
   - **Issue:** `empty($summary['all_evaluable'])` prohibits validators from approving legitimate early-stage partnerships with BDN indicators (e.g. P04).
   - **Remediation:** Allow validation approval if either:
     - All 7 indicators are `DAPAT DINILAI` with 100% completion (promotes to `FINAL/TERVALIDASI`), OR
     - Unrated indicators are legitimately `BELUM DAPAT DINILAI` (BDN) with 0 incomplete/insufficient proofs (promotes to approved `MASA IMPLEMENTASI AWAL`).

7. **[M1-07] Red Team 20.3 Resolution: Monev Milestone Over-Advancement Safeguard**
   - **Target:** `mitra_edit.php` (lines 204-225)
   - **Issue:** Successive saves on different review dates fall through to `$stmtNextSM`, consuming future quarterly milestones.
   - **Remediation:** Do NOT automatically advance to the next milestone on save unless the user explicitly selects or transitions the milestone, or ensure that saving an already completed cycle targets the existing active/recent cycle rather than consuming future cycles.

8. **[M1-08] PHP Upload Size Limit Reconciliation**
   - **Target:** `config/env.php`, custom `php.ini`, or launcher flags
   - **Issue:** Default PHP 2MB cap causes HTTP upload drops on valid 25MB attachments.
   - **Remediation:** Set `upload_max_filesize = 32M` and `post_max_size = 64M` in the runtime environment.

---

### Tier M2: Important Compatibility Improvements (Parity & Reliability)
*Enhances cross-platform parity, developer experience, and headless automation.*

1. **[M2-01] Non-Interactive SSH Environment Configuration**
   - Ensure `~/.profile` and user environment exports `PATH="$HOME/.local/bin:$PATH"` so non-login SSH commands cleanly locate `mysql` and `php`.
2. **[M2-02] Line Ending Normalization & `.gitattributes`**
   - Add `.gitattributes` enforcing `* text=auto eol=lf` for shell and web assets, and `*.bat text eol=crlf` for Windows batch files.
3. **[M2-03] Script Working Directory Robustness (CWD Independence)**
   - Wrap script file lookups with `os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ...))` to eliminate CWD failures.
4. **[M2-04] cURL Connect Timeout Protection**
   - Add `CURLOPT_CONNECTTIMEOUT => 5` across all external cURL calls in `includes/ai_service.php` to prevent gateway latency locks.
5. **[M2-05] Directory Creation Permissions Hardening**
   - Replace `0777` with `0775` in `baseline.php:530` and `tindak_lanjut.php:78`.
6. **[M2-06] Systemd User Service Definition**
   - Provide a template `~/.config/systemd/user/mitra-kinerja.service` for headless autostart and background management.
7. **[M2-07] Dual-OS Batch Parity Preservation**
   - Maintain `start_server_wlan.bat` and `make_backup.bat` with zero regression for Windows users.

---

### Tier M3: Optional / Deferrable Improvements
*Polish items that do not affect functionality or deployment.*

1. **[M3-01] Login Rate-Limiter Scoping (Red Team 20.1)**
   - Scope failed login checks to `IP + username` combination rather than IP alone, preventing local NAT collateral lockout.
2. **[M3-02] Local Font Bundling ("Dancing Script")**
   - Bundle self-hosted WOFF2 font for "Dancing Script" to guarantee identical cursive typography on Linux clients without internet access.
3. **[M3-03] Composer Manifest (`composer.json`)**
   - Add lightweight `composer.json` documenting PHP `>=8.2` and required extensions (`pdo_mysql`, `curl`, `mbstring`, `zip`).

---

## 4. DETAILED RED TEAM CHALLENGE RESOLUTION ANALYSIS

### Challenge 20.2: BDN Early-Stage Validation Trap (`mitra_validasi.php`)
- **Root Cause:** Line 195 currently states:
  ```php
  } elseif ($status === 'DISETUJUI' && ($summary['kelengkapan'] < 100 || empty($summary['all_evaluable']) || ($summary['bukti_kurang_n'] ?? 0) > 0)) {
  ```
  Since `$summary['all_evaluable']` is defined as `($dapatDinilaiCount === 7)`, any partnership having indicators with `BELUM DAPAT DINILAI` (BDN) evaluates to `all_evaluable === false`.
- **The Trap:** Early-stage partnerships (such as P04, where PKS duration is under 90 days and outcomes have not matured) legitimately have BDN indicators. When a validator reviews P04 and submits `DISETUJUI`, line 195 triggers:
  `"Tidak dapat menyetujui: seluruh 7 indikator harus berstatus DAPAT DINILAI / BUKTI MEMADAI."`
  This traps the record in perpetual `BELUM` status, contradicting the Result-Chain methodology.
- **Definitive Fix:**
  Update line 195 to permit validation approval when all evaluable indicators are fully rated and remaining indicators are legitimate BDN:
  ```php
  $hasIncompleteEvidence = ($summary['bukti_kurang_n'] ?? 0) > 0;
  $isFullyRated = ($summary['kelengkapan'] >= 100 && $summary['skor_lengkap']);
  $isLegitimateEarlyStage = ($summary['bdn_n'] > 0 && $isFullyRated && !$hasIncompleteEvidence);
  $isLegitimateMatureStage = (!empty($summary['all_evaluable']) && $isFullyRated && !$hasIncompleteEvidence);

  } elseif ($status === 'DISETUJUI' && !($isLegitimateMatureStage || $isLegitimateEarlyStage)) {
      if ($hasIncompleteEvidence) {
          $errors[] = 'Tidak dapat menyetujui: terdapat indikator dengan status Bukti Belum Memadai.';
      } else {
          $errors[] = 'Tidak dapat menyetujui: kelengkapan indikator belum 100% atau terdapat indikator belum dinilai.';
      }
  }
  ```
  When approved, `syncStatusScorecard` correctly sets `status_scorecard` to:
  - `FINAL/TERVALIDASI` for mature partnerships (`all_evaluable === true`).
  - `MASA IMPLEMENTASI AWAL` for early-stage partnerships (`bdn_n > 0`).

---

### Challenge 20.3: Monev Milestone Over-Advancement (`mitra_edit.php`)
- **Root Cause:** In `mitra_edit.php` (lines 212-224):
  ```php
  if (!$activeSMId) {
      $tglCheck = ($_POST['tanggal_review'] ?? '') !== '' ? $_POST['tanggal_review'] : date('Y-m-d');
      $stmtRecentSM = $pdo->prepare("SELECT id FROM siklus_monev WHERE mitra_id = ? AND status_siklus = 'Selesai' AND tanggal_realisasi_evaluasi = ? ORDER BY siklus_ke DESC LIMIT 1");
      $stmtRecentSM->execute([$id, $tglCheck]);
      $activeSMId = $stmtRecentSM->fetchColumn() ?: null;
  }
  if (!$activeSMId) {
      $stmtNextSM = $pdo->prepare("SELECT id FROM siklus_monev WHERE mitra_id = ? AND status_siklus NOT IN ('Selesai', 'selesai evaluasi') ORDER BY siklus_ke ASC LIMIT 1");
      ...
  ```
  If an evaluator opens an already finalized evaluation weeks later to correct a typo in narrative notes or add an evidence link on a new date `$tglCheck`, `$stmtRecentSM` yields null. The code immediately grabs the NEXT uncompleted cycle (`$stmtNextSM`), sees that indicators are 100% filled, and marks the next cycle `Selesai` with today's date!
- **Definitive Fix:**
  Add an explicit intent guard:
  1. The hidden field `target_siklus_id` must bind strictly to the cycle being reviewed.
  2. If all cycles are already completed or no active cycle is explicitly in progress, do not automatically advance or finalize subsequent cycles without explicit user confirmation.
  3. Update existing completed cycle rather than consuming future cycles when editing past reviews.

---

## 5. PHASE 8 STEP-BY-STEP IMPLEMENTATION BLUEPRINT

### Phase 8 Execution Order

```
[Step 1: Safety Gate & Pre-Migration Backup]
       │
[Step 2: Python Tooling & Dependency Sync] (requirements.txt, openpyxl, pymysql)
       │
[Step 3: Database & PHP Runtime Configuration] (socket, port, session.save_path)
       │
[Step 4: Application Code Hardening] (AI header, BDN trap, monev advance, permissions)
       │
[Step 5: Shell Automation & Service Entrypoints] (start_server.sh, make_backup.sh)
       │
[Step 6: Git Metadata & Line Ending Hygiene] (.gitattributes, POSIX permissions)
       │
[Step 7: Verification Suite Execution & Parity Gate] (live_test_suite, 0 fatal errors)
```

---

### Detailed File Mutation Specifications

#### A. Database Configuration (`config/database.php`)
```php
// Support DB_PORT and DB_SOCKET with intelligent Linux fallback
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: (PHP_OS_FAMILY === 'Windows' ? 'localhost' : '127.0.0.1'));
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
if (!defined('DB_SOCKET')) define('DB_SOCKET', getenv('DB_SOCKET') ?: (file_exists('/home/afra/.local/var/run/mysqld/mysqld.sock') ? '/home/afra/.local/var/run/mysqld/mysqld.sock' : ''));
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'mitra_kinerja');
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (DB_SOCKET !== '') {
            $dsn = "mysql:unix_socket=" . DB_SOCKET . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        } else {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        }
        $multiStmtAttr = defined('\Pdo\Mysql::ATTR_MULTI_STATEMENTS') 
            ? \Pdo\Mysql::ATTR_MULTI_STATEMENTS 
            : PDO::MYSQL_ATTR_MULTI_STATEMENTS;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            $multiStmtAttr               => true,
        ];
        ...
```

#### B. PHP Runtime & Session Configuration (`config/env.php`)
```php
// Ensure session save path is writable across non-root Linux and Windows
if (!headers_sent() && session_status() === PHP_SESSION_NONE) {
    $sessionPath = ini_get('session.save_path');
    if (empty($sessionPath) || !is_writable($sessionPath)) {
        $localSessionDir = __DIR__ . '/../cache/sessions';
        if (!is_dir($localSessionDir)) {
            @mkdir($localSessionDir, 0700, true);
        }
        if (is_writable($localSessionDir)) {
            session_save_path($localSessionDir);
        }
    }
    ...
```

#### C. AI Service Header Fix (`includes/ai_service.php` & `ai_debug_raw.php`)
```php
// Lines 232 & 309 in includes/ai_service.php:
'Authorization: Bearer ' . GROQ_API_KEY,
'Authorization: Bearer ' . OPENROUTER_API_KEY,
```

#### D. Linux Launchers (`start_server.sh` & `make_backup.sh`)
- `start_server.sh`:
  - Shell: `#!/usr/bin/env bash`
  - Dynamic port scanning: 8080, 8085..8100
  - Detect primary LAN/WLAN IP (filtering virtual adapters `vEthernet`, `docker0`)
  - Set `PHP_CLI_SERVER_WORKERS=4`
  - Initialize `cache/sessions` and upload directories with `0775`
  - Launch PHP built-in server with router: `php -S 0.0.0.0:$PORT router.php`
- `make_backup.sh`:
  - Shell: `#!/usr/bin/env bash`
  - Portable invocation: `python3 scripts/make_backup.py "$@"`

#### E. Python Tooling & `requirements.txt`
Author `requirements.txt`:
```
requests>=2.31.0
openpyxl>=3.1.2
pymysql>=1.1.0
```
Update `scripts/make_backup.py` and auxiliary scripts to resolve `mysql` and `mysqldump` dynamically via `shutil.which()`.

---

## 6. VERIFICATION & ACCEPTANCE CRITERIA FOR PHASE 8

Phase 8 implementation will be declared complete when all of the following tests pass with zero errors:

1. **Database Persistence Test:**
   - PHP CLI connects to userland MySQL via socket and TCP without deprecation warnings.
   - All 18 partner records load from `mitra_kinerja` table with 100% data fidelity.
2. **Session & Auth Test:**
   - User `admin` logs in via `login.php`; session cookie `PHPSESSID` is set in `cache/sessions/` without PHP warnings.
   - All 6 role access gates (`admin`, `validator`, `pemeriksa`, `pimpinan`, `pengampu`, `pic`) function correctly.
3. **Red Team Verification:**
   - Partner P04 (with BDN indicators) can be reviewed and successfully approved by validator into `MASA IMPLEMENTASI AWAL`.
   - Editing a partner record with an existing completed monev milestone does NOT prematurely consume subsequent quarterly milestones.
4. **Automated Live Test Suite:**
   - Execute `python3 scripts/live_test_suite_v2_4_2.py http://localhost:8080`.
   - Result must be: **100% PASS RATE (all checks passing, 0 failures, 0 HTTP 500s)**.
5. **Cross-Platform Dual Parity:**
   - Windows `.bat` launchers remain present, valid, and unchanged.
   - Linux `.sh` launchers are executable (`+x`) and functional.

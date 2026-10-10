#!/usr/bin/env python3
"""Comprehensive live test for Mitra Kinerja v2.4.2 bug fixes and features."""
import os
import re
import sys
import requests

BASE = "http://localhost:8080"
if len(sys.argv) > 1:
    BASE = sys.argv[1]
else:
    # Discover active port dynamically (8080, 8085, 8086..8100)
    candidate_ports = [8080, 8085] + list(range(8086, 8101))
    for p in candidate_ports:
        try:
            r_probe = requests.get(f"http://localhost:{p}/login.php", timeout=0.3)
            if r_probe.status_code == 200 and "Mitra Kinerja" in r_probe.text:
                BASE = f"http://localhost:{p}"
                break
        except Exception:
            continue
session = requests.Session()

tests_run = 0
tests_passed = 0

def test(name, condition, detail=""):
    global tests_run, tests_passed
    tests_run += 1
    if condition:
        tests_passed += 1
        print(f"  [PASS] {name}")
    else:
        print(f"  [FAIL] {name} -- {detail}")

print("=" * 70)
print("MITRA KINERJA v2.4.5 -- LIVE EXTENSIVE VERIFICATION SUITE")
print("=" * 70)

def get_csrf(s, path="/login.php"):
    r = s.get(f"{BASE}{path}")
    m = re.search(r'name="csrf_token" value="([^"]+)"', r.text)
    return m.group(1) if m else ""

# 1. AUTHENTICATION & LOGIN
print("\n[1] AUTHENTICATION & CSRF")
r = session.get(f"{BASE}/login.php")
test("Login page loads HTTP 200", r.status_code == 200)

csrf_token = get_csrf(session, "/login.php")
test("Login page includes CSRF token", bool(csrf_token))

# Submit valid demo admin with CSRF token
r_post = session.post(f"{BASE}/login.php", data={"username": "admin", "password": "admin123", "csrf_token": csrf_token}, allow_redirects=True)
test("Admin login succeeds", "dashboard.php" in r_post.url or "MITRA KINERJA" in r_post.text)
test("Session cookie set", "PHPSESSID" in session.cookies)

# Test PHP 8 TypeError protection on array POST parameter
r_array = requests.post(f"{BASE}/login.php", data={"username[]": "admin", "password": "123", "csrf_token": csrf_token})
test("Type safety: array POST parameter handled gracefully (no 500)", r_array.status_code in [200, 302])

# 2. DASHBOARD & KPIS
print("\n[2] DASHBOARD INTEGRITY")
r_dash = session.get(f"{BASE}/dashboard.php")
test("Dashboard HTTP 200", r_dash.status_code == 200)
dashboard_path = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", "dashboard.php"))
with open(dashboard_path, "r", encoding="utf-8") as df:
    dash_src = df.read()
test("DOM XSS protection in formatInsight", "escapeHtml(text)" in dash_src or ("div.textContent = text" in dash_src and "div.innerHTML" in dash_src))
test("Local Chart.js loaded", "chart.umd.min.js" in r_dash.text)
test("North Star metrics present", "Kerja Sama Aktif" in r_dash.text and "Total Naskah" in r_dash.text)
test("Gauge subtitle valid", any(k in r_dash.text.upper() for k in ["KUAT", "CUKUP", "PERLU PERBAIKAN", "KRITIS"]))

# 3. SCORECARD TABLE & V3 RESULT-CHAIN
print("\n[3] SCORECARD TABLE & V3 RESULT-CHAIN")
r_sc = session.get(f"{BASE}/scorecard.php")
test("Scorecard HTTP 200", r_sc.status_code == 200)
test("V3 column headers present", all(h in r_sc.text for h in ["Kode", "Nama Mitra", "Nilai Final", "Status Scorecard", "Kategori Kinerja"]))
test("Integrated scorecard import modal present", "modalImport" in r_sc.text)
test("P03 Pemkot Tanjungpinang present", "P03" in r_sc.text)

# 4. BASELINE 12 ELEMEN & LOCK PROTECTIONS
print("\n[4] BASELINE 12 ELEMEN")
r_base = session.get(f"{BASE}/baseline.php")
test("Baseline ledger HTTP 200", r_base.status_code == 200)
test("Baseline 12 elements rendered", "12 ELEMEN" in r_base.text or "DATA BASELINE" in r_base.text)
test("P01 Dekranasda locked baseline present", "P01" in r_base.text and "🔒 Dikunci" in r_base.text)
test("Integrated baseline import modal present", "modalImport" in r_base.text)

# 5. MITRA EDIT WORKFLOW
print("\n[5] MITRA EDIT & REVIEWER DATA")
r_edit = session.get(f"{BASE}/mitra_edit.php?id=3")
test("Mitra edit P03 HTTP 200", r_edit.status_code == 200)
test("8-field header identity present", all(f in r_edit.text for f in ["Nama Mitra", "Nomor/Tanggal Naskah", "Ruang Lingkup", "Masa Berlaku"]))
test("V3 Result-Chain indicator options present", "DAPAT DINILAI" in r_edit.text and "BUKTI BELUM MEMADAI" in r_edit.text)
test("EWS 4 Area Kontrol present", "Area Kontrol" in r_edit.text)
test("PIC Focal Point input present", "pic_focal_point" in r_edit.text)

# 6. PORTOFOLIO & SEARCH/FILTER
print("\n[6] PORTOFOLIO & FILTERS")
r_port = session.get(f"{BASE}/portofolio.php")
test("Portofolio HTTP 200", r_port.status_code == 200)
test("All filters present", all(filt in r_port.text for filt in ["fPortofolio", "fBidang", "fKategori", "fPosisi", "fRekomendasi", "fStatus"]))
test("Cadangan and Pilot partners present", "P01" in r_port.text and "C01" in r_port.text)

# 7. GATE 0 (PRA-PKS) FEASIBILITY SCREENING
print("\n[7] GATE 0 SCREENING & PROMOTION")
r_gate0 = session.get(f"{BASE}/gate0.php")
test("Gate 0 HTTP 200", r_gate0.status_code == 200)
test("Gate 0 proposal table rendered", "PRA-" in r_gate0.text or "Nomor Usulan" in r_gate0.text)
test("Modal backdrop present in source", "modalBackdrop" in r_gate0.text or "modal-overlay" in r_gate0.text or "background:" in r_gate0.text)

# 8. TINDAK LANJUT LEDGER
print("\n[8] TINDAK LANJUT ACTIVITY LEDGER")
r_tl = session.get(f"{BASE}/tindak_lanjut.php")
test("Tindak Lanjut HTTP 200", r_tl.status_code == 200)
test("Searchable dropdown initialized", "searchable-select" in r_tl.text or "searchable_dropdown.js" in r_tl.text)

# 9. EARLY WARNING MONITORING
print("\n[9] EARLY WARNING")
r_ew = session.get(f"{BASE}/early_warning.php")
test("Early Warning HTTP 200", r_ew.status_code == 200)
test("4-dimension table rendered", "Masa berlaku" in r_ew.text and "Aktivitas/tenggat" in r_ew.text)
test("Severity badges present", any(b in r_ew.text for b in ["E0", "E1", "E2", "E3", "V0"]))

# 10. LAPORAN & EXECUTIVE PRINT PREVIEW
print("\n[10] LAPORAN EKSEKUTIF")
r_lap = session.get(f"{BASE}/laporan.php")
test("Laporan HTTP 200", r_lap.status_code == 200)
test("Official Kop Surat present", "KEMENTERIAN HUKUM" in r_lap.text and "KEPULAUAN RIAU" in r_lap.text)
test("Print button present", "window.print()" in r_lap.text)

# 11. USER MANAGEMENT & CSRF
print("\n[11] USER MANAGEMENT")
r_users = session.get(f"{BASE}/users_list.php")
test("Users list HTTP 200 for admin", r_users.status_code == 200)
test("CSRF token embedded in form", 'name="csrf_token"' in r_users.text)

# 12. ROLE PERMISSION CHECK
print("\n[12] ROLE ACCESS GATING")
roles = ["validator", "pemeriksa", "pimpinan", "pengampu", "pic"]
for role in roles:
    s_role = requests.Session()
    c_tok = get_csrf(s_role, "/login.php")
    s_role.post(f"{BASE}/login.php", data={"username": role, "password": f"{role}123", "csrf_token": c_tok})
    r_role_sc = s_role.get(f"{BASE}/scorecard.php")
    test(f"Role '{role}' can view Scorecard", r_role_sc.status_code == 200)
    
    # Verify non-admins cannot access users_list
    r_role_users = s_role.get(f"{BASE}/users_list.php")
    test(f"Role '{role}' blocked from users_list (403)", r_role_users.status_code == 403)

# 13. CACHE DIRECTORY ACCESS CONTROL
print("\n[13] SECURITY: DIRECTORY ACCESS CONTROLS")
r_cache = requests.get(f"{BASE}/cache/ai_insight.json")
test("cache/ai_insight.json is protected from direct web access (403)", r_cache.status_code in [403, 404])

# 14. ALL 18 PARTNER EDIT PAGES INTEGRITY
print("\n[14] NO HTTP 500 ON ANY PARTNER DETAIL VIEW")
for pid in range(1, 19):
    r_p = session.get(f"{BASE}/mitra_edit.php?id={pid}")
    if r_p.status_code != 200:
        test(f"Partner ID {pid} HTTP 200", False, f"Returned {r_p.status_code}")
        break
else:
    test("All 18 Partner Edit pages return HTTP 200 OK", True)

print("\n" + "=" * 70)
print(f"RESULTS: {tests_passed}/{tests_run} PASSED ({tests_passed/tests_run*100:.1f}%), {tests_run - tests_passed} FAILED")
print("=" * 70)

if tests_passed == tests_run:
    print("ALL VERIFICATION SUITE CHECKS COMPLETED WITH 100% PASS RATE.")
    sys.exit(0)
else:
    sys.exit(1)

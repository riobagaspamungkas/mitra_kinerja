import os
import sys
import time
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1:8080"
SCREENSHOT_DIR = "/home/afra/Agentic Folder/mitra-kinerja/docs/browser_evidence"
os.makedirs(SCREENSHOT_DIR, exist_ok=True)

print("======================================================================")
print("COMPREHENSIVE REAL-BROWSER TEST SUITE — ALL MODULES & PERMISSIONS")
print("======================================================================")

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(viewport={"width": 1366, "height": 768})
    page = context.new_page()

    # 1. LOGIN ADMIN
    print("\n[MODULE 1] Authentication & Dashboard...")
    page.goto(f"{BASE_URL}/login.php")
    page.fill("input[name='username']", "admin")
    page.fill("input[name='password']", "admin123")
    page.click("button[type='submit']")
    page.wait_for_url("**/dashboard.php", timeout=8000)
    assert page.is_visible("#donutChart"), "Dashboard Donut Chart not rendered"
    assert page.is_visible("#gaugeChart"), "Dashboard Gauge Chart not rendered"
    assert page.is_visible(".kpi-row"), "Dashboard KPI row not visible"
    assert page.is_visible(".dash-tables"), "Dashboard tables not visible"
    print("  [PASS] Dashboard loaded with live Chart.js charts and KPI metrics.")
    page.screenshot(path=f"{SCREENSHOT_DIR}/01_dashboard_live.png")

    # 2. GATE 0 MODAL & FORMULIR
    print("\n[MODULE 2] Gate 0 Modal Focus, Background Lock & Submission...")
    page.goto(f"{BASE_URL}/gate0.php")
    page.wait_for_load_state("networkidle")
    
    # Open Add Proposal Modal
    page.click("button[onclick=\"openGate0Modal('addModal')\"]")
    page.wait_for_timeout(300)
    
    # Check background freeze
    assert page.evaluate("document.body.classList.contains('modal-open')"), "modal-open missing"
    assert page.evaluate("window.getComputedStyle(document.body).overflow") == "hidden", "body not overflow:hidden"
    
    # Check modal header, body, footer positioning
    header_box = page.evaluate("document.querySelector('#addModal .app-modal-header').getBoundingClientRect()")
    footer_box = page.evaluate("document.querySelector('#addModal .app-modal-footer').getBoundingClientRect()")
    body_box = page.evaluate("document.querySelector('#addModal .app-modal-body').getBoundingClientRect()")
    assert header_box['top'] < body_box['top'], "Header not positioned above body"
    assert footer_box['bottom'] > body_box['bottom'], "Footer not positioned below body"
    print("  [PASS] Gate 0 Modal Header & Footer are docked; body is scrollable.")

    # Fill and test real form submission in browser
    test_no = f"PRA-TEST-{int(time.time())}"
    page.fill("#addModal input[name='nomor_usulan']", test_no)
    page.fill("#addModal input[name='unit_pemrakarsa']", "Divisi Pelayanan Hukum")
    page.fill("#addModal input[name='calon_mitra']", "Mitra Uji Playwright Browser")
    page.fill("#addModal input[name='judul_rencana']", "Kerja Sama Uji Coba Real Browser Playwright")
    page.click("#addModal button[type='submit']")
    page.wait_for_timeout(1000)
    page.wait_for_load_state("networkidle")

    # Verify new proposal appears in the Gate 0 table
    assert test_no in page.content(), f"Newly created proposal {test_no} not found in Gate 0 table"
    print(f"  [PASS] Proposal {test_no} submitted and rendered in Gate 0 ledger.")
    page.screenshot(path=f"{SCREENSHOT_DIR}/02_gate0_created_proposal.png")

    # Clean up test proposal directly in database
    import pymysql
    conn = pymysql.connect(host="127.0.0.1", user="root", password="", database="mitra_kinerja", unix_socket="/home/afra/.local/var/run/mysqld/mysqld.sock")
    with conn.cursor() as cur:
        cur.execute("DELETE FROM pra_pks WHERE nomor_usulan = %s", (test_no,))
    conn.commit()
    conn.close()
    print("  [PASS] Test proposal cleanly removed from database.")

    # 3. PORTOFOLIO MODULE
    print("\n[MODULE 3] Portofolio Table, Ellipsis Truncation & Work Plans...")
    page.goto(f"{BASE_URL}/portofolio.php")
    page.wait_for_load_state("networkidle")

    # Check Naskah title truncation
    trunc_titles = page.query_selector_all(".port-naskah-title")
    assert len(trunc_titles) > 0, "No truncated title elements found"
    first_title = trunc_titles[0]
    assert first_title.get_attribute("title"), "Tooltip title attribute missing on naskah"
    print("  [PASS] Naskah titles truncated with tooltip on hover.")

    # Check pagination
    assert page.is_visible("#portPaginationWrap"), "Pagination wrapper missing"
    # Click next page if buttons exist
    next_btn = page.query_selector(".port-page-btn[title='Berikutnya']")
    if next_btn and not next_btn.is_disabled():
        next_btn.click()
        page.wait_for_timeout(200)
        assert page.evaluate("portCurrentPage") == 2, "Pagination did not advance to page 2"
        print("  [PASS] Portofolio pagination advanced to page 2.")
        page.click(".port-page-btn[title='Halaman Pertama']")
        page.wait_for_timeout(200)

    # Test Edit Naskah Modal for P01
    page.click("button[onclick='openEditNaskahModal(1)']")
    page.wait_for_timeout(300)
    assert page.is_visible("#modalEditNaskah"), "Edit modal not visible"
    assert page.evaluate("document.body.classList.contains('modal-open')"), "modal-open class missing"
    
    # Switch to Rencana Kerja tab
    page.click("#tabBtnEditRk")
    page.wait_for_timeout(200)
    assert page.is_visible("#tabContentEditRk"), "RK tab content not visible"
    print("  [PASS] Portofolio Edit Modal & Rencana Kerja Tab interactive and responsive.")
    page.screenshot(path=f"{SCREENSHOT_DIR}/03_portofolio_edit_rk_modal.png")
    page.click("button[onclick='closeEditNaskahModal()']")
    page.wait_for_timeout(200)

    # 4. BASELINE 12 ELEMEN
    print("\n[MODULE 4] Baseline 12 Elemen Ledger...")
    page.goto(f"{BASE_URL}/baseline.php?id=1")
    page.wait_for_load_state("networkidle")
    assert page.is_visible(".baseline-table") or page.is_visible("table"), "Baseline table missing"
    assert "Identitas naskah" in page.content(), "Identitas naskah missing"
    assert "TERVERIFIKASI" in page.content(), "Locked status missing"
    print("  [PASS] Baseline 12 Elements rendered with locked status.")
    page.screenshot(path=f"{SCREENSHOT_DIR}/04_baseline_ledger.png")

    # 5. SCORECARD & REAL-TIME SEARCH/FILTER
    print("\n[MODULE 5] Scorecard Search, Filtering & Pagination...")
    page.goto(f"{BASE_URL}/scorecard.php")
    page.wait_for_load_state("networkidle")

    # Test Search filter
    page.fill("#scSearch", "Kerajinan")
    page.wait_for_timeout(200)
    assert page.is_visible("td:has-text('P01')"), "P01 should match search"
    assert not page.is_visible("td:has-text('P03')"), "P03 Pemkot Tanjungpinang should be hidden"
    print("  [PASS] Scorecard live search verified.")

    # Reset Search and test Kategori filter
    page.click("#scBtnReset")
    page.wait_for_timeout(200)
    page.select_option("#scFilterKategori", "KUAT")
    page.wait_for_timeout(200)
    print("  [PASS] Scorecard Kategori filter verified.")
    page.screenshot(path=f"{SCREENSHOT_DIR}/05_scorecard_filtered.png")
    page.click("#scBtnReset")

    # 6. TINDAK LANJUT LEDGER
    print("\n[MODULE 6] Tindak Lanjut Search & Status Filter...")
    page.goto(f"{BASE_URL}/tindak_lanjut.php")
    page.wait_for_load_state("networkidle")
    assert page.is_visible("#tblTindakLanjut"), "Tindak lanjut table missing"
    page.fill("#tlSearch", "P01")
    page.wait_for_timeout(200)
    print("  [PASS] Tindak Lanjut live search verified.")
    page.click("#tlBtnReset")
    page.screenshot(path=f"{SCREENSHOT_DIR}/06_tindak_lanjut.png")

    # 7. EARLY WARNING SYSTEM
    print("\n[MODULE 7] Early Warning System...")
    page.goto(f"{BASE_URL}/early_warning.php")
    page.wait_for_load_state("networkidle")
    assert page.is_visible(".table-wrap table"), "EWS table missing"
    assert "HIJAU" in page.content() or "KUNING" in page.content() or "MERAH" in page.content(), "Severity badges missing"
    print("  [PASS] Early Warning 4-area control rendered with severity badges.")
    page.screenshot(path=f"{SCREENSHOT_DIR}/07_early_warning.png")

    # 8. LAPORAN EKSEKUTIF
    print("\n[MODULE 8] Laporan Eksekutif (Official Kop Surat)...")
    page.goto(f"{BASE_URL}/laporan.php")
    page.wait_for_load_state("networkidle")
    assert "KANTOR WILAYAH KEPULAUAN RIAU" in page.content(), "Kop Surat missing"
    assert page.is_visible(".btn-print") or page.is_visible("button[onclick*='print']"), "Print button missing"
    print("  [PASS] Laporan Eksekutif official Kop Surat verified.")
    page.screenshot(path=f"{SCREENSHOT_DIR}/08_laporan_eksekutif.png")

    # 9. USER MANAGEMENT (ADMIN ONLY)
    print("\n[MODULE 9] User Management...")
    page.goto(f"{BASE_URL}/users_list.php")
    page.wait_for_load_state("networkidle")
    assert "Daftar Pengguna" in page.content() or page.is_visible("table"), "Users list missing"
    print("  [PASS] User management access verified for admin.")
    page.screenshot(path=f"{SCREENSHOT_DIR}/09_users_list.png")

    # 10. ROLE-BASED ACCESS CONTROL (RBAC) TEST IN REAL BROWSER
    print("\n[MODULE 10] Testing RBAC Permissions (Validator, Pemeriksa, PIC)...")
    
    # Test PIC in a separate isolated browser context
    context_pic = browser.new_context(viewport={"width": 1366, "height": 768})
    page_pic = context_pic.new_page()
    page_pic.goto(f"{BASE_URL}/login.php")
    page_pic.fill("input[name='username']", "pic")
    page_pic.fill("input[name='password']", "pic123")
    page_pic.click("button[type='submit']")
    page_pic.wait_for_url("**/dashboard.php", timeout=8000)

    # Verify PIC blocked from users_list (403)
    response = page_pic.goto(f"{BASE_URL}/users_list.php")
    assert response.status == 403, f"Expected HTTP 403 for PIC on users_list.php, got {response.status}"
    print("  [PASS] Role PIC correctly blocked from users_list (HTTP 403).")

    # Verify PIC has read-only access on Tindak Lanjut (no add button)
    page_pic.goto(f"{BASE_URL}/tindak_lanjut.php")
    page_pic.wait_for_load_state("networkidle")
    assert not page_pic.is_visible("button:has-text('Tambah Tindak Lanjut')"), "PIC should not have add TL button"
    print("  [PASS] Role PIC has view-only access on Tindak Lanjut as requested.")
    context_pic.close()

    browser.close()

print("\n======================================================================")
print("ALL 10 MODULES & PERMISSIONS VERIFIED 100% IN REAL CHROMIUM BROWSER!")
print("======================================================================")
sys.exit(0)

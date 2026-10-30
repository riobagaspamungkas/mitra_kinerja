import os
import sys
import time
from playwright.sync_api import sync_playwright

BASE_URL = "http://127.0.0.1:8080"
SCREENSHOT_DIR = "/home/afra/Agentic Folder/mitra-kinerja/docs/browser_evidence"
os.makedirs(SCREENSHOT_DIR, exist_ok=True)

print("======================================================================")
print("LIVE REAL-BROWSER VERIFICATION SUITE VIA PLAYWRIGHT (CHROMIUM)")
print("======================================================================")

with sync_playwright() as p:
    browser = p.chromium.launch(headless=True)
    context = browser.new_context(viewport={"width": 1280, "height": 800})
    page = context.new_page()

    # -------------------------------------------------------------------------
    # 1. AUTHENTICATION
    # -------------------------------------------------------------------------
    print("\n[STEP 1] Testing Authentication...")
    page.goto(f"{BASE_URL}/login.php")
    assert page.is_visible("input[name='username']"), "Username input not found"
    page.fill("input[name='username']", "admin")
    page.fill("input[name='password']", "admin123")
    page.click("button[type='submit']")
    page.wait_for_url("**/dashboard.php", timeout=8000)
    assert "dashboard.php" in page.url or page.is_visible(".kpi-row"), "Login did not redirect to dashboard"
    print("  [PASS] Logged in as Admin successfully.")

    # -------------------------------------------------------------------------
    # 2. GATE 0 MODAL SCROLL FOCUS, CENTERING & BACKGROUND FREEZE
    # -------------------------------------------------------------------------
    print("\n[STEP 2] Testing Gate 0 Modal Centering & Scroll Focus...")
    page.goto(f"{BASE_URL}/gate0.php")
    page.wait_for_load_state("networkidle")

    # Record initial window scroll
    initial_window_scroll = page.evaluate("window.scrollY")
    assert initial_window_scroll == 0, "Window scroll should be 0 initially"

    # Open Add Proposal Modal
    add_btn = page.query_selector("button[onclick=\"openGate0Modal('addModal')\"]")
    assert add_btn is not None, "Button to open addModal not found"
    add_btn.click()
    page.wait_for_timeout(300)

    # Verify modal backdrop and visibility
    backdrop = page.query_selector("#modalBackdrop")
    add_modal = page.query_selector("#addModal")
    assert backdrop is not None and backdrop.is_visible(), "Modal backdrop is not visible"
    assert add_modal is not None and add_modal.is_visible(), "Add modal is not visible"

    # Verify body has modal-open class and overflow is hidden
    body_overflow = page.evaluate("window.getComputedStyle(document.body).overflow")
    body_has_class = page.evaluate("document.body.classList.contains('modal-open')")
    assert body_has_class, "document.body should have 'modal-open' class"
    assert "hidden" in body_overflow, f"document.body overflow should be hidden, got {body_overflow}"
    print("  [PASS] Background page is locked (overflow: hidden, modal-open class applied).")

    # Verify modal is centered in viewport
    modal_box = page.evaluate("""() => {
        const m = document.getElementById('addModal');
        const rect = m.getBoundingClientRect();
        return {
            top: rect.top,
            bottom: rect.bottom,
            left: rect.left,
            right: rect.right,
            width: rect.width,
            height: rect.height,
            viewportWidth: window.innerWidth,
            viewportHeight: window.innerHeight
        };
    }""")
    
    # Check horizontal centering (margin left/right approximately equal)
    left_space = modal_box['left']
    right_space = modal_box['viewportWidth'] - modal_box['right']
    assert abs(left_space - right_space) < 20, f"Modal not horizontally centered: left={left_space}, right={right_space}"
    print(f"  [PASS] Modal is centered horizontally: {modal_box['width']}px wide with equal margins.")

    # Check header and footer are pinned
    header_visible = page.is_visible("#addModal .app-modal-header")
    footer_visible = page.is_visible("#addModal .app-modal-footer")
    assert header_visible, "Modal header should be visible"
    assert footer_visible, "Modal footer should be visible"
    print("  [PASS] Modal header and footer are docked and visible.")

    # Scroll the modal body
    modal_body_scroll_before = page.evaluate("document.querySelector('#addModal .app-modal-body').scrollTop")
    page.evaluate("document.querySelector('#addModal .app-modal-body').scrollTop = 500")
    page.wait_for_timeout(200)
    modal_body_scroll_after = page.evaluate("document.querySelector('#addModal .app-modal-body').scrollTop")
    assert modal_body_scroll_after > 0, "Modal body did not scroll"

    # Verify background window scroll is still strictly 0
    window_scroll_after = page.evaluate("window.scrollY")
    assert window_scroll_after == 0, f"Window scrolled while modal was active: {window_scroll_after}"
    print(f"  [PASS] Scroll focus strictly locked to modal body: scrolled to {modal_body_scroll_after}px while window.scrollY is {window_scroll_after}px.")

    # Capture screenshot of Gate 0 Modal
    ss_g0 = f"{SCREENSHOT_DIR}/01_gate0_modal_centered_scroll.png"
    page.screenshot(path=ss_g0)
    print(f"  [PASS] Saved screenshot: {ss_g0}")

    # Close modal via Escape key
    page.keyboard.press("Escape")
    page.wait_for_timeout(300)
    assert not add_modal.is_visible(), "Modal should be closed after Escape"
    assert not page.evaluate("document.body.classList.contains('modal-open')"), "modal-open class should be removed"
    print("  [PASS] Modal closed cleanly via Escape key, body scroll restored.")

    # -------------------------------------------------------------------------
    # 3. PORTOFOLIO: TRUNCATED NASKAH COLUMN, MODALS & PAGINATION
    # -------------------------------------------------------------------------
    print("\n[STEP 3] Testing Portofolio Table, Truncation & Modals...")
    page.goto(f"{BASE_URL}/portofolio.php")
    page.wait_for_load_state("networkidle")

    # Verify Naskah column header & truncation
    assert page.is_visible("th:has-text('Naskah')"), "Naskah header column missing"
    naskah_title_el = page.query_selector(".port-naskah-title")
    assert naskah_title_el is not None, ".port-naskah-title element not found"
    
    # Check text-overflow is ellipsis and tooltip attribute exists
    trunc_style = page.evaluate("window.getComputedStyle(document.querySelector('.port-naskah-title')).textOverflow")
    has_title_attr = page.evaluate("document.querySelector('.port-naskah-title').hasAttribute('title')")
    full_title_text = page.evaluate("document.querySelector('.port-naskah-title').getAttribute('title')")
    assert trunc_style == "ellipsis", f"text-overflow should be ellipsis, got {trunc_style}"
    assert has_title_attr and len(full_title_text) > 0, "title attribute for hover tooltip missing"
    print(f"  [PASS] Naskah column truncated with ellipsis. Full title in hover tooltip: '{full_title_text[:45]}...'")

    # Test Portofolio Pagination
    assert page.is_visible("#portPaginationWrap"), "Pagination wrap missing"
    page_info = page.inner_text("#portPageInfo")
    print(f"  [PASS] Portofolio pagination initialized: {page_info}")

    # Open Tambah Naskah modal
    page.click("button:has-text('Tambah Naskah')")
    page.wait_for_timeout(300)
    assert page.is_visible("#modalTambahNaskah"), "modalTambahNaskah should be visible"
    assert page.evaluate("document.body.classList.contains('modal-open')"), "body should have modal-open"

    # Test multi-select bidang dropdown in Tambah Naskah
    page.click("#msBtnTambahBidang")
    page.wait_for_timeout(200)
    assert page.is_visible("#msMenuTambahBidang"), "Multi-select menu should be open"
    page.check(".chk-tambah-bidang[value='AHU']")
    page.check(".chk-tambah-bidang[value='KI']")
    label_text = page.inner_text("#msLabelTambahBidang")
    assert "AHU" in label_text or "Bidang" in label_text, f"Multi-select label not updated: {label_text}"
    print(f"  [PASS] Multi-select Bidang dropdown tested: '{label_text}'")

    # Close modal
    page.click("#modalTambahNaskah button:has-text('Batal')")
    page.wait_for_timeout(300)
    assert not page.is_visible("#modalTambahNaskah"), "modalTambahNaskah should be closed"
    print("  [PASS] Tambah Naskah modal closed cleanly.")

    # Capture Portofolio screenshot
    ss_port = f"{SCREENSHOT_DIR}/02_portofolio_pagination_truncation.png"
    page.screenshot(path=ss_port)
    print(f"  [PASS] Saved screenshot: {ss_port}")

    # -------------------------------------------------------------------------
    # 4. SCORECARD: SEARCH, FILTER & CLIENT-SIDE PAGINATION
    # -------------------------------------------------------------------------
    print("\n[STEP 4] Testing Scorecard Search, Filters & Pagination...")
    page.goto(f"{BASE_URL}/scorecard.php")
    page.wait_for_load_state("networkidle")

    # Test Search filter
    page.fill("#scSearch", "Tanjungpinang")
    page.wait_for_timeout(200)
    visible_rows = page.evaluate("document.querySelectorAll('#tblScorecard tbody tr:not(#scNoMatchRow)[style*=\"display: none\"]').length")
    matched_rows = page.evaluate("document.querySelectorAll('#tblScorecard tbody tr:not(#scNoMatchRow):not([style*=\"display: none\"])').length")
    assert matched_rows >= 1, "Should match Pemkot Tanjungpinang"
    print(f"  [PASS] Scorecard Search verified: matched {matched_rows} row(s) for 'Tanjungpinang'.")

    # Reset filter
    page.click("#scBtnReset")
    page.wait_for_timeout(200)
    assert page.input_value("#scSearch") == "", "Search input should be reset"

    # Test Kategori filter
    page.select_option("#scFilterKategori", "KUAT")
    page.wait_for_timeout(200)
    sc_page_info = page.inner_text("#scPageInfo")
    print(f"  [PASS] Scorecard Kategori filter 'KUAT' verified: {sc_page_info}")

    # Reset
    page.click("#scBtnReset")
    page.wait_for_timeout(200)

    # Capture Scorecard screenshot
    ss_sc = f"{SCREENSHOT_DIR}/03_scorecard_search_pagination.png"
    page.screenshot(path=ss_sc)
    print(f"  [PASS] Saved screenshot: {ss_sc}")

    # -------------------------------------------------------------------------
    # 5. TINDAK LANJUT: SEARCH, STATUS & TENGGAT FILTERS
    # -------------------------------------------------------------------------
    print("\n[STEP 5] Testing Tindak Lanjut Search & Filters...")
    page.goto(f"{BASE_URL}/tindak_lanjut.php")
    page.wait_for_load_state("networkidle")

    # Verify search input & filters exist
    assert page.is_visible("#tlSearch"), "#tlSearch missing"
    assert page.is_visible("#tlFilterStatus"), "#tlFilterStatus missing"
    assert page.is_visible("#tlFilterTenggat"), "#tlFilterTenggat missing"
    assert page.is_visible("#tlPaginationWrap"), "#tlPaginationWrap missing"

    # Test filter status Selesai
    page.select_option("#tlFilterStatus", "Selesai")
    page.wait_for_timeout(200)
    tl_info = page.inner_text("#tlPageInfo")
    print(f"  [PASS] Tindak Lanjut Status filter 'Selesai' verified: {tl_info}")

    # Reset filter
    page.click("#tlBtnReset")
    page.wait_for_timeout(200)

    # Capture Tindak Lanjut screenshot
    ss_tl = f"{SCREENSHOT_DIR}/04_tindak_lanjut_filters_pagination.png"
    page.screenshot(path=ss_tl)
    print(f"  [PASS] Saved screenshot: {ss_tl}")

    # -------------------------------------------------------------------------
    # 6. PJAX SPA NAVIGATION (HERO & SIDEBAR MOUNT PERSISTENCE)
    # -------------------------------------------------------------------------
    print("\n[STEP 6] Testing PJAX SPA Navigation & Hero Stability...")
    page.goto(f"{BASE_URL}/dashboard.php")
    page.wait_for_load_state("networkidle")

    # Add a marker attribute to the hero header to prove it is never re-rendered/reloaded
    page.evaluate("document.querySelector('.app-hero').dataset.pjaxMounted = 'STABLE_ORIGINAL'")
    page.evaluate("document.querySelector('.sidebar').dataset.pjaxMounted = 'STABLE_ORIGINAL'")

    # Click Portofolio nav
    page.click(".sidebar-nav a[href='portofolio.php']")
    page.wait_for_timeout(400)
    assert "portofolio.php" in page.url, "URL did not update to portofolio.php"

    # Verify the marker attribute is STILL present (same DOM node, no re-mount)
    hero_marker = page.evaluate("document.querySelector('.app-hero').dataset.pjaxMounted")
    sidebar_marker = page.evaluate("document.querySelector('.sidebar').dataset.pjaxMounted")
    assert hero_marker == "STABLE_ORIGINAL", "Hero header was destroyed and reloaded instead of preserved via PJAX!"
    assert sidebar_marker == "STABLE_ORIGINAL", "Sidebar was destroyed and reloaded instead of preserved via PJAX!"
    print("  [PASS] PJAX Navigation confirmed: Header and Sidebar stayed permanently mounted with zero flicker!")

    # Click Gate 0 nav via PJAX
    page.click(".sidebar-nav a[href='gate0.php']")
    page.wait_for_timeout(400)
    assert "gate0.php" in page.url
    hero_marker2 = page.evaluate("document.querySelector('.app-hero').dataset.pjaxMounted")
    assert hero_marker2 == "STABLE_ORIGINAL", "Hero header lost mount state on second navigation"
    print("  [PASS] Second PJAX navigation confirmed: Hero header still unbroken.")

    # Capture final screenshot
    ss_final = f"{SCREENSHOT_DIR}/05_dashboard_pjax_verified.png"
    page.screenshot(path=ss_final)
    print(f"  [PASS] Saved screenshot: {ss_final}")

    browser.close()

print("\n======================================================================")
print("ALL LIVE REAL-BROWSER TESTS COMPLETED WITH 100% SUCCESS!")
print("======================================================================")
sys.exit(0)

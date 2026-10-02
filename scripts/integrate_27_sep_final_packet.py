import openpyxl, os, glob, re, subprocess, shutil

print("=== INGESTING 27 SEPTEMBER 2026 FINAL DATASET ===")

base_dir = r"docs/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE (1)/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE"
base_file = os.path.join(base_dir, "02_BASELINE_FINAL", "FINAL_BASELINE_MITRA_KINERJA_AUDIT_FINAL_28_AGUSTUS_2026.xlsx")
sc_dir = os.path.join(base_dir, "01_SCORECARD_FINAL_27_SEPTEMBER_2026")

def run_sql(query):
    p = subprocess.run([r"C:\xampp\mysql\bin\mysql.exe", "-u", "root", "mitra_kinerja", "-e", query], capture_output=True, text=True)
    return p.stdout

def esc(s):
    if s is None:
        return ""
    return str(s).replace('\\', '\\\\').replace("'", "''")

# 1. Ingest 15 Baseline Sheets from FINAL_BASELINE_MITRA_KINERJA_AUDIT_FINAL_28_AGUSTUS_2026.xlsx
wb_base = openpyxl.load_workbook(base_file, data_only=True)
sheet_to_mitra_id = {
    'P01_DEKRANASDA': 1,
    'fFitriadi P02_BNNP_KEPRI': 2,
    'FIschika_P03_PEMKOT_TPI': 3,
    'fFitra P04_BAPPERIDA_BINTAN': 4,
    'FNina_P05_STAIN_SAR': 5,
    'fEnjo_P06_UMRAH': 6,
    'FNadia_P07_STAI_ANAMBAS': 7,
    'fFitra_P08_POLIBATAM': 8,
    'FNina_P09_STAI_NATUNA': 9,
    'fEnjo_P10_STISIP_BTM': 10,
    'FChika_C01_PBC': 11,
    'fFitriadi_C02_STIT_MUMTAZ': 12,
    'fNadia_C03_UIS': 13,
    'Fitra_C04_UNRIKA': 14,
    'FNina_C05_STIE_CAKRAWALA': 15,
}

total_baseline_elements = 0
for sheet_name, mid in sheet_to_mitra_id.items():
    if sheet_name not in wb_base.sheetnames:
        continue
    ws = wb_base[sheet_name]
    pemeriksa = ws.cell(5, 8).value or ws.cell(5, 7).value or 'Pokja Data'
    cutoff = ws.cell(8, 8).value or '2026-08-28'
    cutoff_str = cutoff.strftime('%Y-%m-%d') if hasattr(cutoff, 'strftime') else str(cutoff).split()[0]
    p2ma_link_e1 = None

    for r in range(13, 25):
        el_num_val = ws.cell(r, 1).value
        if not el_num_val or not str(el_num_val).strip().isdigit():
            continue
        el_num = int(el_num_val)
        kelompok = str(ws.cell(r, 2).value or '').strip()
        nama_el = str(ws.cell(r, 3).value or '').strip()
        yang_diperiksa = str(ws.cell(r, 4).value or '').strip()
        sumber_min = str(ws.cell(r, 5).value or '').strip()
        raw_status = str(ws.cell(r, 6).value or 'BELUM DIISI').strip().upper()
        fakta = str(ws.cell(r, 7).value or '').strip()
        link_bukti = str(ws.cell(r, 8).value or '').strip()
        catatan = str(ws.cell(r, 9).value or '').strip()

        status = 'BELUM DIISI'
        for vs in ['TERVERIFIKASI', 'BELUM TERVERIFIKASI', 'BELUM TERSEDIA', 'TIDAK RELEVAN', 'BELUM DIISI']:
            if vs in raw_status:
                status = vs
                break

        if el_num == 1 and 'p2ma' in link_bukti.lower():
            m_url = re.search(r'https?://[^\s\)\"\']+', link_bukti)
            if m_url:
                p2ma_link_e1 = m_url.group(0)

        chk = run_sql(f"SELECT id FROM baseline_elemen WHERE mitra_id = {mid} AND nomor_elemen = {el_num};")
        if "id" in chk and len(chk.strip().splitlines()) > 1:
            sql_be = f"""
            UPDATE baseline_elemen 
            SET status = '{esc(status)}', fakta_pemeriksaan = '{esc(fakta)}', 
                link_sumber_bukti = '{esc(link_bukti)}', catatan = '{esc(catatan)}'
            WHERE mitra_id = {mid} AND nomor_elemen = {el_num};
            """
        else:
            sql_be = f"""
            INSERT INTO baseline_elemen (mitra_id, nomor_elemen, kelompok, nama_elemen, yang_diperiksa, sumber_bukti_minimum, status, fakta_pemeriksaan, link_sumber_bukti, catatan)
            VALUES ({mid}, {el_num}, '{esc(kelompok)}', '{esc(nama_el)}', '{esc(yang_diperiksa)}', '{esc(sumber_min)}', '{esc(status)}', '{esc(fakta)}', '{esc(link_bukti)}', '{esc(catatan)}');
            """
        run_sql(sql_be)
        total_baseline_elements += 1

    sql_mk = f"""
    UPDATE mitra_kinerja 
    SET baseline_status = 'TERVERIFIKASI / DIKUNCI',
        baseline_locked_at = '2026-08-28 23:59:59',
        baseline_pemeriksa = '{esc(str(pemeriksa))}',
        cutoff_date = '{cutoff_str}'
    """
    if p2ma_link_e1:
        sql_mk += f", file_naskah = '{p2ma_link_e1}'"
    sql_mk += f" WHERE id = {mid};"
    run_sql(sql_mk)

print(f"[1/4] Baseline Ingested: {total_baseline_elements} elements across 15 partnerships.")

# 2. Ingest 10 Scorecards from 01_SCORECARD_FINAL_27_SEPTEMBER_2026
sc_map = {
    'Scorecard_P01_DEKRANASDA_KEPRI_27_Sep_2026.xlsx': 1,
    'Scorecard_P02_BNNP_MASA_IMPLEMENTASI_AWAL_27_Sep_2026.xlsx': 2,
    'Scorecard_P03_PEMKOT_TANJUNGPINANG_FINAL_27_Sep_2026.xlsx': 3,
    'Scorecard_P04_BAPPERIDA_BINTAN_27_Sep_2026.xlsx': 4,
    'Scorecard_P05_STAIN_SAR_KEPRI_27_Sep_2026.xlsx': 5,
    'Scorecard_P06_UMRAH_27_Sep_2026.xlsx': 6,
    'Scorecard_P07_STAI_ANAMBAS_27_Sep_2026.xlsx': 7,
    'Scorecard_P08_POLIBATAM_27_Sep_2026.xlsx': 8,
    'Scorecard_P09_STAI_NATUNA_27_Sep_2026.xlsx': 9,
    'Scorecard_P10_STISIP_BATAM_27_Sep_2026.xlsx': 10,
}

total_sc_indicators = 0
weights = {'I1': 10, 'I2': 15, 'I3': 15, 'I4': 20, 'I5': 20, 'I6': 10, 'I7': 10}

for sc_file_name, mid in sc_map.items():
    sc_path = os.path.join(sc_dir, sc_file_name)
    if not os.path.exists(sc_path):
        continue
    wb_sc = openpyxl.load_workbook(sc_path, data_only=True)
    pen = wb_sc['PENILAIAN']
    rek = wb_sc['REKOMENDASI']

    raw_status = str(pen.cell(27, 2).value or '').strip()
    posisi = str(rek.cell(9, 2).value or '').strip()
    rekom_text = str(rek.cell(17, 2).value or '').strip()
    temuan = str(rek.cell(16, 2).value or '').strip()
    tindak_lanjut = str(rek.cell(19, 2).value or '').strip()
    full_rekom = f"{rekom_text}\n\nTemuan Utama:\n{temuan}\n\nRencana Tindak Lanjut:\n{tindak_lanjut}".strip()

    if "SIAP DIVALIDASI" in raw_status.upper():
        sc_status_db = "SIAP DIVALIDASI"
    elif "MASA IMPLEMENTASI AWAL" in raw_status.upper():
        sc_status_db = "MASA IMPLEMENTASI AWAL"
    else:
        sc_status_db = raw_status.upper()

    for r in range(13, 20):
        c1 = str(pen.cell(r, 1).value or '').strip()
        m_code = re.match(r'^(I[1-7])', c1)
        if not m_code:
            continue
        ind_code = m_code.group(1)
        bobot = weights.get(ind_code, 10)

        kondisi_baseline = str(pen.cell(r, 4).value or '').strip()
        kondisi_saat_ini = str(pen.cell(r, 5).value or '').strip()
        status_penilaian = str(pen.cell(r, 6).value or '').strip().upper()
        evidence_loc = str(pen.cell(r, 7).value or '').strip()
        skor_val = pen.cell(r, 8).value
        nilai_val = pen.cell(r, 9).value
        alasan_skor = str(pen.cell(r, 10).value or '').strip()
        catatan_tl = str(pen.cell(r, 11).value or '').strip()

        if "BELUM DAPAT DINILAI" in status_penilaian:
            status_pem_db = "BELUM DAPAT DINILAI"
        elif "DAPAT DINILAI" in status_penilaian or "BUKTI MEMADAI" in status_penilaian:
            status_pem_db = "BUKTI MEMADAI"
        elif "BUKTI BELUM MEMADAI" in status_penilaian:
            status_pem_db = "BUKTI BELUM MEMADAI"
        else:
            status_pem_db = status_penilaian

        skor_sql = "NULL" if skor_val is None or str(skor_val).strip() == '' else str(int(float(skor_val)))
        nilai_sql = "NULL" if nilai_val is None or str(nilai_val).strip() == '' else str(round(float(nilai_val), 2))

        chk_ind = run_sql(f"SELECT id FROM indikator_skor WHERE mitra_id = {mid} AND kode_indikator = '{ind_code}';")
        if "id" in chk_ind and len(chk_ind.strip().splitlines()) > 1:
            sql_ind = f"""
            UPDATE indikator_skor
            SET status_pemeriksaan = '{esc(status_pem_db)}',
                kondisi_baseline = '{esc(kondisi_baseline)}',
                kondisi_saat_ini = '{esc(kondisi_saat_ini)}',
                temuan_bukti = '{esc(evidence_loc)}',
                skor = {skor_sql},
                alasan_skor = '{esc(alasan_skor)}',
                catatan_tindak_lanjut = '{esc(catatan_tl)}',
                nilai = {nilai_sql},
                referensi_baseline = '{esc(kondisi_baseline)}'
            WHERE mitra_id = {mid} AND kode_indikator = '{ind_code}';
            """
        else:
            sql_ind = f"""
            INSERT INTO indikator_skor (mitra_id, kode_indikator, deskripsi, bobot, referensi_baseline, kondisi_baseline, status_pemeriksaan, kondisi_saat_ini, temuan_bukti, skor, alasan_skor, catatan_tindak_lanjut, nilai)
            VALUES ({mid}, '{ind_code}', 'Indikator {ind_code}', {bobot}, '{esc(kondisi_baseline)}', '{esc(kondisi_baseline)}', '{esc(status_pem_db)}', '{esc(kondisi_saat_ini)}', '{esc(evidence_loc)}', {skor_sql}, '{esc(alasan_skor)}', '{esc(catatan_tl)}', {nilai_sql});
            """
        run_sql(sql_ind)
        total_sc_indicators += 1

    sql_up_mk = f"""
    UPDATE mitra_kinerja
    SET status_scorecard = '{esc(sc_status_db)}',
        posisi_portofolio = '{esc(posisi)}',
        rekomendasi = '{esc(full_rekom)}'
    WHERE id = {mid};
    """
    run_sql(sql_up_mk)

print(f"[2/4] Scorecard Ingested: {total_sc_indicators} indicators across 10 partnerships.")

# 3. Copy official template files to public/templates/import_naskah/
dest_sc_dir = r"public/templates/import_naskah/01_Scorecard_Final_27_Sep"
dest_base_dir = r"public/templates/import_naskah/02_Baseline_Final"
os.makedirs(dest_sc_dir, exist_ok=True)
os.makedirs(dest_base_dir, exist_ok=True)

for sf in glob.glob(os.path.join(sc_dir, "*.xlsx")):
    shutil.copy2(sf, dest_sc_dir)
shutil.copy2(base_file, dest_base_dir)
print(f"[3/4] Templates deployed to {dest_sc_dir} and {dest_base_dir}.")

# 4. Ingest field activities from SUMBER_AKTUAL into tindak_lanjut if available
total_tl_added = 0
for sc_file_name, mid in sc_map.items():
    sc_path = os.path.join(sc_dir, sc_file_name)
    wb_sc = openpyxl.load_workbook(sc_path, data_only=True)
    if 'SUMBER_AKTUAL' in wb_sc.sheetnames:
        ws_act = wb_sc['SUMBER_AKTUAL']
        for r in range(5, 20):
            sumber = ws_act.cell(r, 2).value
            fakta = ws_act.cell(r, 4).value
            catatan = ws_act.cell(r, 6).value
            if fakta and str(fakta).strip():
                fakta_str = str(fakta).strip()
                chk_tl = run_sql(f"SELECT id FROM tindak_lanjut WHERE mitra_id = {mid} AND deskripsi LIKE '%{esc(fakta_str[:30])}%';")
                if "id" not in chk_tl or len(chk_tl.strip().splitlines()) <= 1:
                    tenggat = '2026-10-31'
                    sql_tl = f"""
                    INSERT INTO tindak_lanjut (mitra_id, indikator_terkait, deskripsi, tenggat, penanggung_jawab, status, catatan)
                    VALUES ({mid}, 'I2', '{esc(fakta_str)}', '{tenggat}', 'Pokja / Mitra', 'Selesai', '{esc(str(catatan or ''))}');
                    """
                    run_sql(sql_tl)
                    total_tl_added += 1

print(f"[4/4] Ingested {total_tl_added} authentic follow-up items from SUMBER_AKTUAL.")

# 5. Sync Scorecard Total Scores via PHP
p_sync = subprocess.run(['php', '-r', """
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/data.php';
$pdo = getDB();
for ($i = 1; $i <= 18; $i++) {
    syncStatusScorecard($pdo, $i);
}
echo 'Scores synced successfully.' . PHP_EOL;
"""], capture_output=True, text=True)
print(p_sync.stdout)

print("=== INGESTION OF 27 SEPTEMBER DATASET COMPLETED 100% ===")

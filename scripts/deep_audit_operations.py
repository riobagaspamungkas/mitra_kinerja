import os
import re
import json
import subprocess
import shutil
import openpyxl

print("=" * 70)
print("DEEP AUDIT OPERATIONS PARITY: DATABASE VS REFERENCE DOCS")
print("=" * 70)

def get_mysql_cmd(extra_args=None):
    candidates = [
        os.path.expanduser("~/.local/bin/mysql"),
        r"C:\xampp\mysql\bin\mysql.exe",
        r"D:\xampp\mysql\bin\mysql.exe",
    ]
    mysql_cmd = "mysql"
    for c in candidates:
        if os.path.exists(c):
            mysql_cmd = c
            break
    else:
        resolved = shutil.which("mysql") or shutil.which("mariadb")
        if resolved:
            mysql_cmd = resolved
    
    cmd_base = [mysql_cmd, "-u", "root"]
    if os.name != 'nt':
        cmd_base.extend(["-h", "127.0.0.1", "--port", "3306"])
    cmd_base.extend(["--default-character-set=utf8mb4", "mitra_kinerja"])
    if extra_args:
        cmd_base.extend(extra_args)
    return cmd_base

def run_sql(q):
    cmd = get_mysql_cmd(["-e", q, "-B"])
    p = subprocess.run(cmd, capture_output=True)
    return p.stdout.decode('utf-8', errors='replace')

# 1. Load Mitra Kinerja
mk_out = run_sql('SELECT id, kode, nama_mitra, portofolio, jenis, bidang, tanggal_mulai, tanggal_berakhir, evaluasi_per_tahun, status_scorecard, baseline_status FROM mitra_kinerja ORDER BY id;')
lines = [l.split('\t') for l in mk_out.strip().splitlines() if l.strip()]
headers = lines[0]
partners = {int(r[0]): dict(zip(headers, r)) for r in lines[1:]}

print(f"Total Partners loaded: {len(partners)}")

# 2. Audit tindak_lanjut
tl_out = run_sql('SELECT id, mitra_id, tindakan, tenggat, status, file_bukti FROM tindak_lanjut ORDER BY mitra_id, id;')
tl_lines = [l.split('\t') for l in tl_out.strip().splitlines() if l.strip()]
tl_headers = tl_lines[0]
tl_rows = [dict(zip(tl_headers, r)) for r in tl_lines[1:]]

print(f"\n--- AUDIT 1: TINDAK_LANJUT ---")
print(f"Total rows in DB: {len(tl_rows)}")
by_partner = {}
for r in tl_rows:
    mid = int(r['mitra_id'])
    by_partner.setdefault(mid, []).append(r)

for mid in sorted(partners.keys()):
    items = by_partner.get(mid, [])
    has_links = [i for i in items if i['file_bukti'] and i['file_bukti'] != 'NULL']
    print(f"Partner {mid:02d} ({partners[mid]['kode']}): {len(items)} items, {len(has_links)} with evidence link/ref")
    for i in items:
        fb = i['file_bukti'] if i['file_bukti'] != 'NULL' else '-'
        print(f"   [ID {i['id']}] Status: {i['status']} | Tenggat: {i['tenggat']} | Bukti: {fb[:60]} | Tindakan: {i['tindakan'][:70]}...")

# 3. Audit rencana_kerja
rk_out = run_sql('SELECT id, mitra_id, judul_rencana, tanggal_mulai, tanggal_selesai, status FROM rencana_kerja ORDER BY mitra_id, id;')
rk_lines = [l.split('\t') for l in rk_out.strip().splitlines() if l.strip()]
rk_headers = rk_lines[0]
rk_rows = [dict(zip(rk_headers, r)) for r in rk_lines[1:]]

print(f"\n--- AUDIT 2: RENCANA_KERJA ---")
print(f"Total rows in DB: {len(rk_rows)}")
rk_by_partner = {}
for r in rk_rows:
    mid = int(r['mitra_id'])
    rk_by_partner.setdefault(mid, []).append(r)

for mid in sorted(partners.keys()):
    p = partners[mid]
    items = rk_by_partner.get(mid, [])
    print(f"Partner {mid:02d} ({p['kode']}): {len(items)} work plans | Contract: {p['tanggal_mulai']} s.d {p['tanggal_berakhir']}")
    for i in items:
        is_anomalous = (i['tanggal_mulai'] < p['tanggal_mulai'] or i['tanggal_selesai'] > p['tanggal_berakhir'])
        mark = " [ANOMALY: OUTSIDE CONTRACT!]" if is_anomalous else " [OK]"
        print(f"   [ID {i['id']}]{mark} {i['tanggal_mulai']} s.d {i['tanggal_selesai']} | {i['judul_rencana'][:70]}...")

# 4. Audit siklus_monev
sm_out = run_sql('SELECT id, mitra_id, rencana_kerja_id, siklus_ke, nama_siklus, tanggal_target_evaluasi, tanggal_realisasi_evaluasi, status_siklus, nilai_siklus FROM siklus_monev ORDER BY mitra_id, siklus_ke;')
sm_lines = [l.split('\t') for l in sm_out.strip().splitlines() if l.strip()]
sm_headers = sm_lines[0]
sm_rows = [dict(zip(sm_headers, r)) for r in sm_lines[1:]]

print(f"\n--- AUDIT 3: SIKLUS_MONEV ---")
print(f"Total rows in DB: {len(sm_rows)}")
sm_by_partner = {}
for r in sm_rows:
    mid = int(r['mitra_id'])
    sm_by_partner.setdefault(mid, []).append(r)

for mid in sorted(partners.keys()):
    p = partners[mid]
    items = sm_by_partner.get(mid, [])
    print(f"Partner {mid:02d} ({p['kode']}): {len(items)} milestones | Contract: {p['tanggal_mulai']} s.d {p['tanggal_berakhir']}")
    for i in items[:3]:
        is_anomalous = (i['tanggal_target_evaluasi'] < p['tanggal_mulai'] or i['tanggal_target_evaluasi'] > p['tanggal_berakhir'])
        mark = " [ANOMALY: OUTSIDE CONTRACT!]" if is_anomalous else " [OK]"
        print(f"   [Siklus {i['siklus_ke']}]{mark} Target: {i['tanggal_target_evaluasi']} | Status: {i['status_siklus']} | Nama: {i['nama_siklus']}")

# 5. Audit early_warning
ew_out = run_sql('SELECT id, mitra_id, dimensi, kondisi, status, fakta_bukti, tindakan, pic, tenggat, progres FROM early_warning ORDER BY mitra_id, id;')
ew_lines = [l.split('\t') for l in ew_out.strip().splitlines() if l.strip()]
ew_headers = ew_lines[0]
ew_rows = [dict(zip(ew_headers, r)) for r in ew_lines[1:]]

print(f"\n--- AUDIT 4: EARLY_WARNING (4 DIMENSIONS X 18 PARTNERS) ---")
print(f"Total rows in DB: {len(ew_rows)} (Expected: {18 * 4} = 72)")
ew_by_partner = {}
for r in ew_rows:
    mid = int(r['mitra_id'])
    ew_by_partner.setdefault(mid, []).append(r)

for mid in sorted(partners.keys()):
    items = ew_by_partner.get(mid, [])
    filled = [i for i in items if i['fakta_bukti'] and i['fakta_bukti'] != 'NULL']
    print(f"Partner {mid:02d} ({partners[mid]['kode']}): {len(items)} dimensions, {len(filled)} populated with real text")

# 6. Audit intervensi_pimpinan
ip_out = run_sql('SELECT id, mitra_id, no_pemicu, pemicu_teks, jawaban, bukti_alasan FROM intervensi_pimpinan ORDER BY mitra_id, no_pemicu;')
ip_lines = [l.split('\t') for l in ip_out.strip().splitlines() if l.strip()]
ip_headers = ip_lines[0]
ip_rows = [dict(zip(ip_headers, r)) for r in ip_lines[1:]]

print(f"\n--- AUDIT 5: INTERVENSI_PIMPINAN (5 TRIGGERS X 18 PARTNERS) ---")
print(f"Total rows in DB: {len(ip_rows)} (Expected: {18 * 5} = 90)")
ip_by_partner = {}
for r in ip_rows:
    mid = int(r['mitra_id'])
    ip_by_partner.setdefault(mid, []).append(r)

for mid in sorted(partners.keys()):
    items = ip_by_partner.get(mid, [])
    jawaban_counts = {}
    for i in items:
        jawaban_counts[i['jawaban']] = jawaban_counts.get(i['jawaban'], 0) + 1
    print(f"Partner {mid:02d} ({partners[mid]['kode']}): {len(items)} triggers | Responses: {jawaban_counts}")

# 7. Audit intervensi_usulan
iu_out = run_sql('SELECT id, mitra_id, upaya_dilakukan, uraian_kendala, keputusan_diminta FROM intervensi_usulan ORDER BY mitra_id;')
iu_lines = [l.split('\t') for l in iu_out.strip().splitlines() if l.strip()]
iu_headers = iu_lines[0]
iu_rows = [dict(zip(iu_headers, r)) for r in iu_lines[1:]]

print(f"\n--- AUDIT 6: INTERVENSI_USULAN (FOR P01, P03, P04) ---")
print(f"Total rows in DB: {len(iu_rows)}")
for r in iu_rows:
    mid = int(r['mitra_id'])
    print(f"Partner {mid:02d} ({partners.get(mid, {}).get('kode', '?')}): Upaya='{r['upaya_dilakukan']}', Kendala='{r['uraian_kendala']}', Keputusan='{r['keputusan_diminta']}'")

# 8. Audit validasi
val_out = run_sql('SELECT v.id, v.mitra_id, v.status, v.validator_id, v.tanggal_validasi, v.catatan FROM validasi v ORDER BY v.mitra_id;')
val_lines = [l.split('\t') for l in val_out.strip().splitlines() if l.strip()]
val_headers = val_lines[0]
val_rows = [dict(zip(val_headers, r)) for r in val_lines[1:]]

print(f"\n--- AUDIT 7: VALIDASI (ALL 18 PARTNERS) ---")
print(f"Total rows in DB: {len(val_rows)} (Expected: 18)")
for r in val_rows:
    mid = int(r['mitra_id'])
    cat = r['catatan'] if r['catatan'] != 'NULL' else '-'
    print(f"Partner {mid:02d} ({partners[mid]['kode']}): Status={r['status']} | Catatan={cat[:60]}...")

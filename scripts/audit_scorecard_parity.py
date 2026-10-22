"""
Comprehensive Audit Script: 1:1 Database Parity for Scorecard Indicators & Partner Metadata
Compares Database vs Authoritative Docs Workbooks
"""

import openpyxl
import pymysql
import os
import re
import json

base_27_sep = r'docs/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE (1)/PAKET_FINAL_SCORECARD_27_SEPTEMBER_DAN_BASELINE'
base_final = r'docs/Paket_Scorecard_dan_Baseline_Final/Paket_Scorecard_dan_Baseline_Final'

wb_map = {
    1: ('01_SCORECARD_FINAL_27_SEPTEMBER_2026', 'Scorecard_P01_DEKRANASDA_KEPRI_27_Sep_2026.xlsx', 'PENILAIAN'),
    2: ('01_SCORECARD_FINAL_27_SEPTEMBER_2026', 'Scorecard_P02_BNNP_MASA_IMPLEMENTASI_AWAL_27_Sep_2026.xlsx', 'PENILAIAN'),
    3: ('01_SCORECARD_FINAL_27_SEPTEMBER_2026', 'Scorecard_P03_PEMKOT_TANJUNGPINANG_FINAL_27_Sep_2026.xlsx', 'PENILAIAN'),
    4: ('01_SCORECARD_FINAL_27_SEPTEMBER_2026', 'Scorecard_P04_BAPPERIDA_BINTAN_27_Sep_2026.xlsx', 'PENILAIAN'),
    5: ('01_SCORECARD_FINAL_27_SEPTEMBER_2026', 'Scorecard_P05_STAIN_SAR_KEPRI_27_Sep_2026.xlsx', 'PENILAIAN'),
    6: ('01_SCORECARD_FINAL_27_SEPTEMBER_2026', 'Scorecard_P06_UMRAH_27_Sep_2026.xlsx', 'PENILAIAN'),
    7: ('01_SCORECARD_FINAL_27_SEPTEMBER_2026', 'Scorecard_P07_STAI_ANAMBAS_27_Sep_2026.xlsx', 'PENILAIAN'),
    8: ('01_SCORECARD_FINAL_27_SEPTEMBER_2026', 'Scorecard_P08_POLIBATAM_27_Sep_2026.xlsx', 'PENILAIAN'),
    9: ('01_SCORECARD_FINAL_27_SEPTEMBER_2026', 'Scorecard_P09_STAI_NATUNA_27_Sep_2026.xlsx', 'PENILAIAN'),
    10: ('01_SCORECARD_FINAL_27_SEPTEMBER_2026', 'Scorecard_P10_STISIP_BATAM_27_Sep_2026.xlsx', 'PENILAIAN'),
    11: ('02_Portofolio_Pengayaan', 'C02_Scorecard_Politeknik_Bintan_Cakrawala.xlsx', 'SCORECARD PBC'),
    12: ('01_Pilot_Utama', 'P05_Scorecard_STIT_Mumtaz_Karimun.xlsx', 'SCORECARD MUMTAZ'),
    13: ('02_Portofolio_Pengayaan', 'C03_Scorecard_Universitas_Ibnu_Sina.xlsx', 'SCORECARD UIS'),
    14: ('02_Portofolio_Pengayaan', 'C04_Scorecard_UNRIKA.xlsx', 'SCORECARD UNRIKA'),
    15: (None, None, None), # C05 Prospective
    16: (None, None, None), # P11 Prospective
    17: (None, None, None), # P12 Prospective
    18: (None, None, None), # P13 Prospective
}

weights_v21 = {'I1': 10, 'I2': 15, 'I3': 15, 'I4': 20, 'I5': 20, 'I6': 10, 'I7': 10}

conn = pymysql.connect(host='localhost', user='root', db='mitra_kinerja', charset='utf8mb4')

def run_audit():
    audit_results = {}
    print("=" * 80)
    print("STARTING 1:1 DEEP AUDIT OF 18 PARTNERS AGAINST AUTHORITATIVE WORKBOOKS")
    print("=" * 80)

    for mid in range(1, 19):
        group, fname, sheet_name = wb_map[mid]
        with conn.cursor(pymysql.cursors.DictCursor) as cur:
            cur.execute('SELECT * FROM mitra_kinerja WHERE id = %s', (mid,))
            db_mitra = cur.fetchone()
            cur.execute('SELECT * FROM indikator_skor WHERE mitra_id = %s ORDER BY kode_indikator', (mid,))
            db_inds = {r['kode_indikator']: r for r in cur.fetchall()}

        res = {
            'mid': mid,
            'kode': db_mitra['kode'],
            'nama': db_mitra['nama_mitra'],
            'group': group,
            'file': fname,
            'partner_diffs': [],
            'indicator_diffs': {},
            'status': 'OK'
        }

        if fname is None:
            res['status'] = 'PROSPECTIVE (NO WORKBOOK)'
            # Check DB state for prospective partners
            expected_status_sc = 'BELUM LENGKAP'
            expected_posisi = 'BELUM DAPAT DITENTUKAN'
            expected_rekom = 'BELUM DITENTUKAN'

            if db_mitra['status_scorecard'] != expected_status_sc:
                res['partner_diffs'].append(f"status_scorecard: DB='{db_mitra['status_scorecard']}' vs EXP='{expected_status_sc}'")
            if db_mitra['posisi_portofolio'] != expected_posisi:
                res['partner_diffs'].append(f"posisi_portofolio: DB='{db_mitra['posisi_portofolio']}' vs EXP='{expected_posisi}'")
            if (db_mitra['rekomendasi'] or '').strip() != expected_rekom:
                res['partner_diffs'].append(f"rekomendasi: DB='{db_mitra['rekomendasi']}' vs EXP='{expected_rekom}'")

            # Check indicators I1-I7: should have standard V2.1 weights and 'BELUM DITELAAH'
            for ind, w in weights_v21.items():
                d_row = db_inds.get(ind)
                if not d_row:
                    res['indicator_diffs'][ind] = ['Missing indicator in DB']
                else:
                    diffs = []
                    if d_row['bobot'] != w:
                        diffs.append(f"bobot: DB={d_row['bobot']} vs EXP={w}")
                    if d_row['status_pemeriksaan'] != 'BELUM DITELAAH':
                        diffs.append(f"status_pemeriksaan: DB='{d_row['status_pemeriksaan']}' vs EXP='BELUM DITELAAH'")
                    if d_row['skor'] is not None:
                        diffs.append(f"skor: DB={d_row['skor']} vs EXP=None")
                    if d_row['nilai'] is not None:
                        diffs.append(f"nilai: DB={d_row['nilai']} vs EXP=None")
                    if diffs:
                        res['indicator_diffs'][ind] = diffs

            audit_results[mid] = res
            continue

        # Load Workbook
        if group == '01_SCORECARD_FINAL_27_SEPTEMBER_2026':
            fpath = os.path.join(base_27_sep, group, fname)
        else:
            fpath = os.path.join(base_final, group, fname)

        wb = openpyxl.load_workbook(fpath, data_only=True)
        ws = wb[sheet_name]

        # 1. Partner Level
        if sheet_name == 'PENILAIAN':
            wb_status = str(ws.cell(27, 2).value or '').strip()
            rek = wb['REKOMENDASI']
            wb_posisi = str(rek.cell(9, 2).value or '').strip()
            wb_rekom = str(rek.cell(17, 2).value or '').strip()
            wb_temuan = str(rek.cell(16, 2).value or '').strip()
            wb_tl = str(rek.cell(19, 2).value or '').strip()
            expected_full_rekom = f"{wb_rekom}\n\nTemuan Utama:\n{wb_temuan}\n\nRencana Tindak Lanjut:\n{wb_tl}".strip()
        else:
            # Legacy SCORECARD V1.1 sheet (C02, P05 Mumtaz, C03, C04)
            wb_status = str(ws.cell(21, 8).value or '').strip()
            wb_posisi = 'BELUM DAPAT DITENTUKAN'
            expected_full_rekom = 'BELUM DITENTUKAN'

        db_status = (db_mitra['status_scorecard'] or '').strip()
        db_posisi = (db_mitra['posisi_portofolio'] or '').strip()
        db_rekom = (db_mitra['rekomendasi'] or '').replace('\r\n', '\n').strip()
        exp_rekom_norm = expected_full_rekom.replace('\r\n', '\n').strip()

        if db_status != wb_status:
            res['partner_diffs'].append(f"status_scorecard: DB={repr(db_status)} vs WB={repr(wb_status)}")
        if db_posisi != wb_posisi:
            res['partner_diffs'].append(f"posisi_portofolio: DB={repr(db_posisi)} vs WB={repr(wb_posisi)}")
        if db_rekom != exp_rekom_norm:
            res['partner_diffs'].append(f"rekomendasi: DB={repr(db_rekom[:60])}... vs WB={repr(exp_rekom_norm[:60])}...")

        # 2. Indicator Level
        if sheet_name == 'PENILAIAN':
            for r in range(13, 20):
                c1 = str(ws.cell(r, 1).value or '').strip()
                m_code = re.match(r'^(I[1-7])', c1)
                if not m_code: continue
                ind = m_code.group(1)
                d_row = db_inds.get(ind)

                wb_bobot = weights_v21[ind]
                wb_apa_dinilai = str(ws.cell(r, 3).value or '').strip()
                wb_bl = str(ws.cell(r, 4).value or '').strip()
                wb_ini = str(ws.cell(r, 5).value or '').strip()
                wb_st = str(ws.cell(r, 6).value or '').strip()
                wb_bukti = str(ws.cell(r, 7).value or '').strip()
                wb_skor = ws.cell(r, 8).value
                wb_nilai = ws.cell(r, 9).value
                wb_alasan = str(ws.cell(r, 10).value or '').strip()
                wb_tl = str(ws.cell(r, 11).value or '').strip()

                wb_skor_val = int(wb_skor) if wb_skor is not None and str(wb_skor).strip() != '' else None
                wb_nilai_val = round(float(wb_nilai), 2) if wb_nilai is not None and str(wb_nilai).strip() != '' else None
                db_skor_val = int(d_row['skor']) if d_row and d_row['skor'] is not None else None
                db_nilai_val = round(float(d_row['nilai']), 2) if d_row and d_row['nilai'] is not None else None

                exp_desc = wb_apa_dinilai

                diffs = []
                if d_row['bobot'] != wb_bobot:
                    diffs.append(f"bobot: DB={d_row['bobot']} vs WB={wb_bobot}")
                if (d_row['kondisi_baseline'] or '').strip() != wb_bl:
                    diffs.append(f"kondisi_baseline: DB={repr(d_row['kondisi_baseline'])} vs WB={repr(wb_bl)}")
                if (d_row['kondisi_saat_ini'] or '').strip() != wb_ini:
                    diffs.append(f"kondisi_saat_ini: DB={repr(d_row['kondisi_saat_ini'])} vs WB={repr(wb_ini)}")
                if (d_row['status_pemeriksaan'] or '').strip() != wb_st:
                    diffs.append(f"status_pemeriksaan: DB={repr(d_row['status_pemeriksaan'])} vs WB={repr(wb_st)}")
                if (d_row['temuan_bukti'] or '').strip() != wb_bukti:
                    diffs.append(f"temuan_bukti: DB={repr(d_row['temuan_bukti'])} vs WB={repr(wb_bukti)}")
                if db_skor_val != wb_skor_val:
                    diffs.append(f"skor: DB={db_skor_val} vs WB={wb_skor_val}")
                if db_nilai_val != wb_nilai_val:
                    diffs.append(f"nilai: DB={db_nilai_val} vs WB={wb_nilai_val}")
                if (d_row['alasan_skor'] or '').strip() != wb_alasan:
                    diffs.append(f"alasan_skor: DB={repr(d_row['alasan_skor'])} vs WB={repr(wb_alasan)}")
                if (d_row['catatan_tindak_lanjut'] or '').strip() != wb_tl:
                    diffs.append(f"catatan_tindak_lanjut: DB={repr(d_row['catatan_tindak_lanjut'])} vs WB={repr(wb_tl)}")
                if (d_row['deskripsi'] or '').strip() != exp_desc:
                    diffs.append(f"deskripsi: DB={repr(d_row['deskripsi'])} vs WB={repr(exp_desc)}")

                if diffs:
                    res['indicator_diffs'][ind] = diffs
        else:
            # Legacy SCORECARD sheet (C02, P05 Mumtaz, C03, C04)
            # In V2.1 architecture, these partners have 7 indicators with weights [10, 15, 15, 20, 20, 10, 10]
            # They are un-evaluated (BELUM DITELAAH) in the 27 Sep final evaluation packet.
            # But let's record what the legacy workbook contained for each row:
            for r in range(13, 19):
                c_code = str(ws.cell(r, 1).value or '').strip()
                if not c_code.startswith('I'): continue
                ind = c_code
                d_row = db_inds.get(ind)

                wb_raw_w = ws.cell(r, 3).value
                wb_raw_st = str(ws.cell(r, 5).value or '').strip()
                wb_raw_bukti = str(ws.cell(r, 6).value or '').strip()
                wb_raw_skor = ws.cell(r, 7).value
                wb_raw_alasan = str(ws.cell(r, 8).value or '').strip()
                wb_raw_nilai = ws.cell(r, 9).value

                res['indicator_diffs'][ind] = [
                    f"Legacy V1.1 WB: bobot={wb_raw_w}, status={wb_raw_st}, skor={wb_raw_skor}, nilai={wb_raw_nilai} | DB V2.1: bobot={d_row['bobot']}, status={d_row['status_pemeriksaan']}, skor={d_row['skor']}, nilai={d_row['nilai']}"
                ]

        audit_results[mid] = res

    return audit_results

if __name__ == '__main__':
    results = run_audit()
    print("\n" + "=" * 80)
    print("AUDIT FINDINGS BY PARTNER")
    print("=" * 80)

    for mid, res in results.items():
        print(f"\nPartner ID {mid}: [{res['kode']}] {res['nama']}")
        print(f"  Reference: {res['group']} / {res['file']}")
        if res.get('status') == 'PROSPECTIVE (NO WORKBOOK)':
            print(f"  Status: Prospective Partnership (no scorecard evaluation workbook)")
            print(f"  DB partner status: {res['partner_diffs'] or 'Valid Default (BELUM LENGKAP / BELUM DAPAT DITENTUKAN / BELUM DITENTUKAN)'}")
            print(f"  DB indicator status: {len(res['indicator_diffs'])} differences (All I1-I7 BELUM DITELAAH)")
            continue

        if not res['partner_diffs'] and not res['indicator_diffs']:
            print("  ==> 100% PARITY ACHIEVED")
        else:
            if res['partner_diffs']:
                print("  [!] Partner-Level Differences:")
                for pd in res['partner_diffs']:
                    print(f"      - {pd}")
            if res['indicator_diffs']:
                print(f"  [!] Indicator Differences ({len(res['indicator_diffs'])} indicators affected):")
                for ind, diffs in res['indicator_diffs'].items():
                    print(f"      [{ind}]:")
                    for d in diffs:
                        print(f"        * {d}")

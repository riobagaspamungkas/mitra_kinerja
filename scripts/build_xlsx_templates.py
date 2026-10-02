import zipfile, os, csv

def create_simple_xlsx(filename, sheet_name, headers, sample_rows):
    content_types = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
  <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
</Types>"""

    rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>"""

    wb_rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
  <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>"""

    workbook = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="{sheet_name}" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>"""

    styles = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="2">
    <font><name val="Calibri"/><sz val="11"/></font>
    <font><b/><name val="Calibri"/><sz val="11"/><color rgb="FFFFFFFF"/></font>
  </fonts>
  <fills count="3">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1E40AF"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/></border>
    <border>
      <left style="thin"><color rgb="FFCBD5E1"/></left>
      <right style="thin"><color rgb="FFCBD5E1"/></right>
      <top style="thin"><color rgb="FFCBD5E1"/></top>
      <bottom style="thin"><color rgb="FFCBD5E1"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="3">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">
      <alignment vertical="center"/>
    </xf>
  </cellXfs>
</styleSheet>"""

    shared_strings_list = []
    string_map = {}

    def get_str_id(s):
        s = str(s)
        if s not in string_map:
            string_map[s] = len(shared_strings_list)
            shared_strings_list.append(s)
        return string_map[s]

    col_letters = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z']
    def get_col_letter(idx):
        if idx < 26:
            return col_letters[idx]
        d1 = idx // 26 - 1
        d2 = idx % 26
        return col_letters[d1] + col_letters[d2]

    sheet_rows_xml = []
    
    # Row 1: Headers
    header_cells = []
    for idx, h in enumerate(headers):
        col = get_col_letter(idx)
        sid = get_str_id(h)
        header_cells.append(f'<c r="{col}1" t="s" s="1"><v>{sid}</v></c>')
    sheet_rows_xml.append(f'<row r="1" ht="28" customHeight="1">' + ''.join(header_cells) + '</row>')

    # Rows 2..N: Data
    for r_idx, row in enumerate(sample_rows, 2):
        row_cells = []
        for c_idx, val in enumerate(row):
            col = get_col_letter(c_idx)
            sid = get_str_id(val)
            row_cells.append(f'<c r="{col}{r_idx}" t="s" s="2"><v>{sid}</v></c>')
        sheet_rows_xml.append(f'<row r="{r_idx}" ht="22" customHeight="1">' + ''.join(row_cells) + '</row>')

    cols_xml = '<cols>'
    for c_idx in range(len(headers)):
        cols_xml += f'<col min="{c_idx+1}" max="{c_idx+1}" width="22" customWidth="1"/>'
    cols_xml += '</cols>'

    worksheet = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  {cols_xml}
  <sheetData>
    {''.join(sheet_rows_xml)}
  </sheetData>
</worksheet>"""

    si_entries = []
    for s in shared_strings_list:
        escaped = s.replace('&', '&amp;').replace('<', '&lt;').replace('>', '&gt;')
        si_entries.append(f'<si><t>{escaped}</t></si>')
    
    shared_strings_xml = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="{len(shared_strings_list)}" uniqueCount="{len(shared_strings_list)}">
  {''.join(si_entries)}
</sst>"""

    os.makedirs(os.path.dirname(filename), exist_ok=True)
    with zipfile.ZipFile(filename, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', content_types)
        z.writestr('_rels/.rels', rels)
        z.writestr('xl/_rels/workbook.xml.rels', wb_rels)
        z.writestr('xl/workbook.xml', workbook)
        z.writestr('xl/styles.xml', styles)
        z.writestr('xl/sharedStrings.xml', shared_strings_xml)
        z.writestr('xl/worksheets/sheet1.xml', worksheet)
    print(f"Generated {filename} ({os.path.getsize(filename)} bytes)")

headers = [
    'nomor_usulan', 'tipe_kerjasama', 'jenis_naskah', 'unit_pemrakarsa', 'penanggung_jawab_usulan',
    'calon_mitra', 'judul_rencana', 'tujuan_singkat', 'ruang_lingkup', 'penerima_manfaat',
    'perkiraan_mulai', 'perkiraan_selesai',
    'q1_tusi_kewenangan', 'q1_bukti',
    'q2_sasaran_kinerja', 'q2_bukti',
    'q3_batas_kewenangan', 'q3_bukti',
    'q4_kebutuhan_nyata', 'q4_bukti',
    'q5_kejelasan_manfaat', 'q5_bukti',
    'q6_output_outcome', 'q6_bukti',
    'q7_daya_ungkit', 'q7_bukti',
    'q8_legalitas_mitra', 'q8_bukti',
    'q9_kapasitas_mitra', 'q9_bukti',
    'q10_integritas_reputasi', 'q10_bukti',
    'q11_peran_kontribusi', 'q11_bukti',
    'q12_focal_point', 'q12_bukti',
    'q13_kesiapan_sdm_anggaran', 'q13_bukti',
    'q14_indikator_awal', 'q14_bukti',
    'q15_manajemen_risiko', 'q15_bukti',
    'q16_tindak_lanjut_pascattd', 'q16_bukti',
    'q17_keberlanjutan_manfaat', 'q17_bukti',
    'q18_mitigasi_hambatan', 'q18_bukti',
    'trigger1_pihak_asing', 'trigger2_keuangan_aset', 'trigger3_data_sistem',
    'trigger4_kekayaan_intelektual', 'trigger5_teknologi_api', 'trigger6_risiko_hukum_reputasi',
    'trigger7_publikasi_branding',
    'catatan_verifikasi', 'gap_penyempurnaan', 'unit_review_tambahan'
]

sample_dn = [
    [
        'PRA-DN-001', 'Dalam Negeri', 'PKS', 'Divisi Pelayanan Hukum dan HAM', 'Kabid Pelayanan Hukum',
        'Universitas Maritim Raja Ali Haji (UMRAH)', 'Fasilitasi Sentra Riset Hukum Maritim dan Bantuan Hukum Nelayan Pesisir',
        'Mendekatkan akses keadilan masyarakat nelayan pesisir', 'Penyuluhan hukum, riset kebijakan maritim, dan klinik konsultasi hukum keliling',
        'Masyarakat nelayan tradisional dan sivitas akademika UMRAH', '2026-11-01', '2029-10-31',
        'YA', 'Sesuai Renstra Kanwil Kementerian Hukum Kepri',
        'YA', 'Mendukung target kinerja BPHN & Kanwil',
        'YA', 'Ruang lingkup dalam batas kewenangan wilayah',
        'YA', 'Terdapat kebutuhan bantuan hukum nelayan',
        'YA', 'Peningkatan literasi hukum pesisir',
        'YA', 'Output: klinik hukum keliling & modul advokasi',
        'YA', 'Optimalisasi jejaring akademisi dan LBH kampus',
        'YA', 'PTN berbadan hukum sah',
        'YA', 'Fakultas Hukum terakreditasi Unggul',
        'YA', 'Tidak ada rekam jejak negatif',
        'YA', 'Pembagian peran diatur dalam rencana kerja',
        'YA', 'Ditunjuk Subbid Luhbankum sebagai focal point',
        'YA', 'Sarana penyuluhan dan anggaran tersedia',
        'YA', 'Target 12 sesi konsultasi per tahun',
        'YA', 'Risiko operasional dimitigasi jadwal terjadwal',
        'YA', 'Evaluasi triwulanan terjadwal pada siklus monev',
        'YA', 'Manfaat berkelanjutan bagi nelayan binaan',
        'YA', 'Mitigasi cuaca dan transportasi pulau tersedia',
        'TIDAK', 'TIDAK', 'TIDAK', 'TIDAK', 'TIDAK', 'TIDAK', 'TIDAK',
        'Proposal lengkap dan telah dicek legalitas mitra.', 'Tidak ada gap material.', 'Subbagian Humas, RB, dan TI'
    ]
]

sample_ln = [
    [
        'PRA-LN-001', 'Luar Negeri', 'MoU', 'Bagian Tata Usaha dan Umum', 'Kasubbag Hubungan Antar Lembaga',
        'Singapore Academy of Law', 'Kerja Sama Pelatihan Mediasi dan Arbitrase Komersial Lintas Batas',
        'Peningkatan kapasitas mediator sengketa perdagangan perbatasan', 'Workshop bersama, kurikulum mediasi, dan transfer knowledge hukum',
        'Aparatur Kanwil Kepri dan mediator tersertifikasi', '2027-02-01', '2028-01-31',
        'YA', 'Mendukung kompetensi aparatur di wilayah perbatasan',
        'YA', 'Mendukung program prioritas penguatan SDM hukum',
        'YA', 'Lingkup non-politis sebatas pelatihan akademis',
        'YA', 'Tinggi frekuensi sengketa perdagangan Kepri-Singapura',
        'YA', 'Transfer pengetahuan mediasi internasional',
        'YA', 'Output: 30 aparatur bersertifikat kompetensi',
        'YA', 'Dibiayai secara proporsional dan non-APBN mengikat',
        'YA', 'Institusi hukum resmi di Singapura',
        'YA', 'Lembaga akreditasi mediasi bereputasi global',
        'YA', 'Tidak ada konflik kepentingan atau isu reputasi',
        'YA', 'Peran kurikulum dan tempat pelatihan jelas',
        'YA', 'Focal point Bagian TU & Kerjasama Kanwil',
        'YA', 'Kebutuhan akomodasi dan narasumber siap',
        'YA', 'Target minimal kelulusan sertifikasi 90%',
        'YA', 'Isu bahasa dan kurikulum disinkronkan',
        'YA', 'Tindak lanjut berupa forum mediasi berkala',
        'YA', 'Penguatan jejaring penyelesaian sengketa perbatasan',
        'YA', 'Mitigasi regulasi hubungan luar negeri dikoordinasikan',
        'YA', 'TIDAK', 'TIDAK', 'TIDAK', 'TIDAK', 'TIDAK', 'TIDAK',
        'Dalam koordinasi resmi dengan Biro Hukerma Kementerian Hukum RI.', 'Menunggu surat rekomendasi / clearance hubungan luar negeri.', 'Biro Hukerma Kementerian Hukum RI & Ditjen AHU'
    ]
]

if __name__ == '__main__':
    create_simple_xlsx('public/templates/template_gate0_dalam_negeri.xlsx', 'Gate0_Dalam_Negeri', headers, sample_dn)
    create_simple_xlsx('public/templates/template_gate0_luar_negeri.xlsx', 'Gate0_Luar_Negeri', headers, sample_ln)

    # Also build UTF-8 BOM CSV files
    for path, data in [
        ('public/templates/template_gate0_dalam_negeri.csv', sample_dn),
        ('public/templates/template_gate0_luar_negeri.csv', sample_ln)
    ]:
        with open(path, 'w', newline='', encoding='utf-8-sig') as f:
            writer = csv.writer(f, delimiter=';')
            writer.writerow(headers)
            for r in data:
                writer.writerow(r)
        print(f"Generated {path}")

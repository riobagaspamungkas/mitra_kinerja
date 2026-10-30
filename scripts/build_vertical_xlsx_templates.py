import zipfile, os, csv

def create_vertical_gate0_xlsx(filename, sheet_name, title, rows_data):
    """
    Builds a professional vertical (downward) OpenXML Excel template for Gate 0.
    Layout matches Google Drive & the official Form Gate 0 Word document.
    """
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
  <fonts count="4">
    <font><name val="Calibri"/><sz val="11"/></font>
    <font><b/><name val="Calibri"/><sz val="11"/><color rgb="FFFFFFFF"/></font>
    <font><b/><name val="Calibri"/><sz val="12"/><color rgb="FF1E3A8A"/></font>
    <font><b/><name val="Calibri"/><sz val="11"/><color rgb="FF0F172A"/></font>
  </fonts>
  <fills count="5">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1E40AF"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>
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
  <cellXfs count="5">
    <!-- 0: default -->
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <!-- 1: Table Header (Navy Blue) -->
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment horizontal="center" vertical="center" wrapText="1"/>
    </xf>
    <!-- 2: Section Divider (Soft Blue) -->
    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment vertical="center"/>
    </xf>
    <!-- 3: Standard Cell -->
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1">
      <alignment vertical="center" wrapText="1"/>
    </xf>
    <!-- 4: Bold Key Cell -->
    <xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1">
      <alignment vertical="center" wrapText="1"/>
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

    col_letters = ['A', 'B', 'C', 'D', 'E', 'F']

    sheet_rows_xml = []
    
    # Row 1: Main Header Columns
    headers = ['NO', 'KOMPONEN / PARAMETER / PERTANYAAN EVALUASI', 'ISIAN DATA / PILIHAN (YA/TIDAK)', 'BUKTI DUKUNG / KETERANGAN / CATATAN REVIU']
    header_cells = []
    for idx, h in enumerate(headers):
        col = col_letters[idx]
        sid = get_str_id(h)
        header_cells.append(f'<c r="{col}1" t="s" s="1"><v>{sid}</v></c>')
    sheet_rows_xml.append('<row r="1" ht="28" customHeight="1">' + ''.join(header_cells) + '</row>')

    # Rows 2..N: Data downwards
    for r_idx, item in enumerate(rows_data, 2):
        row_cells = []
        is_section = item.get('is_section', False)
        
        if is_section:
            # Section banner spanning columns
            sid_sec = get_str_id(item['text'])
            for c_idx in range(4):
                col = col_letters[c_idx]
                if c_idx == 0:
                    row_cells.append(f'<c r="{col}{r_idx}" t="s" s="2"><v>{sid_sec}</v></c>')
                else:
                    row_cells.append(f'<c r="{col}{r_idx}" s="2"/>')
            sheet_rows_xml.append(f'<row r="{r_idx}" ht="24" customHeight="1">' + ''.join(row_cells) + '</row>')
        else:
            col_no = item.get('no', '')
            col_param = item.get('param', '')
            col_val = item.get('val', '')
            col_note = item.get('note', '')

            # A: No
            sid_no = get_str_id(col_no)
            row_cells.append(f'<c r="A{r_idx}" t="s" s="4"><v>{sid_no}</v></c>')
            # B: Param
            sid_param = get_str_id(col_param)
            row_cells.append(f'<c r="B{r_idx}" t="s" s="4"><v>{sid_param}</v></c>')
            # C: Value
            sid_val = get_str_id(col_val)
            row_cells.append(f'<c r="C{r_idx}" t="s" s="3"><v>{sid_val}</v></c>')
            # D: Note
            sid_note = get_str_id(col_note)
            row_cells.append(f'<c r="D{r_idx}" t="s" s="3"><v>{sid_note}</v></c>')
            
            sheet_rows_xml.append(f'<row r="{r_idx}" ht="22" customHeight="1">' + ''.join(row_cells) + '</row>')

    cols_xml = """<cols>
      <col min="1" max="1" width="8" customWidth="1"/>
      <col min="2" max="2" width="46" customWidth="1"/>
      <col min="3" max="3" width="36" customWidth="1"/>
      <col min="4" max="4" width="55" customWidth="1"/>
    </cols>"""

    worksheet = f"""<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  {cols_xml}
  <sheetData>
    {''.join(sheet_rows_xml)}
  </sheetData>
  <dataValidations count="2">
    <dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="C16:C33">
      <formula1>&quot;YA,TIDAK&quot;</formula1>
    </dataValidation>
    <dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="C35:C41">
      <formula1>&quot;YA,TIDAK&quot;</formula1>
    </dataValidation>
  </dataValidations>
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

def build_gate0_data(tipe="Dalam Negeri", no_usulan="PRA-DN-001", mitra_sample="", judul_sample=""):
    data = []
    # Bagian I
    data.append({'is_section': True, 'text': 'BAGIAN I: IDENTITAS & PROFIL USULAN'})
    data.append({'no': '1', 'param': 'Nomor Usulan', 'val': no_usulan, 'note': 'Format: PRA-[DN/LN]-XXX'})
    data.append({'no': '2', 'param': 'Tipe Kerja Sama', 'val': tipe, 'note': 'Pilihan: Dalam Negeri / Luar Negeri'})
    data.append({'no': '3', 'param': 'Jenis Naskah', 'val': 'PKS' if tipe == 'Dalam Negeri' else 'MoU', 'note': 'Pilihan: PKS / MoU / Lainnya'})
    data.append({'no': '4', 'param': 'Unit Pemrakarsa', 'val': 'Divisi Pelayanan Hukum dan HAM' if tipe == 'Dalam Negeri' else 'Bagian Tata Usaha dan Umum', 'note': 'Unit pengusul di lingkungan Kanwil Kepri'})
    data.append({'no': '5', 'param': 'Penanggung Jawab Usulan', 'val': 'Kabid Pelayanan Hukum' if tipe == 'Dalam Negeri' else 'Kasubbag Hubungan Antar Lembaga', 'note': 'Nama & Jabatan Penanggung Jawab Usulan'})
    data.append({'no': '6', 'param': 'Calon Mitra', 'val': mitra_sample, 'note': 'Nama resmi instansi / lembaga calon mitra'})
    data.append({'no': '7', 'param': 'Judul Rencana Kerja Sama', 'val': judul_sample, 'note': 'Judul lengkap rencana naskah kerja sama'})
    data.append({'no': '8', 'param': 'Tujuan Singkat', 'val': 'Mendekatkan akses keadilan masyarakat nelayan pesisir' if tipe == 'Dalam Negeri' else 'Peningkatan kapasitas aparatur hukum perbatasan', 'note': 'Tujuan pokok pelaksanaan kerja sama'})
    data.append({'no': '9', 'param': 'Ruang Lingkup Utama', 'val': 'Penyuluhan hukum, riset kebijakan maritim, dan klinik konsultasi' if tipe == 'Dalam Negeri' else 'Workshop mediasi bersama dan kurikulum internasional', 'note': 'Uraian ruang lingkup kegiatan yang disepakati'})
    data.append({'no': '10', 'param': 'Penerima Manfaat', 'val': 'Masyarakat nelayan tradisional dan sivitas akademika' if tipe == 'Dalam Negeri' else 'Aparatur Kanwil Kepri dan mediator tersertifikasi', 'note': 'Sasaran kelompok penerima manfaat'})
    data.append({'no': '11', 'param': 'Perkiraan Tanggal Mulai', 'val': '2026-11-01', 'note': 'Format tanggal: YYYY-MM-DD'})
    data.append({'no': '12', 'param': 'Perkiraan Tanggal Selesai', 'val': '2029-10-31', 'note': 'Format tanggal: YYYY-MM-DD'})

    # Bagian II: 18 Pertanyaan Uji
    data.append({'is_section': True, 'text': 'BAGIAN II: UJI KELAYAKAN 5 KRITERIA (18 PERTANYAAN EVALUASI)'})
    questions = [
        ('Q1', 'K1.1: Kesesuaian dengan tugas pokok dan fungsi (Tusi) Kementerian Hukum', 'YA', 'Sesuai Renstra Kanwil Kementerian Hukum Kepri'),
        ('Q2', 'K1.2: Kesesuaian dengan sasaran kinerja organisasi dan prioritas wilayah', 'YA', 'Mendukung target kinerja BPHN & Kanwil Kepri'),
        ('Q3', 'K1.3: Tidak melampaui batas kewenangan kewilayahan/substansi', 'YA', 'Ruang lingkup dalam batas kewenangan wilayah Kepri'),
        ('Q4', 'K2.1: Terdapat kebutuhan nyata atas pelaksanaan kerja sama', 'YA', 'Kebutuhan nyata akses keadilan masyarakat pesisir'),
        ('Q5', 'K2.2: Kejelasan manfaat yang akan diperoleh Kanwil maupun masyarakat', 'YA', 'Peningkatan literasi hukum masyarakat pesisir'),
        ('Q6', 'K2.3: Kejelasan target output dan outcome yang terukur', 'YA', 'Target output: klinik hukum keliling & modul advokasi'),
        ('Q7', 'K2.4: Memberikan daya ungkit terhadap peningkatan kinerja pelayanan', 'YA', 'Optimalisasi jejaring akademisi dan LBH kampus'),
        ('Q8', 'K3.1: Legalitas dan status kelembagaan mitra sah dan terverifikasi', 'YA', 'Institusi resmi berbadan hukum sah'),
        ('Q9', 'K3.2: Kapasitas teknis, operasional, dan sumber daya mitra memadai', 'YA', 'Memiliki SDM dan fasilitas operasional memadai'),
        ('Q10', 'K3.3: Reputasi baik dan tidak memiliki rekam jejak negatif', 'YA', 'Tidak ada catatan sengketa atau rekam jejak negatif'),
        ('Q11', 'K4.1: Kejelasan pembagian peran, hak, dan kewajiban para pihak', 'YA', 'Pembagian tugas diatur dalam matriks rencana kerja'),
        ('Q12', 'K4.2: Kesiapan unit pengampu dan focal point/PIC internal', 'YA', 'Telah ditunjuk unit pengampu dan focal point resmi'),
        ('Q13', 'K4.3: Kesiapan sarana pendukung dan pembiayaan non-APBN mengikat', 'YA', 'Sarana penyuluhan dan pendampingan tersedia'),
        ('Q14', 'K4.4: Kesiapan indikator awal penilaian keberhasilan', 'YA', 'Target minimal 12 sesi konsultasi per tahun'),
        ('Q15', 'K5.1: Manajemen risiko operasional dan mitigasi awal', 'YA', 'Risiko operasional dimitigasi jadwal terjadwal'),
        ('Q16', 'K5.2: Komitmen tindak lanjut operasional pasca-penandatanganan', 'YA', 'Evaluasi triwulanan terjadwal pada siklus monev'),
        ('Q17', 'K5.3: Keberlanjutan manfaat kerja sama jangka panjang', 'YA', 'Manfaat berkelanjutan bagi kelompok binaan'),
        ('Q18', 'K5.4: Kesiapan rencana mitigasi bila timbul hambatan pelaksanaan', 'YA', 'Mitigasi cuaca dan sarana transportasi disiapkan')
    ]
    for q_no, q_text, q_ans, q_note in questions:
        data.append({'no': q_no, 'param': q_text, 'val': q_ans, 'note': q_note})

    # Bagian III: 7 Triggers
    data.append({'is_section': True, 'text': 'BAGIAN III: PEMICU KARAKTERISTIK KHUSUS (7 TRIGGERS)'})
    triggers = [
        ('T1', 'Pemicu 1: Keterlibatan Pihak Asing / Entitas Luar Negeri', 'YA' if tipe == 'Luar Negeri' else 'TIDAK', 'Clearance Biro Hukerma Kementerian Hukum RI' if tipe == 'Luar Negeri' else 'Tidak ada keterlibatan entitas asing'),
        ('T2', 'Pemicu 2: Dampak Keuangan, Aset Negara, atau Pembiayaan Khusus', 'TIDAK', 'Tidak ada pendanaan APBN yang mengikat'),
        ('T3', 'Pemicu 3: Penggunaan Data, Kerahasiaan, atau Sistem Informasi', 'TIDAK', 'Tidak ada integrasi data rahasia'),
        ('T4', 'Pemicu 4: Hak Kekayaan Intelektual (Pemanfaatan Merek, Cipta, Paten)', 'TIDAK', 'Tidak ada komersialisasi KI'),
        ('T5', 'Pemicu 5: Integrasi Teknologi Informasi, Server, atau API', 'TIDAK', 'Tidak menggunakan API server internal'),
        ('T6', 'Pemicu 6: Potensi Risiko Hukum Signifikan atau Isu Sensitif', 'TIDAK', 'Aktivitas pembinaan standar'),
        ('T7', 'Pemicu 7: Publikasi, Penggunaan Logo, dan Branding Resmi', 'TIDAK', 'Sesuai pedoman humas Kementerian Hukum')
    ]
    for t_no, t_text, t_ans, t_note in triggers:
        data.append({'no': t_no, 'param': t_text, 'val': t_ans, 'note': t_note})

    # Bagian IV: Catatan Verifikator
    data.append({'is_section': True, 'text': 'BAGIAN IV: CATATAN & HASIL REVIU VERIFIKATOR'})
    data.append({'no': 'C1', 'param': 'Catatan Verifikator', 'val': 'Proposal lengkap dan telah dicek legalitas mitra.', 'note': 'Komentar tim pengelola kerja sama'})
    data.append({'no': 'C2', 'param': 'Gap yang Harus Ditutup', 'val': 'Tidak ada gap material.' if tipe == 'Dalam Negeri' else 'Menunggu surat rekomendasi / clearance hubungan luar negeri.', 'note': 'Syarat perbaikan sebelum penandatanganan'})
    data.append({'no': 'C3', 'param': 'Unit/Fungsi Reviu Tambahan', 'val': 'Subbagian Humas, RB, dan TI' if tipe == 'Dalam Negeri' else 'Biro Hukerma Kementerian Hukum RI & Ditjen AHU', 'note': 'Unit verifikator tambahan yang dilibatkan'})

    return data

if __name__ == '__main__':
    data_dn = build_gate0_data('Dalam Negeri', 'PRA-DN-001', 'Universitas Maritim Raja Ali Haji (UMRAH)', 'Fasilitasi Sentra Riset Hukum Maritim dan Bantuan Hukum Nelayan Pesisir')
    data_ln = build_gate0_data('Luar Negeri', 'PRA-LN-001', 'Singapore Academy of Law', 'Kerja Sama Pelatihan Mediasi dan Arbitrase Komersial Lintas Batas')

    create_vertical_gate0_xlsx('public/templates/template_gate0_dalam_negeri.xlsx', 'Gate0_Dalam_Negeri', 'Formulir Usulan Kerja Sama Dalam Negeri', data_dn)
    create_vertical_gate0_xlsx('public/templates/template_gate0_luar_negeri.xlsx', 'Gate0_Luar_Negeri', 'Formulir Usulan Kerja Sama Luar Negeri', data_ln)

    # Also build UTF-8 BOM CSV files in vertical form
    for path, d in [
        ('public/templates/template_gate0_dalam_negeri.csv', data_dn),
        ('public/templates/template_gate0_luar_negeri.csv', data_ln)
    ]:
        with open(path, 'w', newline='', encoding='utf-8-sig') as f:
            writer = csv.writer(f, delimiter=';')
            writer.writerow(['NO', 'KOMPONEN / PARAMETER / PERTANYAAN EVALUASI', 'ISIAN DATA / PILIHAN (YA/TIDAK)', 'BUKTI DUKUNG / KETERANGAN / CATATAN REVIU'])
            for row in d:
                if row.get('is_section'):
                    writer.writerow(['#', row['text'], '', ''])
                else:
                    writer.writerow([row.get('no', ''), row.get('param', ''), row.get('val', ''), row.get('note', '')])
        print(f"Generated {path}")

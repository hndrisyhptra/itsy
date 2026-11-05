from pathlib import Path
import json, collections, html, re, math, shutil
from docx import Document
from docx.shared import Mm, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from PIL import Image, ImageDraw, ImageFont

ROOT = Path('/Applications/XAMPP/xamppfiles/htdocs/its/area3-prototype')
DATA = ROOT / 'docs/database'
ASSETS = DATA / 'diagrams'
OUT = ROOT / 'output/documents'
for p in [DATA, ASSETS, OUT, ROOT / 'output/pdf']:
    p.mkdir(parents=True, exist_ok=True)
source = Path('/private/tmp/area3-schema-handbook.json')
if not source.exists(): source = DATA/'schema-catalog.json'
schema = json.loads(source.read_text())
keys = collections.defaultdict(list)
for k in schema['keys']:
    keys[(k['table_name'], k['name'], k['type'])].append(k)
tables = {}
for t in schema['tables']:
    n = t['name']
    tables[n] = dict(name=n, columns=[c for c in schema['columns'] if c['table_name']==n],
                     pk=[], unique=[], fks=[])
for (n, name, kind), rows in keys.items():
    cols = [r['column_name'] for r in rows]
    if kind == 'PRIMARY KEY': tables[n]['pk'] = cols
    elif kind == 'UNIQUE': tables[n]['unique'].append(cols)
    elif kind == 'FOREIGN KEY':
        nullable = any(c['nullable']=='YES' for c in tables[n]['columns'] if c['name'] in cols)
        unique = any(set(u).issubset(cols) for u in tables[n]['unique'])
        tables[n]['fks'].append(dict(name=name, columns=cols, target=rows[0]['referenced_table'],
                                      target_columns=[r['referenced_column'] for r in rows], nullable=nullable))
# Cardinality is calculated only after every unique key is loaded.
for n,t in tables.items():
    for f in t['fks']:
        f['unique_child'] = any(set(u).issubset(f['columns']) for u in [t['pk'], *t['unique']] if u)

# Descriptions are based on current source code, not inferred from table occupancy.
CAT = {}
def item(n, purpose, fields, note='', status='Aktif'):
    CAT[n] = dict(purpose=purpose, fields=fields, note=note, status=status)
item('areas','Induk wilayah operasional Area. Menjadi tingkat teratas struktur organisasi.','code, name, is_active','created_by dan updated_by dari master kiriman bukan FK akun aplikasi.')
item('regions','Regional yang berada di bawah satu Area.','area_id, code, name','Satu Area dapat memiliki banyak Region.')
item('branches','Cabang pelaksana proyek sekaligus cakupan akses akun.','region_id, code, kode_branch, name','kode_branch unik, misalnya R502, dipakai pada nomor PP. Kolom region berupa teks tidak menggantikan FK region_id.')
item('service_areas','Wilayah layanan dalam Branch dan Region.','workzone, name, branch_id, region_id','workzone unik. Kecocokan Region terhadap Branch divalidasi aplikasi; FK yang terpisah tidak menjamin kecocokan itu sendiri.')
item('users','Akun login dan penempatan utama pengguna.','username, nik, role, active, id_regions, id_branch, id_service_areas','Login memakai username; password berupa hash. Penempatan utama tidak memperluas izin Branch. role adalah kode teks tanpa FK langsung ke roles.')
item('roles','Daftar kode peran yang dipakai penugasan akun.','code, name','Satu peran dapat dipakai banyak user_assignments. users.role harus sesuai penugasan aktif menurut validasi aplikasi.')
item('user_assignments','Penghubung akun, peran, dan Branch yang benar-benar mengatur cakupan akses.','user_id, role_id, branch_id, active','Kombinasi user, role, Branch unik. Satu akun dapat ditugaskan pada beberapa Branch; mode akun mengharuskan satu peran aktif yang konsisten.')
item('work_units','Pilihan unit kerja Project, yaitu Konstruksi dan Operation.','code, name','Satu unit dapat dipakai banyak Project.')
item('measurement_units','Kamus satuan yang disiapkan untuk normalisasi lanjutan.','code, name','Saat ini designators.unit dan snapshot BOQ menyimpan teks satuan, tanpa FK ke kamus ini.','Disiapkan')
item('packages','Paket pekerjaan untuk kelompok harga designator.','code, name, description','Satu paket mempunyai banyak baris harga sumber.')
item('designator_types','Jenis designator, termasuk komponen Material dan Jasa dari data kiriman.','code, name, is_active','Jenis sumber Material dan Jasa tetap terpisah; tidak digabung menjadi satu master fiktif.')
item('designators','Katalog kode, uraian pekerjaan, satuan, dan jenis designator.','code, item_name, unit, designator_type_id','designator_category_id belum memiliki FK karena tabel kategori tidak disediakan. Kolom unit adalah teks.')
item('designator_package_prices','Harga sumber designator untuk tiap paket; referensi harga aktif BOQ.','designator_id, package_id, price','Kombinasi designator dan paket unik. Harga nol dari sumber dipertahankan.')
item('khs_price_lists','Rancangan daftar harga KHS per paket, Branch, versi, dan masa berlaku.','reference_no, version_no, valid_from, valid_until, status','Belum menjadi sumber kontrak KHS runtime; aplikasi tidak mengarang nomor atau periode KHS.','Disiapkan')
item('khs_prices','Rancangan rincian harga Material dan Jasa pada versi daftar KHS.','price_list_id, designator_id, source_price_id, material_price, service_price','FK ke sumber harga tersedia, tetapi BOQ aktif memakai source_price_id langsung ke designator_package_prices.','Disiapkan')
item('projects','Induk ID Project yang dapat menaungi beberapa PP.','project_code, name, branch_id, service_area_id, work_unit_id','project_code adalah ID Project yang diketik pengguna; id_project adalah PK teknis. FK gabungan memastikan Service Area proyek berasal dari Branch yang sama.')
item('numbering_rules','Pola penomoran PP yang dapat dikonfigurasi.','document_type, pattern, sequence_padding, timezone_name, sequence_scope','Pola default {SEQ}/LG.000/TA-03{BRANCH}/{MM}-{YYYY}. Runtime saat ini memakai scope GLOBAL; pilihan scope DDL bukan berarti semuanya tersedia di UI.')
item('number_sequences','Penghitung nomor berikutnya untuk aturan dan scope tertentu.','rule_id, scope_key, next_value','Kombinasi rule_id dan scope_key unik; penomoran disimpan bersama transaksi PP.')
item('project_plans','Header Project Plan beserta status, nomor, hubungan PP induk, dan snapshot formulir.','project_id, pp_no, root_pp_id, parent_pp_id, status, document_data','Satu Project memiliki banyak PP. root_pp_id menunjuk PP pemilik BOQ; parent_pp_id menunjuk pendahulu lanjutan. Lima approver v3 ada pada snapshot dan approval_steps; manager_user_id adalah kolom warisan.')
item('project_plan_team','Susunan tim yang tercantum pada PP.','pp_id, user_id, name_snapshot, job_snapshot','Anggota dapat berupa nama snapshot tanpa akun; user_id opsional. Susunan tim cetak tidak sama dengan penugasan approval.')
item('project_plan_schedule','Lima tahap jadwal PP dengan jumlah HK dan tanggal hasil perhitungan.','pp_id, stage_no, stage_name, work_days, start_date, end_date','Satu tahap per PP unik. UI mengizinkan perubahan HK; teks tahap mengikuti template. BA KH baru mengambil periode dari PP.')
item('project_plan_costs','Ringkasan nominal kategori biaya PP.','pp_id, cost_type, amount','Satu kategori per PP unik: MANDOR, NONSTOCK, BOP, PERMIT. Nilai ini ringkasan; rincian ada pada project_plan_budget_rows dan snapshot.')
item('project_plan_budget_rows','Rincian anggaran dinamis Mandor, Non Stock, BOP, dan waktu penggunaan.','pp_id, category, position, row_data','category berisi mandor, materials, bop, atau usage. row_data JSON mempertahankan bentuk kolom tiap kategori dan urutan tampilan.')
item('boq_lines','Baris BOQ Owner milik PP root beserta snapshot designator dan asal harga.','root_pp_id, source_price_id, source_row_id, source_data','PP lanjutan memakai root BOQ yang sama. khs_price_id dan pasangan source_import_id/source_row_no disiapkan, belum dipakai oleh importer aktif.')
item('boq_components','Komponen Material atau Jasa beserta volume dan harga resmi pada satu baris BOQ.','boq_line_id, component, quantity, official_unit_price','component M atau S; kombinasi baris dan komponen unik. Volume disimpan 3 desimal; harga 2 desimal. Komponen tidak berlaku berbeda dari harga nol.')
item('contracts','Proses mandor yang mencakup SPH, negosiasi, dan BA KH di menu SPK.','pp_id, mandor_name, bakh_no, spk_no, stage, start_date, end_date, document_data','Tabel ini bukan header SPK baru. SPK tersimpan di work_orders. Periode BA KH draft mengikuti jadwal PP; dokumen terbit mempertahankan snapshot.')
item('contract_components','Volume dan harga yang dinegosiasikan untuk tiap komponen BOQ pada kontrak mandor.','contract_id, boq_component_id, quantity, khs_ceiling_snapshot, sph_price, offered_price, agreed_price','Pasangan kontrak dan komponen unik. Batas SPH aktif mengikuti harga Mandor PP terpilih melalui validasi server/snapshot; kolom khs_ceiling_snapshot saja tidak menjelaskan seluruh aturan batas harga.')
item('work_orders','Header SPK beserta nomor, status, dan snapshot dokumen.','contract_id, spk_no, status, document_data','Alur aplikasi memakai satu SPK per BA KH. contract_id tidak UNIQUE pada tabel ini, sehingga kardinalitas FK database tetap banyak SPK ke satu kontrak; batas bisnis dijaga server.')
item('contract_documents','Rancangan riwayat versi berkas BA KH atau SPK.','contract_id, document_type, version_no, file_id, evidence_reference','Belum ditulis penyimpanan aktif; status dan riwayat dokumen saat ini ada pada contracts/work_orders.document_data.','Disiapkan')
item('payment_terms','Pengajuan termin dari SPK, lengkap dengan status, jenis, dan snapshot.','contract_id, work_order_id, term_no, term_type, status, approved_amount, document_data','Maksimal tiga termin per kontrak; termin ketiga Final. Sesudah Final tidak boleh ada termin baru. Kolom PM/Manager warisan tidak mewakili lima approver v3.')
item('term_actuals','Volume aktual yang ditagihkan untuk tiap komponen kontrak dalam satu termin.','term_id, contract_component_id, actual_quantity','Pasangan termin dan komponen unik. Tidak boleh menagih volume yang sama melampaui saldo; diperiksa ledger dan server.')
item('approval_runs','Satu siklus pengajuan approval untuk PP atau pembayaran.','pp_id, term_id, cycle_no, status, workflow_version','Tepat satu target PP atau termin diisi. Dikembalikan lalu diajukan ulang menghasilkan siklus baru, dengan riwayat keputusan lama tetap disimpan.')
item('approval_steps','Urutan peran dan akun yang ditugaskan pada suatu siklus.','run_id, step_no, expected_role, assigned_user_id, assigned_name_snapshot','v3 menggunakan lima tahap berurutan. Akun harus aktif, sesuai peran dan Branch. Finance setelah scan tidak ditulis sebagai tahap keenam oleh proyeksi runtime ini.')
item('approval_decisions','Keputusan aktual pada satu tahap approval.','step_id, actor_user_id, actor_name_snapshot, decision, note, decided_at, decision_data','step_id unik: satu tahap memiliki nol atau satu keputusan. APPROVE, RETURN, REJECT; pengembalian/penolakan memerlukan alasan. Keputusan lama tidak ditimpa.')
item('document_files','Metadata seluruh berkas privat, termasuk nama asli dan path penyimpanan.','storage_disk, storage_key, original_name, mime_type, byte_size, sha256','Byte file ada di storage/app/private, bukan di kolom database. storage_key unik. Tidak ada FK langsung dari PP ke file BOQ aktif; referensinya melalui metadata upload pada snapshot PP.')
item('term_work_evidence','Lampiran PDF/foto eviden pekerjaan untuk pengajuan termin.','source_id, term_id, file_id, uploaded_by, uploaded_at, removed_by, removed_at','Maksimal 10 lampiran aktif, masing-masing 10 MB. removed_at berarti dikeluarkan dari daftar aktif; riwayat dan berkas tetap disimpan. Ini berbeda dari scan TTD.')
item('term_scans','Versi scan dokumen yang telah ditandatangani fisik dan hasil verifikasi Finance.','term_id, file_id, version_no, status, verified_by, verified_at, note','Satu termin dapat memiliki banyak versi scan; satu file hanya untuk satu baris scan. Penggantian scan memerlukan verifikasi baru. Status WAITING, VALID, atau CORRECTION.')
item('apm_settlements','Nomor APM dan pelunasan penuh satu tagihan termin oleh Finance.','term_id, verified_scan_id, apm_no, amount, recorded_by, recorded_at','term_id dan apm_no masing-masing unik. FK gabungan mengikat APM ke scan milik termin yang sama. Scan valid serta pelunasan penuh dijaga server secara atomik; belum ada integrasi transfer bank.')
item('ba_completions','BA PP yang berasal dari termin Final disetujui, untuk proses penyelesaian dan pelepasan.','final_term_id, document_no, status, evidence_reference, document_data','Satu termin Final memiliki paling banyak satu BA PP. BA PP Sah diperlukan sebelum pelepasan sisa volume/lanjutan; bukan pengganti APM.')
item('volume_balances','Saldo volume terkini per komponen BOQ root.','boq_component_id, original_quantity, available, reserved, committed, accepted, revision','Satu komponen memiliki paling banyak satu baris saldo. Total empat saldo harus sama dengan original_quantity; jangan menggandakan stok saat membuat PP lanjutan.')
item('volume_movements','Jejak perpindahan volume antar saldo untuk satu perintah.','receipt_id, boq_component_id, movement_type, source_type, source_id, available_delta, reserved_delta, committed_delta, accepted_delta','Jumlah empat delta harus nol. source_type/source_id adalah referensi logis, tanpa FK polimorfik. Pembukaan stok BOQ dicatat receipt/audit, bukan dipaksakan menjadi transfer nol.')
item('volume_releases','Rancangan baris normal untuk pelepasan volume setelah BA PP.','completion_id, contract_component_id, boq_component_id, quantity, authorized_by','Runtime masih menyimpan rincian pelepasan pada snapshot BA PP dan ledger; tabel ini belum menjadi sumber baca/tulis proses aktif.','Disiapkan')
item('continuation_allocations','Rancangan alokasi volume yang dilepas ke PP lanjutan.','continuation_pp_id, source_release_id, boq_component_id, quantity','FK gabungan mengikat komponen ke release yang sama. Runtime lanjutan memakai snapshot dan ledger, belum proyeksi tabel ini.','Disiapkan')
item('prototype_workspaces','Identitas ruang kerja bersama, kunci transaksi, revisi, dan arsip state sebelum pemindahan.','owner_key, revision, relational_migrated_at, state','Tabel tetap aktif, tetapi state adalah arsip yang tidak diperbarui dan tidak dipakai sebagai fallback. source_id dokumen dan workspace juga menentukan namespace file.')
item('workflow_metadata','Konfigurasi dan referensi ruang kerja setelah penyimpanan relasional aktif.','metadata','id_workspace sekaligus PK dan FK: paling banyak satu metadata per workspace. Bukan tempat koleksi PP, SPK, termin, atau event baru.')
item('workflow_events','Aktivitas yang ditampilkan dalam timeline dokumen dan aplikasi.','workspace_id, source_id, position, event_data','Satu workspace mempunyai banyak event. Hubungan event ke dokumen tersimpan pada event_data; tidak ada FK langsung event ke setiap jenis dokumen.')
item('prototype_audits','Jejak perintah workflow, pelaku, revisi, serta hash sebelum dan sesudah.','workspace_id, request_id, command, actor_role, actor_user_id, revision, before_hash, after_hash','Unik per workspace/request. Tidak mencakup seluruh login/logout atau CRUD akun; audit ini khusus perintah workflow.')
item('command_receipts','Pencatatan UUID permintaan agar pengiriman ulang tidak menggandakan transaksi.','request_uuid, command_name, actor_user_id, aggregate_type, aggregate_id, expected_revision, resulting_revision','request_uuid unik. aggregate_id menunjuk workspace secara logis, tanpa FK SQL. Receipt dan audit dicatat bersama perubahan transaksi.')
item('boq_import_templates','Rancangan versi template Excel beserta pemetaan kolom.','code, version_no, file_id, worksheet_name, header_row, column_mapping','Importer aktif memakai pemetaan yang didukung pada kode dan manifest privat, belum registri template pada tabel ini.','Disiapkan')
item('boq_import_batches','Rancangan satu sesi upload dan validasi BOQ.','draft_id, pp_id, template_id, file_id, khs_price_list_id, status','Upload Excel aktif sudah nyata; tabel batch ini belum diproyeksikan. Metadata upload aktif berada di snapshot PP, document_files, dan manifest privat.','Disiapkan')
item('boq_import_rows','Rancangan pemeriksaan tiap baris file BOQ impor.','batch_id, row_no, raw_values, matched_khs_price_id, price_mismatch, status, validation_errors','Tidak menjadi sumber importer aktif. Hasil aktif tersimpan pada source_data BOQ/metadata upload; jangan menyimpulkan upload simulasi hanya karena tabel ini kosong.','Disiapkan')
item('master_import_batches','Rancangan pencatatan sesi impor master berikutnya.','file_id, master_type, status, imported_by, created_at','Master kiriman saat ini dimuat migration dari snapshot sumber, bukan lewat fasilitas batch ini.','Disiapkan')
item('master_import_rows','Rancangan rincian baris dan kesalahan validasi impor master.','batch_id, row_no, raw_values, validation_errors','Pasangan batch dan nomor baris unik; belum merupakan riwayat impor runtime.','Disiapkan')
item('migrations','Daftar migration yang sudah dijalankan Laravel.','migration, batch','Mencatat versi struktur; bukan data PP atau histori bisnis. Nama tabel yang tercatat tidak sama dengan jumlah berkas migration.','Infrastruktur')
item('sessions','Penyimpanan sesi bila driver session diganti ke database.','user_id, payload, last_activity','Konfigurasi aktif menggunakan file. user_id hanya berindeks, tanpa FK ke users. Tidak ada relasi SQL yang memaksa penghapusan sesi saat akun dihapus.','Infrastruktur')
item('password_reset_tokens','Token reset kata sandi standar Laravel.','email, token, created_at','email adalah PK; tidak ada FK SQL ke users.email. Fitur reset via email belum tersedia pada aplikasi saat ini.','Infrastruktur')
item('cache','Penyimpanan nilai cache bila driver database digunakan.','key, value, expiration','Konfigurasi cache aktif memakai file; key adalah PK teks.','Infrastruktur')
item('cache_locks','Kunci cache untuk koordinasi proses bila driver database digunakan.','key, owner, expiration','Konfigurasi aktif memakai file; tidak terkait langsung dengan tabel transaksi.','Infrastruktur')
item('jobs','Antrean pekerjaan latar belakang Laravel dengan driver database.','queue, payload, attempts, reserved_at, available_at','Konfigurasi queue aktif sync, sehingga proses langsung dijalankan. Tidak ada FK ke PP atau akun pada tabel ini.','Infrastruktur')
item('job_batches','Ringkasan sekumpulan pekerjaan antrean Laravel.','name, total_jobs, pending_jobs, failed_jobs, failed_job_ids','Bukan batch import BOQ; tidak memiliki FK SQL ke jobs. Dipakai bila fasilitas batch pekerjaan digunakan.','Infrastruktur')
item('failed_jobs','Catatan pekerjaan antrean yang gagal.','uuid, connection, queue, exception, failed_at','uuid unik; tidak mempunyai FK ke jobs. Infrastruktur tersedia, alur bisnis saat ini memakai queue sync.','Infrastruktur')
assert set(CAT) == set(tables), (set(tables)-set(CAT), set(CAT)-set(tables))
for n,c in CAT.items(): tables[n].update(c)

GROUPS = [
('Organisasi dan akun',['areas','regions','branches','service_areas','users','roles','user_assignments','work_units','measurement_units']),
('Master pekerjaan dan harga',['packages','designator_types','designators','designator_package_prices','khs_price_lists','khs_prices']),
('Project dan Project Plan',['projects','numbering_rules','number_sequences','project_plans','project_plan_team','project_plan_schedule','project_plan_costs','project_plan_budget_rows','boq_lines','boq_components']),
('Mandor BA KH dan SPK',['contracts','contract_components','work_orders','contract_documents']),
('Pembayaran dan approval',['payment_terms','term_actuals','approval_runs','approval_steps','approval_decisions']),
('Berkas eviden scan dan APM',['document_files','term_work_evidence','term_scans','apm_settlements']),
('Penyelesaian dan volume',['ba_completions','volume_balances','volume_movements','volume_releases','continuation_allocations']),
('Ruang kerja dan audit',['prototype_workspaces','workflow_metadata','workflow_events','prototype_audits','command_receipts']),
('Rancangan impor terstruktur',['boq_import_templates','boq_import_batches','boq_import_rows','master_import_batches','master_import_rows']),
('Infrastruktur Laravel',['migrations','sessions','password_reset_tokens','cache','cache_locks','jobs','job_batches','failed_jobs']),
]
assert len([n for _, ns in GROUPS for n in ns])==61

FONT = '/System/Library/Fonts/Supplemental/Arial.ttf'
FONTB = '/System/Library/Fonts/Supplemental/Arial Bold.ttf'
if not Path(FONT).exists():
    FONT = '/System/Library/Fonts/Helvetica.ttc'; FONTB=FONT

DIAGRAMS=[]
def diagram(code,title,nodes,edges,note):
    # node is (name, short subtitle, column, row); FK arrows point child -> parent.
    W,H=720, max(r for _,_,_,r in nodes)*128+110
    svg=[f'<svg xmlns="http://www.w3.org/2000/svg" width="{W}" height="{H}" viewBox="0 0 {W} {H}">', '<rect width="100%" height="100%" fill="white"/>', '<defs><marker id="a" markerWidth="9" markerHeight="9" refX="8" refY="4" orient="auto"><path d="M0 0 L8 4 L0 8" fill="none" stroke="#6161b0" stroke-width="1.2"/></marker></defs>']
    scale=2
    im=Image.new('RGB',(W*scale,H*scale),'white'); draw=ImageDraw.Draw(im)
    pos={n:(18+c*365,18+r*128,320,76) for n,_,c,r in nodes}
    def line(points,dash=False):
        coords=' '.join(f'{x},{y}' for x,y in points)
        svg.append(f'<polyline points="{coords}" fill="none" stroke="#6161b0" stroke-width="2"'+(' stroke-dasharray="7 5"' if dash else '')+' marker-end="url(#a)"/>')
        pts=[(int(x*scale),int(y*scale)) for x,y in points]
        if dash:
            for (ax,ay),(bx,by) in zip(pts,pts[1:]):
                length=math.hypot(bx-ax,by-ay)
                if not length: continue
                for start in range(0,int(length),24):
                    stop=min(start+14,length)
                    draw.line([(ax+(bx-ax)*start/length,ay+(by-ay)*start/length),(ax+(bx-ax)*stop/length,ay+(by-ay)*stop/length)],fill='#6161b0',width=4)
        else: draw.line(pts,fill='#6161b0',width=4)
        (x1,y1),(x2,y2)=pts[-2:]; a=math.atan2(y2-y1,x2-x1)
        draw.line([(x2-13*math.cos(a-.5),y2-13*math.sin(a-.5)),(x2,y2),(x2-13*math.cos(a+.5),y2-13*math.sin(a+.5))],fill='#6161b0',width=3)
    for child,parent,label,*other in edges:
        logical=bool(other and other[0]); x,y,w,h=pos[child]; X,Y,ww,hh=pos[parent]
        if x==X:
            if abs(Y-y)>130:
                # Long links go around intermediate boxes rather than through them.
                side=6 if x<100 else 714
                points=[(x if x<100 else x+w,y+h/2),(side,y+h/2),(side,Y+hh/2),(X if X<100 else X+ww,Y+hh/2)]
            elif Y>y: points=[(x+w/2,y+h),(X+ww/2,Y)]
            else: points=[(x+w/2,y),(X+ww/2,Y+hh)]
        elif y==Y:
            points=[(x+w if X>x else x,y+h/2),(X if X>x else X+ww,Y+hh/2)]
        else:
            # Enter the top/bottom of the parent through the gap between rows.
            # This avoids an incoming FK looking like a reverse of a separate horizontal FK.
            gap=Y+hh+22 if Y<y else Y-22
            end=Y+hh if Y<y else Y
            # Offset the target port from vertical FK links on the centre line.
            # In particular a scan -> term link must not look like SPK -> term.
            port=X+ww*.78
            points=[(x+w if X>x else x,y+h/2),(359,y+h/2),(359,gap),(port,gap),(port,end)]
        line(points,logical)
        if label:
            a,b=points[len(points)//2-1:len(points)//2+1]
            xx=(a[0]+b[0])/2; yy=(a[1]+b[1])/2-8
            svg.append(f'<text x="{xx}" y="{yy}" font-family="Arial,sans-serif" font-size="17" text-anchor="middle" fill="#343452">{html.escape(label)}</text>')
            f=ImageFont.truetype(FONT,34); draw.text((xx*2,yy*2),label,font=f,fill='#343452',anchor='ms',stroke_width=2,stroke_fill='white')
    for n,sub,c,r in nodes:
        x,y,w,h=pos[n]; prepared=n in CAT and CAT[n]['status']=='Disiapkan'
        fill='#f1f2f5' if prepared else '#edeeff'; border='#a2a7b2' if prepared else '#9fa1ff'
        svg.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="9" fill="{fill}" stroke="{border}" stroke-width="2"/>')
        # Smallest displayed table name remains about 9 pt when printed at 122 mm.
        fs=min(22, max(18, 548/max(len(n),1)))
        svg.append(f'<text x="{x+w/2}" y="{y+30}" text-anchor="middle" font-family="Arial,sans-serif" font-weight="bold" font-size="{fs}" fill="#111111">{html.escape(n)}</text>')
        svg.append(f'<text x="{x+w/2}" y="{y+57}" text-anchor="middle" font-family="Arial,sans-serif" font-size="18" fill="#404454">{html.escape(sub)}</text>')
        draw.rounded_rectangle((x*2,y*2,(x+w)*2,(y+h)*2),radius=18,fill=fill,outline=border,width=4)
        draw.text(((x+w/2)*2,(y+30)*2),n,font=ImageFont.truetype(FONTB,int(fs*2)),fill='#111111',anchor='ms')
        draw.text(((x+w/2)*2,(y+57)*2),sub,font=ImageFont.truetype(FONT,36),fill='#404454',anchor='ms')
    svg.append('</svg>'); (ASSETS/f'{code}.svg').write_text(''.join(svg)); im.save(ASSETS/f'{code}.png')
    DIAGRAMS.append(dict(code=code,title=title,note=note))

diagram('01-alur-utama','Peta utama transaksi',[
('projects','ID Project',0,0),('project_plans','PP dan snapshot',0,1),('contracts','SPH dan BA KH',0,2),('work_orders','SPK',0,3),('payment_terms','Pembayaran termin',0,4),('boq_lines','BOQ root',1,1),('approval_runs','Siklus PP atau termin',1,2),('term_scans','Scan TTD fisik',1,3),('apm_settlements','Pelunasan penuh',1,4)],[
('project_plans','projects',''),('contracts','project_plans',''),('work_orders','contracts',''),('payment_terms','work_orders',''),('boq_lines','project_plans',''),('approval_runs','project_plans',''),('apm_settlements','term_scans',''),('term_scans','payment_terms','')],
'Panah menunjuk tabel induk. Mayoritas relasi banyak ke satu. APM ke scan memakai FK gabungan termasuk term_id. Relasi tambahan dan FK pelaku dijelaskan pada katalog.')
diagram('02-organisasi','Wilayah dan kewenangan akun',[
('areas','Area',0,0),('regions','Region',0,1),('branches','Branch dan kode_branch',0,2),('service_areas','Wilayah layanan',0,3),('users','Akun dan penempatan',1,1),('roles','Daftar peran',1,2),('user_assignments','Izin peran per Branch',1,3)],[
('regions','areas',''),('branches','regions',''),('service_areas','branches',''),('user_assignments','users',''),('user_assignments','roles',''),('user_assignments','branches','')],
'Wilayah utama akun dan cakupan akses berbeda. users mempunyai tiga FK penempatan; akses Branch ditentukan user_assignments. Diagram memilih relasi utama agar terbaca.')
diagram('03-harga','Master harga dan sumber BOQ',[
('designator_types','Jenis Material atau Jasa',0,0),('packages','Paket',1,0),('designators','Kode uraian dan satuan',0,1),('designator_package_prices','Harga sumber aktif',1,1),('boq_lines','Snapshot designator',1,2),('boq_components','Volume dan harga M atau S',1,3),('khs_price_lists','Versi KHS disiapkan',0,2),('khs_prices','Rincian KHS disiapkan',0,3)],[
('designators','designator_types',''),('designator_package_prices','designators',''),('designator_package_prices','packages',''),('boq_lines','designator_package_prices',''),('boq_components','boq_lines',''),('khs_prices','khs_price_lists','')],
'Kotak abu-abu adalah tabel yang disiapkan. Harga aktif BOQ mengacu designator_package_prices, sedangkan khs_prices belum menjadi sumber runtime.')
diagram('04-pp','Isi Project Plan dan penomoran',[
('projects','Satu ID Project',0,0),('numbering_rules','Format nomor PP',1,0),('project_plans','Banyak PP per Project',0,1),('number_sequences','Nomor berikutnya',1,1),('project_plan_team','Susunan tim',0,2),('project_plan_schedule','Lima tahap dan HK',1,2),('project_plan_costs','Ringkasan biaya',0,3),('project_plan_budget_rows','Rincian dinamis JSON',1,3)],[
('project_plans','projects',''),('number_sequences','numbering_rules',''),('project_plans','number_sequences',''),('project_plan_team','project_plans',''),('project_plan_schedule','project_plans',''),('project_plan_costs','project_plans',''),('project_plan_budget_rows','project_plans','')],
'root_pp_id dan parent_pp_id merupakan FK ke project_plans sendiri. Nomor terbit menyimpan snapshot pola, kode Branch, periode, dan urutan; hubungan nomor memakai FK gabungan.')
diagram('05-kontrak','Komponen kontrak dan tagihan',[
('project_plans','PP Sah',0,0),('boq_components','Komponen BOQ root',1,0),('contracts','Negosiasi dan BA KH',0,1),('contract_components','Volume dan harga Mandor',1,1),('work_orders','SPK',0,2),('term_actuals','Volume tiap tagihan',1,2),('payment_terms','Termin Partial atau Final',0,3)],[
('contracts','project_plans',''),('contract_components','contracts',''),('contract_components','boq_components',''),('work_orders','contracts',''),('payment_terms','work_orders',''),('term_actuals','payment_terms',''),('term_actuals','contract_components','')],
'Satu kontrak dapat mempunyai banyak komponen dan maksimal tiga termin menurut aturan aplikasi. FK work_orders.contract_id sendiri tidak membatasi satu SPK per kontrak.')
diagram('06-approval','Siklus approval dan bukti keputusan',[
('project_plans','Target PP',0,0),('payment_terms','Target pembayaran',1,0),('approval_runs','Satu target per siklus',0,1),('users','Akun aktif terpilih',1,1),('approval_steps','Urutan dan penugasan',0,2),('approval_decisions','Nol atau satu per tahap',0,3)],[
('approval_runs','project_plans',''),('approval_runs','payment_terms',''),('approval_steps','approval_runs',''),('approval_steps','users',''),('approval_decisions','approval_steps','')],
'Satu run menunjuk PP ATAU termin, bukan keduanya. Finance setelah scan tersimpan pada verifikasi scan dan riwayat snapshot termin, terpisah dari lima approval dokumen.')
diagram('07-berkas','Berkas pekerjaan scan dan APM',[
('payment_terms','Induk tagihan',0,0),('document_files','Metadata dan path privat',1,0),('term_work_evidence','PDF atau foto pekerjaan',0,1),('term_scans','Versi scan TTD fisik',1,1),('apm_settlements','Satu APM per termin',1,2),('ba_completions','Satu BA PP per Final',0,2)],[
('term_work_evidence','payment_terms',''),('term_work_evidence','document_files',''),('term_scans','payment_terms',''),('term_scans','document_files',''),('apm_settlements','term_scans',''),('ba_completions','payment_terms','')],
'Eviden dan scan masing-masing mempunyai FK file_id unik. APM terikat scan dan termin yang sama; BA PP hanya untuk Final menurut validasi server. File asli berada di penyimpanan privat.')
diagram('08-ledger','Saldo volume dan rancangan lanjutan',[
('boq_components','Sumber volume root',0,0),('command_receipts','Satu perintah tersimpan',1,0),('volume_balances','Satu saldo per komponen',0,1),('volume_movements','Delta perpindahan',1,1),('ba_completions','BA PP Sah',0,2),('volume_releases','Proyeksi belum aktif',0,3),('continuation_allocations','Proyeksi belum aktif',1,3)],[
('volume_balances','boq_components',''),('volume_movements','boq_components',''),('volume_movements','command_receipts',''),('volume_releases','ba_completions',''),('continuation_allocations','volume_releases','')],
'Relasi SQL pada kotak abu-abu sudah tersedia, tetapi rincian release/lanjutan aktif masih dalam snapshot dan ledger. available + reserved + committed + accepted = original_quantity.')
diagram('09-workspace','Ruang kerja audit dan aktivitas',[
('prototype_workspaces','Kunci revisi dan arsip',0,0),('workflow_metadata','Konfigurasi satu workspace',1,0),('workflow_events','Timeline aktivitas',0,1),('prototype_audits','Jejak perintah workflow',1,1),('command_receipts','UUID dan revisi perintah',1,2),('volume_movements','Perpindahan volume',0,2)],[
('workflow_metadata','prototype_workspaces',''),('workflow_events','prototype_workspaces',''),('prototype_audits','prototype_workspaces',''),('command_receipts','prototype_workspaces','logis',True),('volume_movements','command_receipts','')],
'Garis putus-putus adalah hubungan logis tanpa FK SQL. document_data per dokumen adalah sumber baca transaksi; prototype_workspaces.state tetap arsip.')

# Exact complete ERD, including optional parents and unique-child relationships.
mmd=['erDiagram']
for n,t in tables.items():
    mmd.append(f'    {n} {{')
    fkcols={c for f in t['fks'] for c in f['columns']}
    for c in t['columns']:
        if c['name'] in t['pk'] or c['name'] in fkcols:
            typ=re.sub(r'[^a-zA-Z0-9_]','_',c['type'])
            flag=' PK' if c['name'] in t['pk'] else ' FK'
            mmd.append(f'        {typ} {c["name"]}{flag}')
    mmd.append('    }')
for n,t in tables.items():
    for f in t['fks']:
        parent='|o' if f['nullable'] else '||'
        child='o|' if f['unique_child'] else 'o{'
        mmd.append(f'    {f["target"]} {parent}--{child} {n} : "{", ".join(f["columns"])}"')
(DATA/'area3-relations.mmd').write_text('\n'.join(mmd)+'\n')
snapshot=dict(schema, tables=list(tables.values()), groups=GROUPS, diagrams=DIAGRAMS)
(DATA/'schema-catalog.json').write_text(json.dumps(snapshot,ensure_ascii=False,indent=2)+'\n')

def fktext(f):
    return ', '.join(f['columns'])+' -> '+f['target']+'.'+(', '.join(f['target_columns']) if len(f['target_columns'])==1 else '('+', '.join(f['target_columns'])+')')

doc=Document(); sec=doc.sections[0]
sec.page_width=Mm(148); sec.page_height=Mm(210)
sec.top_margin=Mm(15); sec.bottom_margin=Mm(15); sec.left_margin=Mm(13); sec.right_margin=Mm(13)
sec.header_distance=Mm(7); sec.footer_distance=Mm(7)
styles=doc.styles
for name in ['Normal','Title','Subtitle','Heading 1','Heading 2','Heading 3','Header','Footer']:
    styles[name].font.name='Arial'; styles[name].font.color.rgb=RGBColor(0,0,0)
    for border in styles[name].element.findall('.//'+qn('w:pBdr')):
        border.getparent().remove(border)
    for themed in styles[name].element.findall('.//'+qn('w:color')):
        for attr in ['themeColor','themeTint','themeShade']:
            themed.attrib.pop(qn('w:'+attr),None)
styles['Normal'].font.size=Pt(9.5); styles['Normal'].paragraph_format.space_after=Pt(5)
styles['Normal'].paragraph_format.line_spacing=1.08
styles['Title'].font.size=Pt(27); styles['Title'].font.bold=True
styles['Subtitle'].font.size=Pt(12)
for name,size in [('Heading 1',17),('Heading 2',12),('Heading 3',10.5)]:
    styles[name].font.size=Pt(size); styles[name].font.bold=True
    styles[name].paragraph_format.space_before=Pt(11); styles[name].paragraph_format.space_after=Pt(5)
styles['Header'].font.size=Pt(8); styles['Footer'].font.size=Pt(8)
hp=sec.header.paragraphs[0]; hp.text="it's Area 3   /   Buku saku database"; hp.alignment=WD_ALIGN_PARAGRAPH.RIGHT
fp=sec.footer.paragraphs[0]; fp.alignment=WD_ALIGN_PARAGRAPH.RIGHT
fp.add_run('Edisi 5 November 2026   |   ')
fld=OxmlElement('w:fldSimple'); fld.set(qn('w:instr'),'PAGE'); fp._p.append(fld)
doc.core_properties.title='Buku Saku Relasi Database its Area 3'
doc.core_properties.subject='Struktur aktual 61 tabel dan 106 foreign key database its'
doc.core_properties.author="it's Area 3"
doc.settings.odd_and_even_pages_header_footer=False
def p(text='',boldlead=None,small=False):
    para=doc.add_paragraph()
    if boldlead:
        para.add_run(boldlead+' ').bold=True
    para.add_run(text)
    if small:
        for r in para.runs:r.font.size=Pt(8.5)
    return para
def head(text,l=1):doc.add_heading(text,l)
def page():doc.add_page_break()
def bullet(text):
    para=p('•  '+text); para.paragraph_format.left_indent=Mm(2)
def grid(headers, rows, widths):
    tb=doc.add_table(rows=1, cols=len(headers)); tb.autofit=False
    for c,w in zip(tb.columns,widths):c.width=Mm(w)
    for i,h in enumerate(headers):tb.rows[0].cells[i].text=h
    for row in rows:
        cs=tb.add_row().cells
        for i,txt in enumerate(row):cs[i].text=str(txt)
    for ri,row in enumerate(tb.rows):
        for ci,cell in enumerate(row.cells):
            cell.width=Mm(widths[ci]); tcPr=cell._tc.get_or_add_tcPr()
            borders=OxmlElement('w:tcBorders')
            for edge in ['top','left','bottom','right']:
                el=OxmlElement('w:'+edge); el.set(qn('w:val'),'single'); el.set(qn('w:sz'),'4'); el.set(qn('w:color'),'D9D9D9'); borders.append(el)
            tcPr.append(borders)
            mar=OxmlElement('w:tcMar')
            for side in ['top','left','bottom','right']:
                el=OxmlElement('w:'+side);el.set(qn('w:w'),'80');el.set(qn('w:type'),'dxa');mar.append(el)
            tcPr.append(mar)
            sh=OxmlElement('w:shd'); sh.set(qn('w:fill'),'E8ECF5' if ri==0 else ('F7F8FB' if ri%2==0 else 'FFFFFF'));tcPr.append(sh)
            va=OxmlElement('w:vAlign');va.set(qn('w:val'),'center');tcPr.append(va)
            for par in cell.paragraphs:
                par.paragraph_format.space_after=Pt(1); par.paragraph_format.line_spacing=1.0
                for run in par.runs:run.font.size=Pt(8.5); run.bold=ri==0
        pr=row._tr.get_or_add_trPr(); ns=OxmlElement('w:cantSplit');pr.append(ns)
    repeat=OxmlElement('w:tblHeader');tb.rows[0]._tr.get_or_add_trPr().append(repeat)
    return tb

p("it's Area 3",small=True)
doc.add_paragraph('Buku Saku\nRelasi Database',style='Title')
doc.add_paragraph('Panduan tabel dan hubungan data aplikasi',style='Subtitle')
p('Database its\nLaravel 12 dan React\nXAMPP MariaDB 10.4.28')
p('61 tabel   /   106 foreign key\n497 kolom   /   9 diagram terpilih',boldlead='Cakupan')
p('Edisi 5 November 2026')
p('Untuk Admin Konstruksi, approver, Finance, dan pengembang yang perlu menelusuri asal data PP sampai pembayaran serta memahami tempat data disimpan.')
p('Struktur diperiksa langsung dari metadata database its, lalu dicocokkan dengan migration dan penyimpanan aplikasi. Panduan ini tidak memuat isi akun, transaksi pengguna, atau kredensial.',small=True)
page();head('Cara menggunakan buku ini')
p('Mulai dari peta transaksi dan alur approval. Setelah itu, cari tabel pada bab sesuai proses kerja. Katalog memuat fungsi, primary key, kolom penting, seluruh FK keluar, serta catatan pemakaian.')
grid(['Istilah','Arti'],[
('PK','Primary key, identitas unik satu baris.'),('FK','Foreign key, hubungan yang dipaksakan oleh database.'),('UNIQUE','Nilai atau kombinasi nilai tidak boleh berulang.'),('NULL','Data boleh belum diisi; berbeda dengan nol atau teks kosong.'),('Snapshot','Salinan rincian dan kondisi dokumen pada saat disimpan.'),('N ke 1','Banyak baris anak mengacu satu baris induk.')],[26,96])
head('Status tabel',2)
p('Aktif berarti digunakan oleh kode runtime; tidak berarti tabel harus sudah berisi data. Disiapkan berarti strukturnya sudah ada, tetapi belum menjadi sumber proses aktif yang dijelaskan. Infrastruktur adalah fasilitas Laravel yang pemakaiannya mengikuti konfigurasi.')
p('Panah pada diagram mengarah dari anak ke induk. Diagram memilih FK utama agar terbaca; katalog dan file ERD lengkap mencakup semua 106 FK. Garis putus-putus hanya menunjukkan hubungan logis. Tabel disiapkan berwarna abu-abu.')
p('Batas nol atau satu dikenali dari UNIQUE pada FK. FK nullable mengizinkan anak belum mempunyai induk. Aturan alur seperti lima approval dan maksimal tiga termin tetap diperiksa Laravel; FK saja tidak cukup.')
page();head('Daftar isi')
for i,(title,ns) in enumerate(GROUPS,1):p(f'{i:02}. {title}   ({len(ns)} tabel)')
p('Sesudah katalog: contoh membaca relasi, contoh SQL baca saja, penyimpanan file, batas implementasi, dan sumber pemeriksaan.')
p('Versi HTML pendamping menyediakan pencarian nama tabel/kolom, seluruh tipe data, NULL, kunci unik, dan FK masuk maupun keluar. Sumber Mermaid menyertakan seluruh tabel dan FK untuk pemeliharaan diagram.')
page();head('Alur kerja dan approval')
steps=[('1','Admin Konstruksi membuat PP','Upload BOQ dahulu, isi header dan anggaran, pilih lima approver aktif sesuai Branch.'),('2','PP melewati lima approval','PM Konstruksi -> Admin Area -> Mgr Deployment -> Mgr Bussiness Support -> Head of PMO.'),('3','PP Sah dilanjutkan ke SPK','SPH dan negosiasi -> BA KH Sah -> SPK. BA KH adalah bagian dari menu SPK.'),('4','Admin mengajukan Pembayaran','Isi volume aktual dan lampiran eviden. Maksimal tiga termin; termin ketiga harus Final.'),('5','Termin melewati lima approval','Urutan sama dengan PP. Setelah lengkap, berkas siap cetak dan dimintakan tanda tangan fisik.'),('6','Finance memverifikasi scan','Scan TTD diunggah ke aplikasi. Finance menyetujui scan aktif; penggantian scan perlu verifikasi baru.'),('7','Finance mencatat APM','APM unik melunasi seluruh tagihan termin secara atomik. Ini pencatatan pelunasan, belum transfer bank.')]
for no,title,detail in steps:
    head(no+' '+title,2);p(detail)
p('PP/termin v1 dan v2 mempertahankan aturan serta keputusan historis. Jangan menambahkan persetujuan atau identitas yang tidak pernah diberikan.',small=True)
page();head(DIAGRAMS[0]['title'])
doc.add_picture(str(ASSETS/'01-alur-utama.png'),width=Mm(122))
p(DIAGRAMS[0]['note'])
p('Hubungan inti: satu projects mempunyai banyak project_plans; satu PP dapat mempunyai beberapa kontrak mandor; tagihan selalu ditelusuri kembali melalui SPK, kontrak, dan PP.',small=True)

diagram_for_group={0:1,1:2,2:3,3:4,4:5,5:6,6:7,7:8}
num=0
for gi,(title,names) in enumerate(GROUPS):
    page();head(f'{gi+1:02} {title}')
    if gi in diagram_for_group:
        d=DIAGRAMS[diagram_for_group[gi]]
        doc.add_picture(str(ASSETS/(d['code']+'.png')),width=Mm(122))
        p(d['note'])
        page();head('Katalog '+title.lower())
    elif gi==8:
        p('Tabel berikut sudah memiliki relasi SQL, tetapi importer aktif belum menulis proyeksi batch/template/baris ini. Upload BOQ sesungguhnya tetap berjalan dan menyimpan hasil melalui jalur yang dijelaskan pada bab PP dan berkas.')
    else:
        p('Konfigurasi yang diperiksa: session=file, cache=file, queue=sync. Keberadaan tabel standar Laravel tidak otomatis berarti alur aplikasi menggunakannya.')
    for name in names:
        if name in ['boq_lines','work_orders','term_scans']:
            page()
        block_start=len(doc.paragraphs)
        num+=1;t=tables[name]
        head(f'{num:02} {name}',2)
        q=p(t['purpose']);q.paragraph_format.keep_with_next=True
        p('Status '+t['status']+' | PK '+', '.join(t['pk']),small=True)
        p(t['fields'],boldlead='Kolom penting',small=True)
        if t['fks']:
            # Merge repeated single-column links to the same parent key to keep the pocket format readable.
            merged=collections.OrderedDict()
            for f in t['fks']:
                target=f['target']+'.'+(', '.join(f['target_columns']) if len(f['target_columns'])==1 else '('+', '.join(f['target_columns'])+')')
                merged.setdefault(target,[]).append('('+', '.join(f['columns'])+')' if len(f['columns'])>1 else f['columns'][0])
            for j,(target,sources) in enumerate(merged.items()):
                p('; '.join(sources)+' -> '+target,boldlead='FK' if j==0 else None,small=True)
        else:p('Tidak ada FK keluar yang dideklarasikan.',boldlead='Relasi SQL',small=True)
        p(t['note'],boldlead='Catatan',small=True)
        # Keep each table explanation intact so a note or its FKs never starts the next page alone.
        for block_paragraph in doc.paragraphs[block_start:-1]:
            block_paragraph.paragraph_format.keep_with_next=True

page();head('Cara menelusuri data')
head('Satu Project dapat memiliki beberapa PP',2)
p('Cari projects.project_code, lalu hubungkan projects.id_project ke project_plans.project_id. id_pp adalah identitas tiap PP, sehingga beberapa PP tidak saling menimpa meskipun ID Project sama. Nomor dokumen pp_no tetap unik.')
head('Mengetahui posisi approval',2)
p('Pilih approval_runs untuk PP atau termin dan cycle_no terbaru. Urutkan approval_steps.step_no. Tahap tanpa approval_decisions belum memiliki keputusan. RETURN membuka koreksi dan pengajuan ulang; jangan menghitung keputusan dari siklus lama sebagai persetujuan siklus baru.')
head('Mengetahui apakah sudah terbayar',2)
p('Status approval bukan status pelunasan. Periksa scan aktif yang diverifikasi Finance dan apm_settlements untuk termin yang sama. Satu baris settlement mencatat pelunasan penuh; snapshot termin menyimpan riwayat Finance/pembayaran yang dibaca aplikasi.')
head('Mengapa kolom saja tidak mengubah tampilan',2)
p('Laravel membaca snapshot per dokumen pada document_data, kemudian mengikat akun dan master SQL yang berlaku. Kolom status dan relasi adalah proyeksi yang diselaraskan pada transaksi yang sama. Gunakan aplikasi untuk mengubah dokumen agar kolom, rincian, snapshot, ledger, dan audit tetap konsisten.')

QUERIES=[('Daftar PP per ID Project',"SELECT p.project_code, pp.pp_no, pp.status\nFROM projects p\nJOIN project_plans pp\n  ON pp.project_id = p.id_project\nORDER BY p.project_code, pp.pp_no;"),
('Posisi approval per PP dan siklus',"SELECT pp.pp_no, r.cycle_no, s.step_no,\n       s.expected_role, d.decision\nFROM project_plans pp\nJOIN approval_runs r ON r.pp_id = pp.id_pp\nJOIN approval_steps s\n  ON s.run_id = r.id_approval_run\nLEFT JOIN approval_decisions d\n  ON d.step_id = s.id_approval_step\nORDER BY pp.pp_no, r.cycle_no, s.step_no;"),
('Path eviden pekerjaan termin',"SELECT t.source_id, f.original_name,\n       f.storage_key, e.removed_at\nFROM payment_terms t\nJOIN term_work_evidence e\n  ON e.term_id = t.id_payment_term\nJOIN document_files f\n  ON f.id_document_file = e.file_id;"),
('Referensi APM per tagihan',"SELECT t.source_id, t.term_no,\n       a.apm_no, a.amount, a.recorded_at\nFROM payment_terms t\nLEFT JOIN apm_settlements a\n  ON a.term_id = t.id_payment_term;")]
page();head('Contoh SQL baca saja')
p('Contoh ini untuk petugas yang memang berwenang membaca database its. Tidak mengubah data. Query langsung tidak menerapkan filter Branch dari aplikasi; tambahkan cakupan yang sesuai sebelum membuat laporan operasional.',small=True)
for i,(title,sql) in enumerate(QUERIES):
    if i==2:page();head('Contoh SQL berkas dan APM')
    head(title,2)
    q=p(sql)
    for r in q.runs:r.font.name='Courier New';r.font.size=Pt(8)
    q.paragraph_format.line_spacing=1.05

page();head('Penyimpanan yang perlu dibedakan')
grid(['Data','Tempat penyimpanan'],[
('Akun dan master','Tabel users, penugasan, wilayah, designator, dan harga pada its.'),('Transaksi','Tabel dokumen, rincian, relasi, dan snapshot JSON per dokumen pada its.'),('File asli','Folder privat storage/app/private; path/metadata pada document_files.'),('Sesi dan cache','File Laravel sesuai konfigurasi aktif.'),('Form belum disimpan','Draft lokal browser; belum menjadi transaksi server.'),('Arsip awal','prototype_workspaces.state; tidak dibaca sebagai fallback.')],[35,87])
head('Path relatif berkas',2)
for text in ['BOQ: prototype-boq/{workspace}/{upload}/source.xlsx','Scan TTD: prototype-scans/{workspace}/{termin}/{scan}.{ext}','Eviden: work-evidence/{workspace}/{termin}/{upload}.{ext}']:
    p(text,small=True)
p('Path merujuk nama di disk privat, bukan URL publik. Preview melewati autentikasi dan izin Branch. Cadangan pemulihan perlu memuat database serta folder file privat, karena ekspor SQL tidak menyertakan byte berkas.')
page();head('Batas implementasi saat ini')
for text in [
'Normalisasi belum lengkap. document_data menyimpan formulir dinamis, pilihan approver, riwayat historis, Finance, pelepasan, dan alokasi lanjutan yang belum mempunyai proyeksi tabel tersendiri.',
'Tabel disiapkan mencakup KHS berversi, kamus satuan, import batch/template/baris, versi berkas kontrak, volume_releases, dan continuation_allocations. Status tabel ditentukan dari kode runtime, bukan jumlah baris.',
'Tidak semua kolom yang menyerupai ID adalah FK. Contohnya users.role, designators.designator_category_id, sessions.user_id, command_receipts.aggregate_id, dan referensi sumber polimorfik movement.',
'PK transaksi baru memakai UUID bernama id_pp, id_spk, id_boq, dan seterusnya. Akun/master mempertahankan ID numerik. Tabel infrastruktur serta workspace/audit lama masih memiliki PK id, key, atau email.',
'Finance menyetujui scan aktif sesudah lima approval. Riwayat Finance terdapat dalam snapshot termin dan kolom verifikasi scan, bukan otomatis sebuah approval_steps keenam.',
'Tanda tangan fisik dan verifikasi dokumen dilakukan manusia. APM adalah pencatatan pelunasan manual, tanpa integrasi bank/ERP. Cetakan menggunakan HTML/browser; retensi berkas otomatis dan SSO belum tersedia.'
]:p(text)
page();head('Sumber dan pemeriksaan')
p('Metadata struktur dibaca langsung dari information_schema database its pada 5 November 2026 dalam transaksi baca saja. Tidak ada pembacaan isi akun/PP atau perubahan database untuk penyusunan buku ini.')
p('Hasil inventaris: 61 tabel, 497 kolom, 61 primary key, dan 106 foreign key. Foreign key gabungan dihitung sebagai satu hubungan, bukan satu hubungan per kolom.')
p('Fungsi dan status tabel dicocokkan dengan migration Laravel, RelationalWorkflowStore, akses akun, domain workflow, controller berkas, serta dokumentasi runtime terbaru. Dokumen konseptual lama tidak dipakai untuk mengklaim perilaku aktif.')
for name in ['AGENTS.md','README.md dan WORKPLAN.md','docs/MYSQL_RUNTIME.md','docs/WORKFLOW_V3.md','docs/BOQ_IMPORT.md','docs/TERM_WORK_EVIDENCE.md','app/Services/RelationalWorkflowStore.php','database/migrations/']:
    p(name,small=True)
p('Pendamping buku: area3-relasi-tabel.html untuk penelusuran tabel/kolom; schema-catalog.json untuk metadata dan penjelasan; area3-relations.mmd untuk ERD lengkap. Diagram ringkas tersedia sebagai SVG dan PNG.')
p('Buku ini adalah dokumentasi struktur per tanggal pemeriksaan. Jika migration, alur, atau penyimpanan berubah, perbarui snapshot schema, deskripsi, diagram, dan buku secara bersamaan.')

DOCX=OUT/'Buku-Saku-Relasi-Database-its-Area-3.docx'
for border in doc.element.findall('.//'+qn('w:pBdr')):
    border.getparent().remove(border)
doc.save(DOCX)

# Offline, searchable schema companion. No remote dependencies or embedded business rows.
cards=[]
for title,names in GROUPS:
    cards.append(f'<section class="group"><h2>{html.escape(title)}</h2>')
    for n in names:
        t=tables[n]; incoming=[(a,f) for a,at in tables.items() for f in at['fks'] if f['target']==n]
        fkhtml=''.join('<li>'+html.escape(fktext(f))+(' <em>(opsional)</em>' if f['nullable'] else '')+'</li>' for f in t['fks']) or '<li>Tidak ada FK keluar.</li>'
        inhtml=''.join('<li><a href="#'+a+'">'+html.escape(a)+'</a>: '+html.escape(', '.join(f['columns']))+'</li>' for a,f in incoming) or '<li>Tidak ada FK masuk.</li>'
        rows=''.join('<tr><td>'+html.escape(c['name'])+'</td><td>'+html.escape(c['type'])+'</td><td>'+('Ya' if c['nullable']=='YES' else 'Tidak')+'</td><td>'+html.escape(c['key_type'] or '-')+'</td></tr>' for c in t['columns'])
        uq='; '.join('('+', '.join(u)+')' for u in t['unique']) or 'Tidak ada selain PK.'
        cards.append(f'<article id="{n}" data-search="{html.escape(n+" "+title+" "+t["purpose"]+" "+" ".join(c["name"] for c in t["columns"]))}"><h3>{n}</h3><span class="badge">{t["status"]}</span><p>{html.escape(t["purpose"])}</p><p><b>PK</b> {html.escape(", ".join(t["pk"]))}</p><p>{html.escape(t["note"])}</p><details><summary>Kolom dan seluruh relasi</summary><h4>Kolom</h4><div class="table-scroll"><table><thead><tr><th>Kolom</th><th>Tipe</th><th>NULL</th><th>Key</th></tr></thead><tbody>{rows}</tbody></table></div><p><b>UNIQUE</b> {html.escape(uq)}</p><h4>FK keluar</h4><ul>{fkhtml}</ul><h4>FK masuk</h4><ul>{inhtml}</ul></details></article>')
    cards.append('</section>')
vis=''.join(f'<figure><h3>{d["title"]}</h3>'+ (ASSETS/(d['code']+'.svg')).read_text()+f'<figcaption>{html.escape(d["note"])}</figcaption></figure>' for d in DIAGRAMS)
htmlout='''<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Relasi tabel its Area 3</title><style>
:root{color-scheme:light;font:16px/1.6 Arial,sans-serif;color:#1c2234;background:#f5f6fc}*{box-sizing:border-box}body{margin:0}header,main{max-width:1140px;margin:auto;padding:32px 24px}header{padding-bottom:12px}h1{font-size:34px;margin:10px 0}h2{font-size:25px;margin:30px 0 16px}h3{overflow-wrap:anywhere;margin:0 0 8px}a{color:#4446a2}nav{display:flex;gap:16px;flex-wrap:wrap}input{width:100%;padding:14px;border:2px solid #9fa1ff;border-radius:8px;font:inherit;background:white}.search{position:sticky;top:0;background:#f5f6fc;padding:12px 0;z-index:1}.badge{font-size:12px;background:#edeeff;padding:4px 9px;border-radius:20px}article,figure{background:white;border:1px solid #daddea;border-radius:12px;padding:24px;margin:0 0 20px}figure{max-width:800px}svg{display:block;width:100%;height:auto}figcaption{font-size:14px;margin-top:12px}summary{cursor:pointer;color:#4446a2;font-weight:bold}table{border-collapse:collapse;width:100%;font-size:14px}th,td{border:1px solid #d9d9d9;padding:8px;text-align:left;overflow-wrap:anywhere}th{background:#e8ecf5}tr:nth-child(even){background:#f7f8fb}.table-scroll{overflow:auto}li{overflow-wrap:anywhere}em{font-size:13px}p{margin:10px 0}.hidden{display:none}footer{font-size:13px;padding:32px 0}@media(min-width:900px){.group{display:grid;grid-template-columns:1fr 1fr;gap:20px}.group>h2{grid-column:1/-1}.group article{margin:0}.group{margin-bottom:30px}}@media print{.search,nav{display:none}article{break-inside:avoid}body{background:white}}
</style><header><p>it's Area 3 / Database its / 5 November 2026</p><h1>Visual relasi dan katalog tabel</h1><p>61 tabel · 497 kolom · 106 foreign key. Sumber metadata database aktif dan kode runtime. Tanpa isi akun, transaksi, atau kredensial.</p><nav><a href="#diagram">Diagram</a><a href="#katalog">Katalog lengkap</a><a href="area3-relations.mmd">ERD Mermaid lengkap</a><a href="schema-catalog.json">Metadata schema</a></nav></header><main><section id="diagram"><h2>Diagram relasi utama</h2><p>Panah dari anak ke induk. Kotak abu-abu menandai tabel disiapkan. Diagram memilih relasi utama; seluruh FK tersedia di katalog. Hubungan logis diberi garis putus-putus.</p>'''+vis+'''</section><section id="katalog"><h2>Katalog seluruh tabel</h2><p>Aktif berarti dipakai kode, bukan berarti tabel sudah berisi data. Disiapkan berarti belum menjadi proyeksi proses aktif. Infrastruktur mengikuti driver; saat ini session=file, cache=file, queue=sync.</p><div class="search"><label for="q">Cari nama tabel, kolom, atau fungsi</label><input id="q" type="search" placeholder="Contoh: id_pp, scan, branch, approval" autocomplete="off"><p id="count" aria-live="polite">61 tabel</p></div>'''+''.join(cards)+'''</section><footer>Snapshot JSON per dokumen masih menjadi sumber baca rincian. prototype_workspaces.state adalah arsip. File asli disimpan privat; SQL hanya menyimpan path/metadata. Pembaruan data dilakukan melalui aplikasi agar snapshot, relasi, saldo dan audit tetap konsisten.</footer></main><script>
const q=document.getElementById('q'), cards=[...document.querySelectorAll('article')];q.addEventListener('input',()=>{const s=q.value.toLowerCase().trim();let count=0;cards.forEach(c=>{const show=c.dataset.search.toLowerCase().includes(s);c.classList.toggle('hidden',!show);if(show)count++});document.querySelectorAll('.group').forEach(g=>g.classList.toggle('hidden',![...g.querySelectorAll('article')].some(c=>!c.classList.contains('hidden'))));document.getElementById('count').textContent=count+' tabel ditemukan'});
</script></html>'''
(DATA/'area3-relasi-tabel.html').write_text(htmlout)
print(json.dumps(dict(docx=str(DOCX), tables=len(tables), foreign_keys=sum(len(t['fks']) for t in tables.values()), diagrams=len(DIAGRAMS), status_counts=dict(collections.Counter(t['status'] for t in tables.values())))))

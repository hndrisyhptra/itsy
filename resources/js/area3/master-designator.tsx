import { useEffect, useState } from 'react';
import { Plus, Pencil, Trash2, RefreshCw, ChevronLeft, ChevronRight } from 'lucide-react';
import type { State } from './domain';
import { money } from './domain';
import { appUrl, boot } from './laravel';
import { saveMaster } from './store';
import { MASTER_TABS, masterId, masterRows, type MasterCatalog, type MasterEntity, type MasterRecord } from './master-data';
import { Link, PageTitle, Card, Button, Table, Field, Input, Notice, Empty, Modal, toast } from './ui';
const EMPTY:MasterCatalog={designators:[],packages:[],prices:[],types:[]};
type FormData=Record<string,string|number>;
export function MasterDesignator({s,entity}:{s:State;entity:MasterEntity}) {
  const [catalog,setCatalog]=useState<MasterCatalog>(EMPTY),[revision,setRevision]=useState(0),[loading,setLoading]=useState(true),[error,setError]=useState('');
  const [query,setQuery]=useState(''),[packageId,setPackageId]=useState(''),[page,setPage]=useState(0),[edit,setEdit]=useState<{id?:number;data:FormData}|null>(null),[remove,setRemove]=useState<MasterRecord|null>(null),[saving,setSaving]=useState(false),[formError,setFormError]=useState('');
  const label=MASTER_TABS.find(t=>t.id===entity)!.label,canManage=!!boot?.account&&s.role==='Superadmin';
  useEffect(()=>{const controller=new AbortController();void load(controller.signal);return()=>controller.abort();},[]);
  async function load(signal?:AbortSignal) {
    setLoading(true);setError('');
    try {
      const response=await fetch(appUrl('/api/masters'),{credentials:'same-origin',headers:{Accept:'application/json'},signal:signal??AbortSignal.timeout(15000)});
      if(response.status===401||response.status===419){if(boot?.accountUrls)window.location.assign(boot.accountUrls.login);}
      const data=await response.json();if(!response.ok)throw new Error(data.message||'Data master tidak dapat dimuat.');
      setCatalog(data.catalog);setRevision(data.revision);
    }catch(e){if(!signal?.aborted)setError(e instanceof Error?e.message:'Koneksi terputus.');}finally{if(!signal?.aborted)setLoading(false);}
  }
  const rows=masterRows(catalog,entity,query,packageId),pages=Math.max(1,Math.ceil(rows.length/30)),current=Math.min(page,pages-1),visible=rows.slice(current*30,(current+1)*30);
  function open(row?:MasterRecord) {
    const data:FormData=entity==='designator'?{code:'',item_name:'',unit:'',designator_type_id:''}:entity==='khs'?{designator_id:'',package_id:'',price:''}:{code:'',name:'',description:''};
    if(row)for(const key of Object.keys(data))data[key]=(row as unknown as Record<string,string|number|null>)[key]??'';
    setEdit({id:row?masterId(row):undefined,data});setFormError('');
  }
  async function submit(deleting=false) {
    if(saving)return;setSaving(true);setFormError('');
    try {
      const result=await saveMaster(entity,deleting?{action:'delete',id:masterId(remove!),revision}:{action:'save',...(edit!.id?{id:edit!.id}:{}),data:edit!.data,revision});
      setCatalog(result.catalog);setRevision(result.revision);setEdit(null);setRemove(null);toast(deleting?'Data dihapus dari master aktif.':'Data master berhasil disimpan.');
    }catch(e){setFormError((e instanceof Error?e.message:'Penyimpanan gagal.')+' Jika status penyimpanan belum jelas, muat ulang daftar sebelum mencoba kembali.');}finally{setSaving(false);}
  }
  return <><PageTitle title="Master Designator" description="Kelola designator, harga KHS per paket, dan paket KHS yang menjadi acuan BOQ.">{canManage&&<Button primary title={`Tambah ${label}`} aria-label={`Tambah ${label}`} disabled={loading||!!error} onClick={()=>open()}><Plus size={15}/> Tambah {label}</Button>}</PageTitle>
    <div className="view-tabs" aria-label="Submenu Master Designator">{MASTER_TABS.map(t=><Link key={t.id} className={entity===t.id?'btn primary':'btn'} to={'/master-designator/'+t.id}>{t.label}</Link>)}</div>
    <Notice>{canManage?'Perubahan tersimpan ke database. Hapus mengarsipkan data; dokumen lama tetap dipertahankan.':'Akses lihat. Hubungi Superadmin untuk menambah, mengubah atau menghapus data master.'} {entity==='khs'&&'Harga Material dan Jasa dicatat terpisah sesuai jenis designator.'}</Notice>
    {error&&<Notice tone="error">{error}</Notice>}
    <Card title={label} subtitle={`${rows.length} data${loading?' · Memuat…':''}`} className="no-padding" action={<Button title="Muat ulang daftar" aria-label="Muat ulang daftar master" disabled={loading||saving} onClick={()=>void load()}><RefreshCw size={15}/></Button>}>
      <div className="filters"><input aria-label={`Cari ${label}`} placeholder={`Cari ${label.toLowerCase()}…`} value={query} onChange={e=>{setQuery(e.target.value);setPage(0);}}/>{entity==='khs'&&<select aria-label="Filter paket KHS" value={packageId} onChange={e=>{setPackageId(e.target.value);setPage(0);}}><option value="">Semua paket</option>{catalog.packages.map(p=><option key={p.id_package} value={p.id_package}>{p.name}</option>)}</select>}</div>
      {loading?<div className="empty" role="status">Memuat data master…</div>:<><MasterDataTable entity={entity} catalog={catalog} rows={visible} canManage={canManage} onEdit={open} onDelete={row=>{setRemove(row);setFormError('');}}/>{!rows.length&&<Empty/>}<div className="master-pagination"><span>{rows.length?current*30+1:0}–{Math.min((current+1)*30,rows.length)} dari {rows.length}</span><div className="actions"><Button title="Halaman sebelumnya" aria-label="Halaman sebelumnya" disabled={!current} onClick={()=>setPage(current-1)}><ChevronLeft size={15}/></Button><span>{current+1} / {pages}</span><Button title="Halaman berikutnya" aria-label="Halaman berikutnya" disabled={current>=pages-1} onClick={()=>setPage(current+1)}><ChevronRight size={15}/></Button></div></div></>}
    </Card>
    {edit&&<Modal title={`${edit.id?'Edit':'Tambah'} ${label}`} onClose={()=>{if(!saving)setEdit(null);}}><form onSubmit={e=>{e.preventDefault();void submit();}}><MasterFields entity={entity} data={edit.data} editing={!!edit.id} catalog={catalog} disabled={saving} onChange={(key,value)=>setEdit(v=>v&&({...v,data:{...v.data,[key]:value}}))}/>{formError&&<Notice tone="error">{formError}</Notice>}<div className="modal-actions"><Button disabled={saving} onClick={()=>setEdit(null)}>Batal</Button>{formError&&<Button disabled={saving||loading} title="Tutup form dan muat ulang daftar terbaru" onClick={()=>{setEdit(null);void load();}}>Tutup & muat ulang</Button>}<Button primary type="submit" disabled={saving||loading}>{saving?'Menyimpan…':'Simpan'}</Button></div></form></Modal>}
    {remove&&<Modal title={`Hapus ${label}?`} onClose={()=>{if(!saving)setRemove(null);}}><p><strong>{remove.code}</strong>{'item_name' in remove?' · '+remove.item_name:' · '+remove.name}</p><Notice>Data tidak lagi tersedia untuk BOQ baru. Referensi dan riwayat dokumen lama tetap tersimpan.{entity!=='khs'&&' Hapus dahulu harga KHS yang menggunakan data ini.'}</Notice>{formError&&<Notice tone="error">{formError}</Notice>}<div className="modal-actions"><Button disabled={saving} onClick={()=>setRemove(null)}>Batal</Button>{formError&&<Button disabled={saving||loading} onClick={()=>{setRemove(null);void load();}}>Tutup & muat ulang</Button>}<Button danger disabled={saving||loading} onClick={()=>void submit(true)}><Trash2 size={14}/> {saving?'Menghapus…':'Hapus'}</Button></div></Modal>}
  </>;
}
export function MasterDataTable({entity,catalog,rows,canManage,onEdit,onDelete}:{entity:MasterEntity;catalog:MasterCatalog;rows:MasterRecord[];canManage:boolean;onEdit:(r:MasterRecord)=>void;onDelete:(r:MasterRecord)=>void}) {
  const headers=entity==='designator'?['Designator','Uraian Pekerjaan','Satuan','Jenis']:entity==='khs'?['Designator','Uraian Pekerjaan','Satuan','Komponen','Paket KHS','Harga']:['Kode Paket','Nama Paket','Keterangan'];
  return <Table className="master-table" headers={[...headers,...(canManage?['Aksi']:[])]}>{rows.map(row=><tr key={masterId(row)}><td><b>{row.code}</b></td>{'item_name' in row?<><td>{row.item_name}</td><td>{row.unit}</td>{'component' in row?<><td>{row.component}</td><td>{row.package}</td><td className="numeric">{money(Number(row.price))}</td></>:<td>{catalog.types.find(t=>t.id_designator_type===row.designator_type_id)?.name??'—'}</td>}</>:<><td>{row.name}</td><td>{row.description||'—'}</td></>}{canManage&&<td><div className="actions"><Button title={`Edit ${row.code}`} aria-label={`Edit ${row.code}`} onClick={()=>onEdit(row)}><Pencil size={14}/></Button><Button danger title={`Hapus ${row.code}`} aria-label={`Hapus ${row.code}`} onClick={()=>onDelete(row)}><Trash2 size={14}/></Button></div></td>}</tr>)}</Table>;
}
export function MasterFields({entity,data,editing,catalog,disabled,onChange}:{entity:MasterEntity;data:FormData;editing:boolean;catalog:MasterCatalog;disabled:boolean;onChange:(key:string,value:string)=>void}) {
  return <div className="stack master-form">{entity==='khs'?<><Field label="Designator *" hint={editing?'Relasi tersimpan dikunci; buat harga KHS baru untuk pasangan lain.':undefined}><select required disabled={disabled||editing} value={data.designator_id} onChange={e=>onChange('designator_id',e.target.value)}><option value="">Pilih designator</option>{catalog.designators.filter(d=>[1,2].includes(Number(d.designator_type_id))).map(d=><option key={d.id_designator} value={d.id_designator}>{d.code} · {d.item_name}</option>)}</select></Field><Field label="Paket KHS *"><select required disabled={disabled||editing} value={data.package_id} onChange={e=>onChange('package_id',e.target.value)}><option value="">Pilih paket KHS</option>{catalog.packages.map(p=><option key={p.id_package} value={p.id_package}>{p.code} · {p.name}</option>)}</select></Field><Field label="Harga satuan (Rp) *" hint="Harga nol diperbolehkan. Maksimal dua angka desimal."><Input required type="number" min={0} max={9999999999999.99} step="0.01" disabled={disabled} value={data.price} onChange={v=>onChange('price',v)}/></Field></>:<><Field label={entity==='designator'?'Kode Designator *':'Kode Paket *'}><Input required disabled={disabled} value={data.code} onChange={v=>onChange('code',v)}/></Field>{entity==='designator'?<><Field label="Uraian Pekerjaan *"><textarea required disabled={disabled} maxLength={5000} rows={3} value={data.item_name} onChange={e=>onChange('item_name',e.target.value)}/></Field><Field label="Satuan *"><Input required disabled={disabled} value={data.unit} onChange={v=>onChange('unit',v)}/></Field><Field label="Jenis Designator *"><select required disabled={disabled} value={data.designator_type_id} onChange={e=>onChange('designator_type_id',e.target.value)}><option value="">Pilih jenis</option>{catalog.types.map(t=><option key={t.id_designator_type} value={t.id_designator_type}>{t.name}</option>)}</select></Field></>:<><Field label="Nama Paket *"><Input required disabled={disabled} value={data.name} onChange={v=>onChange('name',v)}/></Field><Field label="Keterangan"><textarea disabled={disabled} maxLength={5000} rows={3} value={data.description} onChange={e=>onChange('description',e.target.value)}/></Field></>}{editing&&<small className="muted">Identitas yang sudah digunakan BOQ dilindungi untuk menjaga dokumen lama.</small>}</>}</div>;
}

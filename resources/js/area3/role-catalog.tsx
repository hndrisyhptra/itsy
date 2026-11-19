import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { changeRole, loadRoles, RoleCatalogError, type CatalogRole, type RolePage } from './role-catalog-api';
import { Badge, Button, Card, Empty, Field, Modal, Notice, Table, confirmAction, toast } from './ui';

type Editor={id?:number;code:string;name:string;version?:string;system:boolean;workflow_name:string|null;request_id:string};
export const ACCOUNT_SETTINGS_TABS=['Akun','Penomoran PP','Aktivitas','Penyimpanan','Peran Pengguna','Telegram'];

export function RoleCatalogTable({rows,disabled=false,onEdit,onDelete}:{rows:CatalogRole[];disabled?:boolean;onEdit:(row:CatalogRole)=>void;onDelete:(row:CatalogRole)=>void}){
  return <Table headers={['Kode Peran','Nama Peran','Jenis','Digunakan Akun','Aksi']}>{rows.map(row=>{
    const reason=row.system?'Peran bawaan dilindungi':row.users_count||row.assignments_count?'Peran sudah digunakan akun atau riwayat penugasan':undefined;
    return <tr key={row.id_role}><td><b>{row.code}</b></td><td>{row.name}</td><td><Badge>{row.system?'Bawaan':'Tambahan'}</Badge>{!row.system&&<small>Belum terhubung ke hak akses</small>}</td><td className="numeric">{row.users_count}{row.assignments_count>0&&<small>{row.assignments_count} penugasan</small>}</td><td><div className="actions"><Button title={`Edit ${row.code}`} aria-label={`Edit ${row.code}`} disabled={disabled} onClick={()=>onEdit(row)}><Pencil size={14}/></Button><span title={reason}><Button danger title={reason??`Hapus ${row.code}`} aria-label={`Hapus ${row.code}`} disabled={disabled||!!reason} onClick={()=>onDelete(row)}><Trash2 size={14}/></Button></span></div></td></tr>;
  })}</Table>;
}

export function RoleCatalog(){
  const [query,setQuery]=useState(''),[search,setSearch]=useState(''),[page,setPage]=useState(1),[reload,setReload]=useState(0);
  const [data,setData]=useState<RolePage>({rows:[],page:1,has_more:false}),[loading,setLoading]=useState(true),[error,setError]=useState('');
  const [edit,setEdit]=useState<Editor|null>(null),[remove,setRemove]=useState<(CatalogRole&{request_id:string})|null>(null),[saving,setSaving]=useState(false),[formError,setFormError]=useState(''),[blocked,setBlocked]=useState(false);
  const busy=useRef(false),mounted=useRef(true);
  useEffect(()=>{mounted.current=true;return()=>{mounted.current=false;};},[]);
  useEffect(()=>{const timer=setTimeout(()=>{setSearch(query.trim());setPage(1);},300);return()=>clearTimeout(timer);},[query]);
  useEffect(()=>{
    const controller=new AbortController();setLoading(true);setError('');
    void loadRoles(search,page,controller.signal).then(result=>{if(!controller.signal.aborted)setData(result);}).catch(e=>{if(!controller.signal.aborted)setError(e instanceof Error?e.message:'Daftar peran belum dapat dimuat.');}).finally(()=>{if(!controller.signal.aborted)setLoading(false);});
    return()=>controller.abort();
  },[search,page,reload]);
  function open(row?:CatalogRole){setFormError('');setBlocked(false);setEdit({id:row?.id_role,code:row?.code??'',name:row?.name??'',version:row?.version,system:row?.system??false,workflow_name:row?.workflow_name??null,request_id:crypto.randomUUID()});}
  function close(){if(busy.current)return;setEdit(null);setRemove(null);setFormError('');setBlocked(false);}
  async function save(deleting=false){
    if(busy.current||blocked)return;const value=deleting?remove:edit;if(!value)return;
    busy.current=true;setSaving(true);setFormError('');
    try{
      const result=await changeRole({action:deleting?'delete':'save',id:'id_role' in value?value.id_role:value.id,code:value.code,name:value.name,version:value.version,request_id:value.request_id});
      if(mounted.current){setEdit(null);setRemove(null);setReload(v=>v+1);toast(result.message);}
    }catch(e){if(mounted.current){setFormError(e instanceof Error?e.message:'Perubahan gagal disimpan.');setBlocked(e instanceof RoleCatalogError&&e.uncertain);}}
    finally{busy.current=false;if(mounted.current)setSaving(false);}
  }
  return <>
    <Notice>Kelola daftar peran pengguna di database. Peran tambahan belum dapat dipilih pada akun sampai hak akses dan flow-nya dihubungkan. Mengubah nama katalog tidak mengubah jabatan, hak akses, atau urutan approval.</Notice>
    {error&&<Notice tone="error">{error}</Notice>}
    <Card title="Peran Pengguna" subtitle="Katalog peran bawaan dan tambahan" className="no-padding" action={<div className="actions"><Button title="Muat ulang peran" aria-label="Muat ulang peran" disabled={saving||loading} onClick={()=>setReload(v=>v+1)}><RefreshCw size={15}/></Button><Button primary disabled={saving||loading||!!error} onClick={()=>open()}><Plus size={15}/> Tambah Peran</Button></div>}>
      <div className="filters"><input aria-label="Cari peran pengguna" placeholder="Cari kode atau nama peran…" maxLength={100} value={query} disabled={saving} onChange={e=>setQuery(e.target.value)}/></div>
      {loading?<div className="empty" role="status">Memuat peran pengguna…</div>:!error&&<><RoleCatalogTable rows={data.rows} disabled={saving} onEdit={open} onDelete={row=>{setRemove({...row,request_id:crypto.randomUUID()});setFormError('');setBlocked(false);}}/>{!data.rows.length&&<Empty description="Tidak ada peran yang sesuai pencarian."/>}<div className="master-pagination"><span>Halaman {data.page} · {data.rows.length} peran</span><div className="actions"><Button title="Halaman sebelumnya" aria-label="Halaman peran sebelumnya" disabled={page<=1||saving} onClick={()=>setPage(p=>p-1)}><ChevronLeft size={15}/></Button><Button title="Halaman berikutnya" aria-label="Halaman peran berikutnya" disabled={!data.has_more||saving} onClick={()=>setPage(p=>p+1)}><ChevronRight size={15}/></Button></div></div></>}
    </Card>
    {edit&&<Modal title={edit.id?'Edit Peran Pengguna':'Tambah Peran Pengguna'} onClose={close}><form onSubmit={async e=>{e.preventDefault();const form=e.currentTarget;if(busy.current||blocked||!form.reportValidity())return;if(await confirmAction('Simpan peran pengguna ini?')&&form.isConnected&&form.reportValidity())void save();}}><div className="stack"><Field label="Kode Peran *" hint={edit.id?'Kode tetap untuk menjaga identitas peran.':'Huruf besar, angka dan garis bawah, 2–40 karakter. Contoh: OFFICER_SS1.'}><input aria-label="Kode Peran" required pattern="[A-Z][A-Z0-9_]{1,39}" minLength={2} maxLength={40} disabled={saving||blocked||!!edit.id} value={edit.code} onChange={e=>setEdit(v=>v&&({...v,code:e.target.value.toUpperCase()}))}/></Field><Field label="Nama Peran *"><input aria-label="Nama Peran" required maxLength={100} disabled={saving||blocked} value={edit.name} onChange={e=>setEdit(v=>v&&({...v,name:e.target.value}))}/></Field>{edit.system&&<Notice>Peran bawaan untuk akses dan workflow: {edit.workflow_name}. Perubahan nama hanya pada katalog; kode dan aturan akses tetap.</Notice>}{formError&&<Notice tone="error">{formError}</Notice>}</div><div className="modal-actions"><Button disabled={saving} onClick={close}>Batal</Button>{formError&&<Button disabled={saving} onClick={()=>{close();setReload(v=>v+1);}}>Tutup & muat ulang</Button>}<Button primary type="submit" disabled={saving||blocked}>{saving?'Menyimpan…':'Simpan Peran'}</Button></div></form></Modal>}
    {remove&&<Modal title="Hapus Peran Pengguna?" onClose={close}><p>Hapus <strong>{remove.name}</strong> ({remove.code}) dari daftar peran?</p><Notice>Peran bawaan dan peran yang sudah memiliki akun atau riwayat penugasan tidak dapat dihapus.</Notice>{formError&&<Notice tone="error">{formError}</Notice>}<div className="modal-actions"><Button disabled={saving} onClick={close}>TIDAK</Button>{formError&&<Button disabled={saving} onClick={()=>{close();setReload(v=>v+1);}}>Tutup & muat ulang</Button>}<Button danger disabled={saving||blocked} onClick={()=>void save(true)}>{saving?'Menghapus…':'YA'}</Button></div></Modal>}
  </>;
}

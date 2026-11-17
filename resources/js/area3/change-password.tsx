import { useEffect, useRef, useState } from 'react';
import { Eye, EyeOff, KeyRound } from 'lucide-react';
import { Button, Field, Notice, confirmAction } from './ui';
import { changeOwnPassword, PasswordChangeError, type PasswordInput, type PasswordErrors } from './account-password';

const empty:PasswordInput={current_password:'',password:'',password_confirmation:''};
export function ChangePasswordForm({onBack}:{onBack:()=>void}){
  const [input,setInput]=useState<PasswordInput>({...empty}),[visible,setVisible]=useState<Partial<Record<keyof PasswordInput,boolean>>>({}),[errors,setErrors]=useState<PasswordErrors>({}),[message,setMessage]=useState(''),[success,setSuccess]=useState(false),[busy,setBusy]=useState(false);
  const working=useRef(false),active=useRef(true),controller=useRef<AbortController|null>(null);
  useEffect(()=>{active.current=true;return()=>{active.current=false;controller.current?.abort();};},[]);
  async function submit(e:React.FormEvent<HTMLFormElement>){
    e.preventDefault();if(working.current)return;
    setErrors({});setMessage('');setSuccess(false);
    if(input.password!==input.password_confirmation){setErrors({password_confirmation:'Konfirmasi kata sandi baru tidak sesuai.'});return;}
    working.current=true;setBusy(true);
    try{
      if(!await confirmAction('Ganti kata sandi akun Anda sekarang?'))return;
      if(!active.current)return;
      controller.current=new AbortController();
      const timeout=window.setTimeout(()=>controller.current?.abort(),30000);
      try{await changeOwnPassword(input,controller.current.signal);}finally{window.clearTimeout(timeout);}
      if(active.current){setInput({...empty});setVisible({});setSuccess(true);setMessage('Kata sandi berhasil diubah. Gunakan kata sandi baru saat login berikutnya.');}
    }catch(error){
      if(active.current){setMessage(error instanceof PasswordChangeError?error.message:'Kata sandi belum dapat diubah. Coba kembali.');setErrors(error instanceof PasswordChangeError?error.fields:{});}
    }finally{working.current=false;if(active.current)setBusy(false);}
  }
  const fields:[keyof PasswordInput,string][]=[['current_password','Kata sandi saat ini'],['password','Kata sandi baru'],['password_confirmation','Konfirmasi kata sandi baru']];
  return <form className="change-password-form stack" onSubmit={e=>void submit(e)}>
    <p>Gunakan minimal 6 karakter dengan kombinasi huruf dan angka.</p>
    {message&&<div role={success?'status':'alert'}><Notice tone={success?'success':'warning'}>{message}</Notice></div>}
    {fields.map(([key,label])=><Field key={key} label={label}><div className="change-password-input"><input aria-label={label} name={key} type={visible[key]?'text':'password'} autoComplete={key==='current_password'?'current-password':'new-password'} required minLength={key==='current_password'?undefined:6} disabled={busy} value={input[key]} aria-invalid={!!errors[key]} aria-describedby={errors[key]?'change-password-'+key:undefined} onChange={e=>{setInput({...input,[key]:e.target.value});setErrors({...errors,[key]:undefined});setMessage('');}}/><Button aria-label={(visible[key]?'Sembunyikan ':'Tampilkan ')+label.toLowerCase()} aria-pressed={!!visible[key]} disabled={busy} onClick={()=>setVisible({...visible,[key]:!visible[key]})}>{visible[key]?<EyeOff size={17}/>:<Eye size={17}/>}</Button></div>{errors[key]&&<small className="change-password-error" id={'change-password-'+key}>{errors[key]}</small>}</Field>)}
    <div className="modal-actions"><Button disabled={busy} onClick={onBack}>Kembali ke profil</Button><Button primary type="submit" disabled={busy} aria-busy={busy}><KeyRound size={15}/>{busy?'Memproses…':'Simpan Kata Sandi'}</Button></div>
  </form>;
}

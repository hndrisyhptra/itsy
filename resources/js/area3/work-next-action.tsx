import {useState} from 'react';
import {ArrowRight,Plus} from 'lucide-react';
import type {State,Term,Bapp} from './domain';
import {canReviseSubmission,termTotal,operational,prepareBapp} from './domain';
import {barpCurrent,requiresBarp,barpKasbonReturned} from './bapp-workflow';
import {savedBappPreparationAllowed,workBappReady} from './work-document-flow';
import {mutate,waitForPersistence,persistencePending} from './store';
import {Button,navigate,toast} from './ui';

/** The returned child is resolved from confirmed state on the exact term route. */
export function WorkNextAction({s,t,b,amount}:{s:State;t:Term;b?:Bapp;amount?:number}){
 const [busy,setBusy]=useState(false);
 if(requiresBarp(t)&&!barpCurrent(t))return <Button onClick={()=>navigate('/ba-rp/'+t.id)}>Lengkapi & Sahkan BA RP <ArrowRight size={14}/></Button>;
 if(!b&&operational(s)&&savedBappPreparationAllowed(t,amount??termTotal(s,t)))return <Button primary confirm={requiresBarp(t)?'Buat BA PP dan lampiran berdasarkan BA RP yang sudah Sah?':'Buat BA PP dari BA RP tersimpan? Isi BA RP dan approval yang sudah tercatat tetap dipertahankan.'} disabled={busy||persistencePending} onClick={()=>{if(busy||persistencePending)return;setBusy(true);void (async()=>{try{mutate(d=>prepareBapp(d,t.id));await waitForPersistence();navigate('/ba-rp/'+t.id+'?step=bapp');toast('BA PP dibuat. Atur tanggal dan sahkan dokumen.');}catch(error){toast(error instanceof Error?error.message:String(error),true);}finally{setBusy(false);}})();}}><Plus size={14}/>{requiresBarp(t)?'Buat BA PP':'Buat BA PP dari BA RP Tersimpan'}</Button>;
 if(!b&&!canReviseSubmission(t))return <Button onClick={()=>navigate('/termin/'+t.id)}>Detail Pembayaran <ArrowRight size={14}/></Button>;
 const ready=workBappReady(t,b),returned=barpKasbonReturned(t);
 return <Button onClick={()=>navigate(ready?'/termin/'+t.id:b?'/ba-rp/'+t.id+'?step=bapp':'/ba-rp/'+t.id)}>{ready?(returned?'Perbaiki Kasbon & Ajukan Ulang':'Kasbon & Pembayaran'):b?(returned?'Periksa & Sahkan Ulang BA PP':'Periksa & Sahkan BA PP'):'Lengkapi BA RP'}<ArrowRight size={14}/></Button>;
}

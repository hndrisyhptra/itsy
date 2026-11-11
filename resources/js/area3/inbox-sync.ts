export const INBOX_SYNC_INTERVAL=15000;
export const inboxSyncRoute=(path:string)=>['/dashboard','/inbox','/project-plan','/termin','/spk','/ba-kh','/spk/ba-kh','/ba-pp','/laporan'].includes(path.split('?')[0].replace(/\/$/,'')||'/dashboard');

type SyncOptions={visible:()=>boolean;eligible:()=>boolean;revision:()=>number;probe:(signal:AbortSignal)=>Promise<number>;refresh:(signal:AbortSignal)=>Promise<void>;status?:(value:string)=>void;timer?:(fn:()=>void,ms:number)=>ReturnType<typeof setTimeout>;cancel?:(id:ReturnType<typeof setTimeout>)=>void;now?:()=>number};

/** One request at a time, revision-only until changed, no writes or retry of commands. */
export function createInboxSync(o:SyncOptions) {
  const timer=o.timer??setTimeout,cancel=o.cancel??clearTimeout,now=o.now??Date.now;
  let stopped=false,running=false,failures=0,last=-Infinity,scheduled:ReturnType<typeof setTimeout>|undefined,controller:AbortController|undefined;
  const clear=()=>{if(scheduled!==undefined){cancel(scheduled);scheduled=undefined;}};
  function schedule(ms:number){clear();if(!stopped&&o.visible())scheduled=timer(()=>{scheduled=undefined;void tick();},ms);}
  async function tick(){
    if(stopped||running||!o.visible())return;
    if(!o.eligible()){o.status?.('Sinkronisasi inbox dijeda saat mengisi form atau membuka dokumen.');schedule(INBOX_SYNC_INTERVAL);return;}
    running=true;last=now();controller=new AbortController();
    try {
      const remote=await o.probe(controller.signal);
      if(!Number.isSafeInteger(remote)||remote<0)throw new Error('Revisi server tidak valid.');
      if(!stopped&&!controller.signal.aborted&&o.visible()&&o.eligible()&&remote>o.revision())await o.refresh(controller.signal);
      if(!stopped&&!controller.signal.aborted){failures=0;o.status?.('Inbox diperbarui otomatis setiap 15 detik.');}
    }catch(e){if(!stopped&&!controller.signal.aborted){failures++;o.status?.('Sinkronisasi inbox tertunda. Mencoba kembali otomatis.');}}
    finally{running=false;controller=undefined;schedule(Math.min(120000,INBOX_SYNC_INTERVAL*2**failures));}
  }
  function wake(){
    if(stopped)return;
    if(!o.visible()){clear();controller?.abort();return;}
    if(!o.eligible()){controller?.abort();o.status?.('Sinkronisasi inbox dijeda saat mengisi form atau membuka dokumen.');schedule(INBOX_SYNC_INTERVAL);return;}
    if(!running)schedule(Math.max(0,3000-(now()-last)));
  }
  wake();
  return {wake,stop(){stopped=true;clear();controller?.abort();}};
}

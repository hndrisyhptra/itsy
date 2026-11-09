import { type UploadProgress, uploadPhaseLabel } from './upload-progress';

export function UploadProgressView({progress,name}:{progress:UploadProgress;name?:string}) {
  const waiting=['confirming','queued','processing'].includes(progress.phase),problem=progress.phase==='failed'||progress.phase==='error';
  return <div className={'upload-progress'+(problem?' upload-progress-error':'')}>
    {name&&<strong className="upload-progress-name">{name}</strong>}
    <div className="upload-progress-heading"><span>Pengiriman file</span><strong>{progress.percent===null?'Ukuran belum terukur':`${progress.percent}%`}</strong></div>
    <progress aria-label={name?`Pengiriman ${name}`:'Pengiriman file'} max={100} value={progress.percent??undefined}/>
    <div className="upload-progress-state" role="status"><span>{uploadPhaseLabel(progress.phase)}</span>{waiting&&<progress aria-label={name?`Pemrosesan ${name}`:'Pemrosesan file'} aria-valuetext={uploadPhaseLabel(progress.phase)}/>}</div>
    {waiting&&<small>{progress.phase==='queued'?'File sudah diterima server; menunggu worker.':progress.phase==='processing'?'File sudah diterima; hasil belum selesai.':'Pengiriman selesai; server belum mengonfirmasi hasil.'} Persentase di atas hanya untuk pengiriman file.</small>}
    {progress.message&&<small>{progress.message}</small>}
  </div>;
}

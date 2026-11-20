import { useId, useState } from 'react';
import { isWholeVolume, parseVolumeInput, VOLUME_MESSAGE } from './whole-volume';

export function VolumeInput({value,onChange,disabled,label,max,invalid}:{value:number;onChange:(value:number)=>void;disabled?:boolean;label?:string;max?:number;invalid?:boolean}) {
  const id=useId(), [rejected,setRejected]=useState(false);
  const error=!disabled&&(rejected||!isWholeVolume(value));
  return <span className="volume-input-field"><input aria-label={label} aria-invalid={invalid||error||undefined} aria-describedby={error?id:undefined} className="number-input" type="text" inputMode="numeric" pattern="[0-9]*" data-max-volume={max} disabled={disabled} value={Number.isFinite(value)?value:''} title={VOLUME_MESSAGE} onKeyDown={event=>{
    if(!event.ctrlKey&&!event.metaKey&&!event.altKey&&event.key.length===1&&!/^[0-9]$/.test(event.key)){event.preventDefault();setRejected(true);}
  }} onChange={event=>{
    const parsed=parseVolumeInput(event.target.value);
    if(parsed===null){setRejected(true);return;}
    setRejected(false);onChange(parsed);
  }}/>{error&&<small id={id} className="error-text" role="status">{VOLUME_MESSAGE}</small>}</span>;
}

import { Moon, Sun } from 'lucide-react';
import { Button } from './ui';
export type Theme='light'|'dark';
export function readTheme():Theme{try{return localStorage.getItem('area3-theme')==='dark'?'dark':'light';}catch{return 'light';}}
export function saveTheme(theme:Theme){try{localStorage.setItem('area3-theme',theme);}catch{/* Display still changes when browser storage is unavailable. */}}
export function ThemeToggle({theme,onChange}:{theme:Theme;onChange:(theme:Theme)=>void}){
  const label='Gunakan mode '+(theme==='light'?'gelap':'terang');
  return <Button className="icon-button" aria-label={label} title={label} aria-pressed={theme==='dark'} onClick={()=>onChange(theme==='light'?'dark':'light')}>{theme==='light'?<Moon size={18}/>:<Sun size={18}/>}</Button>;
}

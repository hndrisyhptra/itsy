import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { ThemeToggle,readTheme,saveTheme } from '../../resources/js/area3/theme-toggle';
import { ChangePasswordForm } from '../../resources/js/area3/change-password';

test('theme action exposes its next mode with an icon and no confirmation',()=>{
  for(const theme of ['light','dark'] as const){const html=renderToStaticMarkup(createElement(ThemeToggle,{theme,onChange:()=>{}}));assert.ok(html.includes('Gunakan mode '+(theme==='light'?'gelap':'terang')));assert.ok(html.includes('aria-pressed="'+(theme==='dark')+'"'));assert.doesNotMatch(html,/data-confirm|Ganti mode tampilan/);}
  const previous=globalThis.localStorage;const data=new Map<string,string>();
  try{
    Object.assign(globalThis,{localStorage:{getItem:(key:string)=>data.get(key)??null,setItem:(key:string,value:string)=>data.set(key,value)}});
    assert.equal(readTheme(),'light');saveTheme('dark');assert.equal(readTheme(),'dark');data.set('area3-theme','invalid');assert.equal(readTheme(),'light');
    Object.assign(globalThis,{localStorage:{getItem:()=>{throw new Error('Blocked');},setItem:()=>{throw new Error('Blocked');}}});assert.equal(readTheme(),'light');assert.doesNotThrow(()=>saveTheme('dark'));
  }finally{Object.assign(globalThis,{localStorage:previous});}
});

test('password form starts empty, uses correct autocomplete and never renders a saved credential',()=>{
  const html=renderToStaticMarkup(createElement(ChangePasswordForm,{onBack:()=>{}}));
  for(const label of ['Kata sandi saat ini','Kata sandi baru','Konfirmasi kata sandi baru','Simpan Kata Sandi'])assert.ok(html.includes(label));
  assert.equal((html.match(/type="password"/g)??[]).length,3);assert.equal((html.match(/autoComplete="new-password"/g)??[]).length,2);assert.match(html,/autoComplete="current-password"/);assert.match(html,/minLength="6"/);assert.doesNotMatch(html,/defaultValue|old123|abc123/);
});

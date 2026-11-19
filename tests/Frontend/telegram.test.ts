import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
Object.assign(globalThis,{window:{addEventListener:()=>{},__AREA3__:{account:{id:'db-user-1',role:'Superadmin'},basePath:'/its-area3',csrf:'csrf-fixture'}}});
const {telegramRequest,TelegramRequestError}=await import('../../resources/js/area3/telegram-api');
const {TelegramProfile,TelegramSettings,telegramTime}=await import('../../resources/js/area3/telegram');
const {ACCOUNT_SETTINGS_TABS}=await import('../../resources/js/area3/role-catalog');

test('Telegram requests stay same-origin/subfolder, no-store, authenticated and CSRF-protected',async()=>{
 const old=globalThis.fetch;try{
 globalThis.fetch=async(url,options)=>{assert.equal(url,'/its-area3/api/telegram/profile');assert.equal(options?.cache,'no-store');assert.equal(options?.credentials,'same-origin');assert.equal(options?.method,'POST');assert.equal((options?.headers as Record<string,string>)['X-CSRF-TOKEN'],'csrf-fixture');assert.deepEqual(JSON.parse(String(options?.body)),{action:'link',request_id:'uuid'});return Response.json({message:'ok'});};
 assert.equal((await telegramRequest('profile',{action:'link',request_id:'uuid'})).message,'ok');
 }finally{globalThis.fetch=old;}
});
test('Telegram uncertain writes do not auto retry; reads and access failures cannot claim delivery',async()=>{
 const old=globalThis.fetch;try{
 for(const status of [401,403,409,419,422,429,500,503]){let calls=0;globalThis.fetch=async()=>{calls++;return Response.json({message:'Uji ditolak'},{status});};await assert.rejects(()=>telegramRequest('profile',{action:'test'}),e=>e instanceof TelegramRequestError&&e.uncertain===[409,500,503].includes(status));assert.equal(calls,1);}
 let calls=0;globalThis.fetch=async()=>{calls++;throw new Error('offline');};await assert.rejects(()=>telegramRequest('profile',{action:'test'}),e=>e instanceof TelegramRequestError&&e.uncertain);assert.equal(calls,1);
 }finally{globalThis.fetch=old;}
});
test('Telegram surfaces private linking and separate Superadmin controls after role catalog without initial mutations',()=>{
 assert.equal(ACCOUNT_SETTINGS_TABS[ACCOUNT_SETTINGS_TABS.indexOf('Peran Pengguna')+1],'Telegram');
 const profile=renderToStaticMarkup(createElement(TelegramProfile));assert.match(profile,/Telegram Approval/);assert.match(profile,/Memuat status Telegram/);assert.match(profile,/Keputusan approval tetap dilakukan di aplikasi/);assert.doesNotMatch(profile,/chat_cipher|bot_token|secret_token/);
 const settings=renderToStaticMarkup(createElement(TelegramSettings));for(const label of ['Pengaturan Bot','Akun Terhubung','PIC Finance','Riwayat Pengiriman'])assert.match(settings,new RegExp(label));assert.match(settings,/Interval reminder tetap satu jam/);
});

test('Telegram UTC audit timestamps display as WIB without changing stored values',()=>{const value='2026-11-18 01:00:00';assert.match(telegramTime(value),/08[.:]00.*WIB/);assert.equal(telegramTime('invalid'),'Waktu tidak tersedia');assert.equal(value,'2026-11-18 01:00:00');});

import test from 'node:test';
import assert from 'node:assert/strict';
import { createElement as h } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

Object.assign(globalThis,{window:{addEventListener:()=>{},__AREA3__:{account:{id:'db-user-1',role:'Superadmin'},basePath:'/its-area3',csrf:'csrf-fixture'}}});
const {changeRole,loadRoles,RoleCatalogError}=await import('../../resources/js/area3/role-catalog-api');
const {RoleCatalogTable,ACCOUNT_SETTINGS_TABS}=await import('../../resources/js/area3/role-catalog');
const row={id_role:90,code:'OFFICER_SS1',name:'Officer 1 SS',system:false,workflow_name:null,version:'a'.repeat(64),users_count:0,assignments_count:0};

test('role API scopes URL to current origin, protects writes with CSRF and omits unsupported privileges',async()=>{
  const previous=globalThis.fetch;
  try{
    globalThis.fetch=async(url,options)=>{assert.equal(url,'/its-area3/api/account/roles?q=Officer+SS&page=2');assert.equal(options?.cache,'no-store');assert.equal(options?.credentials,'same-origin');return Response.json({rows:[],page:2,has_more:false});};
    await loadRoles('Officer SS',2);
    let calls=0;globalThis.fetch=async(url,options)=>{calls++;assert.equal(url,'/its-area3/api/account/roles');assert.equal(options?.method,'POST');assert.equal((options?.headers as Record<string,string>)['X-CSRF-TOKEN'],'csrf-fixture');assert.deepEqual(JSON.parse(String(options?.body)),{action:'save',code:row.code,name:row.name,request_id:'uuid-test',id:row.id_role,version:row.version});return Response.json({role:row,message:'Saved'});};
    await changeRole({action:'save',code:row.code,name:row.name,id:row.id_role,version:row.version,request_id:'uuid-test',permissions:['SUPERADMIN'],actor_user_id:99} as Parameters<typeof changeRole>[0]);assert.equal(calls,1);
  }finally{globalThis.fetch=previous;}
});

test('role request failures never retry or claim success and uncertain writes require inspection',async()=>{
  const previous=globalThis.fetch;
  try{
    for(const status of [401,403,419,422,429,409,500]){
      let calls=0;globalThis.fetch=async()=>{calls++;return Response.json({message:'Data peran berubah.'},{status});};
      await assert.rejects(()=>changeRole({action:'save',code:row.code,name:row.name,request_id:'uuid-test'}),error=>{assert(error instanceof RoleCatalogError);assert.equal(error.uncertain,status===500);return true;});assert.equal(calls,1);
    }
    let calls=0;globalThis.fetch=async()=>{calls++;throw new Error('offline');};
    await assert.rejects(()=>changeRole({action:'save',code:row.code,name:row.name,request_id:'uuid-test'}),error=>error instanceof RoleCatalogError&&error.uncertain);assert.equal(calls,1);
  }finally{globalThis.fetch=previous;}
});

test('role tab follows storage and table protects builtins and referenced roles with accessible icon descriptions',()=>{
  assert.equal(ACCOUNT_SETTINGS_TABS[ACCOUNT_SETTINGS_TABS.indexOf('Penyimpanan')+1],'Peran Pengguna');
  const rows=[row,{...row,id_role:1,code:'SUPERADMIN',name:'Superadmin',system:true,workflow_name:'Superadmin'},{...row,id_role:91,code:'USED_ROLE',users_count:0,assignments_count:1}];
  const html=renderToStaticMarkup(h(RoleCatalogTable,{rows,onEdit:()=>{},onDelete:()=>{}}));
  assert.match(html,/Belum terhubung ke hak akses/);assert.match(html,/title="Edit OFFICER_SS1"/);assert.match(html,/aria-label="Hapus OFFICER_SS1"/);assert.equal((html.match(/disabled=""/g)||[]).length,2);
  assert.match(html,/Peran bawaan dilindungi/);assert.match(html,/riwayat penugasan/);
  const escaped=renderToStaticMarkup(h(RoleCatalogTable,{rows:[{...row,name:'<script>alert(1)</script>'}],onEdit:()=>{},onDelete:()=>{}}));assert(!escaped.includes('<script>'));assert.match(escaped,/&lt;script&gt;/);
});

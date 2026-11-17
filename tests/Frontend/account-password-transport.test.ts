import test from 'node:test';
import assert from 'node:assert/strict';

Object.assign(globalThis,{window:{__AREA3__:{account:{id:'db-user-1'},basePath:'/its-area3',csrf:'csrf-fixture'}}});
const {changeOwnPassword,PasswordChangeError}=await import('../../resources/js/area3/account-password');
const input={current_password:'old123',password:'abc123',password_confirmation:'abc123'};

test('password request is self-only, CSRF-protected, same-origin and sent once with exactly three credential fields',async()=>{
  const previous=globalThis.fetch;let count=0;
  try{
    globalThis.fetch=async(url,options)=>{count++;assert.equal(url,'/its-area3/api/account/password');assert.equal(options?.method,'PUT');assert.equal(options?.credentials,'same-origin');assert.equal(options?.cache,'no-store');assert.equal((options?.headers as Record<string,string>)['X-CSRF-TOKEN'],'csrf-fixture');assert.deepEqual(JSON.parse(String(options?.body)),input);return new Response(JSON.stringify({message:'Kata sandi berhasil diubah.'}),{status:200});};
    await changeOwnPassword({...input,id_user:99,role:'SUPERADMIN'} as typeof input);assert.equal(count,1);
  }finally{globalThis.fetch=previous;}
});

test('password errors stay scoped, sessions/throttle are explained and uncertain requests never retry',async()=>{
  const previous=globalThis.fetch;
  try{
    for(const status of [401,419,429,500,422]){
      let count=0;globalThis.fetch=async()=>{count++;return new Response(JSON.stringify({message:'RAW SERVER DETAIL',errors:{current_password:['Kata sandi saat ini tidak sesuai.'],private_field:['HIDDEN']}}),{status});};
      await assert.rejects(()=>changeOwnPassword(input),error=>{assert(error instanceof PasswordChangeError);assert(!error.message.includes('RAW SERVER'));assert(!error.message.includes(input.password));if(status===422)assert.deepEqual(error.fields,{current_password:'Kata sandi saat ini tidak sesuai.'});return true;});assert.equal(count,1);
    }
    let count=0;globalThis.fetch=async()=>{count++;throw new Error('RAW NETWORK DETAIL');};
    await assert.rejects(()=>changeOwnPassword(input),/Status perubahan belum dapat dipastikan/);assert.equal(count,1);
  }finally{globalThis.fetch=previous;}
});

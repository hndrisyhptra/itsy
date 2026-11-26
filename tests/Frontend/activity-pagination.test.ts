import test from 'node:test';
import assert from 'node:assert/strict';
import {createElement} from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {ActivityLog} from '../../resources/js/area3/management';
import type {State} from '../../resources/js/area3/domain';
const events:State['events']=Array.from({length:105},(_,i)=>({id:'e-'+i,title:'Activity QA '+i,detail:'Detail '+i,actor:'Admin Konstruksi',at:'2026-11-26T02:00:00Z',route:'/project-plan/qa-'+i}));
test('activity table renders default20 rows and total without mutating already-authorized events',()=>{
 const before=JSON.stringify(events),html=renderToStaticMarkup(createElement(ActivityLog,{events}));
 assert.equal((html.match(/<tbody>(.*?)<\/tbody>/s)?.[1].match(/<tr>/g)??[]).length,20);assert.match(html,/Total 105 aktivitas/);assert.match(html,/Halaman 1 \/ 6/);assert.match(html,/Baris per halaman aktivitas/);assert.match(html,/value="100"/);assert.doesNotMatch(html,/Activity QA 20<\/td>/);assert.equal(JSON.stringify(events),before);
});
test('empty activity pages and demo presentation preserve labels without an enabled next page',()=>{
 const empty=renderToStaticMarkup(createElement(ActivityLog,{events:[]}));assert.match(empty,/Belum ada aktivitas pada Branch penugasan Anda/);assert.match(empty,/Halaman 1 \/ 1/);assert.match(empty,/disabled=""[^>]*>Berikutnya/);
 const demo=renderToStaticMarkup(createElement(ActivityLog,{events:events.slice(0,2),demo:true}));assert.match(demo,/Log Aktivitas/);assert.match(demo,/Waktu WIB/);assert.match(demo,/Rekaman/);assert.match(demo,/href="\/project-plan\/qa-0"/);
});

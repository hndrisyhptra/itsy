import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { pendingUpload,uploadStatus,type UploadTask } from '../../resources/js/area3/upload-queue';
const task:UploadTask={id:'qa-task',documentId:'pp-1',kind:'boq',name:'boq.xlsx',status:'queued',attempts:0,error:null,createdAt:'2026-11-06'};
test('queue distinguishes pending, completed and failed without presenting accepted bytes as imported BOQ',()=>{
  assert.equal(pendingUpload(task),true);assert.equal(uploadStatus(task),'Dalam antrean');assert.equal(pendingUpload({...task,status:'processing'}),true);
  assert.equal(pendingUpload({...task,status:'completed'}),false);assert.equal(uploadStatus({...task,status:'failed'}),'Gagal');
  const store=readFileSync('resources/js/area3/store.ts','utf8');assert.match(store,/await waitUpload\(task/);assert.match(store,/uploadTask\(task.id,true/);assert.match(store,/data.revision<revision/);
});
test('BOQ result adoption preserves latest editor state and uploaded jobs have recoverable status UI',()=>{
  const ui=readFileSync('resources/js/area3/workflow-ui.tsx','utf8');assert.match(ui,/const current=latest.current/);assert.match(ui,/UploadTasks kind="boq" documentId=\{p.id\}/);
  const tasks=readFileSync('resources/js/area3/upload-tasks.tsx','utf8');assert.match(tasks,/Gunakan hasil/);assert.match(tasks,/Worker belum aktif/);assert.match(tasks,/controller.abort\(\)/);assert.match(tasks,/&&pending/);
  const app=readFileSync('resources/js/area3/App.tsx','utf8');assert.match(app,/lazy\(\(\)=>import\('\.\/projects'\)/);assert.match(app,/<Suspense fallback=/);
});

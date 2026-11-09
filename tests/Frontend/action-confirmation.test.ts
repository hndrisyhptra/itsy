import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import { confirmAction, runConfirmedAction, installFormConfirmation } from '../../resources/js/area3/confirmation';
import { Button } from '../../resources/js/area3/ui';

class Element extends EventTarget {
  children: Element[] = []; parent?: Element; isConnected = true; disabled = false;
  textContent = ''; className = ''; id = ''; type = ''; attributes = new Map<string, string>();
  constructor(readonly tagName: string) { super(); }
  append(...children: Element[]) { for (const child of children) { child.parent = this; this.children.push(child); } }
  setAttribute(key: string, value: string) { this.attributes.set(key, value); }
  focus() { doc.activeElement = this; }
  showModal() { if (failModal) throw new Error('Unsupported dialog'); }
  remove() { this.isConnected = false; if (this.parent) this.parent.children = this.parent.children.filter(c => c !== this); }
  click() { this.dispatchEvent(new Event('click')); }
}
class Form extends Element {
  dataset = { confirm: 'Simpan akun?' }; valid = true; submissions = 0;
  reportValidity() { return this.valid; }
  requestSubmit(submitter?: Element) {
    const event = new Event('submit', { cancelable: true });
    Object.defineProperty(event, 'submitter', { value: submitter });
    this.dispatchEvent(event);
    if (!event.defaultPrevented) this.submissions++;
  }
}
let doc: { activeElement: Element | null; body: Element; createElement: (tag: string) => Element }, win: EventTarget, failModal = false;
const turn = () => new Promise<void>(resolve => setImmediate(resolve));
function setup() {
  const previous = { document: globalThis.document, window: globalThis.window };
  doc = { activeElement: new Element('button'), body: new Element('body'), createElement: tag => new Element(tag) };
  win = new EventTarget(); failModal = false;
  Object.assign(globalThis, { document: doc, window: win });
  return () => { win.dispatchEvent(new Event('pagehide')); Object.assign(globalThis, previous); };
}
const dialog = () => doc.body.children[0];
const choice = (yes: boolean) => dialog().children[0].children[2].children[yes ? 1 : 0];

test('YA runs exactly once; TIDAK and repeated clicks never run a second action', async () => {
  const restore = setup();
  try {
    const original = doc.activeElement; let writes = 0;
    const cancelled = runConfirmedAction('Ajukan PP?', () => writes++);
    assert.equal(writes, 0); assert.equal(doc.activeElement, choice(false));
    assert.equal(dialog().attributes.get('role'), 'alertdialog');
    assert.equal(dialog().attributes.get('aria-describedby'), 'action-confirm-message');
    assert.deepEqual([choice(false).textContent, choice(true).textContent], ['TIDAK', 'YA']);
    assert.equal(await runConfirmedAction('Duplicate request?', () => writes++), false);
    choice(false).click(); assert.equal(await cancelled, false); assert.equal(writes, 0);
    assert.equal(doc.activeElement, original);
    const approved = runConfirmedAction('Ajukan PP?', () => writes++);
    const yes = choice(true); yes.click(); yes.click();
    assert.equal(await approved, true); assert.equal(writes, 1); assert.equal(doc.body.children.length, 0);
  } finally { restore(); }
});

test('Escape, backdrop, route change and page exit cancel; unavailable dialog fails closed', async () => {
  const restore = setup();
  try {
    let writes = 0;
    for (const exit of ['cancel', 'backdrop', 'popstate', 'pagehide']) {
      const request = runConfirmedAction('Hapus?', () => writes++);
      if (exit === 'cancel') { const e = new Event('cancel', { cancelable: true }); dialog().dispatchEvent(e); assert.equal(e.defaultPrevented, true); }
      else if (exit === 'backdrop') dialog().click();
      else win.dispatchEvent(new Event(exit));
      assert.equal(await request, false); assert.equal(doc.body.children.length, 0);
    }
    failModal = true; assert.equal(await runConfirmedAction('Simpan?', () => writes++), false);
    assert.equal(writes, 0);
    failModal = false;
    const again = confirmAction('Simpan?'); choice(false).click(); assert.equal(await again, false);
  } finally { restore(); }
});

test('confirmation text is literal and current availability is rechecked before invoking action', async () => {
  const restore = setup();
  try {
    let available = false, writes = 0;
    assert.equal(await runConfirmedAction('Disabled', () => writes++, () => available), false);
    assert.equal(doc.body.children.length, 0);
    available = true;
    const request = runConfirmedAction('Hapus <img onerror="bad()">?', () => writes++, () => available);
    assert.equal(dialog().children[0].children[1].textContent, 'Hapus <img onerror="bad()">?');
    available = false; choice(true).click(); assert.equal(await request, false); assert.equal(writes, 0);
    const asyncWrite = runConfirmedAction('Bayar?', async () => { await turn(); writes++; });
    choice(true).click(); assert.equal(await asyncWrite, true); assert.equal(writes, 1);
  } finally { restore(); }
});

test('shared Button holds callbacks until YA, respects live disabled state and leaves navigation direct', async () => {
  const restore = setup();
  try {
    const node = new Element('button'); let writes = 0;
    const element = Button({ children: 'Simpan', confirm: 'Simpan?', onClick: () => { writes++; } });
    assert.equal(element.props['data-confirm'], 'Simpan?');
    const click = { currentTarget: node, preventDefault() {} } as never;
    element.props.onClick(click); assert.equal(writes, 0); choice(false).click(); await turn(); assert.equal(writes, 0);
    element.props.onClick(click); node.disabled = true; choice(true).click(); await turn(); assert.equal(writes, 0);
    node.disabled = false; element.props.onClick(click); node.isConnected = false; choice(true).click(); await turn(); assert.equal(writes, 0);
    node.isConnected = true;
    node.disabled = false; element.props.onClick(click); choice(true).click(); await turn(); assert.equal(writes, 1);
    const next = Button({ children: 'Selanjutnya', onClick: () => { writes++; } });
    next.props.onClick(click); assert.equal(writes, 2); assert.equal(doc.body.children.length, 0);
  } finally { restore(); }
});

test('account submit/Enter is cancelled safely; YA preserves native validation and submits only once', async () => {
  const restore = setup();
  try {
    const form = new Form('form'), submit = new Element('button'); let busy = 0;
    installFormConfirmation(form as unknown as HTMLFormElement, () => { busy++; });
    form.requestSubmit(submit); form.requestSubmit(submit);
    assert.equal(busy, 0); assert.equal(form.submissions, 0); assert.equal(doc.body.children.length, 1);
    choice(false).click(); await turn(); assert.equal(busy, 0); assert.equal(form.submissions, 0);
    form.requestSubmit(); form.valid = false; choice(true).click(); await turn(); assert.equal(form.submissions, 0);
    form.valid = true;
    form.requestSubmit(submit); submit.disabled = true; choice(true).click(); await turn(); assert.equal(form.submissions, 0);
    submit.disabled = false;
    form.requestSubmit(submit); choice(true).click(); await turn(); assert.equal(busy, 1); assert.equal(form.submissions, 1);
  } finally { restore(); }
});

test('mutation and file entry points explicitly require confirmation; existing final dialogs use YA/TIDAK', () => {
  const cases: Record<string, string[]> = {
    'projects.tsx': ['save();', "save(true)"],
    'workflow-ui.tsx': ['decideProject(', 'setNumberFormat(', 'assignPaymentApprovers(', 'assignProjectApprovers('],
    'partial-payment-panel.tsx': ['requestPartialPayment(', 'verifyPartialPayment(', 'payPartialPayment('],
    'payment-evidence.tsx': ['verifyTermScan(', 'uploadTermScan(', 'recordApm('],
    'payments.tsx': ['save()', 'save(true)', 'createTerm(', 'recordApm(', 'recordPayment(', 'decide(', 'releaseVolume(', 'createContinuation('],
    'contracts.tsx': ["save('save')", 'save(confirm)', 'createTerm('],
    'master-import.tsx': ['commit()', 'inspect()'],
    'term-work-evidence.tsx': ['removeTermWorkEvidence('],
    'management.tsx': ['saveProfile(', 'saveUser(', 'updateKhs('],
    'upload-tasks.tsx': ['resumeUpload(', 'onUse(task)'],
  };
  for (const [file, calls] of Object.entries(cases)) {
    const source = readFileSync(new URL('../../resources/js/area3/' + file, import.meta.url), 'utf8');
    const tree = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
    const found = new Set<string>();
    function visit(node: ts.Node) {
      if (ts.isJsxElement(node) && node.openingElement.tagName.getText(tree) === 'Button') {
        const attributes = node.openingElement.attributes.properties;
        const handler = attributes.find(a => a.name?.getText(tree) === 'onClick')?.getText(tree) ?? '';
        for (const call of calls) if (handler.includes(call)) {
          found.add(call);
          const explicit = attributes.some(a => a.name?.getText(tree) === 'confirm');
          const finalYes = node.children.some(c => ts.isJsxText(c) && c.text.trim() === 'YA');
          assert.ok(explicit || finalYes, `${file}: ${call} needs confirmation`);
        }
      }
      ts.forEachChild(node, visit);
    }
    visit(tree);
    assert.deepEqual([...found].sort(), [...calls].sort(), file);
  }
  for (const [file, guard] of [['workflow-ui.tsx', 'await confirmAction(`Unggah BOQ'], ['term-work-evidence.tsx', 'await confirmAction(`Unggah ${selected.length}'], ['master-designator.tsx', 'await confirmAction(`Simpan data'], ['App.tsx', "await confirmAction('Keluar"]]) {
    assert.ok(readFileSync(new URL('../../resources/js/area3/' + file, import.meta.url), 'utf8').includes(guard), file);
  }
  for (const file of ['account-form.blade.php', 'login.blade.php']) assert.match(readFileSync(new URL('../../resources/views/auth/' + file, import.meta.url), 'utf8'), /data-confirm=/);
});

/** Shared by the React workspace and account forms. No request starts before YA. */
let active = false;

export function confirmAction(message: string): Promise<boolean> {
  if (active || typeof document === 'undefined') return Promise.resolve(false);
  active = true;
  return new Promise(resolve => {
    const previous = document.activeElement as HTMLElement | null;
    const dialog = document.createElement('dialog');
    dialog.className = 'action-confirm';
    dialog.setAttribute('role', 'alertdialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'action-confirm-title');
    dialog.setAttribute('aria-describedby', 'action-confirm-message');
    const content = document.createElement('div');
    content.className = 'action-confirm-content';
    const title = document.createElement('h2');
    title.id = 'action-confirm-title';
    title.textContent = 'Konfirmasi tindakan';
    const description = document.createElement('p');
    description.id = 'action-confirm-message';
    description.textContent = message;
    const actions = document.createElement('div');
    actions.className = 'action-confirm-actions';
    const no = document.createElement('button'), yes = document.createElement('button');
    no.type = yes.type = 'button';
    no.textContent = 'TIDAK'; yes.textContent = 'YA';
    yes.className = 'action-confirm-yes';
    actions.append(no, yes); content.append(title, description, actions); dialog.append(content);
    let finished = false;
    const finish = (approved: boolean) => {
      if (finished) return;
      finished = true;
      window.removeEventListener('popstate', cancel);
      window.removeEventListener('pagehide', cancel);
      dialog.remove(); active = false;
      if (previous?.isConnected) previous.focus();
      resolve(approved);
    };
    const cancel = () => finish(false);
    no.addEventListener('click', cancel);
    yes.addEventListener('click', () => finish(true));
    dialog.addEventListener('cancel', e => { e.preventDefault(); cancel(); });
    dialog.addEventListener('close', cancel);
    dialog.addEventListener('click', e => { if (e.target === dialog) cancel(); });
    // Keep an underlying React Modal open when Escape dismisses this confirmation.
    dialog.addEventListener('keydown', e => e.stopPropagation());
    window.addEventListener('popstate', cancel);
    window.addEventListener('pagehide', cancel);
    document.body.append(dialog);
    try { dialog.showModal(); no.focus(); } catch { cancel(); }
  });
}

export async function runConfirmedAction(message: string, action: () => unknown, available = () => true, ask = confirmAction): Promise<boolean> {
  if (!available() || !await ask(message) || !available()) return false;
  await action();
  return true;
}

/** Native submit/Enter keeps browser validation and CSRF fields. */
export function installFormConfirmation(form: HTMLFormElement, onSubmit: () => void) {
  let approved = false, waiting = false;
  form.addEventListener('submit', async e => {
    if (approved || !form.dataset.confirm) { approved = false; onSubmit(); return; }
    e.preventDefault();
    if (waiting) return;
    waiting = true;
    const submitter = e.submitter as HTMLButtonElement | null;
    try {
      if (await confirmAction(form.dataset.confirm) && form.isConnected && !submitter?.disabled && form.reportValidity()) {
        approved = true;
        form.requestSubmit(submitter ?? undefined);
      }
    } finally { waiting = false; approved = false; }
  });
}

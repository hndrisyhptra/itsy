import './auth.css';
import './confirmation.css';
import { installFormConfirmation } from './confirmation';

const region = document.querySelector<HTMLSelectElement>('[data-region-select]');
const branch = document.querySelector<HTMLSelectElement>('[data-branch-select]');
const service = document.querySelector<HTMLSelectElement>('[data-service-select]');
function filterPlacement() {
  if (region && branch) {
    for (const option of branch.options) { option.hidden = !!option.value && option.dataset.region !== region.value; option.disabled = option.hidden; }
    if (branch.selectedOptions[0]?.disabled) branch.value = '';
  }
  if (branch && service) {
    for (const option of service.options) { option.hidden = !!option.value && option.dataset.branch !== branch.value; option.disabled = option.hidden; }
    if (service.selectedOptions[0]?.disabled) service.value = '';
  }
}
region?.addEventListener('change', filterPlacement);
branch?.addEventListener('change', filterPlacement);
filterPlacement();

document.querySelectorAll<HTMLButtonElement>('[data-toggle-password]').forEach(button => {
  button.addEventListener('click', () => {
    const input = document.getElementById(button.dataset.togglePassword ?? '') as HTMLInputElement | null;
    if (!input) return;
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(visible));
    button.setAttribute('aria-label', visible ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
  });
});
document.querySelectorAll<HTMLFormElement>('[data-access-form]').forEach(form => {
  installFormConfirmation(form, () => {
    const button = form.querySelector<HTMLButtonElement>('[data-submit-label]');
    if (button) { button.disabled = true; button.textContent = button.dataset.submitLabel ?? 'Menyimpan…'; }
    form.setAttribute('aria-busy', 'true');
  });
});
document.querySelector<HTMLElement>('[data-error-summary]')?.focus();
window.addEventListener('pageshow', () => {
  document.querySelectorAll<HTMLButtonElement>('[data-submit-label]').forEach(button => { button.disabled = false; });
});

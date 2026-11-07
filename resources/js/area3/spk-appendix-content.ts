import type { State, Project, Deal } from './domain';

const rupiah = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
const volume = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 });
const round = (value: number) => Math.round((value + Number.EPSILON) * 100) / 100;

/** Presentation of the selected agreement only; reference indexes never supply prices/volumes. */
export function spkAppendixData(state: State, project: Project, deal: Deal) {
  const root = project.rootId === project.id ? project : state.projects.find(p => p.id === project.rootId);
  const source = new Map(root?.boq.map(row => [row.id, row.khsId]) ?? []);
  const catalog = new Map(state.khs.map(item => [item.id, item]));
  const rows: { id: string; component: string; cells: string[] }[] = [];
  let material = 0, total = 0, splitVolumes = false;
  for (const row of deal.rows) {
    if (!row.m && !row.s) continue;
    const item = catalog.get(source.get(row.rowId) ?? '');
    const m = row.m ? round(row.qm * row.pm) : 0;
    const combined = round((row.m ? row.qm * row.pm : 0) + (row.s ? row.qs * row.ps : 0));
    // Same cent allocation as the main SPK letter; M + J always equals the domain line total.
    const s = round(combined - m);
    material += m; total += combined;
    const split = row.m && row.s && row.qm !== row.qs;
    splitVolumes ||= split;
    const parts = split ? ['m', 's'] as const : ['both'] as const;
    for (const part of parts) {
      const hasM = row.m && part !== 's', hasS = row.s && part !== 'm';
      const qty = hasM ? row.qm : row.qs;
      const component = split ? part === 'm' ? 'Material' : 'Jasa' : '';
      rows.push({ id: `${row.rowId}-${part}`, component, cells: [
        String(rows.length + 1), item?.code ?? 'Referensi tidak tersedia', item?.name ?? 'Referensi tidak tersedia', item?.unit ?? '',
        hasM ? rupiah.format(row.pm) : '', hasS ? rupiah.format(row.ps) : '', volume.format(qty), volume.format(qty),
        hasM ? rupiah.format(m) : '', hasS ? rupiah.format(s) : '', rupiah.format(round((hasM ? m : 0) + (hasS ? s : 0))),
      ] });
    }
  }
  material = round(material); total = round(total);
  return { rows, splitVolumes, material, service: round(total - material), total,
    materialText: deal.rows.some(row => row.m) ? rupiah.format(material) : '',
    serviceText: deal.rows.some(row => row.s) ? rupiah.format(round(total - material)) : '', totalText: rupiah.format(total) };
}

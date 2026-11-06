import type { State, Project, Deal } from './domain';

const weekday = new Intl.DateTimeFormat('id-ID', { weekday: 'long', timeZone: 'UTC' });
const month = new Intl.DateTimeFormat('id-ID', { month: 'long', timeZone: 'UTC' });
const fullDate = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
const words = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];

function dateNumberWords(value: number): string {
  if (value < 12) return words[value];
  if (value < 20) return `${words[value - 10]} Belas`;
  if (value < 100) return `${words[Math.floor(value / 10)]} Puluh ${dateNumberWords(value % 10)}`.trim();
  if (value < 200) return `Seratus ${dateNumberWords(value - 100)}`.trim();
  if (value < 1000) return `${words[Math.floor(value / 100)]} Ratus ${dateNumberWords(value % 100)}`.trim();
  if (value < 2000) return `Seribu ${dateNumberWords(value - 1000)}`.trim();
  return `${words[Math.floor(value / 1000)]} Ribu ${dateNumberWords(value % 1000)}`.trim();
}

/** Calendar-only presentation: never substitute today's date or normalize invalid dates. */
export function bakhDocumentDate(value?: string) {
  if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
  const date = new Date(`${value}T12:00:00Z`);
  if (!Number.isFinite(date.getTime()) || date.getUTCFullYear() < 1 || date.toISOString().slice(0, 10) !== value) return null;
  return { weekday: weekday.format(date), dayWords: dateNumberWords(date.getUTCDate()), month: month.format(date), yearWords: dateNumberWords(date.getUTCFullYear()), full: fullDate.format(date) };
}

export interface BakhRun { text: string; bold?: boolean; italic?: boolean; underline?: boolean }
const filled = (value?: string) => value?.trim() || '…';
export const BAKH_TEXT = {
  title: 'BERITA ACARA', subtitle: 'NEGOSIASI KESEPAKATAN HARGA',
  appendixTitle: 'LAMPIRAN BERITA ACARA KESEPEKATAN HARGA',
  result: 'Hasil dari Negosiasi Kesepakatan Harga adalah sebagai berikut;',
  price: 'Sesuai dengan lampiran Harga Satuan.', tax: 'Belum Termasuk Pajak Penghasilan.',
  payment: 'Skema pembayaran akan dilakukan rekonsiliasi sesuai hasil Opname Realisasi Pekerjaan antara Waspang TA dan Pelaksana Pekerjaan. Opname realisasi pekerjaan dapat dilakukan secara berkala sampai dengan perhitungan final pekerjaan sampai dinyatakan dengan BAPP.',
  closing: 'Demikian Berita Acara Kesepakatan ini dibuat sebagai bahan proses lebih lanjut.',
};

export function bakhSignatures(deal: Deal, appendix = false) {
  return [
    { title: appendix ? 'TELKOM AKSES' : 'PT TELKOM AKSES', name: deal.signer || (appendix ? 'Belum ditetapkan' : '…'), job: deal.signerRole === 'Head of PMO' ? 'HEAD OF PMO AREA JAWA BALI' : 'Penandatangan sesuai BA KH', nik: deal.signerSource === 'pp-head-pmo' ? `NIK: ${deal.signerNik || 'Belum tersedia'}` : '' },
    { title: 'MANDOR PROJECT', name: deal.mandor || (appendix ? 'Belum ditetapkan' : '…'), job: appendix ? 'KETUA MANDOR' : 'KETUA', nik: '' },
  ];
}

export function bakhDocumentData(project: Project, deal: Deal) {
  const date = bakhDocumentDate(deal.date), start = bakhDocumentDate(deal.start), end = bakhDocumentDate(deal.end);
  const opening: BakhRun[] = [
    { text: 'Pada hari ini ' }, { text: date?.weekday ?? '…', bold: true }, { text: ' tanggal ' }, { text: date?.dayWords ?? '…', bold: true },
    { text: ' bulan ' }, { text: date?.month ?? '…', bold: true }, { text: ' tahun ' }, { text: date?.yearWords ?? '…', bold: true },
    { text: ', kami yang bertanda tangan di bawah ini telah melaksanakan Negosiasi Kesepakatan Harga atas pekerjaan sesuai tersebut di atas.' },
  ];
  const team: BakhRun[] = [
    { text: 'Penyelesaian pekerjaan disepakati dengan jumlah tim FO ' }, { text: filled(deal.fo), bold: true },
    { text: ' dan jointer ' }, { text: filled(deal.jointer), bold: true }, { text: ' pada kurun waktu dari ' },
    { text: start?.full ?? '…', bold: true }, { text: ' sampai dengan ' }, { text: end?.full ?? '…', bold: true }, { text: ' (POW terlampir).' },
  ];
  return {
    number: filled(deal.no), metadata: [['PEKERJAAN', filled(project.name)], ['LOKASI', filled(deal.location)], ['WITEL', filled(deal.witel)]],
    opening, team, place: `${filled(deal.place)}, ${date?.full ?? '…'}`, signatures: bakhSignatures(deal),
  };
}

const rupiah = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
export function bakhAppendixRows(state: State, project: Project, deal: Deal) {
  const root = project.rootId === project.id ? project : state.projects.find(p => p.id === project.rootId);
  const sourceRows = new Map(root?.boq.map(row => [row.id, row]) ?? []);
  const catalog = new Map(state.khs.map(item => [item.id, item]));
  const price = (selected: boolean, value: number) => selected ? rupiah.format(value) : '';
  return deal.rows.filter(row => row.m || row.s).map((row, index) => {
    const item = catalog.get(sourceRows.get(row.rowId)?.khsId ?? '');
    return { id: row.rowId, cells: [String(index + 1), item?.code ?? 'Referensi tidak tersedia', item?.name ?? 'Referensi tidak tersedia', item?.unit ?? '—', price(row.m, row.offerM), price(row.s, row.offerS), price(row.m, row.sphM), price(row.s, row.sphS), price(row.m, row.pm), price(row.s, row.ps)] };
  });
}

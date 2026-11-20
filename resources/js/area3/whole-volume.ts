export const MAX_VOLUME = 1_000_000_000;
export const VOLUME_MESSAGE = 'Volume harus berupa bilangan bulat 0–1.000.000.000 tanpa koma atau desimal.';
export const isWholeVolume = (value: number) => Number.isInteger(value) && value >= 0 && value <= MAX_VOLUME;
// Reject the whole edit: converting "1,5" to "15" would change the requested volume.
export function parseVolumeInput(text: string): number | null {
  if (/[^0-9]/.test(text)) return null;
  const value = text === '' ? 0 : Number(text);
  return isWholeVolume(value) ? value : null;
}
export const isHistoricalVolume = (value:number) => Number.isFinite(value) && value >= 0 && value <= MAX_VOLUME && Math.abs(value*1000-Math.round(value*1000)) < 0.000001;

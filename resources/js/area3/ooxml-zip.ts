/** Small deterministic UTF-8 ZIP store writer for native OOXML, without external libraries. */
const crcTable = Uint32Array.from({ length: 256 }, (_, n) => {
  for (let k = 0; k < 8; k++) n = n & 1 ? 0xedb88320 ^ (n >>> 1) : n >>> 1;
  return n >>> 0;
});

function crc32(bytes: Uint8Array) {
  let crc = 0xffffffff;
  for (const byte of bytes) crc = crcTable[(crc ^ byte) & 255] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}

export function ooxmlZip(entries: Record<string, string | Uint8Array>): Uint8Array<ArrayBuffer> {
  const encoder = new TextEncoder();
  const files = Object.entries(entries).map(([path, xml]) => {
    const name = encoder.encode(path), bytes = typeof xml === 'string' ? encoder.encode(xml) : xml;
    return { name, bytes, crc: crc32(bytes), offset: 0 };
  });
  const localSize = files.reduce((n, f) => n + 30 + f.name.length + f.bytes.length, 0);
  const directorySize = files.reduce((n, f) => n + 46 + f.name.length, 0);
  // No truncation or ZIP64: keep browser memory bounded and report a recoverable error.
  if (files.length > 65535 || localSize + directorySize + 22 > 64 * 1024 * 1024) throw new Error('Dokumen terlalu besar untuk diunduh melalui browser.');
  const output = new Uint8Array(localSize + directorySize + 22), view = new DataView(output.buffer);
  let cursor = 0;
  const word = (value: number) => { view.setUint16(cursor, value, true); cursor += 2; };
  const dword = (value: number) => { view.setUint32(cursor, value, true); cursor += 4; };
  const bytes = (value: Uint8Array) => { output.set(value, cursor); cursor += value.length; };
  for (const file of files) {
    file.offset = cursor;
    dword(0x04034b50); word(20); word(0x0800); word(0); word(0); word(33); dword(file.crc);
    dword(file.bytes.length); dword(file.bytes.length); word(file.name.length); word(0); bytes(file.name); bytes(file.bytes);
  }
  for (const file of files) {
    dword(0x02014b50); word(20); word(20); word(0x0800); word(0); word(0); word(33); dword(file.crc);
    dword(file.bytes.length); dword(file.bytes.length); word(file.name.length); word(0); word(0); word(0); word(0); dword(0); dword(file.offset); bytes(file.name);
  }
  dword(0x06054b50); word(0); word(0); word(files.length); word(files.length); dword(directorySize); dword(localSize); word(0);
  return output;
}

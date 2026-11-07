import assert from 'node:assert/strict';
// Read via ZIP central directory (not the writer's local-header iteration).
export function docxParts(bytes: Uint8Array): Record<string, string> {
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength), decoder = new TextDecoder();
  assert.equal(view.getUint32(bytes.length - 22, true), 0x06054b50);
  const count = view.getUint16(bytes.length - 12, true), result: Record<string, string> = {};
  let cursor = view.getUint32(bytes.length - 6, true);
  for (let i = 0; i < count; i++) {
    assert.equal(view.getUint32(cursor, true), 0x02014b50);
    const size = view.getUint32(cursor + 24, true), nameLength = view.getUint16(cursor + 28, true), extra = view.getUint16(cursor + 30, true), comment = view.getUint16(cursor + 32, true), local = view.getUint32(cursor + 42, true);
    const name = decoder.decode(bytes.subarray(cursor + 46, cursor + 46 + nameLength));
    assert.equal(view.getUint32(local, true), 0x04034b50); assert.equal(view.getUint16(local + 8, true), 0);
    assert.equal(view.getUint32(local + 18, true), size); assert.equal(view.getUint32(local + 14, true), view.getUint32(cursor + 16, true));
    const start = local + 30 + view.getUint16(local + 26, true) + view.getUint16(local + 28, true);
    result[name] = decoder.decode(bytes.subarray(start, start + size));
    cursor += 46 + nameLength + extra + comment;
  }
  return result;
}


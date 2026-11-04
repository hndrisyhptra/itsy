export interface Command { command: string; args: unknown[] }
let recorder: ((command: Command) => void) | undefined;
export function recordCommand(command: string, args: unknown[]) { recorder?.({ command, args: structuredClone(args) }); }
export function setCommandRecorder(fn?: (command: Command) => void) { recorder = fn; }

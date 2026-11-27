import { trans } from 'laravel-vue-i18n';

export type PickedFile = { path: string; file: File };

export const MAX_FILES = 50_000;
export const MAX_BYTES = 1024 * 1024 * 1024;

const SKIP_DIRECTORIES = new Set([
    'node_modules',
    '.git',
    '.next',
    '.nuxt',
    '.svelte-kit',
    '.turbo',
    '.vercel',
    '.cache',
    '__MACOSX',
    'vendor',
]);
const SKIP_FILES = new Set(['.DS_Store', 'Thumbs.db']);

export class PackError extends Error {}

function skippedDirectory(name: string): boolean {
    return SKIP_DIRECTORIES.has(name);
}

export function kept(path: string): boolean {
    const parts = path.split('/').filter(Boolean);
    const file = parts.at(-1) ?? '';

    if (parts.slice(0, -1).some(skippedDirectory)) return false;
    if (/^\.env(\..+)?$/.test(file) && !/\.(example|sample|dist)$/.test(file)) {
        return false;
    }

    return !SKIP_FILES.has(file);
}

export function isZip(file: File): boolean {
    return file.name.toLowerCase().endsWith('.zip');
}

export function fromInput(list: FileList | null | undefined): PickedFile[] {
    return Array.from(list ?? [])
        .map((file) => ({ path: file.webkitRelativePath || file.name, file }))
        .filter((picked) => kept(picked.path));
}

function readAll(
    reader: FileSystemDirectoryReader,
): Promise<FileSystemEntry[]> {
    return new Promise((resolve, reject) => {
        const entries: FileSystemEntry[] = [];
        const next = () =>
            reader.readEntries((batch) => {
                if (batch.length === 0) {
                    resolve(entries);
                } else {
                    entries.push(...batch);
                    next();
                }
            }, reject);
        next();
    });
}

async function walk(
    entry: FileSystemEntry,
    into: PickedFile[],
    prefix = '',
): Promise<void> {
    if (into.length > MAX_FILES) return;
    const path = `${prefix}${entry.name}`;

    if (entry.isFile) {
        if (!kept(path)) return;
        const file = await new Promise<File>((resolve, reject) =>
            (entry as FileSystemFileEntry).file(resolve, reject),
        );
        into.push({ path, file });

        return;
    }

    if (entry.isDirectory && !skippedDirectory(entry.name)) {
        const children = await readAll(
            (entry as FileSystemDirectoryEntry).createReader(),
        );
        for (const child of children) {
            await walk(child, into, `${path}/`);
        }
    }
}

export async function fromDrop(
    transfer: DataTransfer | null | undefined,
): Promise<PickedFile[]> {
    if (!transfer) return [];
    const entries = Array.from(transfer.items ?? [])
        .filter((item) => item.kind === 'file')
        .map((item) => item.webkitGetAsEntry?.() ?? null);

    if (entries.length === 0 || entries.some((entry) => entry === null)) {
        return fromInput(transfer.files);
    }

    const found: PickedFile[] = [];
    for (const entry of entries) {
        await walk(entry as FileSystemEntry, found);
    }

    return found;
}

export function archiveName(files: PickedFile[]): string {
    const roots = new Set(files.map((picked) => picked.path.split('/')[0]));
    const [root] = [...roots];

    if (
        roots.size === 1 &&
        files.every((picked) => picked.path.includes('/'))
    ) {
        return `${root}.zip`;
    }

    return 'app.zip';
}

const CRC_TABLE = (() => {
    const table = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) {
            c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        }
        table[n] = c >>> 0;
    }

    return table;
})();

function crc32(bytes: Uint8Array, seed = 0): number {
    let crc = ~seed >>> 0;
    for (let i = 0; i < bytes.length; i++) {
        crc = CRC_TABLE[(crc ^ bytes[i]) & 0xff] ^ (crc >>> 8);
    }

    return ~crc >>> 0;
}

function dosTime(ms: number): { time: number; date: number } {
    const d = new Date(ms || Date.now());
    const year = Math.max(1980, d.getFullYear());

    return {
        time:
            (d.getHours() << 11) |
            (d.getMinutes() << 5) |
            Math.floor(d.getSeconds() / 2),
        date: ((year - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate(),
    };
}

const encoder = new TextEncoder();

export async function pack(
    files: PickedFile[],
    onProgress: (done: number) => void = () => {},
): Promise<File> {
    if (files.length === 0) {
        throw new PackError(
            trans(
                'Nothing to upload once dependencies and build folders are left out.',
            ),
        );
    }
    if (files.length > MAX_FILES) {
        throw new PackError(
            trans(
                'That folder has more than 50,000 files. Leave out node_modules and build folders.',
            ),
        );
    }
    const total = files.reduce((sum, picked) => sum + picked.file.size, 0);
    if (total > MAX_BYTES) {
        throw new PackError(
            trans(
                'That folder is over 1 GB. Leave out node_modules and build folders.',
            ),
        );
    }

    const parts: BlobPart[] = [];
    const central: Uint8Array<ArrayBuffer>[] = [];
    let offset = 0;
    let read = 0;

    for (const { path, file } of files) {
        const name = new Uint8Array(encoder.encode(path));
        const data = new Uint8Array(await file.arrayBuffer());
        const crc = crc32(data);
        const { time, date } = dosTime(file.lastModified);

        const local = new DataView(new ArrayBuffer(30));
        local.setUint32(0, 0x04034b50, true);
        local.setUint16(4, 20, true);
        local.setUint16(6, 0x0800, true);
        local.setUint16(8, 0, true);
        local.setUint16(10, time, true);
        local.setUint16(12, date, true);
        local.setUint32(14, crc, true);
        local.setUint32(18, data.length, true);
        local.setUint32(22, data.length, true);
        local.setUint16(26, name.length, true);
        local.setUint16(28, 0, true);
        parts.push(local.buffer, name, file);

        const entry = new DataView(new ArrayBuffer(46));
        entry.setUint32(0, 0x02014b50, true);
        entry.setUint16(4, 20, true);
        entry.setUint16(6, 20, true);
        entry.setUint16(8, 0x0800, true);
        entry.setUint16(10, 0, true);
        entry.setUint16(12, time, true);
        entry.setUint16(14, date, true);
        entry.setUint32(16, crc, true);
        entry.setUint32(20, data.length, true);
        entry.setUint32(24, data.length, true);
        entry.setUint16(28, name.length, true);
        entry.setUint32(42, offset, true);
        const record = new Uint8Array(46 + name.length);
        record.set(new Uint8Array(entry.buffer), 0);
        record.set(name, 46);
        central.push(record);

        offset += 30 + name.length + data.length;
        read += data.length;
        onProgress(total ? read / total : 1);
    }

    const size = central.reduce((sum, record) => sum + record.length, 0);
    const end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054b50, true);
    end.setUint16(8, files.length, true);
    end.setUint16(10, files.length, true);
    end.setUint32(12, size, true);
    end.setUint32(16, offset, true);

    return new File([...parts, ...central, end.buffer], archiveName(files), {
        type: 'application/zip',
    });
}

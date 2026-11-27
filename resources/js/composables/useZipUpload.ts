import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { RequestError, describe, errorMessage, failIfBad } from '@/lib/errors';
import { workspacePath } from '@/lib/workspaceRoute';

export type UploadedSource = {
    detected: boolean;
    template: string | null;
    framework: string | null;
    type: string | null;
    repo: string;
    branch: string;
    commit: string;
    reasons: string[];
    upload: { filename: string; files: number; expiresAt: string };
};

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function post(url: string, body: BodyInit, type: string) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': type,
            'X-CSRF-TOKEN': csrf(),
        },
        body,
    });
    await failIfBad(response);
    return await response.json().catch(() => ({}));
}

function sendChunk(
    url: string,
    body: Blob,
    onProgress: (sent: number) => void,
): Promise<void> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('Content-Type', 'application/octet-stream');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrf());
        xhr.upload.onprogress = (event) => onProgress(event.loaded);
        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve();

                return;
            }
            let answer: unknown = null;
            try {
                answer = JSON.parse(xhr.responseText);
            } catch {}
            reject(new RequestError(describe(xhr.status, answer), xhr.status));
        };
        xhr.onerror = () => reject(new TypeError('Failed to fetch'));
        xhr.send(body);
    });
}

export function useZipUpload() {
    const uploading = ref(false);
    const progress = ref(0);
    const error = ref<string | null>(null);

    async function upload(
        machineId: number,
        file: File | undefined,
    ): Promise<UploadedSource | null> {
        if (!file || uploading.value) return null;
        if (!file.name.toLowerCase().endsWith('.zip')) {
            error.value = trans('Upload a .zip of your project folder.');
            return null;
        }

        uploading.value = true;
        progress.value = 0;
        error.value = null;
        const base = workspacePath(`/machines/${machineId}/sources`);

        try {
            const start = await post(
                base,
                JSON.stringify({ filename: file.name, size: file.size }),
                'application/json',
            );
            for (let offset = 0; offset < file.size; offset += start.chunk) {
                await sendChunk(
                    `${base}/${start.id}/chunk?offset=${offset}`,
                    file.slice(offset, offset + start.chunk),
                    (sent) =>
                        (progress.value = Math.min(
                            1,
                            (offset + sent) / file.size,
                        )),
                );
                progress.value = Math.min(
                    1,
                    (offset + start.chunk) / file.size,
                );
            }

            return (await post(
                `${base}/${start.id}/finish`,
                '{}',
                'application/json',
            )) as UploadedSource;
        } catch (cause) {
            error.value = errorMessage(
                cause,
                trans('That upload didn’t work. Try again.'),
            );
            return null;
        } finally {
            uploading.value = false;
        }
    }

    return { uploading, progress, error, upload, post };
}

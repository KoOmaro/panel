import { trans } from 'laravel-vue-i18n';
import { toast } from '@/lib/toast';

export type YamlTone = 'comment' | 'key' | 'value' | 'ref';

export type YamlPart = { text: string; tone: YamlTone };

export type DiffLine = { no: number; sign: '+' | '-' | ' '; text: string };

const PAIR = /^(\s*(?:"[^"]*"|[^\s:#"-][^:#]*?)\s*:)(\s.*|)$/;
const ITEM = /^(\s*-)(\s.*|)$/;

function values(text: string): YamlPart[] {
    return text
        .split(/(\$\{\{.*?\}\})/)
        .filter((part) => part !== '')
        .map((part) => ({
            text: part,
            tone: part.startsWith('${{') ? 'ref' : 'value',
        }));
}

function pair(text: string): YamlPart[] {
    const match = PAIR.exec(text);

    if (!match) return values(text);

    return [{ text: match[1], tone: 'key' }, ...values(match[2])];
}

export function yamlParts(line: string): YamlPart[] {
    if (/^\s*#/.test(line)) return [{ text: line, tone: 'comment' }];

    const item = ITEM.exec(line);

    if (item) {
        const rest = item[2];
        const inner = /^(\s*)(.*)$/.exec(rest);

        return [
            { text: item[1] + (inner?.[1] ?? ''), tone: 'key' },
            ...pair(inner?.[2] ?? ''),
        ];
    }

    return pair(line);
}

export async function copyText(text: string, done: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(done);
    } catch {
        toast.error(
            trans('Couldn’t copy. Select the text and copy it instead.'),
        );
    }
}

export function comingSoon(what: string): void {
    toast.info(trans('Coming soon'), { description: what });
}

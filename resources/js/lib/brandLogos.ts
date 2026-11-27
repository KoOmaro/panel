import {
    siAnthropic,
    siAstro,
    siBackblaze,
    siBun,
    siClaude,
    siClickhouse,
    siCloudflare,
    siCurl,
    siDigitalocean,
    siDiscord,
    siDjango,
    siDocker,
    siGhost,
    siGithub,
    siGitlab,
    siGrafana,
    siHetzner,
    siLaravel,
    siLinear,
    siLinux,
    siMariadb,
    siMeilisearch,
    siMinio,
    siMongodb,
    siN8n,
    siNextdotjs,
    siNginx,
    siNodedotjs,
    siOllama,
    siOpenrouter,
    siOvh,
    siPagerduty,
    siPlausibleanalytics,
    siPostgresql,
    siProxmox,
    siPython,
    siRedis,
    siSentry,
    siTailscale,
    siTelegram,
    siUbuntu,
    siUptimekuma,
    siVultr,
} from 'simple-icons';
import type { SimpleIcon } from 'simple-icons';
import awsSvg from '@/components/dash/assets/aws.svg?raw';
import slackSvg from '@/components/dash/assets/slack.svg?raw';
import { AI_BRANDS } from '@/lib/aiBrands';
import { providerBrand } from '@/lib/providerBrand';
import { serviceBrand } from '@/lib/serviceBrand';

export type BrandMark =
    | { kind: 'path'; title: string; path: string; dark: string; light: string }
    | { kind: 'svg'; title: string; svg: string };

const MONO_DARK = '#ededed';
const MONO_LIGHT = '#151515';

function mark(icon: SimpleIcon, dark?: string, light?: string): BrandMark {
    const brand = `#${icon.hex}`;

    return {
        kind: 'path',
        title: icon.title,
        path: icon.path,
        dark: dark ?? brand,
        light: light ?? brand,
    };
}

const mono = (icon: SimpleIcon): BrandMark => mark(icon, MONO_DARK, MONO_LIGHT);

export const brandLogos = {
    next: mono(siNextdotjs),
    laravel: mark(siLaravel),
    astro: mark(siAstro),
    plausible: mark(siPlausibleanalytics),
    kuma: mark(siUptimekuma),
    ghost: mono(siGhost),
    n8n: mark(siN8n),
    node: mark(siNodedotjs),
    minio: mark(siMinio),
    meili: mark(siMeilisearch),
    redis: mark(siRedis),
    clickhouse: mark(siClickhouse, '#ffcc01', '#c99d00'),
    hetzner: mark(siHetzner),
    digitalocean: mark(siDigitalocean),
    vultr: mark(siVultr),
    postgres: mark(siPostgresql),
    github: mono(siGithub),
    discord: mark(siDiscord),
    claude: mark(siClaude),
    mongodb: mark(siMongodb),
    mariadb: mark(siMariadb, '#c49a6c', '#003545'),
    django: mark(siDjango, '#44b78b', '#092e20'),
    bun: mark(siBun, '#f9f1e1', MONO_LIGHT),
    linux: mark(siLinux, '#fcc624', '#d3a200'),
    ubuntu: mark(siUbuntu),
    nginx: mark(siNginx),
    docker: mark(siDocker),
    python: mark(siPython),
    curl: mono(siCurl),
    cloudflare: mark(siCloudflare),
    backblaze: mark(siBackblaze),
    telegram: mark(siTelegram),
    slack: { kind: 'svg', title: 'Slack', svg: slackSvg },
    gitlab: mark(siGitlab),
    sentry: mono(siSentry),
    linear: mark(siLinear),
    pagerduty: mark(siPagerduty),
    aws: { kind: 'svg', title: 'AWS', svg: awsSvg },
    ovhcloud: mono(siOvh),
    proxmox: mark(siProxmox),
    tailscale: mono(siTailscale),
    anthropic: mono(siAnthropic),
    openai: {
        kind: 'path',
        title: 'OpenAI',
        path: AI_BRANDS.openai.paths[0].d,
        dark: MONO_DARK,
        light: MONO_LIGHT,
    },
    ollama: mono(siOllama),
    openrouter: mono(siOpenrouter),
    grafana: mark(siGrafana),
} satisfies Record<string, BrandMark>;

export type BrandName = keyof typeof brandLogos;

const aliases: [RegExp, BrandName][] = [
    [/next(?:\.?js)?\b/, 'next'],
    [/laravel/, 'laravel'],
    [/\bastro\b/, 'astro'],
    [/plausible/, 'plausible'],
    [/uptime-?kuma|\bkuma\b/, 'kuma'],
    [/\bghost\b/, 'ghost'],
    [/\bn8n\b/, 'n8n'],
    [/\bnode(?:\.?js)?\b/, 'node'],
    [/\bminio\b/, 'minio'],
    [/meili(?:search)?/, 'meili'],
    [/\bredis\b|valkey/, 'redis'],
    [/clickhouse/, 'clickhouse'],
    [/hetzner/, 'hetzner'],
    [/digital\s*ocean/, 'digitalocean'],
    [/vultr/, 'vultr'],
    [/postgres(?:ql)?|\bpg\b|timescale/, 'postgres'],
    [/github/, 'github'],
    [/discord/, 'discord'],
    [/claude|anthropic/, 'claude'],
    [/\bmongo(?:db)?\b/, 'mongodb'],
    [/\bmaria(?:db)?\b/, 'mariadb'],
    [/django/, 'django'],
    [/\bbun\b/, 'bun'],
    [/ubuntu/, 'ubuntu'],
    [/\blinux\b|debian/, 'linux'],
    [/\bnginx\b/, 'nginx'],
    [/\bdocker\b/, 'docker'],
    [/python|gunicorn|uvicorn/, 'python'],
    [/\bcurl\b/, 'curl'],
    [/cloudflare/, 'cloudflare'],
    [/backblaze|\bb2\b/, 'backblaze'],
    [/telegram/, 'telegram'],
    [/slack/, 'slack'],
];

export function isBrandName(value: string): value is BrandName {
    return Object.hasOwn(brandLogos, value);
}

export function brandMark(value: string | null | undefined): BrandMark | null {
    if (!value) {
        return null;
    }

    const text = value.toLowerCase();

    if (isBrandName(text)) {
        return brandLogos[text];
    }

    const alias = aliases.find(([pattern]) => pattern.test(text));

    if (alias) {
        return brandLogos[alias[1]];
    }

    const known = serviceBrand(text) ?? providerBrand(value);

    if (!known) {
        return null;
    }

    const [r, g, b] = [0, 2, 4].map(
        (i) => parseInt(known.hex.slice(i, i + 2), 16) / 255,
    );
    const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;

    return luminance < 0.12 ? mono(known) : mark(known);
}
